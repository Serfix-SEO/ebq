<?php

namespace Tests\Feature;

use App\Models\ContentOnboardingSession;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Funnel fix 2026-09-12: no login/registration path may strand an account
 * with zero websites outside onboarding. A tool-gate `redirect` target is
 * honored only for accounts that already have a website; everyone else
 * funnels to the content onboarding entry (content.get-started in content-
 * only mode) or resumes a pending wizard run.
 */
class SignupFunnelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.seo_platform_ui' => false]); // prod truth
    }

    private function registerPayload(array $extra = []): array
    {
        return [
            'name' => 'Fresh Signup',
            'email' => 'fresh@example.net',
            'password' => 'Str0ng-Pass-123',
            'password_confirmation' => 'Str0ng-Pass-123',
        ] + $extra;
    }

    public function test_tool_gate_password_signup_funnels_to_onboarding(): void
    {
        $this->post(route('register'), $this->registerPayload([
            'redirect' => '/keyword-volume-checker/some-token',
        ]))->assertRedirect(route('content.get-started'));

        $user = User::query()->where('email', 'fresh@example.net')->first();
        $this->assertNotNull($user);
        $this->assertFalse($user->hasAccessibleWebsites());
    }

    public function test_analyze_funnel_signup_still_attaches_and_shows_the_report(): void
    {
        $this->withSession(['analyze_domain' => 'fresh-site.example'])
            ->post(route('register'), $this->registerPayload());

        $user = User::query()->where('email', 'fresh@example.net')->first();
        $this->assertTrue($user->hasAccessibleWebsites(), 'analyze funnel attaches the domain');
    }

    public function test_websiteless_login_with_tool_redirect_funnels(): void
    {
        $user = User::factory()->create(['password' => 'Str0ng-Pass-123']);

        $this->post(route('login'), [
            'email' => $user->email, 'password' => 'Str0ng-Pass-123',
            'redirect' => '/free-audit',
        ])->assertRedirect(route('content.get-started'));
    }

    public function test_login_with_a_website_still_honors_the_tool_redirect(): void
    {
        $user = User::factory()->create(['password' => 'Str0ng-Pass-123']);
        Website::factory()->for($user)->create();

        $this->post(route('login'), [
            'email' => $user->email, 'password' => 'Str0ng-Pass-123',
            'redirect' => '/free-audit',
        ])->assertRedirect('/free-audit');
    }

    public function test_authed_wizard_google_button_resumes_a_pending_run(): void
    {
        // contentOnboardingRedirect used to bounce authed users to
        // get-started, silently dropping their provisional website.
        ContentOnboardingSession::query()->create([
            'token' => 'tok-resume-1', 'domain' => 'pending.example', 'step' => 4,
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['content_onboarding_token' => 'tok-resume-1'])
            ->get(route('content.onboarding.google'))
            ->assertRedirect(route('content.onboarding'));
    }

    public function test_authed_wizard_google_button_without_a_run_goes_to_get_started(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('content.onboarding.google'))
            ->assertRedirect(route('content.get-started'));
    }
}
