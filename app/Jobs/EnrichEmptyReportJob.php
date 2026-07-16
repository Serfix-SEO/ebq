<?php

namespace App\Jobs;

use App\Services\Reports\ReportEnrichmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Stage A of the empty-domain report enrichment: free sync signals (Open
 * PageRank, Moz, 2-3 page fetch) + async keyword-fleet dispatch. The
 * FinalizeReportEnrichmentJob poller takes it from there.
 *
 * With `keywordsOnly` (full 'ready' reports), only the keywords section is
 * fetched and later merged into the existing payload.
 *
 * tries=1: the service finalizes with whatever exists on any failure, and a
 * stuck row self-heals via the short partial TTL — retrying would double
 * provider calls for no benefit.
 */
class EnrichEmptyReportJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 1800;

    public function __construct(
        public readonly string $domain,
        public readonly bool $keywordsOnly = false,
    ) {
        $this->onQueue(\App\Support\Queues::DEFAULT);
    }

    public function uniqueId(): string
    {
        return 'enrich_report:'.($this->keywordsOnly ? 'kw:' : '').$this->domain;
    }

    public function handle(ReportEnrichmentService $service): void
    {
        if ($this->keywordsOnly) {
            $service->bootstrapReadyKeywords($this->domain);

            return;
        }

        $service->bootstrap($this->domain);
    }
}
