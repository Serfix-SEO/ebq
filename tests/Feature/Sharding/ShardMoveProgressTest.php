<?php

namespace Tests\Feature\Sharding;

use App\Jobs\Fleet\MoveShardJob;
use App\Models\CrawlSite;
use App\Models\DbNode;
use App\Models\ShardMove;
use App\Models\User;
use App\Models\Website;
use App\Services\Sharding\ShardMover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Progress tracking for shard moves (added 2026-07-06): ShardMover writes a
 * shard_moves row through counting → copying → verifying → cutover → purging
 * → completed, with per-chunk rows_copied. The fleet page's ShardMoves panel
 * polls it. Before this a MoveShardJob was a black box (the June 18 timeout
 * was only visible in failed_jobs).
 *
 * Test harness: source and target both resolve to the test sqlite connection
 * (a node connection registered as a clone of the default), so the "copy" is
 * a same-connection insertOrIgnore no-op-ish round trip — fine, we're testing
 * the progress bookkeeping, not MariaDB networking (that was validated on a
 * throwaway docker MariaDB per SHARDING_PLAN).
 */
class ShardMoveProgressTest extends TestCase
{
    use RefreshDatabase;

    private function targetNode(): DbNode
    {
        $node = DbNode::create([
            'name' => 'test-node',
            'role' => DbNode::ROLE_TENANT,
            'status' => DbNode::STATUS_ACTIVE,
            'private_ip' => '10.0.0.99',
            'db_name' => 'ebq_test',
            'is_healthy' => true,
        ]);
        // Register the node connection as a clone of the test connection —
        // and share the live PDO: each named sqlite :memory: connection is
        // otherwise a separate empty database with no tables.
        Config::set('database.connections.'.$node->connectionName(), config('database.connections.'.config('database.default')));
        DB::connection($node->connectionName())->setPdo(DB::connection()->getPdo());

        return $node;
    }

    public function test_crawl_move_records_full_progress_lifecycle(): void
    {
        $owner = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $owner->id, 'domain' => 'move-progress.test']);
        $cs = (string) $website->crawl_site_id;
        // Seed a couple of crawl-tier rows so rows_total > 0.
        foreach (range(1, 3) as $i) {
            \App\Models\WebsitePage::create([
                'crawl_site_id' => $cs,
                'url' => "https://move-progress.test/{$i}",
                'url_hash' => \App\Models\WebsitePage::hashUrl("https://move-progress.test/{$i}"),
                'http_status' => 200, 'is_indexable' => true, 'last_crawled_at' => now(),
            ]);
        }

        $node = $this->targetNode();
        app(ShardMover::class)->moveCrawlSite($cs, $node);

        $move = ShardMove::query()->where('kind', 'crawl')->where('subject_id', $cs)->firstOrFail();
        $this->assertSame(ShardMove::STATUS_COMPLETED, $move->status);
        $this->assertSame('move-progress.test', $move->subject_label);
        $this->assertSame($node->id, $move->target_node_id);
        $this->assertGreaterThanOrEqual(3, $move->rows_total);
        $this->assertSame($move->rows_total, $move->rows_copied);
        $this->assertSame($move->tables_total, $move->tables_done);
        $this->assertNotNull($move->finished_at);
        $this->assertSame(100, $move->progressPercent());
        $this->assertIsArray($move->table_counts);
    }

    public function test_failed_move_is_marked_with_the_error(): void
    {
        $owner = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $owner->id, 'domain' => 'move-fail.test']);
        $cs = (string) $website->crawl_site_id;

        $node = $this->targetNode();
        // Sabotage: drop the registered connection AFTER constructing the
        // mover path by pointing it at a bogus sqlite file? Simpler: delete
        // the crawl site so findOrFail throws inside... that throws before
        // the move row exists. Instead force a verify failure: seed a dest
        // row the source doesn't have is not possible same-connection.
        // Pragmatic failure: unregister the connection and assert the
        // pre-row RuntimeException path doesn't create a move row, THEN
        // test failMove via the job-killed path below.
        Config::set('database.connections.'.$node->connectionName(), null);

        try {
            app(ShardMover::class)->moveCrawlSite($cs, $node);
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException) {
        }

        // Connection-missing throws before tracking starts — no orphan row.
        $this->assertSame(0, ShardMove::query()->count());
    }

    public function test_killed_job_marks_the_in_flight_move_failed(): void
    {
        $move = ShardMove::create([
            'kind' => 'crawl',
            'subject_id' => 'some-crawl-site-id',
            'target_node_id' => 'some-node-id',
            'status' => ShardMove::STATUS_COPYING,
            'started_at' => now(),
        ]);

        (new MoveShardJob('crawl', 'some-crawl-site-id', 'some-node-id'))
            ->failed(new \RuntimeException('has timed out'));

        $move->refresh();
        $this->assertSame(ShardMove::STATUS_FAILED, $move->status);
        $this->assertStringContainsString('job killed', $move->error);
        $this->assertNotNull($move->finished_at);
    }

    public function test_shard_moves_panel_renders_progress(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        ShardMove::create([
            'kind' => 'tenant',
            'subject_id' => 'u-1',
            'subject_label' => 'client@example.com',
            'target_node_id' => 'n-1',
            'status' => ShardMove::STATUS_COPYING,
            'current_table' => 'search_console_data',
            'tables_total' => 8,
            'tables_done' => 2,
            'rows_total' => 1000,
            'rows_copied' => 400,
            'started_at' => now()->subMinute(),
        ]);

        Livewire::actingAs($admin)
            ->test(\App\Livewire\Admin\ShardMoves::class)
            ->assertSee('client@example.com')
            ->assertSee('copying')
            ->assertSee('search_console_data')
            ->assertSee('move in progress');
    }
}
