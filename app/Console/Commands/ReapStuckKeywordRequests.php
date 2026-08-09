<?php

namespace App\Console\Commands;

use App\Models\KeywordApiRequest;
use App\Services\KeywordFinder\KeywordFinderPool;
use Illuminate\Console\Command;

/**
 * Fail keyword API requests whose webhook never arrived.
 *
 * The node fleet delivers results via webhook and gives up after 3 attempts
 * (~15s); if the node process dies mid-job (or the webhook is lost) the row
 * stays `queued`/`running` forever — no other code path ever touches it again
 * (the UI poll just times out). Seen live 2026-07-07: a node crash stranded
 * two `running` rows. This reaper is the terminal backstop: anything still
 * in flight well past the longest legitimate job (a few minutes) is dead.
 */
class ReapStuckKeywordRequests extends Command
{
    protected $signature = 'ebq:reap-stuck-keyword-requests {--minutes=15 : Age before an in-flight request is considered dead}';

    protected $description = 'Mark keyword API requests stuck in queued/running as failed';

    public function handle(): int
    {
        $cutoff = now()->subMinutes((int) $this->option('minutes'));

        $stuck = KeywordApiRequest::query()
            ->whereIn('status', [KeywordApiRequest::STATUS_QUEUED, KeywordApiRequest::STATUS_RUNNING])
            ->where('created_at', '<', $cutoff)
            ->get();

        $pool = app(KeywordFinderPool::class);
        foreach ($stuck as $request) {
            // Self-recovery first: a node browser crash wipes its in-memory
            // queue, so a stuck row usually means the work was LOST, not slow.
            // Re-send the stored payload (same request_id — pollers keep
            // working, never re-billed) before declaring it dead.
            if ($pool->redispatch($request)) {
                $this->line("redispatched {$request->id} ({$request->type}, attempt {$request->attempts})");

                continue;
            }
            $request->markFailed('Timed out: no result from the keyword server. Please try again.');
            $this->line("reaped {$request->id} ({$request->type}, created {$request->created_at})");
        }

        $this->info("Reaped {$stuck->count()} stuck request(s).");

        return self::SUCCESS;
    }
}
