<?php

namespace App\Jobs\Content;

use App\Models\ContentPlan;
use App\Models\ContentPlanKeyword;
use App\Models\KeywordMetric;
use App\Services\DataForSeoBacklinkClient;
use App\Services\Reports\DataForSeoSpendMeter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Paid bulk enrichment of a covered plan's keyword library via DataForSEO:
 * real keyword difficulty + search intent + precise Google Ads volume for
 * every library keyword that doesn't yet have a fresh dfs_labs metric row.
 * Three flat-fee requests per 1,000-keyword chunk (~$0.07/chunk).
 *
 * Money rails, in order:
 *  - ACTIVE + billing-covered plans only (paying / trial / comped — a lapsed
 *    client's library stops costing anything automatically);
 *  - kill switch: services.content_autopilot.keyword_enrichment;
 *  - DataForSeoSpendMeter checked before EVERY chunk (real billed tasks[0]
 *    cost is added after each chunk);
 *  - delta-only: keywords with a fresh dfs_labs metric are never re-bought
 *    (30-day keyword_metrics freshness), which also dedupes across clients
 *    because metrics are a shared asset;
 *  - MAX_CHUNKS bounds any single run.
 *
 * Results land in keyword_metrics (data_source=dfs_labs) and are backfilled
 * onto content_plan_keywords for EVERY plan sharing the keyword+country, so
 * Research-page labels, the classifier and topic ranking all upgrade at once.
 */
class EnrichPlanKeywordMetricsJob implements ShouldQueue
{
    use Queueable;

    private const CHUNK = 1000;

    private const MAX_CHUNKS = 8;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public string $planId)
    {
        $this->onQueue('content');
        $this->onConnection('redis-long');
    }

    public function handle(): void
    {
        if (! config('services.content_autopilot.keyword_enrichment', true)) {
            return;
        }
        $plan = ContentPlan::query()->with('website')->find($this->planId);
        if ($plan === null
            || $plan->status !== ContentPlan::STATUS_ACTIVE
            || $plan->billing_covered_at === null) {
            return;
        }

        $dfs = app(DataForSeoBacklinkClient::class);
        $meter = app(DataForSeoSpendMeter::class);
        if (! $dfs->isConfigured() || $meter->exhausted()) {
            return;
        }

        $country = strtolower((string) ($plan->country ?: 'global'));
        [$location, $language] = DataForSeoBacklinkClient::labsGeo($country);

        // Delta: library keywords with no FRESH dfs_labs metric row. Fresh rows
        // were paid for already (by any client) and must never be re-bought.
        $fresh = KeywordMetric::query()
            ->where('country', $country)
            ->where('data_source', 'dfs_labs')
            ->where('expires_at', '>', now())
            ->whereIn('keyword_hash', fn ($q) => $q->select('keyword_hash')
                ->from('content_plan_keywords')->where('plan_id', $plan->id))
            ->pluck('keyword_hash')
            ->flip()
            ->all();

        $pending = ContentPlanKeyword::query()
            ->where('plan_id', $plan->id)
            ->orderByDesc('search_volume')
            ->get(['keyword', 'keyword_hash'])
            ->reject(fn ($r) => isset($fresh[$r->keyword_hash]))
            ->unique('keyword_hash')
            ->values();

        if ($pending->isEmpty()) {
            $plan->forceFill(['keywords_enriched_at' => now()])->saveQuietly();

            return;
        }

        $chunks = $pending->chunk(self::CHUNK)->take(self::MAX_CHUNKS);
        $enriched = 0;
        foreach ($chunks as $chunk) {
            if ($meter->exhausted()) {
                Log::warning('content_enrichment.meter_exhausted', ['plan_id' => $plan->id]);
                break;
            }
            $rows = $dfs->resetCost()->bulkKeywordMetrics($chunk->pluck('keyword')->all(), [
                'location_code' => $location, 'language_name' => $language,
            ]);
            $cost = $dfs->totalCost();
            if ($cost > 0) {
                $meter->add($cost);
            }
            Log::info('content_enrichment.chunk', [
                'plan_id' => $plan->id, 'keywords' => $chunk->count(),
                'returned' => count($rows), 'cost_usd' => round($cost, 4),
            ]);
            if ($rows === []) {
                continue; // endpoint failure — fail soft, keep what we have
            }
            $this->persist($rows, $country);
            $enriched += count($rows);
        }

        // Stamp even on partial runs — the monthly guard decides cadence, the
        // delta query decides what's left; next month's run picks up the rest.
        $plan->forceFill(['keywords_enriched_at' => now()])->saveQuietly();
        Log::info('content_enrichment.done', ['plan_id' => $plan->id, 'enriched' => $enriched]);
    }

    /** @param list<array{keyword:string, search_volume:?int, cpc:?float, competition:?float, keyword_difficulty:?int, search_intent:?string}> $rows */
    private function persist(array $rows, string $country): void
    {
        $now = now();
        $metricRows = [];
        foreach ($rows as $r) {
            $metricRows[] = [
                'id' => (string) Str::ulid(),
                'keyword' => $r['keyword'],
                'keyword_hash' => KeywordMetric::hashKeyword($r['keyword']),
                'country' => $country,
                'data_source' => 'dfs_labs',
                'search_volume' => $r['search_volume'],
                'cpc' => $r['cpc'],
                'competition' => $r['competition'],
                'keyword_difficulty' => $r['keyword_difficulty'],
                'search_intent' => $r['search_intent'],
                'fetched_at' => $now,
                'expires_at' => $now->copy()->addDays(30),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($metricRows, 250) as $batch) {
            DB::table('keyword_metrics')->upsert(
                $batch,
                ['keyword_hash', 'country', 'data_source'],
                ['keyword', 'search_volume', 'cpc', 'competition', 'keyword_difficulty', 'search_intent', 'fetched_at', 'expires_at', 'updated_at'],
            );
        }

        // Backfill EVERY plan sharing the keyword (shared-asset win). KD/intent
        // fill only where missing; volume upgrades to the precise figure.
        foreach ($rows as $r) {
            $hash = KeywordMetric::hashKeyword($r['keyword']);
            $base = ContentPlanKeyword::query()->where('keyword_hash', $hash)->where('country', $country);
            if ($r['keyword_difficulty'] !== null) {
                (clone $base)->whereNull('keyword_difficulty')->update(['keyword_difficulty' => $r['keyword_difficulty']]);
            }
            if ($r['search_intent'] !== null) {
                (clone $base)->whereNull('search_intent')->update(['search_intent' => $r['search_intent']]);
            }
            if ($r['search_volume'] !== null) {
                (clone $base)->update(['search_volume' => $r['search_volume']]);
            }
            if ($r['competition'] !== null) {
                (clone $base)->whereNull('competition')->update(['competition' => $r['competition']]);
            }
        }
    }
}
