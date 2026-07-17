<?php

namespace Tests\Feature;

use App\Jobs\SyncAnalyticsData;
use App\Jobs\SyncSearchConsoleData;
use App\Livewire\Onboarding\ConnectGoogle;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Models\Website;
use App\Support\GoogleSourcePool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Domain-first onboarding (2026-07-17 redesign): step 1 creates the website
 * from the domain alone; step 2 optionally attaches Google sources to that
 * SAME website (or exits to the dashboard with none).
 */
class OnboardingDataSourcesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Stub GoogleSourcePool so mount()/fetchGoogleData() never hits Google.
     */
    private function fakePool(string $accountId): void
    {
        $pool = Mockery::mock(GoogleSourcePool::class);
        $pool->shouldReceive('forUser')->andReturn([
            'ga' => [['id' => 'properties/123', 'name' => 'My GA', 'account_id' => $accountId, 'account_label' => 'a@b.com']],
            'gsc' => [['siteUrl' => 'sc-domain:example.com', 'account_id' => $accountId, 'account_label' => 'a@b.com']],
            'accounts' => [['id' => $accountId, 'label' => 'a@b.com']],
            'ga_error' => false,
            'gsc_error' => false,
        ]);
        $this->app->instance(GoogleSourcePool::class, $pool);
    }

    public function test_step_one_domain_creates_website_and_advances_to_step_two(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ConnectGoogle::class)
            ->set('domain', 'example.com')
            ->call('addWebsite')
            ->assertHasNoErrors()
            ->assertSet('step', 2);

        $website = Website::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('example.com', $website->domain);
        $this->assertFalse($website->hasGa());
        $this->assertFalse($website->hasGsc());
    }

    public function test_step_one_requires_a_domain(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ConnectGoogle::class)
            ->set('domain', '')
            ->call('addWebsite')
            ->assertHasErrors('domain')
            ->assertSet('step', 1);

        $this->assertSame(0, Website::where('user_id', $user->id)->count());
    }

    public function test_existing_website_lands_on_step_two_at_mount(): void
    {
        $user = User::factory()->create();
        Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);

        Livewire::actingAs($user)
            ->test(ConnectGoogle::class)
            ->assertSet('step', 2)
            ->assertSet('domain', 'example.com');
    }

    public function test_ga_only_save_attaches_analytics_and_dispatches_only_analytics_sync(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);
        $this->fakePool($account->id);
        Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);

        Livewire::actingAs($user)
            ->test(ConnectGoogle::class)
            ->set('gaSelection', $account->id.'|properties/123')
            ->set('gscSelection', '')
            ->call('saveWebsite')
            ->assertHasNoErrors()
            ->assertRedirect(route('website-overview'));

        $website = Website::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('properties/123', $website->ga_property_id);
        $this->assertSame($account->id, $website->ga_google_account_id);
        $this->assertSame('', $website->gsc_site_url);
        $this->assertNull($website->gsc_google_account_id);
        $this->assertTrue($website->hasGa());
        $this->assertFalse($website->hasGsc());

        Queue::assertPushed(SyncAnalyticsData::class);
        Queue::assertNotPushed(SyncSearchConsoleData::class);
    }

    public function test_gsc_only_save_dispatches_only_search_console_sync(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);
        $this->fakePool($account->id);
        Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);

        Livewire::actingAs($user)
            ->test(ConnectGoogle::class)
            ->set('gaSelection', '')
            ->set('gscSelection', $account->id.'|sc-domain:example.com')
            ->call('saveWebsite')
            ->assertHasNoErrors()
            ->assertRedirect(route('website-overview'));

        $website = Website::where('user_id', $user->id)->firstOrFail();
        $this->assertTrue($website->hasGsc());
        $this->assertFalse($website->hasGa());
        $this->assertSame($account->id, $website->gsc_google_account_id);

        Queue::assertPushed(SyncSearchConsoleData::class);
        Queue::assertNotPushed(SyncAnalyticsData::class);
    }

    public function test_save_with_no_source_selected_just_finishes_no_error_wall(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);
        $this->fakePool($account->id);
        Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);

        Livewire::actingAs($user)
            ->test(ConnectGoogle::class)
            ->set('gaSelection', '')
            ->set('gscSelection', '')
            ->call('saveWebsite')
            ->assertHasNoErrors()
            ->assertRedirect(route('website-overview'));

        Queue::assertNotPushed(SyncAnalyticsData::class);
        Queue::assertNotPushed(SyncSearchConsoleData::class);
    }

    public function test_skip_from_step_two_goes_to_dashboard_with_sourceless_website(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);

        Livewire::actingAs($user)
            ->test(ConnectGoogle::class)
            ->call('skipForNow')
            ->assertRedirect(route('website-overview'));

        $website = Website::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('example.com', $website->domain);
        $this->assertFalse($website->hasGa());
        $this->assertFalse($website->hasGsc());

        Queue::assertNotPushed(SyncAnalyticsData::class);
        Queue::assertNotPushed(SyncSearchConsoleData::class);
    }

    public function test_change_domain_goes_back_and_resubmit_updates_same_website(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $c = Livewire::actingAs($user)
            ->test(ConnectGoogle::class)
            ->set('domain', 'first-try.com')
            ->call('addWebsite')
            ->assertSet('step', 2)
            ->call('changeDomain')
            ->assertSet('step', 1)
            ->assertSet('domain', 'first-try.com') // pre-filled for editing
            ->set('domain', 'corrected.com')
            ->call('addWebsite')
            ->assertSet('step', 2);

        // Same row updated — no second website created.
        $this->assertSame(1, Website::where('user_id', $user->id)->count());
        $this->assertSame('corrected.com', Website::where('user_id', $user->id)->first()->domain);
    }

    public function test_skip_without_a_website_returns_to_step_one(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ConnectGoogle::class)
            ->call('skipForNow')
            ->assertSet('step', 1)
            ->assertNoRedirect();

        $this->assertSame(0, Website::where('user_id', $user->id)->count());
    }
}
