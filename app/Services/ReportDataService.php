<?php

namespace App\Services;

use App\Models\AnalyticsData;
use App\Models\Backlink;
use App\Models\KeywordMetric;
use App\Models\PageIndexingStatus;
use App\Models\SearchConsoleData;
use App\Models\Website;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class ReportDataService
{
    /**
     * Most recent date for which it's safe to report on this website.
     *
     * "Safe" means BOTH:
     *   1. Old enough to be GSC-final — at least `gsc_lag_days` ago
     *      (default 3, floored at 1 because today itself is always
     *      partial). Google takes 24–72h to finalise daily numbers,
     *      so anything fresher than the floor risks comparing two
     *      partial days, which reads as a regression even on up-days.
     *   2. Actually present in `search_console_data` for this site.
     *      A site whose sync is stalled at D-7 still gets a usable
     *      report (D-7 vs D-8); a site caught up to D-3 reports on
     *      D-3 vs D-4. A brand-new site with zero rows returns null
     *      and the caller skips the send.
     *
     * The optional $timezone argument lets callers anchor "today" to
     * a specific user's tz when that matters; the cron path passes
     * nothing and gets app-tz, which is fine since the multi-day lag
     * floor swallows any sub-24h tz delta.
     *
     * Returns null when no usable date exists.
     */
    public function lastSafeReportDate(string $websiteId, ?int $minLagDays = null, ?string $timezone = null): ?Carbon
    {
        $lag = $minLagDays ?? (int) config('reports.gsc_lag_days', 3);
        $tz = $timezone ?? config('app.timezone');
        $ceiling = Carbon::today($tz)->subDays(max(1, $lag));

        $lastSynced = SearchConsoleData::query()
            ->where('website_id', $websiteId)
            ->where('date', '<=', $ceiling->toDateString())
            ->max('date');

        return $lastSynced ? Carbon::parse($lastSynced, $tz)->startOfDay() : null;
    }

    /**
     * End-of-window anchor for the statistics-page aggregates: the last day
     * WITH finalized GSC data, falling back to yesterday for fresh sites.
     * Yesterday-anchored windows silently include 2-3 empty GSC-lag days,
     * deflating totals and biasing period comparisons (found 2026-07-06).
     */
    private function statsWindowEnd(string $websiteId): Carbon
    {
        return $this->lastSafeReportDate($websiteId)
            ?? Carbon::yesterday(config('app.timezone'));
    }

    /**
     * Most recent date with Analytics data for this website. Unlike GSC,
     * GA4 finalises within hours, so there's no multi-day lag floor —
     * the latest stored day is reportable. Returns null when there are
     * no GA rows yet (fresh / GA-not-connected site).
     */
    public function lastSafeAnalyticsDate(string $websiteId, ?string $timezone = null): ?Carbon
    {
        $tz = $timezone ?? config('app.timezone');

        $last = AnalyticsData::query()
            ->where('website_id', $websiteId)
            ->max('date');

        return $last ? Carbon::parse($last, $tz)->startOfDay() : null;
    }

    /**
     * Per-source report readiness for a website, driven by which data is
     * actually present (not merely which source is "connected"). Lets
     * degraded sites (GA-only or GSC-only) still receive a report anchored
     * to whichever source has usable data. `any` is false only when
     * neither source has reportable data — the one case where we skip the
     * send entirely (matching the historical GSC-only behavior, now
     * widened to GA).
     *
     * @return array{ga: bool, gsc: bool, any: bool, date: ?Carbon}
     */
    public function reportReadiness(Website $website, ?string $timezone = null): array
    {
        $gscDate = $this->lastSafeReportDate($website->id, null, $timezone);
        $gaDate = $this->lastSafeAnalyticsDate($website->id, $timezone);

        // Prefer the GSC date (the historical anchor + lag-aware) when
        // present; otherwise fall back to the latest GA day.
        $date = $gscDate ?? $gaDate;

        return [
            'ga' => $gaDate !== null,
            'gsc' => $gscDate !== null,
            'any' => $date !== null,
            'date' => $date,
        ];
    }

    /**
     * Technical-SEO / Site-Health section for growth reports, sourced from the
     * latest crawl. Returns an empty array when the site hasn't been crawled.
     *
     * @return array<string,mixed>
     */
    public function siteHealthSection(string $websiteId): array
    {
        $crawl = app(\App\Services\Crawler\CrawlReportService::class);
        $summary = $crawl->summary($websiteId);
        if (! $summary['has_crawl']) {
            return [];
        }

        return [
            'health_score' => $summary['health_score'],
            'last_crawled_at' => $summary['last_crawled_at'],
            'blocked' => $summary['blocked'],
            'pages_total' => $summary['pages_total'],
            'orphan_count' => $summary['orphan_count'],
            'findings' => $summary['findings'],
            'groups' => $crawl->actionGroups($websiteId),
        ];
    }

    public function generate(string $websiteId, string $startDate, string $endDate, ?string $country = null): array
    {
        $website = Website::query()->find($websiteId);
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();
        $days = $start->diffInDays($end) + 1;

        $prevEnd = $start->copy()->subDay()->endOfDay();
        $prevStart = $prevEnd->copy()->subDays($days - 1)->startOfDay();

        $periodLabel = $this->periodLabel($days);
        $currentLabel = $this->currentPeriodLabel($days);
        $previousLabel = $this->previousPeriodLabel($days);
        $country = $this->normalizeCountry($country);

        return [
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'prev_start' => $prevStart->toDateString(),
                'prev_end' => $prevEnd->toDateString(),
                'days' => $days,
                'label' => $periodLabel,
                'current_label' => $currentLabel,
                'previous_label' => $previousLabel,
                'country' => $country,
            ],
            // Which data sources are connected, so views can render real
            // sections vs "connect this source to unlock" placeholders.
            'sources' => [
                'ga' => $website?->hasGa() ?? false,
                'gsc' => $website?->hasGsc() ?? false,
            ],
            'analytics' => $this->buildAnalytics($websiteId, $start, $end, $prevStart, $prevEnd),
            'search_console' => $this->buildSearchConsole($websiteId, $start, $end, $prevStart, $prevEnd, $country),
            'backlinks' => $this->buildBacklinks($websiteId, $start, $end, $prevStart, $prevEnd),
            'indexing' => $this->buildIndexing($websiteId),
            'ppc_equivalent' => $this->buildPpcEquivalent($websiteId, $start, $end, $country),
        ];
    }

    /**
     * Sum of projected monthly organic value across all GSC queries in the
     * report window for which we have cached Keywords Everywhere metrics.
     * Returns {value, keywords} or null if fewer than 10 queries were priced
     * (small samples underestimate and mislead).
     *
     * @return array{value: float, keywords: int}|null
     */
    private function buildPpcEquivalent(string $websiteId, Carbon $start, Carbon $end, ?string $country): ?array
    {
        $rows = SearchConsoleData::query()
            ->where('website_id', $websiteId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('query', '!=', '')
            ->when($country, fn ($q, $c) => $q->where('country', $c))
            ->selectRaw('query, SUM(impressions) as impressions, AVG(position) as avg_position')
            ->groupBy('query')
            ->havingRaw('SUM(impressions) >= ?', [50])
            ->orderByDesc('impressions')
            ->limit(1000)
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $hashes = $rows->map(fn ($r) => KeywordMetric::hashKeyword((string) $r->query))->unique()->all();
        $metrics = KeywordMetric::query()
            ->whereIn('keyword_hash', $hashes)
            ->where('country', 'global')
            ->get()
            ->keyBy('keyword_hash');

        $sum = 0.0;
        $count = 0;
        foreach ($rows as $r) {
            $hit = $metrics[KeywordMetric::hashKeyword((string) $r->query)] ?? null;
            if (! $hit || $hit->cpc === null || $hit->search_volume === null) {
                continue;
            }
            $val = \App\Services\KeywordValueCalculator::projectedMonthlyValue(
                $hit->search_volume,
                (float) $r->avg_position,
                $hit->cpc,
            );
            if ($val !== null) {
                $sum += $val;
                $count++;
            }
        }

        if ($count < 10) {
            return null;
        }

        return ['value' => round($sum, 2), 'keywords' => $count];
    }

    private function buildAnalytics(string $websiteId, Carbon $start, Carbon $end, Carbon $prevStart, Carbon $prevEnd): array
    {
        $current = AnalyticsData::where('website_id', $websiteId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()]);

        $previous = AnalyticsData::where('website_id', $websiteId)
            ->whereBetween('date', [$prevStart->toDateString(), $prevEnd->toDateString()]);

        $curUsers = (int) (clone $current)->sum('users');
        $prevUsers = (int) (clone $previous)->sum('users');

        $curSessions = (int) (clone $current)->sum('sessions');
        $prevSessions = (int) (clone $previous)->sum('sessions');

        $curBounceCount = (clone $current)->count();
        $curBounceRate = $curBounceCount > 0
            ? round((float) (clone $current)->avg('bounce_rate'), 2)
            : 0.0;

        $prevBounceCount = (clone $previous)->count();
        $prevBounceRate = $prevBounceCount > 0
            ? round((float) (clone $previous)->avg('bounce_rate'), 2)
            : 0.0;

        $topSources = AnalyticsData::where('website_id', $websiteId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('source, SUM(users) as total_users, SUM(sessions) as total_sessions')
            ->groupBy('source')
            ->orderByDesc('total_users')
            ->limit(10)
            ->get()
            ->map(function ($row) use ($websiteId, $prevStart, $prevEnd) {
                $prevUsers = (int) AnalyticsData::where('website_id', $websiteId)
                    ->whereBetween('date', [$prevStart->toDateString(), $prevEnd->toDateString()])
                    ->where('source', $row->source)
                    ->sum('users');

                return [
                    'source' => $row->source ?: '(direct)',
                    'users' => (int) $row->total_users,
                    'sessions' => (int) $row->total_sessions,
                    'prev_users' => $prevUsers,
                    'change' => $this->calcChange((int) $row->total_users, $prevUsers, true),
                ];
            })
            ->toArray();

        $sourceMovers = $this->buildSourceMovers($websiteId, $start, $end, $prevStart, $prevEnd);
        $sessionsPerUserCurrent = $curUsers > 0 ? round($curSessions / $curUsers, 2) : 0.0;
        $sessionsPerUserPrevious = $prevUsers > 0 ? round($prevSessions / $prevUsers, 2) : 0.0;
        $top3Users = collect($topSources)->take(3)->sum('users');
        $sourceConcentration = $curUsers > 0 ? round(($top3Users / $curUsers) * 100, 1) : 0.0;

        return [
            'users' => $this->calcChange($curUsers, $prevUsers, true),
            'sessions' => $this->calcChange($curSessions, $prevSessions, true),
            'bounce_rate' => $this->calcChange($curBounceRate, $prevBounceRate, false),
            'top_sources' => $topSources,
            'sessions_per_user' => $this->calcChange($sessionsPerUserCurrent, $sessionsPerUserPrevious, true),
            'source_concentration_top3' => $sourceConcentration,
            'top_source_gainers' => $sourceMovers['gainers'],
            'top_source_losers' => $sourceMovers['losers'],
        ];
    }

    private function buildSearchConsole(string $websiteId, Carbon $start, Carbon $end, Carbon $prevStart, Carbon $prevEnd, ?string $country = null): array
    {
        $current = SearchConsoleData::where('website_id', $websiteId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->when($country, fn ($q, $c) => $q->where('country', $c));

        $previous = SearchConsoleData::where('website_id', $websiteId)
            ->whereBetween('date', [$prevStart->toDateString(), $prevEnd->toDateString()])
            ->when($country, fn ($q, $c) => $q->where('country', $c));

        $curClicks = (int) (clone $current)->sum('clicks');
        $prevClicks = (int) (clone $previous)->sum('clicks');

        $curImpressions = (int) (clone $current)->sum('impressions');
        $prevImpressions = (int) (clone $previous)->sum('impressions');

        $curPosCount = (clone $current)->count();
        $curPosition = $curPosCount > 0
            ? round((float) (clone $current)->avg('position'), 1)
            : 0.0;

        $prevPosCount = (clone $previous)->count();
        $prevPosition = $prevPosCount > 0
            ? round((float) (clone $previous)->avg('position'), 1)
            : 0.0;

        $curCtr = $curImpressions > 0
            ? round($curClicks / $curImpressions * 100, 2)
            : 0.0;
        $prevCtr = $prevImpressions > 0
            ? round($prevClicks / $prevImpressions * 100, 2)
            : 0.0;

        $topQueries = SearchConsoleData::where('website_id', $websiteId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->when($country, fn ($q, $c) => $q->where('country', $c))
            ->selectRaw('query, SUM(clicks) as total_clicks, SUM(impressions) as total_impressions, AVG(position) as avg_position, AVG(ctr) as avg_ctr')
            ->groupBy('query')
            ->orderByDesc('total_clicks')
            ->limit(10)
            ->get()
            ->map(function ($row) use ($websiteId, $prevStart, $prevEnd, $country) {
                $prevClicks = (int) SearchConsoleData::where('website_id', $websiteId)
                    ->whereBetween('date', [$prevStart->toDateString(), $prevEnd->toDateString()])
                    ->where('query', $row->query)
                    ->when($country, fn ($q, $c) => $q->where('country', $c))
                    ->sum('clicks');

                return [
                    'query' => $row->query,
                    'clicks' => (int) $row->total_clicks,
                    'impressions' => (int) $row->total_impressions,
                    'position' => round((float) $row->avg_position, 1),
                    'ctr' => round((float) $row->avg_ctr * 100, 2),
                    'prev_clicks' => $prevClicks,
                    'change' => $this->calcChange((int) $row->total_clicks, $prevClicks, true),
                ];
            })
            ->toArray();

        $topPages = SearchConsoleData::where('website_id', $websiteId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->when($country, fn ($q, $c) => $q->where('country', $c))
            ->selectRaw('page, SUM(clicks) as total_clicks, SUM(impressions) as total_impressions, AVG(ctr) as avg_ctr')
            ->groupBy('page')
            ->orderByDesc('total_clicks')
            ->limit(10)
            ->get()
            ->map(function ($row) use ($websiteId, $prevStart, $prevEnd, $country) {
                $prevClicks = (int) SearchConsoleData::where('website_id', $websiteId)
                    ->whereBetween('date', [$prevStart->toDateString(), $prevEnd->toDateString()])
                    ->where('page', $row->page)
                    ->when($country, fn ($q, $c) => $q->where('country', $c))
                    ->sum('clicks');

                return [
                    'page' => $row->page,
                    'clicks' => (int) $row->total_clicks,
                    'impressions' => (int) $row->total_impressions,
                    'ctr' => round((float) $row->avg_ctr * 100, 2),
                    'prev_clicks' => $prevClicks,
                    'change' => $this->calcChange((int) $row->total_clicks, $prevClicks, true),
                ];
            })
            ->toArray();

        $devices = SearchConsoleData::where('website_id', $websiteId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('device', '!=', '')
            ->when($country, fn ($q, $c) => $q->where('country', $c))
            ->selectRaw('device, SUM(clicks) as total_clicks')
            ->groupBy('device')
            ->orderByDesc('total_clicks')
            ->get()
            ->map(function ($row) use ($websiteId, $prevStart, $prevEnd, $curClicks, $country) {
                $prevDevClicks = (int) SearchConsoleData::where('website_id', $websiteId)
                    ->whereBetween('date', [$prevStart->toDateString(), $prevEnd->toDateString()])
                    ->where('device', $row->device)
                    ->when($country, fn ($q, $c) => $q->where('country', $c))
                    ->sum('clicks');

                return [
                    'device' => $row->device ?: 'Unknown',
                    'clicks' => (int) $row->total_clicks,
                    'percentage' => $curClicks > 0 ? round((int) $row->total_clicks / $curClicks * 100, 1) : 0,
                    'prev_clicks' => $prevDevClicks,
                    'change' => $this->calcChange((int) $row->total_clicks, $prevDevClicks, true),
                ];
            })
            ->toArray();

        $countries = SearchConsoleData::where('website_id', $websiteId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('country', '!=', '')
            ->selectRaw('country, SUM(clicks) as total_clicks, SUM(impressions) as total_impressions')
            ->groupBy('country')
            ->orderByDesc('total_clicks')
            ->limit(10)
            ->get()
            ->map(function ($row) use ($websiteId, $prevStart, $prevEnd) {
                $prevClicks = (int) SearchConsoleData::where('website_id', $websiteId)
                    ->whereBetween('date', [$prevStart->toDateString(), $prevEnd->toDateString()])
                    ->where('country', $row->country)
                    ->sum('clicks');

                return [
                    'country' => $row->country,
                    'clicks' => (int) $row->total_clicks,
                    'impressions' => (int) $row->total_impressions,
                    'prev_clicks' => $prevClicks,
                    'change' => $this->calcChange((int) $row->total_clicks, $prevClicks, true),
                ];
            })
            ->toArray();

        $queryMovers = $this->buildQueryMovers($websiteId, $start, $end, $prevStart, $prevEnd, $country);
        $pageMovers = $this->buildPageMovers($websiteId, $start, $end, $prevStart, $prevEnd, $country);
        $positionBuckets = $this->buildPositionBuckets($websiteId, $start, $end, $country);
        $opportunities = $this->buildOpportunities($websiteId, $start, $end, $country);

        return [
            'clicks' => $this->calcChange($curClicks, $prevClicks, true),
            'impressions' => $this->calcChange($curImpressions, $prevImpressions, true),
            'position' => $this->calcChange($curPosition, $prevPosition, false),
            'ctr' => $this->calcChange($curCtr, $prevCtr, true),
            'top_queries' => $topQueries,
            'top_pages' => $topPages,
            'devices' => $devices,
            'countries' => $countries,
            'top_query_gainers' => $queryMovers['gainers'],
            'top_query_losers' => $queryMovers['losers'],
            'top_page_gainers' => $pageMovers['gainers'],
            'top_page_losers' => $pageMovers['losers'],
            'position_buckets' => $positionBuckets,
            'opportunities' => $opportunities,
        ];
    }

    private function buildBacklinks(string $websiteId, Carbon $start, Carbon $end, Carbon $prevStart, Carbon $prevEnd): array
    {
        $current = Backlink::where('website_id', $websiteId)
            ->whereBetween('tracked_date', [$start->toDateString(), $end->toDateString()]);

        $previous = Backlink::where('website_id', $websiteId)
            ->whereBetween('tracked_date', [$prevStart->toDateString(), $prevEnd->toDateString()]);

        $curCount = (clone $current)->count();
        $prevCount = (clone $previous)->count();

        $curDa = $curCount > 0 ? round((float) (clone $current)->avg('domain_authority'), 1) : 0;
        $prevDa = $prevCount > 0 ? round((float) (clone $previous)->avg('domain_authority'), 1) : 0;

        $curDofollow = (clone $current)->where('is_dofollow', true)->count();
        $prevDofollow = (clone $previous)->where('is_dofollow', true)->count();

        $curNofollow = $curCount - $curDofollow;
        $prevNofollow = $prevCount - $prevDofollow;

        $topBacklinks = Backlink::where('website_id', $websiteId)
            ->whereBetween('tracked_date', [$start->toDateString(), $end->toDateString()])
            ->orderByDesc('domain_authority')
            ->orderBy('referring_page_url')
            ->limit(20)
            ->get()
            ->map(fn ($b) => [
                'referring_page_url' => $b->referring_page_url,
                'target_page_url' => $b->target_page_url,
                'domain_authority' => $b->domain_authority,
                'spam_score' => $b->spam_score,
                'anchor_text' => $b->anchor_text,
                'type' => $b->type->label(),
                'is_dofollow' => $b->is_dofollow,
            ])
            ->toArray();

        return [
            'count' => $this->calcChange($curCount, $prevCount, true),
            'avg_da' => $this->calcChange($curDa, $prevDa, true),
            'dofollow' => $this->calcChange($curDofollow, $prevDofollow, true),
            'nofollow' => $this->calcChange($curNofollow, $prevNofollow, true),
            'top_backlinks' => $topBacklinks,
        ];
    }

    private function buildIndexing(string $websiteId): array
    {
        $rows = PageIndexingStatus::query()
            ->where('website_id', $websiteId)
            ->whereNotNull('last_google_status_checked_at')
            ->orderByDesc('last_google_status_checked_at')
            ->limit(10)
            ->get();

        $totalTracked = PageIndexingStatus::query()
            ->where('website_id', $websiteId)
            ->count();

        $checkedCount = PageIndexingStatus::query()
            ->where('website_id', $websiteId)
            ->whereNotNull('last_google_status_checked_at')
            ->count();

        $passCount = PageIndexingStatus::query()
            ->where('website_id', $websiteId)
            ->where('google_verdict', 'PASS')
            ->count();

        $failCount = PageIndexingStatus::query()
            ->where('website_id', $websiteId)
            ->where('google_verdict', 'FAIL')
            ->count();

        return [
            'summary' => [
                'tracked_pages' => $totalTracked,
                'checked_pages' => $checkedCount,
                'pass_pages' => $passCount,
                'fail_pages' => $failCount,
                'last_checked_at' => $rows->first()?->last_google_status_checked_at?->toDateTimeString(),
            ],
            'latest' => $rows->map(fn (PageIndexingStatus $row) => [
                'page' => $row->page,
                'verdict' => $row->google_verdict ?: 'UNKNOWN',
                'coverage_state' => $row->google_coverage_state ?: 'Unknown',
                'indexing_state' => $row->google_indexing_state ?: 'Unknown',
                'last_crawl_at' => $row->google_last_crawl_at?->toDateTimeString(),
                'checked_at' => $row->last_google_status_checked_at?->toDateTimeString(),
            ])->toArray(),
        ];
    }

    /**
     * @param bool $upIsPositive Whether an increase in this metric is good
     */
    private function calcChange(float|int $current, float|int $previous, bool $upIsPositive): array
    {
        $change = $current - $previous;
        $changePercent = $previous != 0
            ? round(($change / abs($previous)) * 100, 1)
            : ($current != 0 ? null : 0.0);

        if (abs($change) < 0.001) {
            $direction = 'flat';
        } else {
            $direction = $change > 0 ? 'up' : 'down';
        }

        $isPositive = match ($direction) {
            'up' => $upIsPositive,
            'down' => ! $upIsPositive,
            default => true,
        };

        return [
            'current' => $current,
            'previous' => $previous,
            'change' => round($change, 2),
            'change_percent' => $changePercent,
            'direction' => $direction,
            'is_positive' => $isPositive,
        ];
    }

    private function periodLabel(int $days): string
    {
        return match (true) {
            $days === 1 => 'Daily',
            $days <= 7 => 'Weekly',
            $days <= 31 => 'Monthly',
            default => 'Custom',
        };
    }

    private function currentPeriodLabel(int $days): string
    {
        return match (true) {
            $days === 1 => 'Today',
            $days <= 7 => 'This Week',
            $days <= 31 => 'This Month',
            default => "Last {$days} Days",
        };
    }

    private function previousPeriodLabel(int $days): string
    {
        return match (true) {
            $days === 1 => 'Yesterday',
            $days <= 7 => 'Prev Week',
            $days <= 31 => 'Prev Month',
            default => "Preceding {$days} Days",
        };
    }

    private function buildSourceMovers(string $websiteId, Carbon $start, Carbon $end, Carbon $prevStart, Carbon $prevEnd): array
    {
        $current = AnalyticsData::where('website_id', $websiteId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('source, SUM(users) as users')
            ->groupBy('source')
            ->pluck('users', 'source');
        $previous = AnalyticsData::where('website_id', $websiteId)
            ->whereBetween('date', [$prevStart->toDateString(), $prevEnd->toDateString()])
            ->selectRaw('source, SUM(users) as users')
            ->groupBy('source')
            ->pluck('users', 'source');

        $movements = collect($current)->map(function ($users, $source) use ($previous) {
            $prev = (int) ($previous[$source] ?? 0);
            return [
                'source' => $source ?: '(direct)',
                'current' => (int) $users,
                'previous' => $prev,
                'change' => (int) $users - $prev,
            ];
        })->values();

        return [
            'gainers' => $movements->sortByDesc('change')->take(5)->values()->toArray(),
            'losers' => $movements->sortBy('change')->take(5)->values()->toArray(),
        ];
    }

    private function buildQueryMovers(string $websiteId, Carbon $start, Carbon $end, Carbon $prevStart, Carbon $prevEnd, ?string $country = null): array
    {
        $current = SearchConsoleData::where('website_id', $websiteId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->when($country, fn ($q, $c) => $q->where('country', $c))
            ->selectRaw('query, SUM(clicks) as clicks')
            ->groupBy('query')
            ->pluck('clicks', 'query');
        $previous = SearchConsoleData::where('website_id', $websiteId)
            ->whereBetween('date', [$prevStart->toDateString(), $prevEnd->toDateString()])
            ->when($country, fn ($q, $c) => $q->where('country', $c))
            ->selectRaw('query, SUM(clicks) as clicks')
            ->groupBy('query')
            ->pluck('clicks', 'query');

        $movements = collect($current)->map(function ($clicks, $query) use ($previous) {
            $prev = (int) ($previous[$query] ?? 0);
            return [
                'query' => $query,
                'current' => (int) $clicks,
                'previous' => $prev,
                'change' => (int) $clicks - $prev,
            ];
        })->values();

        return [
            'gainers' => $movements->sortByDesc('change')->take(5)->values()->toArray(),
            'losers' => $movements->sortBy('change')->take(5)->values()->toArray(),
        ];
    }

    private function buildPageMovers(string $websiteId, Carbon $start, Carbon $end, Carbon $prevStart, Carbon $prevEnd, ?string $country = null): array
    {
        $current = SearchConsoleData::where('website_id', $websiteId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->when($country, fn ($q, $c) => $q->where('country', $c))
            ->selectRaw('page, SUM(clicks) as clicks')
            ->groupBy('page')
            ->pluck('clicks', 'page');
        $previous = SearchConsoleData::where('website_id', $websiteId)
            ->whereBetween('date', [$prevStart->toDateString(), $prevEnd->toDateString()])
            ->when($country, fn ($q, $c) => $q->where('country', $c))
            ->selectRaw('page, SUM(clicks) as clicks')
            ->groupBy('page')
            ->pluck('clicks', 'page');

        $movements = collect($current)->map(function ($clicks, $page) use ($previous) {
            $prev = (int) ($previous[$page] ?? 0);
            return [
                'page' => $page,
                'current' => (int) $clicks,
                'previous' => $prev,
                'change' => (int) $clicks - $prev,
            ];
        })->values();

        return [
            'gainers' => $movements->sortByDesc('change')->take(5)->values()->toArray(),
            'losers' => $movements->sortBy('change')->take(5)->values()->toArray(),
        ];
    }

    private function buildPositionBuckets(string $websiteId, Carbon $start, Carbon $end, ?string $country = null): array
    {
        $rows = SearchConsoleData::where('website_id', $websiteId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->when($country, fn ($q, $c) => $q->where('country', $c))
            ->selectRaw('query, AVG(position) as avg_position')
            ->groupBy('query')
            ->get();

        $buckets = [
            'top_3' => 0,
            'top_10' => 0,
            'near_page_1' => 0,
            'beyond_20' => 0,
        ];

        foreach ($rows as $row) {
            $pos = (float) $row->avg_position;
            if ($pos <= 3) {
                $buckets['top_3']++;
            } elseif ($pos <= 10) {
                $buckets['top_10']++;
            } elseif ($pos <= 20) {
                $buckets['near_page_1']++;
            } else {
                $buckets['beyond_20']++;
            }
        }

        return $buckets;
    }

    private function buildOpportunities(string $websiteId, Carbon $start, Carbon $end, ?string $country = null): array
    {
        return $this->strikingDistance($websiteId, $start->toDateString(), $end->toDateString(), 8, $country);
    }

    /**
     * Queries where multiple pages from this site split clicks/impressions —
     * Google has to pick one; two competing URLs dilute each other's authority.
     *
     * @return array<int, array{query: string, primary_page: string, total_clicks: int, total_impressions: int, competing_pages: list<array{page: string, clicks: int, impressions: int, share: float, position: float}>}>
     */
    public function cannibalizationReport(string $websiteId, ?string $startDate = null, ?string $endDate = null, int $limit = 50, ?string $country = null, bool $cacheOnly = false): array
    {
        [$start, $end] = $this->resolveRange($startDate, $endDate, 28, $websiteId);

        $key = sprintf(
            'report:cannibalization:v1:%s:%s:%s:%d:%s:%d',
            $websiteId,
            $start->toDateString(),
            $end->toDateString(),
            $limit,
            $country ?? 'all',
            ReportCache::version($websiteId),
        );

        // $cacheOnly: callers that only show a secondary badge (e.g. the Keywords
        // table on high-volume sites) must not block on the heavy GROUP BY query —
        // use the cached value if present, otherwise nothing. The dashboard /
        // Insights report compute and warm it.
        if ($cacheOnly) {
            return Cache::get($key, []);
        }

        return Cache::remember(
            $key,
            now()->addHours(24),
            fn () => $this->buildCannibalizationReport($websiteId, $start, $end, $limit, $country),
        );
    }

    /**
     * @return array<int, mixed>
     */
    private function buildCannibalizationReport(string $websiteId, Carbon $start, Carbon $end, int $limit, ?string $country): array
    {
        $rows = SearchConsoleData::query()
            ->where('website_id', $websiteId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('query', '!=', '')
            ->where('page', '!=', '')
            ->when($this->normalizeCountry($country), fn ($q, $c) => $q->where('country', $c))
            ->selectRaw('query, page, SUM(clicks) as total_clicks, SUM(impressions) as total_impressions, AVG(position) as avg_position')
            ->groupBy('query', 'page')
            ->get();

        $grouped = $rows->groupBy('query');
        $out = [];

        foreach ($grouped as $query => $pageRows) {
            if ($pageRows->count() < 2) {
                continue;
            }
            $pages = $pageRows
                ->map(fn ($r) => [
                    'page' => (string) $r->page,
                    'clicks' => (int) $r->total_clicks,
                    'impressions' => (int) $r->total_impressions,
                    'position' => round((float) $r->avg_position, 1),
                ])
                ->sortByDesc('clicks')
                ->values();

            $totalClicks = (int) $pages->sum('clicks');
            $totalImpressions = (int) $pages->sum('impressions');

            if ($totalImpressions < 100) {
                continue;
            }

            $primary = $pages->first();
            $primaryShare = $totalClicks > 0 ? ($primary['clicks'] / $totalClicks) * 100 : 0.0;
            if ($totalClicks > 0 && $primaryShare >= 90.0) {
                continue;
            }

            $competing = $pages->slice(1)->map(fn (array $p) => $p + [
                'share' => $totalClicks > 0 ? round(($p['clicks'] / $totalClicks) * 100, 1) : 0.0,
            ])->values()->toArray();

            if (empty($competing)) {
                continue;
            }

            $out[] = [
                'query' => (string) $query,
                'primary_page' => $primary['page'],
                'total_clicks' => $totalClicks,
                'total_impressions' => $totalImpressions,
                'page_count' => $pages->count(),
                'competing_pages' => $competing,
            ];
        }

        usort($out, fn ($a, $b) => $b['total_impressions'] <=> $a['total_impressions']);

        return $this->attachKeywordMetrics(array_slice($out, 0, $limit), 'query');
    }

    /**
     * Queries ranking 5–20 with high impressions and below-curve CTR — the single
     * highest-ROI SEO optimization target list.
     *
     * @return array<int, array{query: string, impressions: int, clicks: int, ctr: float, position: float, score: float}>
     */
    public function strikingDistance(string $websiteId, ?string $startDate = null, ?string $endDate = null, int $limit = 50, ?string $country = null): array
    {
        [$start, $end] = $this->resolveRange($startDate, $endDate, 28, $websiteId);
        $country = $this->normalizeCountry($country);

        return Cache::remember(
            // v2: row shape gained `page` + `page_position` (ranking URL).
            sprintf(
                'report:strikingDistance:v2:%s:%s:%s:%d:%s:%d',
                $websiteId,
                $start->toDateString(),
                $end->toDateString(),
                $limit,
                $country ?? 'all',
                ReportCache::version($websiteId),
            ),
            now()->addHours(24),
            fn () => $this->buildStrikingDistance($websiteId, $start, $end, $limit, $country),
        );
    }

    /**
     * @return array<int, mixed>
     */
    private function buildStrikingDistance(string $websiteId, Carbon $start, Carbon $end, int $limit, ?string $country): array
    {
        // Group by (query, page) so we can surface the ranking URL for each
        // query, then aggregate the per-page rows up to one row per query.
        $list = SearchConsoleData::query()
            ->where('website_id', $websiteId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('query', '!=', '')
            ->where('page', '!=', '')
            ->when($country, fn ($q, $c) => $q->where('country', $c))
            ->selectRaw('query, page, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as avg_position')
            ->groupBy('query', 'page')
            ->get()
            ->groupBy('query')
            ->map(function ($pageRows, $query) {
                $impressions = (int) $pageRows->sum('impressions');
                $clicks = (int) $pageRows->sum('clicks');
                $ctr = $impressions > 0 ? ($clicks / $impressions) * 100 : 0.0;
                // Impression-weighted average position across the query's pages.
                $position = round(
                    $pageRows->reduce(fn ($c, $r) => $c + ((float) $r->avg_position * (int) $r->impressions), 0.0)
                        / max(1, $impressions),
                    1
                );
                if ($impressions < 200 || $position < 5 || $position > 20) {
                    return null;
                }
                // The ranking URL = the page with the most impressions for this query.
                $top = $pageRows->sortByDesc('impressions')->first();
                $score = round(($impressions / 100) + (20 - $position) - ($ctr * 0.6), 1);
                return [
                    'query' => (string) $query,
                    'page' => (string) $top->page,
                    'page_position' => round((float) $top->avg_position, 1),
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'ctr' => round($ctr, 2),
                    'position' => $position,
                    'score' => $score,
                ];
            })
            ->filter()
            ->values()
            ->toArray();

        $enriched = $this->attachKeywordMetrics($list, 'query');

        // Rows with a real upside_value sort first (by $ desc), rows that
        // can't be valued yet fall back to the legacy impression-based score.
        usort($enriched, function (array $a, array $b): int {
            $au = $a['upside_value'];
            $bu = $b['upside_value'];
            if ($au !== null && $bu !== null) {
                return $bu <=> $au;
            }
            if ($au !== null) {
                return -1;
            }
            if ($bu !== null) {
                return 1;
            }
            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });

        return array_slice($enriched, 0, $limit);
    }

    /**
     * Attach cached Keywords Everywhere metrics (search_volume, cpc, competition)
     * onto a list of rows. Pure DB lookup — never calls the API. Rows without
     * cached metrics get null fields so UI rendering is uniform.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function attachKeywordMetrics(array $rows, string $queryKey): array
    {
        if ($rows === []) {
            return $rows;
        }

        $keywords = [];
        foreach ($rows as $r) {
            $kw = isset($r[$queryKey]) ? (string) $r[$queryKey] : '';
            if ($kw !== '') {
                $keywords[] = $kw;
            }
        }
        if ($keywords === []) {
            return $rows;
        }

        $metrics = \App\Models\KeywordMetric::query()
            ->whereIn('keyword_hash', array_unique(array_map(fn ($k) => \App\Models\KeywordMetric::hashKeyword($k), $keywords)))
            ->where('country', 'global')
            ->get()
            ->keyBy('keyword_hash');

        $detector = app(\App\Services\LanguageDetectorService::class);

        foreach ($rows as $i => $r) {
            $kw = isset($r[$queryKey]) ? (string) $r[$queryKey] : '';
            $hit = $kw !== '' ? ($metrics[\App\Models\KeywordMetric::hashKeyword($kw)] ?? null) : null;
            $rows[$i]['language'] = $kw !== '' ? $detector->detect($kw) : null;
            $rows[$i]['search_volume'] = $hit?->search_volume;
            $rows[$i]['cpc'] = $hit?->cpc;
            $rows[$i]['cpc_currency'] = $hit?->currency;
            $rows[$i]['competition'] = $hit?->competition;
            $rows[$i]['trend_class'] = $hit ? \App\Services\KeywordValueCalculator::trendClassify($hit->trend_12m) : 'unknown';

            // Projected current value (what this row is worth to the site today)
            // and upside value (what it would gain from moving to position 3).
            $position = isset($r['position']) && is_numeric($r['position']) ? (float) $r['position'] : null;
            $rows[$i]['projected_value'] = \App\Services\KeywordValueCalculator::projectedMonthlyValue(
                $hit?->search_volume,
                $position,
                $hit?->cpc,
            );
            $rows[$i]['upside_value'] = \App\Services\KeywordValueCalculator::upsideValue(
                $hit?->search_volume,
                $position,
                3,
                $hit?->cpc,
            );
            // "Addressable" value = the whole market at position 1. For
            // cannibalization rows this is what the split is costing in total;
            // for striking-distance it doubles as the ceiling.
            $rows[$i]['addressable_value'] = \App\Services\KeywordValueCalculator::projectedMonthlyValue(
                $hit?->search_volume,
                1.0,
                $hit?->cpc,
            );
        }

        return $rows;
    }

    /**
     * Per-page 28d vs prior-28d, and vs same 28d last year. Surfaces pages with
     * sustained decline that still get impressions (recoverable). Left-joins
     * indexing status so decay vs. de-indexing is distinguishable.
     *
     * @return array{pages: array<int, array<string, mixed>>, has_yoy_history: bool}
     */
    public function contentDecay(string $websiteId, int $limit = 25, ?string $country = null): array
    {
        $country = $this->normalizeCountry($country);

        return Cache::remember(
            sprintf(
                'report:contentDecay:v1:%s:%d:%s:%s:%d',
                $websiteId,
                $limit,
                $country ?? 'all',
                Carbon::yesterday(config('app.timezone'))->toDateString(),
                ReportCache::version($websiteId),
            ),
            now()->addHours(24),
            fn () => $this->buildContentDecay($websiteId, $limit, $country),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContentDecay(string $websiteId, int $limit, ?string $country): array
    {
        $tz = config('app.timezone');
        $end = $this->statsWindowEnd($websiteId)->endOfDay();
        $start = $end->copy()->subDays(27)->startOfDay();
        $prevEnd = $start->copy()->subDay()->endOfDay();
        $prevStart = $prevEnd->copy()->subDays(27)->startOfDay();
        $yoyEnd = $end->copy()->subYear()->endOfDay();
        $yoyStart = $start->copy()->subYear()->startOfDay();

        $earliest = SearchConsoleData::query()
            ->where('website_id', $websiteId)
            ->when($country, fn ($q, $c) => $q->where('country', $c))
            ->min('date');
        $hasYoy = $earliest && Carbon::parse($earliest)->lte($yoyStart);

        $current = SearchConsoleData::query()
            ->where('website_id', $websiteId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('page', '!=', '')
            ->when($country, fn ($q, $c) => $q->where('country', $c))
            ->selectRaw('page, SUM(clicks) as clicks, SUM(impressions) as impressions')
            ->groupBy('page')
            ->get()
            ->keyBy('page');

        $previous = SearchConsoleData::query()
            ->where('website_id', $websiteId)
            ->whereBetween('date', [$prevStart->toDateString(), $prevEnd->toDateString()])
            ->whereIn('page', $current->keys()->all())
            ->when($country, fn ($q, $c) => $q->where('country', $c))
            ->selectRaw('page, SUM(clicks) as clicks, SUM(impressions) as impressions')
            ->groupBy('page')
            ->get()
            ->keyBy('page');

        $yoy = $hasYoy
            ? SearchConsoleData::query()
                ->where('website_id', $websiteId)
                ->whereBetween('date', [$yoyStart->toDateString(), $yoyEnd->toDateString()])
                ->whereIn('page', $current->keys()->all())
                ->when($country, fn ($q, $c) => $q->where('country', $c))
                ->selectRaw('page, SUM(clicks) as clicks, SUM(impressions) as impressions')
                ->groupBy('page')
                ->get()
                ->keyBy('page')
            : collect();

        $indexing = PageIndexingStatus::query()
            ->where('website_id', $websiteId)
            ->whereIn('page', $current->keys()->all())
            ->get(['page', 'google_verdict', 'google_coverage_state'])
            ->keyBy('page');

        $pages = $current->map(function ($cur, string $page) use ($previous, $yoy, $indexing, $hasYoy) {
            $curClicks = (int) $cur->clicks;
            $curImpr = (int) $cur->impressions;
            $prev = $previous->get($page);
            $prevClicks = $prev ? (int) $prev->clicks : 0;
            $prevImpr = $prev ? (int) $prev->impressions : 0;
            $yoyRow = $hasYoy ? $yoy->get($page) : null;
            $yoyClicks = $yoyRow ? (int) $yoyRow->clicks : 0;

            if ($curImpr < 100) {
                return null;
            }
            $clicksDelta = $curClicks - $prevClicks;
            $clicksPct = $prevClicks > 0 ? round(($clicksDelta / $prevClicks) * 100, 1) : null;
            if ($clicksPct === null || $clicksPct > -15.0) {
                return null;
            }
            $yoyPct = ($hasYoy && $yoyClicks > 0)
                ? round((($curClicks - $yoyClicks) / $yoyClicks) * 100, 1)
                : null;

            $verdict = $indexing->get($page)?->google_verdict;

            return [
                'page' => $page,
                'current_clicks' => $curClicks,
                'previous_clicks' => $prevClicks,
                'clicks_change_percent' => $clicksPct,
                'yoy_clicks' => $yoyClicks,
                'yoy_change_percent' => $yoyPct,
                'current_impressions' => $curImpr,
                'previous_impressions' => $prevImpr,
                'verdict' => $verdict ?: null,
            ];
        })->filter()->sortBy('clicks_change_percent')->take($limit)->values()->toArray();

        $pages = $this->tagDecayReasons($websiteId, $start, $end, $pages, $country);

        return [
            'pages' => $pages,
            'has_yoy_history' => $hasYoy,
        ];
    }

    /**
     * For each decaying page, inspect the 3 top-impression GSC queries that
     * drove it over the last 28 days. If ≥2 of those queries have a cached
     * KE trend classified as 'falling', the decay is driven by shrinking
     * demand, not the page losing rankings — we tag it as `market_decline`
     * so the UI can badge it differently and the email can bucket it into
     * a "monitor, not fix" section.
     *
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<int, array<string, mixed>>
     */
    private function tagDecayReasons(string $websiteId, Carbon $start, Carbon $end, array $pages, ?string $country): array
    {
        if ($pages === []) {
            return $pages;
        }

        $pageUrls = array_values(array_filter(array_map(fn ($p) => (string) ($p['page'] ?? ''), $pages)));
        if ($pageUrls === []) {
            return $pages;
        }

        $queryRows = SearchConsoleData::query()
            ->where('website_id', $websiteId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('page', $pageUrls)
            ->where('query', '!=', '')
            ->when($country, fn ($q, $c) => $q->where('country', $c))
            ->selectRaw('page, query, SUM(impressions) as impressions')
            ->groupBy('page', 'query')
            ->get();

        $topQueriesPerPage = [];
        foreach ($queryRows->groupBy('page') as $page => $rows) {
            $topQueriesPerPage[(string) $page] = $rows
                ->sortByDesc('impressions')
                ->take(3)
                ->pluck('query')
                ->map(fn ($q) => (string) $q)
                ->all();
        }

        $allQueries = array_unique(array_merge(...array_values($topQueriesPerPage)) ?: []);
        $metricsByHash = [];
        if ($allQueries !== []) {
            $metricsByHash = KeywordMetric::query()
                ->whereIn('keyword_hash', array_map(fn ($q) => KeywordMetric::hashKeyword($q), $allQueries))
                ->where('country', 'global')
                ->get()
                ->keyBy('keyword_hash')
                ->all();
        }

        foreach ($pages as $i => $p) {
            $queries = $topQueriesPerPage[(string) ($p['page'] ?? '')] ?? [];
            $falling = 0;
            $knownTrends = 0;
            foreach ($queries as $q) {
                $hit = $metricsByHash[KeywordMetric::hashKeyword($q)] ?? null;
                if ($hit === null) {
                    continue;
                }
                $knownTrends++;
                if (\App\Services\KeywordValueCalculator::trendClassify($hit->trend_12m) === 'falling') {
                    $falling++;
                }
            }
            $pages[$i]['top_queries'] = $queries;
            $pages[$i]['decay_reason'] = ($knownTrends >= 2 && $falling >= 2) ? 'market_decline' : 'recoverable';
        }

        return $pages;
    }

    /**
     * Pages where Google's last verdict != PASS AND we've had impressions in the
     * last 14 days — urgent-action cohort.
     *
     * @return array<int, array{page: string, verdict: string, coverage_state: string, indexing_state: string, last_crawl_at: ?string, recent_clicks: int, recent_impressions: int}>
     */
    public function indexingFailsWithTraffic(string $websiteId, int $windowDays = 14, int $limit = 50, ?string $country = null): array
    {
        $country = $this->normalizeCountry($country);

        return Cache::remember(
            sprintf(
                'report:indexingFails:v1:%s:%d:%d:%s:%s:%d',
                $websiteId,
                $windowDays,
                $limit,
                $country ?? 'all',
                Carbon::yesterday(config('app.timezone'))->toDateString(),
                ReportCache::version($websiteId),
            ),
            now()->addHours(24),
            fn () => $this->buildIndexingFailsWithTraffic($websiteId, $windowDays, $limit, $country),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildIndexingFailsWithTraffic(string $websiteId, int $windowDays, int $limit, ?string $country): array
    {
        $tz = config('app.timezone');
        $end = $this->statsWindowEnd($websiteId)->endOfDay();
        $start = $end->copy()->subDays($windowDays - 1)->startOfDay();

        $failing = PageIndexingStatus::query()
            ->where('website_id', $websiteId)
            ->whereNotNull('google_verdict')
            ->where('google_verdict', '!=', 'PASS')
            ->get();

        if ($failing->isEmpty()) {
            return [];
        }

        $pages = $failing->pluck('page')->all();
        $traffic = SearchConsoleData::query()
            ->where('website_id', $websiteId)
            ->whereIn('page', $pages)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->when($country, fn ($q, $c) => $q->where('country', $c))
            ->selectRaw('page, SUM(clicks) as clicks, SUM(impressions) as impressions')
            ->groupBy('page')
            ->get()
            ->keyBy('page');

        $out = [];
        foreach ($failing as $row) {
            $t = $traffic->get($row->page);
            $impressions = $t ? (int) $t->impressions : 0;
            if ($impressions <= 0) {
                continue;
            }
            $out[] = [
                'page' => (string) $row->page,
                'verdict' => (string) $row->google_verdict,
                'coverage_state' => (string) ($row->google_coverage_state ?: 'Unknown'),
                'indexing_state' => (string) ($row->google_indexing_state ?: 'Unknown'),
                'last_crawl_at' => $row->google_last_crawl_at?->toDateTimeString(),
                'recent_clicks' => $t ? (int) $t->clicks : 0,
                'recent_impressions' => $impressions,
            ];
        }

        usort($out, fn ($a, $b) => $b['recent_impressions'] <=> $a['recent_impressions']);

        return array_slice($out, 0, $limit);
    }

    /**
     * Counts for Dashboard insight cards. Cheap enough to compute on each render.
     *
     * @return array{cannibalizations: int, striking_distance: int, indexing_fails_with_traffic: int, content_decay: int}
     */
    public function insightCounts(string $websiteId, ?string $country = null): array
    {
        $country = $this->normalizeCountry($country);

        return Cache::remember(
            sprintf('report:insightCounts:v1:%s:%s:%d', $websiteId, $country ?? 'all', ReportCache::version($websiteId)),
            now()->addHours(24),
            fn () => [
                'cannibalizations' => count($this->cannibalizationReport($websiteId, null, null, 50, $country)),
                'striking_distance' => count($this->strikingDistance($websiteId, null, null, 50, $country)),
                'indexing_fails_with_traffic' => count($this->indexingFailsWithTraffic($websiteId, 14, 50, $country)),
                'content_decay' => count($this->contentDecay($websiteId, 25, $country)['pages']),
                'quick_wins' => count($this->quickWins($websiteId, 20)),
            ],
        );
    }

    /**
     * Quick-wins radar: keywords with real search volume (≥500/mo) AND low
     * competition (≤0.4) where the site either doesn't rank or ranks below
     * position 10. Scored by the dollar upside of moving to position 3.
     *
     * Pure DB join — zero API calls. Relies on whatever KE data the site has
     * already paid for via the nightly sync.
     *
     * @return array<int, array{keyword: string, search_volume: int, cpc: ?float, competition: ?float, current_position: ?float, current_page: ?string, projected_value: ?float, upside_value: ?float, impressions: int}>
     */
    public function quickWins(string $websiteId, int $limit = 20): array
    {
        return Cache::remember(
            sprintf(
                'report:quickWins:v1:%s:%d:%s:%d',
                $websiteId,
                $limit,
                Carbon::yesterday(config('app.timezone'))->toDateString(),
                ReportCache::version($websiteId),
            ),
            now()->addHours(24),
            fn () => $this->buildQuickWins($websiteId, $limit),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildQuickWins(string $websiteId, int $limit): array
    {
        $minVolume = 500;
        $maxCompetition = 0.4;

        $candidates = KeywordMetric::query()
            ->where('country', 'global')
            ->where('search_volume', '>=', $minVolume)
            ->where(function ($q) use ($maxCompetition) {
                $q->whereNull('competition')->orWhere('competition', '<=', $maxCompetition);
            })
            ->orderByDesc('search_volume')
            ->limit(2000)
            ->get();

        if ($candidates->isEmpty()) {
            return [];
        }

        $endC = $this->statsWindowEnd($websiteId);
        $end = $endC->toDateString();
        $start = $endC->copy()->subDays(89)->toDateString();

        // Per-query aggregate over 90d: best (min) position + top page by clicks + impressions
        $gsc = SearchConsoleData::query()
            ->where('website_id', $websiteId)
            ->whereBetween('date', [$start, $end])
            ->whereIn('query', $candidates->pluck('keyword')->all())
            ->selectRaw('query, MIN(position) as best_position, SUM(impressions) as impressions')
            ->groupBy('query')
            ->get()
            ->keyBy(fn ($row) => mb_strtolower((string) $row->query));

        // Best-ranking page per query (for the "audit" deep-link)
        $bestPages = SearchConsoleData::query()
            ->where('website_id', $websiteId)
            ->whereBetween('date', [$start, $end])
            ->whereIn('query', $candidates->pluck('keyword')->all())
            ->selectRaw('query, page, SUM(clicks) as clicks')
            ->groupBy('query', 'page')
            ->get()
            ->groupBy(fn ($r) => mb_strtolower((string) $r->query))
            ->map(fn ($rows) => $rows->sortByDesc('clicks')->first()?->page)
            ->all();

        $out = [];
        foreach ($candidates as $m) {
            $keyLower = mb_strtolower((string) $m->keyword);
            $match = $gsc->get($keyLower);

            // The keyword_metrics cache is shared across every website (one
            // KE fetch serves all tenants). Without this gate, queries that
            // matter to OTHER sites' niches would surface as "quick wins"
            // here. Only suggest keywords this website has at least some
            // GSC presence on.
            if ($match === null) {
                continue;
            }

            $bestPos = (float) $match->best_position;

            // Already winning — not a quick-win.
            if ($bestPos <= 10.0) {
                continue;
            }

            $upside = KeywordValueCalculator::upsideValue(
                $m->search_volume,
                $bestPos,
                3,
                $m->cpc,
            );
            if ($upside === null || $upside <= 0.0) {
                continue;
            }

            $out[] = [
                'keyword' => (string) $m->keyword,
                'language' => app(\App\Services\LanguageDetectorService::class)->detect((string) $m->keyword),
                'search_volume' => (int) $m->search_volume,
                'cpc' => $m->cpc !== null ? (float) $m->cpc : null,
                'competition' => $m->competition !== null ? (float) $m->competition : null,
                'current_position' => $bestPos !== null ? round($bestPos, 1) : null,
                'current_page' => $bestPages[$keyLower] ?? null,
                'impressions' => $match ? (int) $match->impressions : 0,
                'projected_value' => KeywordValueCalculator::projectedMonthlyValue(
                    $m->search_volume,
                    $bestPos ?? 100.0,
                    $m->cpc,
                ),
                'upside_value' => $upside,
            ];
        }

        usort($out, fn ($a, $b) => ($b['upside_value'] ?? 0) <=> ($a['upside_value'] ?? 0));

        return array_slice($out, 0, $limit);
    }

    /**
     * Top N countries by clicks for the last 30 days vs the preceding 30 days,
     * with a delta. Used by the Dashboard's TopCountriesCard and the
     * growth-email "Top countries" section.
     *
     * @return array<int, array{country: string, clicks: int, prev_clicks: int, change: array<string, mixed>}>
     */
    public function topCountriesTrend(string $websiteId, int $limit = 10): array
    {
        return Cache::remember(
            sprintf(
                'report:topCountries:v1:%s:%d:%s:%d',
                $websiteId,
                $limit,
                Carbon::yesterday(config('app.timezone'))->toDateString(),
                ReportCache::version($websiteId),
            ),
            now()->addHours(24),
            fn () => $this->buildTopCountriesTrend($websiteId, $limit),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildTopCountriesTrend(string $websiteId, int $limit): array
    {
        $tz = config('app.timezone');
        $end = $this->statsWindowEnd($websiteId)->endOfDay();
        $start = $end->copy()->subDays(29)->startOfDay();
        $prevEnd = $start->copy()->subDay()->endOfDay();
        $prevStart = $prevEnd->copy()->subDays(29)->startOfDay();

        $current = SearchConsoleData::query()
            ->where('website_id', $websiteId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('country', '!=', '')
            ->selectRaw('country, SUM(clicks) as clicks, SUM(impressions) as impressions')
            ->groupBy('country')
            ->orderByDesc('clicks')
            ->limit($limit)
            ->get();

        if ($current->isEmpty()) {
            return [];
        }

        $countries = $current->pluck('country')->all();
        $previous = SearchConsoleData::query()
            ->where('website_id', $websiteId)
            ->whereBetween('date', [$prevStart->toDateString(), $prevEnd->toDateString()])
            ->whereIn('country', $countries)
            ->selectRaw('country, SUM(clicks) as clicks')
            ->groupBy('country')
            ->pluck('clicks', 'country');

        return $current->map(function ($row) use ($previous) {
            $clicks = (int) $row->clicks;
            $prev = (int) ($previous[$row->country] ?? 0);

            return [
                'country' => (string) $row->country,
                'clicks' => $clicks,
                'impressions' => (int) $row->impressions,
                'prev_clicks' => $prev,
                'change' => $this->calcChange($clicks, $prev, true),
            ];
        })->values()->all();
    }

    private function normalizeCountry(?string $country): ?string
    {
        if ($country === null) {
            return null;
        }
        $country = strtoupper(trim($country));
        if ($country === '' || $country === 'ALL') {
            return null;
        }

        return $country;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveRange(?string $startDate, ?string $endDate, int $defaultDays, ?string $websiteId = null): array
    {
        // Default end anchors to the last day WITH GSC data when the caller
        // gives us a website — a bare "yesterday" window silently includes
        // 2-3 empty GSC-lag days (see statsWindowEnd docblock).
        $end = $endDate
            ? Carbon::parse($endDate)->endOfDay()
            : ($websiteId !== null
                ? $this->statsWindowEnd($websiteId)->endOfDay()
                : Carbon::yesterday(config('app.timezone'))->endOfDay());
        $start = $startDate
            ? Carbon::parse($startDate)->startOfDay()
            : $end->copy()->subDays($defaultDays - 1)->startOfDay();

        return [$start, $end];
    }
}
