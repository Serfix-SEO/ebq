<?php

namespace Tests\Feature\Content;

use App\Models\ContentPlan;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentEntitlements;
use App\Services\Content\Social\SocialPoster;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Auto-share got its own destination under Content on 2026-07-31. It used to be
 * the last card on the Integrations page, which is about where articles PUBLISH
 * to — so "what happens once they're live" sat below the WordPress/webhook setup
 * and nobody found it.
 *
 * The nav item and the page share ONE gate, so a client can never click a menu
 * entry that leads to an empty screen.
 */
class ContentSocialPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function contentUser(): array
    {
        // Comp slots, not a trial: the sidebar's full Content group renders on
        // hasContentAccessFor(), and a comped account is the simplest way to
        // make that true without a Stripe subscription.
        $user = User::factory()->create([
            'content_comp_sites' => 2,
            'content_comp_until' => null,
        ]);
        $website = Website::factory()->for($user)->create();
        ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
            'billing_covered_at' => now(),
        ]);
        app(ContentEntitlements::class)->coverWebsite($website);

        return [$user, $website];
    }

    public function test_auto_share_has_its_own_page_and_is_gone_from_integrations(): void
    {
        config(['services.x.client_id' => 'x-app', 'services.x.client_secret' => 'x-secret']);
        [$user, $website] = $this->contentUser();
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        $this->get(route('content.social'))
            ->assertOk()
            ->assertSee('Auto-share')
            ->assertSee(route('social.x.redirect'));

        // Integrations keeps publishing only — no connect button hiding at the
        // bottom of it any more.
        $this->get(route('content.integrations'))
            ->assertOk()
            ->assertDontSee(route('social.x.redirect'));
    }

    /**
     * The sidebar entry and the page are wired to ONE gate, so they cannot
     * drift into a menu item that opens an empty screen. The gate itself is
     * asserted here; the nav markup is checked structurally below, because the
     * sidebar's Content group depends on route registration that the test
     * environment does not set up.
     */
    public function test_the_gate_needs_both_a_provider_and_the_kill_switch(): void
    {
        config(['services.facebook.client_id' => null, 'services.x.client_id' => null]);
        $this->assertFalse(SocialPoster::anyProviderConfigured(), 'no provider configured');

        config(['services.x.client_id' => 'x-app']);
        $this->assertTrue(SocialPoster::anyProviderConfigured(), 'one provider is enough');

        config(['services.x.client_id' => null, 'services.facebook.client_id' => 'fb-app']);
        $this->assertTrue(SocialPoster::anyProviderConfigured(), 'either provider counts');

        config(['services.content_autopilot.social_sharing' => false]);
        $this->assertFalse(SocialPoster::anyProviderConfigured(), 'the kill switch wins');
    }

    /** The sidebar links the page, and only behind that gate. */
    public function test_the_sidebar_wires_the_page_behind_the_same_gate(): void
    {
        $layout = file_get_contents(resource_path('views/components/layouts/app.blade.php'));

        $this->assertStringContainsString("'route' => 'content.social'", $layout);
        $this->assertMatchesRegularExpression(
            "/SocialPoster::anyProviderConfigured\\(\\)\\s*\\?\\s*\\[\\s*\\[\\s*'route'\\s*=>\\s*'content\\.social'/s",
            $layout,
            'the Auto-share nav item must sit behind anyProviderConfigured()',
        );
    }

    /** Reached directly with nothing configured, the page explains itself. */
    public function test_the_page_reached_directly_without_a_provider_is_not_blank(): void
    {
        config(['services.facebook.client_id' => null, 'services.x.client_id' => null]);
        [$user, $website] = $this->contentUser();
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        $this->get(route('content.social'))
            ->assertOk()
            ->assertSee('Coming soon')
            ->assertDontSee(route('social.x.redirect'));
    }
}
