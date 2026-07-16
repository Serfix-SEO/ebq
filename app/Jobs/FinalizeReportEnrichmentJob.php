<?php

namespace App\Jobs;

use App\Models\WebsiteReportSnapshot;
use App\Services\Reports\ReportEnrichmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Poller for the report-enrichment pipeline: each tick advances the snapshot's
 * enrichment_state one step (keyword request completed? classify → SERP tally
 * → competitor keywords → finalize) and re-dispatches itself delayed while
 * work is still pending. Bounded by the service's attempt counter + time
 * budget, so it always terminates.
 *
 * All state lives in the snapshot row and the terminal write is an atomic
 * conditional UPDATE — safe on the two-box Horizon fleet with no extra locks.
 */
class FinalizeReportEnrichmentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly string $domain)
    {
        $this->onQueue(\App\Support\Queues::DEFAULT);
    }

    public function handle(ReportEnrichmentService $service): void
    {
        $snapshot = WebsiteReportSnapshot::forDomain($this->domain);
        if ($snapshot === null || empty($snapshot->enrichment_state)) {
            return; // finished (or superseded) — stop polling
        }

        if ($service->advance($this->domain)) {
            return;
        }

        $delay = max(10, (int) config('services.report.enrichment.poll_seconds', 30));
        self::dispatch($this->domain)->delay(now()->addSeconds($delay));
    }
}
