<?php

namespace App\Console\Commands;

use App\Models\DbNode;
use App\Services\Sharding\ShardMover;
use Illuminate\Console\Command;

/**
 * Move a tenant (a user + all their websites' fact data) or a crawl-site's crawl
 * data between shard nodes. ULIDs make this a clean copy → verify → flip-anchor →
 * purge-source sequence (see {@see ShardMover}).
 *
 *   ebq:shard move tenant <userId> --to=<dbNodeId> [--dry-run]
 *   ebq:shard move crawl  <crawlSiteId> --to=<dbNodeId> [--dry-run]
 */
class ShardCommand extends Command
{
    protected $signature = 'ebq:shard
        {action : move}
        {kind : tenant|crawl}
        {id : user id (tenant) or crawl_site id (crawl)}
        {--to= : target db_nodes id}
        {--dry-run : print the plan without moving}';

    protected $description = 'Move tenant / crawl-site data between shard nodes';

    public function handle(ShardMover $mover): int
    {
        if ($this->argument('action') !== 'move') {
            $this->error('only "move" is supported');

            return self::FAILURE;
        }
        $target = DbNode::find($this->option('to'));
        if (! $target) {
            $this->error('target node not found (--to=<dbNodeId>)');

            return self::FAILURE;
        }
        $kind = $this->argument('kind');
        $id = (string) $this->argument('id');

        if ($this->option('dry-run')) {
            $this->info("[dry-run] would move {$kind} {$id} → node {$target->id} ({$target->name})");

            return self::SUCCESS;
        }

        try {
            $counts = $kind === 'crawl'
                ? $mover->moveCrawlSite($id, $target)
                : $mover->moveTenant($id, $target);
        } catch (\Throwable $e) {
            $this->error('move failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $total = array_sum($counts);
        $this->info("moved {$kind} {$id} → {$target->name}: {$total} rows across ".count($counts).' tables');
        foreach ($counts as $table => $n) {
            if ($n > 0) {
                $this->line(sprintf('  %-28s %d', $table, $n));
            }
        }

        return self::SUCCESS;
    }
}
