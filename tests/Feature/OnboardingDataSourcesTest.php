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

    public function test_ga_only_finish_creates_website_and_dispatches_only_analytics_sync(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);
        $this->fakePool($account->id);

        Livewire::actingAs($user)
            ->test(ConnectGoogle::class)
            ->set('gaSelection', $account->id.'|properties/123')
            ->set('gscSelection', '')
            ->set('domain', 'example.com')
            ->call('saveWebsite')
            ->assertHasNoErrors();

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

    public function test_gsc_only_finish_dispatches_only_search_console_sync(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);
        $this->fakePool($account->id);

        Livewire::actingAs($user)
            ->test(ConnectGoogle::class)
            ->set('gaSelection', '')
            ->set('gscSelection', $account->id.'|sc-domain:example.com')
            ->set('domain', 'example.com')
            ->call('saveWebsite')
            ->assertHasNoErrors();

        $website = Website::where('user_id', $user->id)->firstOrFail();
        $this->assertTrue($website->hasGsc());
        $this->assertFalse($website->hasGa());
        $this->assertSame($account->id, $website->gsc_google_account_id);

        Queue::assertPushed(SyncSearchConsoleData::class);
        Queue::assertNotPushed(SyncAnalyticsData::class);
    }

    public function test_finishing_with_no_source_selected_is_a_validation_error(): void
    {
        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);
        $this->fakePool($account->id);

        Livewire::actingAs($user)
            ->test(ConnectGoogle::class)
            ->set('gaSelection', '')
            ->set('gscSelection', '')
            ->set('domain', 'example.com')
            ->call('saveWebsite')
            ->assertHasErrors('gaSelection');

        $this->assertSame(0, Website::where('user_id', $user->id)->count());
    }

    public function test_skip_for_now_creates_sourceless_website_and_redirects_to_overview(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);
        $this->fakePool($account->id);

        Livewire::actingAs($user)
            ->test(ConnectGoogle::class)
            ->set('domain', 'example.com')
            ->call('skipForNow')
            ->assertRedirect(route('website-overview'));

        $website = Website::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('example.com', $website->domain);
        $this->assertFalse($website->hasGa());
        $this->assertFalse($website->hasGsc());

        Queue::assertNotPushed(SyncAnalyticsData::class);
        Queue::assertNotPushed(SyncSearchConsoleData::class);
    }

    public function test_skip_for_now_requires_a_domain(): void
    {
        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);
        $this->fakePool($account->id);

        Livewire::actingAs($user)
            ->test(ConnectGoogle::class)
            ->set('domain', '')
            ->call('skipForNow')
            ->assertHasErrors('domain')
            ->assertNoRedirect();

        $this->assertSame(0, Website::where('user_id', $user->id)->count());
    }
}
