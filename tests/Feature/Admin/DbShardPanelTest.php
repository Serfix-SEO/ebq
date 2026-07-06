<?php

namespace Tests\Feature\Admin;

use App\Jobs\Fleet\MoveShardJob;
use App\Livewire\Admin\DbShardPanel;
use App\Models\DbNode;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Live Database-shards panel (2026-07-06): node resident lists with row
 * counts, host-aware move form (current host disabled as a target), and
 * wire:poll instead of the old full-page reload.
 */
class DbShardPanelTest extends TestCase
{
    use RefreshDatabase;

    private function nodes(): array
    {
        $primary = DbNode::create([
            'name' => 'primary', 'role' => DbNode::ROLE_PRIMARY, 'status' => DbNode::STATUS_ACTIVE,
            'private_ip' => '10.0.0.2', 'db_name' => 'ebq', 'is_pinned' => true, 'is_healthy' => true,
        ]);
        $shard = DbNode::create([
            'name' => 'shard-a', 'role' => DbNode::ROLE_TENANT, 'status' => DbNode::STATUS_ACTIVE,
            'private_ip' => '10.0.0.4', 'db_name' => 'ebq', 'is_healthy' => true,
        ]);

        return [$primary, $shard];
    }

    public function test_panel_lists_residents_per_node(): void
    {
        [$primary, $shard] = $this->nodes();
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create(['email' => 'resident@example.com']);
        Website::factory()->create(['user_id' => $owner->id, 'domain' => 'resident-site.test']);

        Livewire::actingAs($admin)
            ->test(DbShardPanel::class)
            ->call('toggleExpand', $primary->id)
            ->assertSee('resident@example.com')
            ->assertSee('resident-site.test')
            ->assertSee('rows');
    }

    public function test_move_rejects_target_equal_to_current_host(): void
    {
        [$primary, $shard] = $this->nodes();
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create();
        Website::factory()->create(['user_id' => $owner->id, 'domain' => 'hosted.test']);

        Queue::fake();
        // Owner unanchored => host = pinned primary. Target primary must be rejected.
        Livewire::actingAs($admin)
            ->test(DbShardPanel::class)
            ->set('moveKind', 'tenant')
            ->set('moveSubjectId', (string) $owner->id)
            ->set('moveTargetId', $primary->id)
            ->call('move')
            ->assertHasErrors('moveTargetId');
        Queue::assertNothingPushed();
    }

    public function test_move_to_other_node_dispatches_job(): void
    {
        [$primary, $shard] = $this->nodes();
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create();
        Website::factory()->create(['user_id' => $owner->id, 'domain' => 'moving.test']);

        Queue::fake();
        Livewire::actingAs($admin)
            ->test(DbShardPanel::class)
            ->set('moveKind', 'tenant')
            ->set('moveSubjectId', (string) $owner->id)
            ->set('moveTargetId', $shard->id)
            ->call('move')
            ->assertHasNoErrors();
        Queue::assertPushed(MoveShardJob::class, fn (MoveShardJob $j) => $j->kind === 'tenant' && $j->targetNodeId === $shard->id);
    }

    public function test_refresh_row_count_busts_the_cache(): void
    {
        [$primary] = $this->nodes();
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create();
        Website::factory()->create(['user_id' => $owner->id, 'domain' => 'countme.test']);

        cache()->put('shard-rows:tenant:'.$owner->id, 999999, 3600);

        Livewire::actingAs($admin)
            ->test(DbShardPanel::class)
            ->call('refreshRowCount', 'tenant', (string) $owner->id);

        $this->assertNull(cache()->get('shard-rows:tenant:'.$owner->id));
    }

    public function test_refresh_node_row_counts_busts_every_resident_cache(): void
    {
        [$primary, $shard] = $this->nodes();
        $admin = User::factory()->create(['is_admin' => true]);
        $a = User::factory()->create();
        $b = User::factory()->create();
        $siteA = Website::factory()->create(['user_id' => $a->id, 'domain' => 'bulk-a.test']);
        Website::factory()->create(['user_id' => $b->id, 'domain' => 'bulk-b.test']);

        cache()->put('shard-rows:tenant:'.$a->id, 111, 3600);
        cache()->put('shard-rows:tenant:'.$b->id, 222, 3600);
        cache()->put('shard-rows:crawl:'.$siteA->crawl_site_id, 333, 3600);

        // All three residents are unanchored => live on the pinned primary.
        Livewire::actingAs($admin)
            ->test(DbShardPanel::class)
            ->call('refreshNodeRowCounts', $primary->id);

        // The action auto-expands the node, so render() recounts within the
        // same request — stale sentinels replaced by fresh (0-row) counts.
        $this->assertSame(0, cache()->get('shard-rows:tenant:'.$a->id));
        $this->assertSame(0, cache()->get('shard-rows:tenant:'.$b->id));
        $this->assertSame(0, cache()->get('shard-rows:crawl:'.$siteA->crawl_site_id));
    }

    public function test_non_admin_is_rejected(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        Livewire::actingAs($user)->test(DbShardPanel::class)->assertForbidden();
    }
}
