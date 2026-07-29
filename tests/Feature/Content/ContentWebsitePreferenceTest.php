<?php

namespace Tests\Feature\Content;

use App\Livewire\WebsiteSelector;
use App\Models\ContentPlan;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentEntitlements;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression (prod 2026-07-21): the content wizard "kept refreshing on step 1".
 *
 * With no `current_website_id` in the session, EnsureContentAccess resolved the
 * user's FIRST accessible website. For an account whose oldest site was
 * uncovered, every request — including Livewire's POST /livewire/update — was
 * redirected to Get started, which redirects back to the wizard, which re-fires
 * `wire:init="analyzeSite"`, which redirects again. The access log showed the
 * signature plainly: GET 200, GET 200, POST /livewire/update 302, repeating
 * every ~3 seconds.
 *
 * Both sides now resolve the same way, preferring a COVERED site.
 */
class ContentWebsitePreferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    /** @return array{0: User, 1: Website, 2: Website} */
    private function userWithUncoveredThenCoveredSite(): array
    {
        $user = User::factory()->create([
            'content_trial_started_at' => now(),
            'content_trial_ends_at' => now()->addDays(5),
        ]);

        // Oldest site: no plan at all → uncovered, no articles (the trap).
        $stale = Website::factory()->for($user)->create(['domain' => 'aaa-uncovered.test']);

        // Newer site: covered, and the one the user actually works on.
        $active = Website::factory()->for($user)->create(['domain' => 'zzz-covered.test']);
        ContentPlan::factory()->create([
            'website_id' => $active->id,
            'billing_covered_at' => now(),
            'business_description' => 'A real, configured site.',
        ]);

        return [$user, $stale, $active];
    }

    public function test_a_covered_site_is_preferred_when_the_session_pins_none(): void
    {
        [$user, $stale, $active] = $this->userWithUncoveredThenCoveredSite();

        $preferred = app(ContentEntitlements::class)->preferredWebsite($user);

        $this->assertSame($active->id, $preferred->id, 'must not fall back to the uncovered older site');
        $this->assertNotSame($stale->id, $preferred->id);
    }

    /** The loop itself: settings must load, not bounce to Get started. */
    public function test_content_settings_loads_without_a_pinned_website(): void
    {
        [$user] = $this->userWithUncoveredThenCoveredSite();

        $this->actingAs($user)
            ->get('/content/settings')
            ->assertOk();
    }

    public function test_the_calendar_also_loads_without_a_pinned_website(): void
    {
        [$user] = $this->userWithUncoveredThenCoveredSite();

        $this->actingAs($user)->get('/content')->assertOk();
    }

    /** A genuinely uncovered account must still be sent to Get started. */
    public function test_an_account_with_no_covered_site_is_still_gated(): void
    {
        $user = User::factory()->create();
        Website::factory()->for($user)->create(['domain' => 'nothing-covered.test']);

        $this->actingAs($user)
            ->get('/content/settings')
            ->assertRedirect(route('content.get-started'));
    }

    /**
     * A pin on a site that was NEVER a content site (no plan row at all) is an
     * alphabetical accident from EnsureFeatureAccess, not a choice — correct it
     * instead of gating the user out of a product they pay for.
     */
    public function test_a_pin_on_a_never_content_site_is_corrected(): void
    {
        [$user, $stale, $active] = $this->userWithUncoveredThenCoveredSite();

        $this->actingAs($user)
            ->withSession(['current_website_id' => $stale->id])
            ->get('/content/settings')
            ->assertOk();

        $this->assertSame($active->id, session('current_website_id'), 're-pinned to the covered site');
    }

    /**
     * A site whose plan LAPSED is a deliberate selection — the user may want to
     * resubscribe for exactly that site, so it must still reach Get started
     * rather than being silently swapped for another.
     */
    public function test_a_pin_on_a_lapsed_content_site_is_respected(): void
    {
        [$user, $stale] = $this->userWithUncoveredThenCoveredSite();
        ContentPlan::factory()->create([
            'website_id' => $stale->id,
            'billing_covered_at' => null,   // had content once, no longer covered
        ]);

        $this->actingAs($user)
            ->withSession(['current_website_id' => $stale->id])
            ->get('/content/settings')
            ->assertRedirect(route('content.get-started'));
    }

    /**
     * …but a pin the USER made from the website dropdown is not an accident.
     * Silently swapping it back made the selector revert on every refresh —
     * pick pubgnamegenerator.net, reload, and you were on the other site again
     * (prod 2026-07-29). A deliberate choice is honoured: they land on Get
     * started for THAT site, where they can activate it.
     */
    public function test_a_deliberate_pin_from_the_website_dropdown_is_not_swapped(): void
    {
        [$user, $chosen] = $this->userWithUncoveredThenCoveredSite();

        $this->actingAs($user)
            ->withSession([
                'current_website_id' => $chosen->id,
                WebsiteSelector::EXPLICIT_PIN_KEY => true,
            ])
            ->get('/content/settings')
            ->assertRedirect(route('content.get-started'));

        $this->assertSame($chosen->id, session('current_website_id'), 'the user\'s choice survives');
    }

    /** Choosing a site in the dropdown marks the pin as deliberate. */
    public function test_the_selector_marks_the_pin_as_user_made(): void
    {
        [$user, $stale, $active] = $this->userWithUncoveredThenCoveredSite();

        Livewire::actingAs($user)
            ->test(WebsiteSelector::class)
            ->set('websiteId', $stale->id);

        $this->assertSame($stale->id, session('current_website_id'));
        $this->assertTrue(session(WebsiteSelector::EXPLICIT_PIN_KEY));
    }

    /** An alphabetical auto-pin clears the flag, so correction still works later. */
    public function test_the_automatic_alphabetical_pin_clears_the_user_made_flag(): void
    {
        [$user, $stale, $active] = $this->userWithUncoveredThenCoveredSite();

        $this->actingAs($user)
            ->withSession([
                'current_website_id' => 'deleted-site-id',   // stale pin, no longer accessible
                WebsiteSelector::EXPLICIT_PIN_KEY => true,
            ])
            ->get('/content/settings')
            ->assertOk();

        $this->assertFalse((bool) session(WebsiteSelector::EXPLICIT_PIN_KEY));
        $this->assertSame($active->id, session('current_website_id'));
    }
}
