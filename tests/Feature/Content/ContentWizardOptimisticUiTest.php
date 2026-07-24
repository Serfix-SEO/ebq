<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\ContentCalendar;
use App\Models\ContentPlan;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Optimistic wizard UI (2026-07-23): selection/toggle controls bind their
 * visual state to $wire.<prop> (Livewire's reactive client state) and pass
 * EXPLICIT target values to the server methods so the assignment + method
 * call can never double-flip. The smart-bricks regression this guards
 * against: a local Alpine x-data snapshot went stale when analyzeSite set
 * siteType server-side after first render — nothing looked selected, and a
 * later morph snapped the highlight to the stale auto value over the
 * user's click.
 */
class ContentWizardOptimisticUiTest extends TestCase
{
    use RefreshDatabase;

    private function actingWizard(): \Livewire\Features\SupportTesting\Testable
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create([
            'normalized_domain' => 'example.com', 'domain' => 'example.com',
        ]);
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        return Livewire::test(ContentCalendar::class, ['mode' => 'settings']);
    }

    public function test_step_one_chips_bind_to_wire_site_type_not_a_local_snapshot(): void
    {
        $html = $this->actingWizard()->html();

        // Reactive binding + optimistic assignment must both be present…
        $this->assertStringContainsString('$wire.siteType', $html);
        $this->assertStringContainsString('$wire.selectSiteType(', $html);
        // …and the stale-prone local mirror must not come back.
        $this->assertStringNotContainsString('{ st:', $html);
    }

    public function test_toggle_structure_accepts_an_explicit_value_and_is_idempotent(): void
    {
        $c = $this->actingWizard();

        $c->call('toggleStructure', 'faq', false);
        $this->assertFalse($c->get('structureToggles')['faq']);

        // Same explicit value again (e.g. optimistic UI retry) must not flip back.
        $c->call('toggleStructure', 'faq', false);
        $this->assertFalse($c->get('structureToggles')['faq']);

        // Legacy no-value call still flips.
        $c->call('toggleStructure', 'faq');
        $this->assertTrue($c->get('structureToggles')['faq']);
    }

    public function test_toggle_images_accepts_an_explicit_value_and_is_idempotent(): void
    {
        $c = $this->actingWizard();

        $c->call('toggleImages', false);
        $this->assertFalse($c->get('imagesEnabled'));
        $c->call('toggleImages', false);
        $this->assertFalse($c->get('imagesEnabled'));
        $c->call('toggleImages');
        $this->assertTrue($c->get('imagesEnabled'));
    }

    public function test_toggle_term_accepts_an_explicit_state_and_never_duplicates(): void
    {
        $c = $this->actingWizard();

        $c->call('toggleTerm', 'Best Widgets UAE', true);
        $this->assertSame(['best widgets uae'], $c->get('removedTerms'));

        // Same explicit state again — no duplicate entry.
        $c->call('toggleTerm', 'best widgets uae', true);
        $this->assertSame(['best widgets uae'], $c->get('removedTerms'));

        $c->call('toggleTerm', 'best widgets uae', false);
        $this->assertSame([], $c->get('removedTerms'));

        // Removing an absent term stays a no-op.
        $c->call('toggleTerm', 'never added', false);
        $this->assertSame([], $c->get('removedTerms'));

        // Legacy no-value call still flips.
        $c->call('toggleTerm', 'flip me');
        $this->assertSame(['flip me'], $c->get('removedTerms'));
    }

    public function test_select_site_type_records_the_human_decision(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create([
            'normalized_domain' => 'example.com', 'domain' => 'example.com',
        ]);
        ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_DRAFT,
            'site_type' => 'saas',
            'site_type_source' => 'auto',
        ]);
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->call('selectSiteType', 'b2b_services');

        $plan = ContentPlan::query()->where('website_id', $website->id)->first();
        $this->assertSame('b2b_services', $plan->site_type);
        $this->assertSame('user', $plan->site_type_source);
    }
}
