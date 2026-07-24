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
        ]);
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

    public function test_identity_only_callback_creates_user_skips_gsc_and_returns_to_redirect(): void
    {
        $social = Mockery::mock(SocialiteUser::class)->makePartial();
        $social->shouldReceive('getEmail')->andReturn('newbie@example.com');
        $social->shouldReceive('getName')->andReturn('New Bie');
        $social->shouldReceive('getNickname')->andReturn(null);
        $social->accessTokenResponseBody = ['scope' => 'openid email profile']; // identity only

        $provider = Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($social);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->withSession([
            'google_sso.intent' => 'register',
            'google_sso.redirect' => '/keyword-volume-checker/some-token',
        ])->get(route('google.sso.callback'))
            ->assertRedirect('/keyword-volume-checker/some-token');

        $this->assertAuthenticated();
        $user = User::where('email', 'newbie@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->email_verified_at, 'Google emails are pre-verified');
        $this->assertSame(0, GoogleAccount::count(), 'identity-only login must not create a GSC connection');
    }

    public function test_existing_user_logs_in_via_google(): void
    {
        $existing = User::factory()->create(['email' => 'known@example.com']);

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
