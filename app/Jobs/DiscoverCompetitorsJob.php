<?php

namespace App\Jobs;

use App\Models\WebsiteReportSnapshot;
use App\Services\Reports\ReportEnrichmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Standalone competitor discovery for an arbitrary URL, backing the
 * Competitor Discovery page. Resolves the domain's keyword rows (from a
 * completed fleet request or the monthly cache), runs the SERP-minimal
 * discovery pipeline (LLM junk-check → keywords-or-crawled-queries → SERP
 * tally), and stores the result in the shared cache the page polls.
 *
 * tries=1: LLM/SERP cost money; on any failure we finalize an empty result so
 * the page never spins forever.
 */
class DiscoverCompetitorsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 150;

    public int $uniqueFor = 600;

    /**
     * @param  array{id: ?string, cache_key: ?string}  $keywordRef
     */
    public function __construct(
        public readonly string $domain,
        public readonly array $keywordRef,
        public readonly ?string $userId = null,
    ) {
        $this->onQueue(\App\Support\Queues::DEFAULT);
    }

    public function uniqueId(): string
    {
        return 'discover_competitors:'.$this->domain;
    }

    public static function cacheKey(string $domain): string
    {
        return 'competitor-discovery:'.WebsiteReportSnapshot::normalizeDomain($domain);
    }

    public function handle(ReportEnrichmentService $service): void
    {
        $key = self::cacheKey($this->domain);
        $result = ['status' => 'done', 'competitors' => [], 'scrap' => false, 'query_source' => null, 'queries' => [], 'at' => now()->toIso8601String()];

        try {
            $rows = $service->keywordRowsFor($this->keywordRef);
            if ($rows === null || $rows === []) {
                // Keywords never arrived / empty — nothing to discover from.
                $result['status'] = 'no_keywords';
                Cache::put($key, $result, now()->addDays(7));

                return;
            }

            $discovery = $service->discoverCompetitorsFor($this->domain, $rows, $this->userId);
            $result = array_merge($result, [
                'competitors' => $discovery['competitors'],
                'scrap' => $discovery['scrap'],
                'query_source' => $discovery['query_source'],
                'queries' => $discovery['queries'],
            ]);
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('DiscoverCompetitorsJob failed', [
                'domain' => $this->domain, 'message' => $e->getMessage(),
            ]);
        }

        // Discovered competitors are a domain fact — cache cross-user for a week
        // so repeat lookups cost no SERP/LLM.
        Cache::put($key, $result, now()->addDays(7));
    }
}
