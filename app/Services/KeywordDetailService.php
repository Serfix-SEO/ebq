<?php

namespace App\Services;

use App\Models\KeywordMetric;
use App\Models\RankTrackingKeyword;
use App\Models\RankTrackingSnapshot;
use App\Models\SearchConsoleData;
use Illuminate\Support\Carbon;

/**
 * Every data signal we hold on a single query, scoped to one website.
 * Extracted from Livewire\Keywords\KeywordDetail so the portal detail page
 * and the WP-plugin HQ API (`GET /api/v1/hq/keyword-detail`) share one
 * implementation — the HQ API is a surface over the service layer, never a
 * parallel copy of it.
 *
 * No data from other tenants is ever surfaced — every source is either
 * scoped to $websiteId or user-owned (rank tracker keywords).
 */
class KeywordDetailService
{
    public function __construct(private KeywordMetricsService $metrics)
    {
    }

    /**
     * @return array{
     *     metric: ?KeywordMetric,
     *     gsc_totals: ?array{clicks: int, impressions: int, ctr: float, position: float, window_days: int},
     *     gsc_daily: list<array{date: string, clicks: int, impressions: int, position: float}>,
     *     top_pages: list<array{page: string, clicks: int, impressions: int, position: float, ctr: float}>,
     *     countries: list<array{country: string, clicks: int, impressions: int, position: float}>,
     *     devices: list<array{device: string, clicks: int, impressions: int, position: float}>,
     *     tracker: ?RankTrackingKeyword,
     *     tracker_latest_snapshot: ?RankTrackingSnapshot,
     *     related_searches: list<array<string, mixed>>,
     *     paa: list<array<string, mixed>>,
     *     flags: array{striking_distance: bool, cannibalized: bool, quick_win: bool},
     *     projections: array{projected_clicks: ?int}
     * }
     */
    public function signals(string $websiteId, string $query): array
    {
        $metric = $this->metrics->metricsFor($query, 'global');
        $gscTotals = $this->gscTotals($websiteId, $query);
        $topPages = $this->topPages($websiteId, $query);
        $tracker = $this->trackedKeyword($websiteId, $query);
        $snapshot = $tracker ? $this->latestSnapshot($tracker->id) : null;

        return [
            'metric' => $metric,
            'gsc_totals' => $gscTotals,
            'gsc_daily' => $this->gscDaily($websiteId, $query),
            'top_pages' => $topPages,
            'countries' => $this->countries($websiteId, $query),
            'devices' => $this->devices($websiteId, $query),
            'tracker' => $tracker,
            'tracker_latest_snapshot' => $snapshot,
            'related_searches' => $snapshot ? $this->extractSnapshotList($snapshot, 'related_searches') : [],
            'paa' => $snapshot ? $this->extractSnapshotList($snapshot, 'people_also_ask') : [],
            'flags' => $this->opportunityFlags($gscTotals, $topPages),
            'projections' => $this->projections($metric, $gscTotals),
        ];
    }

    /**
     * @return array{clicks: int, impressions: int, ctr: float, position: float, window_days: int}|null
     */
    private function gscTotals(string $websiteId, string $query): ?array
    {
        $row = SearchConsoleData::query()
            ->where('website_id', $websiteId)
            ->where('query', $query)
            ->whereDate('date', '>=', Carbon::now()->subDays(27)->toDateString())
            ->selectRaw('SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as position, AVG(ctr) as ctr')
            ->first();

        if (! $row || $row->impressions === null) {
            return null;
        }

        $impr = (int) $row->impressions;

        return [
            'clicks' => (int) $row->clicks,
            'impressions' => $impr,
            'ctr' => $impr > 0 ? round(((int) $row->clicks) / $impr * 100, 2) : 0.0,
            'position' => round((float) $row->position, 1),
            'window_days' => 28,
        ];
    }

    /**
     * Daily clicks + impressions + position for the last 90 days.
     *
     * @return list<array{date: string, clicks: int, impressions: int, position: float}>
     */
    private function gscDaily(string $websiteId, string $query): array
    {
        return SearchConsoleData::query()
            ->where('website_id', $websiteId)
            ->where('query', $query)
            ->whereDate('date', '>=', Carbon::now()->subDays(89)->toDateString())
            ->selectRaw('date, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as position')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => [
                'date' => $r->date instanceof \Carbon\CarbonInterface ? $r->date->toDateString() : (string) $r->date,
                'clicks' => (int) $r->clicks,
                'impressions' => (int) $r->impressions,
                'position' => round((float) $r->position, 1),
            ])
            ->all();
    }

    /**
     * @return list<array{page: string, clicks: int, impressions: int, position: float, ctr: float}>
     */
    private function topPages(string $websiteId, string $query): array
    {
        return SearchConsoleData::query()
            ->where('website_id', $websiteId)
            ->where('query', $query)
            ->whereDate('date', '>=', Carbon::now()->subDays(89)->toDateString())
            ->where('page', '!=', '')
            ->selectRaw('page, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as position, AVG(ctr) as ctr')
            ->groupBy('page')
            ->orderByDesc('impressions')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'page' => (string) $r->page,
                'clicks' => (int) $r->clicks,
                'impressions' => (int) $r->impressions,
                'position' => round((float) $r->position, 1),
                'ctr' => round((float) $r->ctr * 100, 2),
            ])
            ->all();
    }

    /**
     * @return list<array{country: string, clicks: int, impressions: int, position: float}>
     */
    private function countries(string $websiteId, string $query): array
    {
        return SearchConsoleData::query()
            ->where('website_id', $websiteId)
            ->where('query', $query)
            ->whereDate('date', '>=', Carbon::now()->subDays(89)->toDateString())
            ->where('country', '!=', '')
            ->selectRaw('country, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as position')
            ->groupBy('country')
            ->orderByDesc('impressions')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'country' => (string) $r->country,
                'clicks' => (int) $r->clicks,
                'impressions' => (int) $r->impressions,
                'position' => round((float) $r->position, 1),
            ])
            ->all();
    }

    /**
     * @return list<array{device: string, clicks: int, impressions: int, position: float}>
     */
    private function devices(string $websiteId, string $query): array
    {
        return SearchConsoleData::query()
            ->where('website_id', $websiteId)
            ->where('query', $query)
            ->whereDate('date', '>=', Carbon::now()->subDays(89)->toDateString())
            ->where('device', '!=', '')
            ->selectRaw('device, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as position')
            ->groupBy('device')
            ->orderByDesc('impressions')
            ->get()
            ->map(fn ($r) => [
                'device' => (string) $r->device,
                'clicks' => (int) $r->clicks,
                'impressions' => (int) $r->impressions,
                'position' => round((float) $r->position, 1),
            ])
            ->all();
    }

    private function trackedKeyword(string $websiteId, string $query): ?RankTrackingKeyword
    {
        return RankTrackingKeyword::query()
            ->where('website_id', $websiteId)
            ->where('keyword_hash', RankTrackingKeyword::hashKeyword($query))
            ->orderByDesc('id')
            ->first();
    }

    private function latestSnapshot(string $keywordId): ?RankTrackingSnapshot
    {
        return RankTrackingSnapshot::query()
            ->where('rank_tracking_keyword_id', $keywordId)
            ->orderByDesc('checked_at')
            ->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractSnapshotList(RankTrackingSnapshot $snapshot, string $attr): array
    {
        $list = $snapshot->{$attr} ?? [];
        if (! is_array($list)) {
            return [];
        }

        return array_slice(array_values(array_filter($list, 'is_array')), 0, 10);
    }

    /**
     * @param  array<string, mixed>|null  $totals
     * @param  list<array<string, mixed>>  $topPages
     * @return array{striking_distance: bool, cannibalized: bool, quick_win: bool}
     */
    private function opportunityFlags(?array $totals, array $topPages): array
    {
        $flags = ['striking_distance' => false, 'cannibalized' => false, 'quick_win' => false];

        if ($totals && $totals['impressions'] >= 200 && $totals['position'] >= 5 && $totals['position'] <= 20) {
            $flags['striking_distance'] = true;
        }

        // Cannibalized: 2+ pages with non-negligible share.
        if (count(array_filter($topPages, fn ($p) => $p['impressions'] >= 20)) >= 2) {
            $flags['cannibalized'] = true;
        }

        if ($totals && $totals['position'] > 10) {
            // Matches the quick-wins gate.
            $flags['quick_win'] = true;
        }

        return $flags;
    }

    /**
     * Dollar projections (volume x CTR x CPC) were removed from the UI
     * 2026-07-07 — bucketed volumes on generic head terms produced absurd
     * "$5.8M/mo" figures. Only the CTR-curve click projection remains.
     *
     * @param  array<string, mixed>|null  $totals
     * @return array{projected_clicks: ?int}
     */
    private function projections(?KeywordMetric $metric, ?array $totals): array
    {
        if (! $metric) {
            return ['projected_clicks' => null];
        }

        $position = $totals['position'] ?? null;

        return [
            'projected_clicks' => KeywordValueCalculator::projectedMonthlyClicks(
                $metric->search_volume,
                $position !== null ? (float) $position : null,
            ),
        ];
    }
}
