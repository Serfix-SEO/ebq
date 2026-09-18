<?php

namespace Tests\Feature;

use App\Jobs\SyncAnalyticsData;
use App\Jobs\SyncSearchConsoleData;
use App\Livewire\ConnectSourcesModal;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Models\Website;
use App\Support\GoogleSourcePool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class ConnectSourcesModalTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

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

    public function test_open_lazily_loads_the_pool_for_the_current_website(): void
    {
        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);
        $website = Website::factory()->withNoSources()->create(['user_id' => $user->id]);
        $this->fakePool($account->id);

        session(['current_website_id' => $website->id]);

        Livewire::actingAs($user)
            ->test(ConnectSourcesModal::class)
            // Pool is not fetched until opened.
            ->assertSet('loaded', false)
            ->call('open')
            ->assertSet('loaded', true)
            ->assertSet('hasGoogle', true)
            ->assertCount('gaOptions', 1)
            ->assertCount('gscOptions', 1);
    }

    public function test_open_targets_an_explicit_website_over_the_session_one(): void
    {
        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);
        // Session points at a fully-connected site…
        $sessionSite = Website::factory()->withBothSources()->create(['user_id' => $user->id]);
        // …but the audit-detail page opens the modal for a different, bare site.
        $auditSite = Website::factory()->withNoSources()->create(['user_id' => $user->id]);
        $this->fakePool($account->id);

        session(['current_website_id' => $sessionSite->id]);

        Livewire::actingAs($user)
            ->test(ConnectSourcesModal::class)
            ->call('open', $auditSite->id)
            ->assertSet('websiteId', $auditSite->id)
            // No current selections, because the targeted site has no sources.
            ->assertSet('gaSelection', '')
            ->assertSet('gscSelection', '');
    }

    public function test_saving_connects_the_source_and_dispatches_sync(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);
        $website = Website::factory()->withNoSources()->create(['user_id' => $user->id]);
        $this->fakePool($account->id);

        session(['current_website_id' => $website->id]);

        Livewire::actingAs($user)
            ->test(ConnectSourcesModal::class)
            ->call('open')
            ->set('gscSelection', $account->id.'|sc-domain:example.com')
            ->call('saveSources')
            // Only Search Console was picked, so say exactly that. This used
            // to assert a blanket "Connected!", which is how a client came to
            // believe Analytics was linked when it was not (2026-09-17).
            ->assertSet('saved', 'Search Console is connected. Google Analytics is not — choose its property above if you have one. This page will refresh.');

        $website->refresh();
        $this->assertTrue($website->hasGsc());
        $this->assertSame($account->id, $website->gsc_google_account_id);

        Queue::assertPushed(SyncSearchConsoleData::class);
        Queue::assertNotPushed(SyncAnalyticsData::class);
    }
}
