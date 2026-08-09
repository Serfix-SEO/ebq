<?php

namespace App\Console\Commands;

use App\Models\KeywordApiRequest;
use App\Models\KeywordApiServer;
use App\Services\KeywordFinder\KeywordFinderClient;
use App\Services\KeywordFinder\KeywordFinderPool;
use Illuminate\Console\Command;

/**
 * Fast orphan detection for keyword requests.
 *
 * The node's queue is IN-MEMORY: a headless-browser crash drops every queued
 * job without a webhook, leaving our rows `running` forever. The generic
 * reaper waits 15 minutes because it cannot tell "slow" from "dead" — but we
 * can: if the node reports an EMPTY queue while we hold in-flight rows older
 * than a small grace period, those rows are provably lost. Redispatch them
 * immediately (same request_id, never re-billed) instead of making the
 * customer wait out the reaper.
 *
 * Deliberately conservative: an unreachable node proves nothing (skip), a
 * busy node proves nothing (skip), and the grace period covers the window
 * where a job was ACKed but not yet visible in the node's queue counters.
 * (2026-08-09: a browser crash orphaned an onboarding lead's whole keyword
 * batch; recovery took a manual sweep.)
 */
class DetectOrphanedKeywordRequests extends Command
{
    protected $signature = 'ebq:detect-orphaned-keyword-requests {--minutes=3 : Grace period before an in-flight row can be declared orphaned}';

    protected $description = 'Redispatch keyword requests the node provably lost (empty node queue + in-flight rows)';

    public function handle(KeywordFinderPool $pool): int
    {
        $cutoff = now()->subMinutes(max(1, (int) $this->option('minutes')));
        $recovered = 0;
        $failed = 0;

        foreach (KeywordApiServer::query()->routable()->get() as $server) {
            $inFlight = KeywordApiRequest::query()
                ->where('keyword_api_server_id', $server->id)
                ->whereIn('status', [KeywordApiRequest::STATUS_QUEUED, KeywordApiRequest::STATUS_RUNNING])
                ->where('dispatched_at', '<', $cutoff)
                ->get();
            if ($inFlight->isEmpty()) {
                continue;
            }

            $queue = (new KeywordFinderClient($server))->queue();
            if ($queue === null) {
                // Unreachable — proves nothing about the jobs. The 15-minute
                // reaper stays the backstop for this case.
                $this->line("{$server->name}: unreachable, skipping");

                continue;
            }
            if ((int) ($queue['waiting'] ?? 0) > 0 || (int) ($queue['running'] ?? 0) > 0) {
                continue; // node is working — our rows may simply be next
            }

            // Empty node queue + our in-flight rows past grace = provably lost.
            foreach ($inFlight as $request) {
                if ($pool->redispatch($request)) {
                    $recovered++;
                    $this->line("recovered {$request->id} ({$request->type}, attempt {$request->attempts})");
                } else {
                    $request->markFailed('The keyword server lost this job. Please try again.');
                    $failed++;
                    $this->line("failed {$request->id} (retry cap reached or no server)");
                }
            }
        }

        $this->info("Recovered {$recovered}, failed {$failed}.");

        return self::SUCCESS;
    }
}
