<?php

namespace Tests\Feature\Content;

use App\Livewire\ConnectSourcesModal;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Models\Website;
use App\Services\Google\GoogleOAuthService;
use App\Support\GoogleSourcePool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Facades\Socialite;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Linking Google Analytics from a content-only account, end to end.
 *
 * safibusinessservice.com (support ticket, 2026-09-17) said "analytics is
 * connected, why is there no info". Every step of the path had misled them:
 *  - the "Connect Analytics" cards sent them through Google sign-in to the
 *    get-started sales page, with no property picker;
 *  - saving the picker with Analytics left unselected still said "Connected!";
 *  - their Google login could only see another company's property, and
 *    nothing said so;
 *  - "connect a different Google login" returned them to a Settings page
 *    content-only clients cannot use.
 */
class AnalyticsConnectPathTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.seo_platform_ui' => false]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @param list<array{id: string, name: string}> $properties */
    private function pool(string $accountId, array $properties): void
    {
        $pool = Mockery::mock(GoogleSourcePool::class);
        $pool->shouldReceive('forUser')->andReturn([
            'ga' => array_map(fn ($p) => $p + ['account_id' => $accountId, 'account_label' => 'opseondotcom@gmail.com'], $properties),
            'gsc' => [['siteUrl' => 'sc-domain:safibusinessservice.com', 'account_id' => $accountId, 'account_label' => 'opseondotcom@gmail.com']],
            'accounts' => [['id' => $accountId, 'label' => 'opseondotcom@gmail.com']],
            'ga_error' => false,
            'gsc_error' => false,
        ]);
        $this->app->instance(GoogleSourcePool::class, $pool);
    }

    /** @return array{0: User, 1: Website, 2: GoogleAccount} */
    private function client(): array
    {
        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);
        $website = Website::factory()->withNoSources()->create([
            'user_id' => $user->id,
            'domain' => 'safibusinessservice.com',
            'normalized_domain' => 'safibusinessservice.com',
        ]);
        session(['current_website_id' => $website->id]);

        return [$user, $website, $account];
    }

    // ── the cards ──────────────────────────────────────────────────────

    public function test_the_connect_cards_open_the_picker_instead_of_a_google_sign_in_dead_end(): void
    {
        [$user, $website] = $this->client();
        $this->actingAs($user);

        foreach (['x-content.connect-ga', 'x-content.connect-gsc'] as $card) {
            $html = (string) $this->blade("<{$card} :website=\"\$site\" />", ['site' => $website]);

            $this->assertStringContainsString('open-connect-sources', $html, "{$card} must open the picker");
            $this->assertStringContainsString($website->id, $html, "{$card} must target this website");
            $this->assertStringNotContainsString(route('google.redirect'), $html, "{$card} must not dead-end at Google sign-in");
        }
    }

    /** The picker refuses to save for shared members, so don't offer them the button. */
    public function test_shared_members_do_not_get_a_connect_button_they_cannot_use(): void
    {
        [, $website] = $this->client();
        $this->actingAs(User::factory()->create());

        $this->assertSame('', trim((string) $this->blade('<x-content.connect-ga :website="$site" />', ['site' => $website])));
    }

    // ── the picker ─────────────────────────────────────────────────────

    public function test_a_login_that_only_sees_another_sites_property_is_told_so(): void
    {
        [$user, , $account] = $this->client();
        $this->pool($account->id, [['id' => 'properties/290005220', 'name' => 'www.opseon.com - GA4']]);

        Livewire::actingAs($user)->test(ConnectSourcesModal::class)
            ->call('open')
            ->assertSee('None of these Analytics properties looks like safibusinessservice.com')
            ->assertSee('add this login as a Viewer');
    }

    public function test_a_matching_property_gets_no_warning(): void
    {
        [$user, , $account] = $this->client();
        $this->pool($account->id, [['id' => 'properties/1', 'name' => 'Safi Business Services - GA4']]);

        Livewire::actingAs($user)->test(ConnectSourcesModal::class)
            ->call('open')
            ->assertSet('gaHint', '');
    }

    public function test_a_login_with_no_analytics_properties_is_told_so(): void
    {
        [$user, , $account] = $this->client();
        $this->pool($account->id, []);

        Livewire::actingAs($user)->test(ConnectSourcesModal::class)
            ->call('open')
            ->assertSee('This Google login has no Google Analytics properties');
    }

    public function test_saving_with_nothing_selected_says_so_instead_of_connected(): void
    {
        [$user, , $account] = $this->client();
        $this->pool($account->id, []);

        Livewire::actingAs($user)->test(ConnectSourcesModal::class)
            ->call('open')
            ->call('saveSources')
            ->assertSet('saved', '')
            ->assertSee('Nothing is selected yet');
    }

    public function test_saving_both_sources_says_both_are_connected(): void
    {
        Queue::fake();
        [$user, , $account] = $this->client();
        $this->pool($account->id, [['id' => 'properties/1', 'name' => 'Safi Business Services - GA4']]);

        Livewire::actingAs($user)->test(ConnectSourcesModal::class)
            ->call('open')
            ->set('gaSelection', $account->id.'|properties/1')
            ->set('gscSelection', $account->id.'|sc-domain:safibusinessservice.com')
            ->call('saveSources')
            ->assertSet('saved', 'Google Analytics and Search Console are connected. We’re pulling your data now — this page will refresh.');
    }

    // ── connecting a different Google login ────────────────────────────

    public function test_connect_another_login_returns_content_clients_to_the_picker_not_settings(): void
    {
        [$user, , $account] = $this->client();
        $this->pool($account->id, []);

        Livewire::actingAs($user)->test(ConnectSourcesModal::class)
            ->call('open')
            ->assertSet('googleReturn', 'content.sources')
            ->assertSee(route('google.redirect', ['return' => 'content.sources']), false);

        config(['features.seo_platform_ui' => true]);
        Livewire::actingAs($user)->test(ConnectSourcesModal::class)
            ->call('open')
            ->assertSet('googleReturn', 'settings.integrations');
    }

    public function test_the_oauth_round_trip_lands_back_on_content_with_the_picker_reopening(): void
    {
        [$user] = $this->client();
        $this->actingAs($user);

        $this->get(route('google.redirect', ['return' => 'content.sources']));
        $this->assertSame('content.sources', session('google_oauth.return'));

        $provider = Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn(Mockery::mock(\Laravel\Socialite\Two\User::class));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
        $oauth = Mockery::mock(GoogleOAuthService::class);
        $oauth->shouldReceive('persistAccount')->once();
        $this->app->instance(GoogleOAuthService::class, $oauth);

        $this->get(route('google.callback'))
            ->assertRedirect(route('content.index'))
            ->assertSessionHas('open_connect_sources', true);
    }

    public function test_an_unknown_return_target_still_cannot_become_an_open_redirect(): void
    {
        [$user] = $this->client();
        $this->actingAs($user);

        $this->get(route('google.redirect', ['return' => 'https://evil.example']));

        $this->assertSame('onboarding', session('google_oauth.return'));
    }

    // ── the banner ─────────────────────────────────────────────────────

    public function test_the_banner_no_longer_promises_a_report_content_clients_do_not_have(): void
    {
        [$user, $website] = $this->client();
        $website->forceFill(['gsc_site_url' => 'sc-domain:safibusinessservice.com', 'gsc_google_account_id' => GoogleAccount::query()->value('id')])->save();
        $this->actingAs($user);

        $html = (string) $this->blade('@include("partials.connect-source-banner")');

        $this->assertStringContainsString('Connect Google Analytics to see visitors per article', $html);
        $this->assertStringNotContainsString('unlock the full report', $html);
    }
}
