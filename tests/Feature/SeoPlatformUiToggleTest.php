<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Website;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The SEO-platform UI kill-switch (config `features.seo_platform_ui`).
 *
 * Off = the app is Content-AI-focused: `/` serves the Content Autopilot
 * landing, SEO marketing pages 302 away, authed SEO surfaces redirect to
 * /content (admins exempt), the sidebar and /billing go single-product.
 * Backend untouched. The switch is RUNTIME (config, not route registration),
 * which is what lets this one file prove both states.
 */
class SeoPlatformUiToggleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function off(): void
    {
        config(['features.seo_platform_ui' => false]);
    }

    // ── Public pages ────────────────────────────────────────────────────

    public function test_the_root_url_serves_the_content_landing_when_off(): void
    {
        $this->off();

        $this->get('/')
            ->assertOk()
            ->assertSee('Content AI Autopilot', false)
            ->assertDontSee('The SEO command center');
    }

    /**
     * Both `/` and `/content-autopilot` serve the same page when off — they
     * must canonicalize to ONE url (the root) or Google indexes a duplicate.
     */
    public function test_both_landing_urls_canonicalize_to_the_root(): void
    {
        $this->off();

        $canonical = '<link rel="canonical" href="'.url('/').'"';
        $this->get('/')->assertOk()->assertSee($canonical, false);
        $this->get('/content-autopilot')->assertOk()->assertSee($canonical, false);
    }

    public function test_seo_marketing_pages_redirect_when_off(): void
    {
        $this->off();

        $this->get('/pricing')->assertRedirect(route('content.pricing'));
        foreach (['/features', '/wordpress-plugin', '/trust-score', '/website-revamp', '/guide'] as $url) {
            $this->get($url)->assertRedirect(route('landing'));
        }
    }

    public function test_the_free_tool_pages_redirect_when_off(): void
    {
        $this->off();

        foreach (['/free-audit', '/pagespeed-test', '/rank-tracker', '/keyword-volume-checker'] as $url) {
            $this->get($url)->assertRedirect(route('landing'));
        }
    }

    /** Client report links are deliverables opened from emails — never break them. */
    public function test_report_deliverables_keep_working_when_off(): void
    {
        $this->off();

        // Not swallowed by the kill-switch: both routes answer for THEMSELVES
        // (a bogus token 404s; a report with a url renders the guest teaser).
        $this->get('/r/definitely-not-a-real-token')->assertNotFound();
        $this->get('/report/view?url=example.com')->assertOk();
    }

    // ── Authed surfaces ─────────────────────────────────────────────────

    public function test_seo_surfaces_redirect_signed_in_users_to_content_when_off(): void
    {
        $this->off();
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        foreach (['dashboard', 'backlinks.index', 'keywords.index', 'onboarding'] as $routeName) {
            $this->get(route($routeName))->assertRedirect(route('content.index'));
        }
    }

    /** Admins debug client data — they keep the SEO surfaces. */
    public function test_admins_keep_seo_access_when_off(): void
    {
        $this->off();
        $admin = User::factory()->create(['is_admin' => true]);
        $website = Website::factory()->for($admin)->create();

        $this->actingAs($admin)->withSession(['current_website_id' => $website->id])
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_the_sidebar_hides_the_seo_groups_when_off(): void
    {
        $this->off();
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();

        // The content calendar page renders the app layout with its sidebar.
        $html = $this->actingAs($user)->withSession(['current_website_id' => $website->id])
            ->get(route('content.get-started'))->assertOk()->getContent();

        $this->assertStringNotContainsString(route('dashboard'), $html, 'no Site Health link');
        $this->assertStringNotContainsString(route('backlinks.index'), $html, 'no Backlinks link');
        $this->assertStringNotContainsString(route('rank-tracking.index'), $html, 'no Ranking link');
        $this->assertStringContainsString(route('billing.show'), $html, 'Billing stays');
        $this->assertStringContainsString(route('websites.index'), $html, 'Websites stays');
    }

    public function test_billing_is_single_product_when_off(): void
    {
        $this->off();
        $user = User::factory()->create();
        Website::factory()->for($user)->create();

        $html = $this->actingAs($user)->get(route('billing.show'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Available plans', $html, 'no SEO plan grid');
        $this->assertStringNotContainsString(__('SEO platform'), $html, 'no SEO tab or panel');
        // The content plans are the page's pricing now.
        $this->assertStringContainsString('Extra websites', $html);
    }

    public function test_registration_lands_on_content_get_started_when_off(): void
    {
        $this->off();

        $response = $this->post(route('register'), [
            'name' => 'Content User',
            'email' => 'content-user@example.com',
            'phone' => '555 000 1111',
            'password' => 'super-secret-1',
            'password_confirmation' => 'super-secret-1',
        ]);

        $response->assertRedirect(route('content.get-started'));
    }

    /**
     * Impersonation exists to see what the CLIENT sees. Exempting it from the
     * kill-switch showed the owner an SEO teaser selling a hidden product.
     */
    public function test_an_impersonating_admin_sees_the_client_view_not_the_seo_teaser(): void
    {
        $this->off();
        $client = User::factory()->create();
        $website = Website::factory()->for($client)->create();

        $this->actingAs($client)->withSession([
            'current_website_id' => $website->id,
            'impersonator_id' => User::factory()->create(['is_admin' => true])->id,
        ]);

        $this->get(route('dashboard'))->assertRedirect(route('content.index'));
    }

    /** The winback strip promotes SEO plans — it goes with the product. */
    public function test_the_winback_banner_is_hidden_when_off(): void
    {
        $this->off();
        config(['services.stripe.winback_promo_code' => 'SAVE30', 'services.stripe.winback_promo_percent' => 30]);

        $user = User::factory()->create(['created_at' => now()->subDays(60)]);
        $website = Website::factory()->for($user)->create();

        $html = $this->actingAs($user)->withSession(['current_website_id' => $website->id])
            ->get(route('content.get-started'))->assertOk()->getContent();

        $this->assertStringNotContainsString('OFF any plan', $html);
    }

    // ── Reversibility ───────────────────────────────────────────────────

    /** Flag on = the SEO product is fully back. Proven in-suite. */
    public function test_everything_is_restored_when_the_flag_is_on(): void
    {
        config(['features.seo_platform_ui' => true]);

        $this->get('/')->assertOk()->assertSee('The SEO command center');
        $this->get('/pricing')->assertOk();
        $this->get('/free-audit')->assertOk();

        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();
        $this->actingAs($user)->withSession(['current_website_id' => $website->id])
            ->get(route('dashboard'))
            ->assertOk();
    }
}
