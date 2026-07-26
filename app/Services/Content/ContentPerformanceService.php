<?php

namespace App\Services\Content;

use App\Models\ContentPageAnalytics;
use App\Models\SearchConsoleData;
use App\Models\Website;
use App\Services\ReportCache;
use App\Services\ReportDataService;
use App\Support\UrlNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Read-side aggregation for the Keyword Tracker's performance reporting. Pulls
 * DAILY series so a client can watch published content climb:
 *   - keyword rankings/clicks/impressions from GSC (search_console_data, already
 *     synced daily — keyed by the query),
 *   - per-article pageviews/sessions from GA (content_page_analytics, our own
 *     per-page table — GSC also supplies per-page clicks/impressions).
 *
 * Cached with ReportCache::version() so a same-window GSC/GA re-sync invalidates,
 * and lag-anchored via ReportDataService::lastSafeReportDate() so the chart never
 * shows a fake cliff on GSC's 2-3 unfinalized days.
 */
class ContentPerformanceService
{
    private const TTL = 21600; // 6h

    public function __construct(private ReportDataService $reports) {}

    /** Lag-safe window end; falls back to yesterday for brand-new sites. */
    private function windowEnd(string $websiteId): Carbon
    {
        return $this->reports->lastSafeReportDate($websiteId)
            ?? Carbon::yesterday(config('app.timezone'));
    }

    /**
     * Daily GSC series for a single tracked keyword.
     *
     * @return array{window:array{start:string,end:string}, days:array<int,array{date:string,clicks:int,impressions:int,position:?float,ctr:float}>, totals:array{clicks:int,impressions:int,position:?float}, has_data:bool}
     */
    public function keywordSeries(Website $website, string $normalizedKeyword, int $days = 30): array
    {
        $end = $this->windowEnd($website->id);
        $start = $end->copy()->subDays(max(1, $days) - 1);
        $key = sprintf('content_perf:kw:v1:%s:%d:%s:%s', $website->id, ReportCache::version($website->id), $start->toDateString(), md5($normalizedKeyword));

        return Cache::remember($key, self::TTL, function () use ($website, $normalizedKeyword, $start, $end) {
            $rows = SearchConsoleData::query()
                ->where('website_id', $website->id)
                ->where('query', $normalizedKeyword)
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->selectRaw('date, SUM(clicks) as clicks, SUM(impressions) as impressions, SUM(position * impressions) as pos_weight')
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            $days = $rows->map(fn ($r) => [
                'date' => (string) (is_string($r->date) ? $r->date : $r->date?->toDateString()),
                'clicks' => (int) $r->clicks,
                'impressions' => (int) $r->impressions,
                'position' => $r->impressions > 0 ? round($r->pos_weight / $r->impressions, 1) : null,
                'ctr' => $r->impressions > 0 ? round($r->clicks / $r->impressions * 100, 2) : 0.0,
            ])->all();

            $imps = array_sum(array_column($days, 'impressions'));
            $latestPos = null;
            for ($i = count($days) - 1; $i >= 0; $i--) {
                if ($days[$i]['position'] !== null) {
                    $latestPos = $days[$i]['position'];
                    break;
                }
            }

            return [
                'window' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
                'days' => $days,
                'totals' => [
                    'clicks' => (int) array_sum(array_column($days, 'clicks')),
                    'impressions' => (int) $imps,
                    'position' => $latestPos,
                ],
                'has_data' => $days !== [],
            ];
        });
    }

    /**
     * Compact summaries for the tracker list — one entry per normalized keyword.
     *
     * @param  string[]  $normalizedKeywords
     * @return array<string,array{clicks:int,impressions:int,position:?float,spark:array<int,int>,has_data:bool}>
     */
    public function keywordSummaries(Website $website, array $normalizedKeywords, int $days = 28): array
    {
        $normalizedKeywords = array_values(array_unique(array_filter($normalizedKeywords)));
        if ($normalizedKeywords === []) {
            return [];
        }
        $end = $this->windowEnd($website->id);
        $start = $end->copy()->subDays(max(1, $days) - 1);
        $key = sprintf('content_perf:kwsum:v1:%s:%d:%s:%s', $website->id, ReportCache::version($website->id), $start->toDateString(), md5(implode('|', $normalizedKeywords)));

        return Cache::remember($key, self::TTL, function () use ($website, $normalizedKeywords, $start, $end) {
            $rows = SearchConsoleData::query()
                ->where('website_id', $website->id)
                ->whereIn('query', $normalizedKeywords)
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->selectRaw('`query` as kw, date, SUM(clicks) as clicks, SUM(impressions) as impressions, SUM(position * impressions) as pos_weight')
                ->groupBy('query', 'date')
                ->orderBy('date')
                ->get();

            $out = [];
            foreach ($normalizedKeywords as $kw) {
                $out[$kw] = ['clicks' => 0, 'impressions' => 0, 'position' => null, 'spark' => [], 'has_data' => false];
            }
            foreach ($rows as $r) {
                $kw = (string) $r->kw;
                if (! isset($out[$kw])) {
                    continue;
                }
                $out[$kw]['clicks'] += (int) $r->clicks;
                $out[$kw]['impressions'] += (int) $r->impressions;
                $out[$kw]['spark'][] = (int) $r->clicks;
                $out[$kw]['has_data'] = true;
                if ($r->impressions > 0) {
                    $out[$kw]['position'] = round($r->pos_weight / $r->impressions, 1); // last date wins (ordered)
                }
            }

            return $out;
        });
    }

    /**
     * Daily per-article series: GSC page clicks/impressions/position merged with
     * GA pageviews/sessions/users. This is the "prove it's working" chart.
     *
     * @return array{window:array{start:string,end:string}, days:array<int,array{date:string,clicks:int,impressions:int,position:?float,pageviews:int,sessions:int,users:int}>, has_gsc:bool, has_ga:bool}
     */
    public function pageSeries(Website $website, string $pageUrl, int $days = 30): array
    {
        $page = UrlNormalizer::normalize($pageUrl);
        $end = $this->windowEnd($website->id);
        $start = $end->copy()->subDays(max(1, $days) - 1);
        $key = sprintf('content_perf:page:v1:%s:%d:%s:%s', $website->id, ReportCache::version($website->id), $start->toDateString(), md5($page));

        return Cache::remember($key, self::TTL, function () use ($website, $page, $start, $end) {
            $gsc = SearchConsoleData::query()
                ->where('website_id', $website->id)
                ->where('page', $page)
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->selectRaw('date, SUM(clicks) as clicks, SUM(impressions) as impressions, SUM(position * impressions) as pos_weight')
                ->groupBy('date')
                ->get()
                ->keyBy(fn ($r) => (string) (is_string($r->date) ? $r->date : $r->date?->toDateString()));

            $ga = ContentPageAnalytics::query()
                ->where('website_id', $website->id)
                ->where('page', $page)
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->get()
                ->keyBy(fn ($r) => $r->date?->toDateString());

            $dates = $gsc->keys()->merge($ga->keys())->unique()->sort()->values();
            $out = [];
            foreach ($dates as $d) {
                $g = $gsc->get($d);
                $a = $ga->get($d);
                $imps = (int) ($g->impressions ?? 0);
                $out[] = [
                    'date' => (string) $d,
                    'clicks' => (int) ($g->clicks ?? 0),
                    'impressions' => $imps,
                    'position' => $imps > 0 ? round($g->pos_weight / $imps, 1) : null,
                    'pageviews' => (int) ($a->pageviews ?? 0),
                    'sessions' => (int) ($a->sessions ?? 0),
                    'users' => (int) ($a->users ?? 0),
                ];
            }

            return [
                'window' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
                'days' => $out,
                'has_gsc' => $gsc->isNotEmpty(),
                'has_ga' => $ga->isNotEmpty(),
            ];
        });
    }
}
