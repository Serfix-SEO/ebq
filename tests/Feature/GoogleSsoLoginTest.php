<?php

namespace Tests\Feature;

use App\Models\GoogleAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * "Continue with Google" sign-in (2026-07-24). Login/registration requests
 * MINIMUM scopes (identity only) — Analytics/Search Console are NOT requested
 * and are connected later as a separate in-app step.
 */
class GoogleSsoLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-secret',
            // Prod truth: content-only mode — the zero-website funnel targets
            // content.get-started (SEO_PLATFORM_UI is pinned true in
            // phpunit.xml for legacy SEO tests, so mirror prod here).
            'features.seo_platform_ui' => false,
        ]);
    }

    private function fakeGoogleUser(string $email, string $name = 'Test User'): void
    {
        $social = Mockery::mock(SocialiteUser::class)->makePartial();
        $social->shouldReceive('getEmail')->andReturn($email);
        $social->shouldReceive('getName')->andReturn($name);
        $social->shouldReceive('getNickname')->andReturn(null);
        $social->accessTokenResponseBody = ['scope' => 'openid email profile'];

        $provider = Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($social);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_sso_redirect_forces_the_account_picker(): void
    {
        // prompt=select_account sidesteps Google's silent stored-session
        // resume, which 500s inside Google for stale multi-account cookie
        // states (owner reproduced 2026-09-08).
        $res = $this->get(route('google.sso.redirect', ['intent' => 'login']));
        $res->assertRedirect();
        $this->assertStringContainsString('prompt=select_account', (string) $res->headers->get('Location'));
    }

    public function test_sso_redirect_requests_minimum_scopes_only(): void
    {
        $res = $this->get(route('google.sso.redirect', ['intent' => 'register']));

        $res->assertRedirect();
        $url = urldecode((string) $res->headers->get('Location'));

        $this->assertStringContainsString('accounts.google.com', $url);
        $this->assertStringContainsString('openid', $url);
        $this->assertStringContainsString('email', $url);
        $this->assertStringContainsString('profile', $url);
        // The privacy point of this change: login never asks for data scopes.
        $this->assertStringNotContainsString('webmasters', $url);
        $this->assertStringNotContainsString('analytics', $url);
        $this->assertStringNotContainsString('indexing', $url);
    }

    public function test_new_email_sso_funnels_to_onboarding_even_with_a_tool_redirect(): void
    {
        // Funnel fix 2026-09-12: the tool-gate redirect used to jump the
        // zero-website onboarding funnel — a brand-new account landed on a
        // public tool page with no website, stranded. New emails ALWAYS
        // enter onboarding now.
        $this->fakeGoogleUser('newbie@example.com', 'New Bie');

        $this->withSession([
            'google_sso.intent' => 'register',
            'google_sso.redirect' => '/keyword-volume-checker/some-token',
        ])->get(route('google.sso.callback'))
            ->assertRedirect(route('content.get-started'));

        $this->assertAuthenticated();
        $user = User::where('email', 'newbie@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->email_verified_at, 'Google emails are pre-verified');
        $this->assertFalse($user->hasAccessibleWebsites());
        $this->assertSame(0, GoogleAccount::count(), 'identity-only login must not create a GSC connection');
    }

    public function test_existing_user_with_a_website_returns_to_the_tool_redirect(): void
    {
        // Regression pin: the tool-gate round-trip still works for accounts
        // that already have a website.
        $existing = User::factory()->create(['email' => 'tooluser@example.com']);
        \App\Models\Website::factory()->for($existing)->create();
        $this->fakeGoogleUser('tooluser@example.com');

        $this->withSession([
            'google_sso.intent' => 'login',
            'google_sso.redirect' => '/keyword-volume-checker/some-token',
        ])->get(route('google.sso.callback'))
            ->assertRedirect('/keyword-volume-checker/some-token');
    }

    public function test_existing_websiteless_user_funnels_instead_of_the_redirect(): void
    {
        User::factory()->create(['email' => 'stranded@example.com']);
        $this->fakeGoogleUser('stranded@example.com');

        $this->withSession([
            'google_sso.intent' => 'login',
            'google_sso.redirect' => '/free-audit',
        ])->get(route('google.sso.callback'))
            ->assertRedirect(route('content.get-started'));
    }

    public function test_new_email_sso_with_pending_wizard_token_resumes_the_wizard(): void
    {
        $session = \App\Models\ContentOnboardingSession::query()->create([
            'token' => 'tok-pending-123', 'domain' => 'pending.example', 'step' => 3,
        ]);
        $this->fakeGoogleUser('midwizard@example.com');

        $this->withSession([
            'google_sso.intent' => 'register',
            'google_sso.redirect' => '/free-audit',
            'content_onboarding_token' => 'tok-pending-123',
        ])->get(route('google.sso.callback'))
            ->assertRedirect(route('content.onboarding'));
    }

    public function test_existing_user_logs_in_via_google(): void
    {
        $existing = User::factory()->create(['email' => 'known@example.com']);
        \App\Models\Website::factory()->for($existing)->create();

        $social = Mockery::mock(SocialiteUser::class)->makePartial();
        $social->shouldReceive('getEmail')->andReturn('known@example.com');
        $social->shouldReceive('getName')->andReturn('Known');
        $social->shouldReceive('getNickname')->andReturn(null);
        $social->accessTokenResponseBody = ['scope' => 'openid email profile'];

        $provider = Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($social);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->withSession(['google_sso.intent' => 'login', 'google_sso.redirect' => '/free-audit'])
            ->get(route('google.sso.callback'))
            ->assertRedirect('/free-audit');

        $this->assertAuthenticatedAs($existing->fresh());
        $this->assertSame(1, User::where('email', 'known@example.com')->count());
    }
}
