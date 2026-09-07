<?php

namespace Tests\Feature\Content;

use App\Jobs\Content\DiscoverProductPagesJob;
use App\Livewire\Content\ContentCalendar;
use App\Models\ContentPlan;
use App\Models\ContentProduct;
use App\Models\ContentProductRun;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Support\ContentSiteTypeProfiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Strict Product Mode client UX: the mandatory step-7 choice (dual-host),
 * the existing-client banner, the catalog progress screen, and the
 * Settings → Products tab. Mandatoriness is a UI property only — the
 * planner-side behavior lives in StrictModeGateTest.
 */
class ProductModeWizardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /** @return array{0: User, 1: Website, 2: ContentPlan} */
    private function ecomSetup(string $planStatus = ContentPlan::STATUS_DRAFT, ?string $siteType = ContentSiteTypeProfiles::BRAND): array
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => $planStatus,
            'site_type' => $siteType,
        ]);
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        return [$user, $website, $plan];
    }

    // ── Step-7 mandatory choice (dashboard host) ────────────────────────

    public function test_wizard_requires_product_choice_for_ecommerce_site_types(): void
    {
        $this->ecomSetup();

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('wizardStep', 7)
            ->assertViewHas('wizard', fn ($w) => $w['requiresProductChoice'] === true);
    }

    public function test_wizard_does_not_require_product_choice_for_non_ecommerce(): void
    {
        $this->ecomSetup(ContentPlan::STATUS_DRAFT, 'local_service');

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('wizardStep', 7)
            ->assertViewHas('wizard', fn ($w) => $w['requiresProductChoice'] === false);
    }

    public function test_launch_is_blocked_until_a_product_mode_is_picked(): void
    {
        Queue::fake();
        [, , $plan] = $this->ecomSetup();

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('wizardStep', 7)
            ->call('launch');

        $plan->refresh();
        $this->assertSame(ContentPlan::STATUS_DRAFT, $plan->status);
        $this->assertNull($plan->product_mode);
    }

    public function test_launch_with_strict_choice_activates_through_the_single_path(): void
    {
        Queue::fake();
        [, , $plan] = $this->ecomSetup();

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('wizardStep', 7)
            ->call('chooseProductMode', 'strict')
            ->call('launch');

        $plan->refresh();
        $this->assertSame(ContentPlan::STATUS_ACTIVE, $plan->status);
        $this->assertSame(ContentPlan::PRODUCT_MODE_STRICT, $plan->product_mode);
        Queue::assertPushed(DiscoverProductPagesJob::class);
    }

    public function test_launch_with_normal_choice_records_it_and_scrapes_nothing(): void
    {
        Queue::fake();
        [, , $plan] = $this->ecomSetup();

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('wizardStep', 7)
            ->call('chooseProductMode', 'normal')
            ->call('launch');

        $this->assertSame(ContentPlan::PRODUCT_MODE_NORMAL, $plan->refresh()->product_mode);
        Queue::assertNotPushed(DiscoverProductPagesJob::class);
    }

    public function test_invalid_mode_choice_is_rejected(): void
    {
        $this->ecomSetup();

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->call('chooseProductMode', 'bogus')
            ->assertSet('productModeChoice', '');
    }

    // ── Public onboarding host (dual-component rule) ────────────────────

    public function test_public_host_exposes_the_same_choice_action(): void
    {
        // The wizard blade is shared, so a $wire.chooseProductMode click must
        // resolve on BOTH hosts (content-wizard-dual-component rule).
        foreach ([\App\Livewire\Content\ContentCalendar::class, \App\Livewire\Content\PublicOnboarding::class] as $host) {
            $this->assertTrue(method_exists($host, 'chooseProductMode'), "$host missing chooseProductMode");
        }
        $trait = new \ReflectionClass(\App\Livewire\Content\Concerns\ContentWizard::class);
        $this->assertTrue($trait->hasProperty('productModeChoice'));
    }

    // ── Existing-client banner (calendar) ───────────────────────────────

    public function test_active_undecided_ecom_plan_sees_the_banner(): void
    {
        $this->ecomSetup(ContentPlan::STATUS_ACTIVE);

        Livewire::test(ContentCalendar::class, ['mode' => 'calendar'])
            ->assertViewHas('showProductModeBanner', true);
    }

    public function test_decided_plan_never_sees_the_banner(): void
    {
        [, , $plan] = $this->ecomSetup(ContentPlan::STATUS_ACTIVE);
        $plan->update(['product_mode' => ContentPlan::PRODUCT_MODE_NORMAL]);

        Livewire::test(ContentCalendar::class, ['mode' => 'calendar'])
            ->assertViewHas('showProductModeBanner', false);
    }

    public function test_banner_one_click_opt_in_activates_strict(): void
    {
        Queue::fake();
        [, , $plan] = $this->ecomSetup(ContentPlan::STATUS_ACTIVE);

        Livewire::test(ContentCalendar::class, ['mode' => 'calendar'])
            ->call('activateStrictMode');

        $this->assertSame(ContentPlan::PRODUCT_MODE_STRICT, $plan->refresh()->product_mode);
        Queue::assertPushed(DiscoverProductPagesJob::class);
    }

    public function test_banner_keep_as_is_records_normal_so_it_never_returns(): void
    {
        Queue::fake();
        [, , $plan] = $this->ecomSetup(ContentPlan::STATUS_ACTIVE);

        Livewire::test(ContentCalendar::class, ['mode' => 'calendar'])
            ->call('keepNormalMode')
            ->assertViewHas('showProductModeBanner', false);

        $this->assertSame(ContentPlan::PRODUCT_MODE_NORMAL, $plan->refresh()->product_mode);
    }

    // ── Catalog progress screen ─────────────────────────────────────────

    public function test_strict_plan_with_run_in_flight_shows_the_progress_screen(): void
    {
        [, $website, $plan] = $this->ecomSetup(ContentPlan::STATUS_ACTIVE);
        $plan->update(['product_mode' => ContentPlan::PRODUCT_MODE_STRICT]);
        ContentProductRun::factory()->create([
            'website_id' => $website->id, 'plan_id' => $plan->id,
            'status' => ContentProductRun::STATUS_EXTRACTING,
        ]);

        Livewire::test(ContentCalendar::class, ['mode' => 'calendar'])
            ->assertViewHas('catalogProgress', fn ($cp) => $cp !== null && $cp['run'] !== null);
    }

    public function test_strict_plan_with_ready_catalog_shows_the_calendar(): void
    {
        [, $website, $plan] = $this->ecomSetup(ContentPlan::STATUS_ACTIVE);
        $plan->update(['product_mode' => ContentPlan::PRODUCT_MODE_STRICT]);
        ContentProduct::factory()->create(['website_id' => $website->id]);
        ContentProductRun::factory()->create([
            'website_id' => $website->id, 'status' => ContentProductRun::STATUS_READY,
        ]);

        $html = Livewire::test(ContentCalendar::class, ['mode' => 'calendar'])->html();
        $this->assertStringNotContainsString('retryCatalogScan', $html);
    }

    public function test_failed_empty_run_offers_retry_and_normal_escape(): void
    {
        Queue::fake();
        [, $website, $plan] = $this->ecomSetup(ContentPlan::STATUS_ACTIVE);
        $plan->update(['product_mode' => ContentPlan::PRODUCT_MODE_STRICT]);
        ContentProductRun::factory()->create([
            'website_id' => $website->id, 'status' => ContentProductRun::STATUS_FAILED,
            'error' => 'no_product_pages_found',
        ]);

        $component = Livewire::test(ContentCalendar::class, ['mode' => 'calendar']);
        $component->assertSee('retryCatalogScan');

        $component->call('useNormalInstead');
        $this->assertSame(ContentPlan::PRODUCT_MODE_NORMAL, $plan->refresh()->product_mode);
    }

    public function test_normal_mode_plan_never_sees_the_progress_screen(): void
    {
        [, $website, $plan] = $this->ecomSetup(ContentPlan::STATUS_ACTIVE);
        $plan->update(['product_mode' => ContentPlan::PRODUCT_MODE_NORMAL]);
        ContentProductRun::factory()->create([
            'website_id' => $website->id, 'status' => ContentProductRun::STATUS_EXTRACTING,
        ]);

        Livewire::test(ContentCalendar::class, ['mode' => 'calendar'])
            ->assertViewHas('catalogProgress', null);
    }

    // ── Settings → Products tab ─────────────────────────────────────────

    public function test_settings_products_tab_appears_for_ecommerce_plans_only(): void
    {
        [, , $plan] = $this->ecomSetup(ContentPlan::STATUS_ACTIVE);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->assertViewHas('productsTab', fn ($pt) => $pt !== null);

        $plan->update(['site_type' => 'local_service']);
        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->assertViewHas('productsTab', null);
    }

    public function test_exclude_toggle_flips_only_own_website_products(): void
    {
        [, $website] = $this->ecomSetup(ContentPlan::STATUS_ACTIVE);
        $own = ContentProduct::factory()->create(['website_id' => $website->id]);
        $foreign = ContentProduct::factory()->create([
            'website_id' => Website::factory()->for(User::factory())->create()->id,
        ]);

        $component = Livewire::test(ContentCalendar::class, ['mode' => 'settings']);
        $component->call('toggleProductExclusion', $own->id);
        $this->assertTrue($own->refresh()->is_excluded);

        $component->call('toggleProductExclusion', $foreign->id);
        $this->assertFalse($foreign->refresh()->is_excluded);
    }

    public function test_product_detail_panel_shows_info_and_blocks_foreign_products(): void
    {
        [, $website] = $this->ecomSetup(ContentPlan::STATUS_ACTIVE);
        $own = ContentProduct::factory()->create([
            'website_id' => $website->id, 'name' => 'Detail Serum',
            'brand' => 'HouseBrand', 'category' => 'Serums',
        ]);
        $foreign = ContentProduct::factory()->create([
            'website_id' => Website::factory()->for(User::factory())->create()->id,
        ]);

        $c = Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->call('viewProduct', $own->id)
            ->assertViewHas('productsTab', fn ($pt) => ($pt['detail']['product']->id ?? null) === $own->id)
            ->assertSee('Detail Serum')
            ->assertSee('HouseBrand');

        // Foreign product never resolves a detail panel.
        $c->call('viewProduct', $foreign->id)
            ->assertViewHas('productsTab', fn ($pt) => ($pt['detail'] ?? null) === null);

        $c->call('viewProduct', $own->id)
            ->call('closeProduct')
            ->assertViewHas('productsTab', fn ($pt) => ($pt['detail'] ?? null) === null);
    }

    public function test_client_scan_again_is_rate_limited_to_once_per_day(): void
    {
        Queue::fake();
        [, $website, $plan] = $this->ecomSetup(ContentPlan::STATUS_ACTIVE);
        $plan->update(['product_mode' => ContentPlan::PRODUCT_MODE_NORMAL]);

        $component = Livewire::test(ContentCalendar::class, ['mode' => 'settings']);
        $component->call('scanProductsAgain');
        $component->call('scanProductsAgain');

        $this->assertSame(1, ContentProductRun::query()->where('website_id', $website->id)->count());
    }

    public function test_settings_mode_switch_to_strict_starts_a_scan(): void
    {
        Queue::fake();
        [, , $plan] = $this->ecomSetup(ContentPlan::STATUS_ACTIVE);
        $plan->update(['product_mode' => ContentPlan::PRODUCT_MODE_NORMAL]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->call('setProductMode', 'strict');

        $this->assertSame(ContentPlan::PRODUCT_MODE_STRICT, $plan->refresh()->product_mode);
        Queue::assertPushed(DiscoverProductPagesJob::class);
    }

    public function test_settings_mode_switch_to_strict_clears_unwritten_topics(): void
    {
        Queue::fake();
        [, , $plan] = $this->ecomSetup(ContentPlan::STATUS_ACTIVE);
        $plan->update(['product_mode' => ContentPlan::PRODUCT_MODE_NORMAL]);
        $unwritten = ContentTopic::factory()->create([
            'plan_id' => $plan->id, 'status' => ContentTopic::STATUS_APPROVED,
        ]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->call('setProductMode', 'strict');

        $this->assertDatabaseMissing('content_topics', ['id' => $unwritten->id]);
    }
}
