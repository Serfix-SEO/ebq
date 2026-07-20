<?php

namespace App\Jobs;

use App\Models\ContentPlan;
use App\Models\KeywordMetric;
use App\Services\Content\ContentKeywordInsights;
use App\Services\DataForSeoKeywordDataClient;
use App\Services\Reports\DataForSeoSpendMeter;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Enrich a content plan's keyword volumes with real CLICKSTREAM-based figures
 * from DataForSEO, in ONE batched task (≤1000 keywords, flat per-task cost — never per
 * keyword). Results are written to the shared {@see KeywordMetric} cache so
 * every future onboarding/plan reuses them for free (90-day TTL). Only keywords
 * not already fresh in the cache are sent, and the whole thing is gated by the
 * DataForSeoSpendMeter monthly breaker. On success the keyword digest cache is
 * cleared so the step re-renders with the accurate volumes (which cascade into
 * the topic "searches/mo" pills and the traffic estimate).
 *
 * Dispatched once per plan (7-day guard) from ContentKeywordInsights::get().
 */
class EnrichContentKeywordVolumesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    /** keyword_metrics.data_source for content clickstream volumes (≤16 chars). */
    public const SOURCE = 'clickstream';

    /** KeywordFinder country key → DataForSEO location_code (Google Ads). */
    private const LOCATION = [
        'us' => 2840, 'gb' => 2826, 'uk' => 2826, 'ca' => 2124, 'au' => 2036,
        'in' => 2356, 'de' => 2276, 'fr' => 2250, 'es' => 2724, 'it' => 2380,
        'nl' => 2528, 'br' => 2076, 'mx' => 2484, 'ae' => 2784, 'sa' => 2682,
        'global' => 2840, '' => 2840,
    ];

    public function __construct(public string $planId)
    {
        $this->onQueue(Queues::CONTENT);
        $this->onConnection('redis-long');
    }

    public function handle(DataForSeoKeywordDataClient $dfs, DataForSeoSpendMeter $meter, ContentKeywordInsights $insights): void
    {
        // Belt-and-suspenders: this paid fetch is a Content AI feature only.
        // Always clear the "refining volumes" flag when we exit, so the wizard
        // stops polling whether we enriched, skipped, or bailed.
        $clearPending = fn () => \Illuminate\Support\Facades\Cache::forget('content:kw-dfs-pending:'.$this->planId);

        if (! config('services.content_autopilot.enrich_volume', true)) {
            $clearPending();

            return;
        }
        $plan = ContentPlan::query()->with('website.user')->find($this->planId);
        if ($plan === null || ! $dfs->isConfigured() || $meter->exhausted()) {
            $clearPending();

            return;
        }

        $country = $insights->metricCountry($plan);
        $keywords = $insights->allKeywords($plan);
        if ($keywords === []) {
            $clearPending();

            return;
        }

        // Reuse: only price keywords we don't already have fresh in the cache.
        $hashes = [];
        foreach ($keywords as $k) {
            $hashes[KeywordMetric::hashKeyword($k)] = $k;
        }
        $haveHashes = KeywordMetric::query()
            ->whereIn('keyword_hash', array_keys($hashes))
            ->where('country', $country)
            ->where('data_source', self::SOURCE) // only a fresh CLICKSTREAM row counts as "have"
            ->where('expires_at', '>', now())
            ->pluck('keyword_hash')->all();
        $missing = [];
        foreach ($hashes as $hash => $kw) {
            if (! in_array($hash, $haveHashes, true)) {
                $missing[] = $kw;
            }
        }
        if ($missing === []) {
            $clearPending();
            $insights->forget($plan); // already fully cached → just re-render with it
            $this->backfillTopics($plan, $country);

            return;
        }

        // One batched call. Admins / forced runs hit the free sandbox.
        if (($plan->website?->user?->is_admin) || (bool) config('services.dataforseo.force_sandbox')) {
            $dfs->useSandbox();
        }
        $data = $dfs->searchVolume($missing, self::LOCATION[$country] ?? 2840, $this->language($plan));
        $meter->add($dfs->totalCost());

        foreach ($data as $kw => $d) {
            KeywordMetric::query()->updateOrCreate(
                ['keyword_hash' => KeywordMetric::hashKeyword($kw), 'country' => $country, 'data_source' => self::SOURCE],
                [
                    'keyword' => mb_substr($kw, 0, 255),
                    'search_volume' => $d['search_volume'],
                    'cpc' => $d['cpc'],
                    'low_top_of_page_bid' => $d['low_top_of_page_bid'],
                    'high_top_of_page_bid' => $d['high_top_of_page_bid'],
                    'competition' => $d['competition'],
                    'currency' => 'USD',
                    'trend_12m' => $d['monthly'],
                    'fetched_at' => now(),
                    'expires_at' => now()->addDays(90),
                ],
            );
        }

        Log::info('content_autopilot.kw_volume_enriched', [
            'plan_id' => $plan->id, 'requested' => count($missing), 'returned' => count($data), 'cost' => round($dfs->totalCost(), 4),
        ]);

        // Re-render the digest with accurate volumes + push them onto the topics.
        $clearPending();
        $insights->forget($plan);
        $this->backfillTopics($plan, $country);
    }

    /** Write DFS volumes onto the plan's topics so calendar/first-articles pills update. */
    private function backfillTopics(ContentPlan $plan, string $country): void
    {
        try {
            foreach ($plan->topics()->get(['id', 'target_keyword']) as $topic) {
                $kw = mb_strtolower(trim((string) $topic->target_keyword));
                if ($kw === '') {
                    continue;
                }
                $m = KeywordMetric::query()
                    ->where('keyword_hash', KeywordMetric::hashKeyword($kw))
                    ->where('country', $country)
                    ->where('data_source', self::SOURCE)
                    ->where('expires_at', '>', now())
                    ->first(['search_volume']);
                if ($m?->search_volume !== null) {
                    $topic->forceFill(['keyword_volume' => (int) $m->search_volume])->saveQuietly();
                }
            }
        } catch (\Throwable) {
            // cosmetic enrichment only
        }
    }

    private function language(ContentPlan $plan): string
    {
        return mb_strtolower(trim((string) ($plan->language ?: 'en'))) === 'ar' ? 'ar' : 'en';
    }
}
