<?php

namespace App\Services;

use App\Models\RankTrackingKeyword;
use App\Services\Crawler\CrawlReportService;

/**
 * Builds the dashboard "Priority Action Queue": a single, impact-ranked list of
 * the highest-value SEO actions for a website, aggregated from data that already
 * exists (GSC growth insights, page audits, and rank-tracking drops).
 *
 * The queue shows GROUPED summaries (one row per issue type). Each row drills
 * into a comprehensive slide-over via {@see issueRows()}.
 *
 * Read-only: every source method is itself cached; nothing here writes data.
 */
class ActionQueueService
{
    public const SEV_CRITICAL = 'critical';

    public const SEV_HIGH = 'high';

    public const SEV_GROWTH = 'growth';

    /** A tracked keyword that fell this many positions (or more) counts as a "drop". */
    private const RANK_DROP_THRESHOLD = 5;

    /**
     * No practical cap — show the real count and every issue. The source
     * methods default to 50, which makes any large group read as exactly "50".
     * The expensive work (grouping every row) happens before the final slice
     * and each method caches by limit, so requesting everything is cheap.
     */
    private const COUNT_LIMIT = PHP_INT_MAX;

    public function __construct(
        private ReportDataService $reports,
        private AuditPerformanceService $audit,
        private CrawlReportService $crawl,
    ) {}

    /**
     * Ranked, grouped action rows for the queue. Empty groups are dropped.
     * Sorted by severity tier (critical → high → growth), then by impact desc.
     *
     * @return array<int, array{key: string, title: string, description: string, count: int, severity: string, impact: float, impact_label: ?string, action_label: string}>
     */
    public function groupedActions(string $websiteId, ?string $country = null): array
    {
        $cannibalization = $this->reports->cannibalizationReport($websiteId, null, null, self::COUNT_LIMIT, $country);
        $striking = $this->reports->strikingDistance($websiteId, null, null, self::COUNT_LIMIT, $country);
        $decay = $this->reports->contentDecay($websiteId, self::COUNT_LIMIT, $country)['pages'] ?? [];
        $indexing = $this->reports->indexingFailsWithTraffic($websiteId, 14, self::COUNT_LIMIT, $country);
        $quickWins = $this->reports->quickWins($websiteId, self::COUNT_LIMIT);
        $auditPerf = $this->audit->underperformingPages($websiteId, 28, self::COUNT_LIMIT, $country);
        $rankDrops = $this->rankDropRows($websiteId);

        $items = [
            $this->summary('indexing_fails', 'Indexing failures with traffic',
                'Pages Google isn’t indexing that still earn impressions.',
                self::SEV_CRITICAL, count($indexing), 0.0, 'Fix'),
            $this->summary('cannibalization', 'Keyword cannibalization',
                'Multiple pages competing for the same query and splitting clicks.',
                self::SEV_HIGH, count($cannibalization), 0.0, 'View'),
            $this->summary('content_decay', 'Decaying pages',
                'Pages losing clicks fast that still earn impressions — recoverable.',
                self::SEV_HIGH, count($decay), 0.0, 'View'),
            $this->summary('rank_drops', 'Ranking drops',
                'Tracked keywords that fell 5+ positions since the last check.',
                self::SEV_HIGH, count($rankDrops), 0.0, 'View'),
            $this->summary('audit_performance', 'Slow pages costing traffic',
                'Audited pages scoring under 70 that still pull real traffic.',
                self::SEV_HIGH, count($auditPerf), 0.0, 'View'),
            $this->summary('striking_distance', 'Striking-distance keywords',
                'Ranking positions 5–20 with traffic — small pushes reach page one.',
                self::SEV_GROWTH, count($striking), 0.0, 'View'),
            $this->summary('quick_wins', 'Quick-win keywords',
                'High-volume, low-competition terms you don’t yet rank for.',
                self::SEV_GROWTH, count($quickWins), 0.0, 'View'),
        ];

        // Merge crawl-derived issues (broken links, orphans, on-page, etc.).
        $items = array_merge($items, $this->crawl->actionGroups($websiteId));

        $items = array_values(array_filter($items, fn (array $i): bool => $i['count'] > 0));

        usort($items, function (array $a, array $b): int {
            $tier = $this->tier($a['severity']) <=> $this->tier($b['severity']);

            return $tier !== 0 ? $tier : ($b['impact'] <=> $a['impact']);
        });

        return $items;
    }

    /**
     * Comprehensive, normalized detail rows for ONE issue group — loaded lazily
     * when the user opens the slide-over. Each row renders uniformly in the view.
     *
     * @return array<int, array{title: string, subtitle: string, metric: ?string, fix_url: ?string, fix_feature: string}>
     */
    public function issueRows(string $key, string $websiteId, ?string $country = null): array
    {
        return match ($key) {
            'indexing_fails' => array_map(fn (array $r): array => [
                'title' => $this->shortenUrl($r['page']),
                'subtitle' => 'Verdict: '.($r['verdict'] ?: 'Unknown').' · '.$r['coverage_state'],
                'metric' => number_format($r['recent_clicks']).' clicks · '.number_format($r['recent_impressions']).' impr (14d)',
                'fix_url' => $this->pageUrl($r['page']),
                'fix_feature' => 'pages',
                'open_url' => $r['page'] ?? null,
            ], $this->reports->indexingFailsWithTraffic($websiteId, 14, self::COUNT_LIMIT, $country)),

            'cannibalization' => array_map(fn (array $r): array => [
                'title' => $r['query'],
                'subtitle' => $r['page_count'].' pages competing · primary '.$this->shortenUrl($r['primary_page']),
                'metric' => number_format($r['total_clicks']).' clicks',
                'fix_url' => $this->pageUrl($r['primary_page']),
                'fix_feature' => 'pages',
                'open_url' => $r['primary_page'] ?? null,
            ], $this->reports->cannibalizationReport($websiteId, null, null, self::COUNT_LIMIT, $country)),

            'content_decay' => array_map(fn (array $r): array => [
                'title' => $this->shortenUrl($r['page']),
                'subtitle' => 'Clicks '.number_format($r['previous_clicks']).' → '.number_format($r['current_clicks']),
                'metric' => round((float) $r['clicks_change_percent']).'% (28d)',
                'fix_url' => $this->pageUrl($r['page']),
                'fix_feature' => 'pages',
                'open_url' => $r['page'] ?? null,
            ], $this->reports->contentDecay($websiteId, self::COUNT_LIMIT, $country)['pages'] ?? []),

            'rank_drops' => array_map(fn (array $r): array => [
                'title' => $r['keyword'],
                'subtitle' => 'Now position '.($r['current_position'] ?? '—').' · best '.($r['best_position'] ?? '—'),
                'metric' => '↓ '.abs((int) $r['position_change']).' positions',
                'fix_url' => route('rank-tracking.show', ['keywordId' => $r['id']]),
                'fix_feature' => 'rank_tracking',
            ], $this->rankDropRows($websiteId)),

            'audit_performance' => array_map(fn (array $r): array => [
                'title' => $this->shortenUrl($r['page']),
                'subtitle' => 'Performance — mobile '.($r['performance_score_mobile'] ?? '—').' · desktop '.($r['performance_score_desktop'] ?? '—'),
                'metric' => number_format($r['clicks']).' clicks · '.number_format($r['impressions']).' impr',
                'fix_url' => $this->pageUrl($r['page']),
                'fix_feature' => 'pages',
                'open_url' => $r['page'] ?? null,
            ], $this->audit->underperformingPages($websiteId, 28, self::COUNT_LIMIT, $country)),

            'striking_distance' => array_map(fn (array $r): array => [
                'title' => $r['query'],
                'subtitle' => 'Position '.$r['page_position'].' · '.$this->shortenUrl($r['page']),
                'metric' => number_format($r['impressions']).' impr',
                'fix_url' => route('keywords.fix', array_filter([
                    'keyword' => $r['query'],
                    'page' => $r['page'],
                    'country' => $country,
                ])),
                'fix_feature' => 'audits',
                'open_url' => $r['page'] ?? null,
            ], $this->reports->strikingDistance($websiteId, null, null, self::COUNT_LIMIT, $country)),

            'quick_wins' => array_map(fn (array $r): array => [
                'title' => $r['keyword'],
                'subtitle' => 'Volume '.number_format((int) $r['search_volume']).' · '.($r['current_position'] !== null ? 'position '.$r['current_position'] : 'not ranking'),
                'metric' => 'Volume '.number_format((int) $r['search_volume']),
                'fix_url' => $r['current_page'] ? $this->pageUrl($r['current_page']) : route('keywords.show', ['query' => $r['keyword']]),
                'fix_feature' => $r['current_page'] ? 'pages' : 'keywords',
                'open_url' => $r['current_page'] ?? null,
            ], $this->reports->quickWins($websiteId, self::COUNT_LIMIT)),

            default => str_starts_with($key, 'crawl_')
                ? $this->crawl->issueRows(substr($key, strlen('crawl_')), $websiteId)
                : [],
        };
    }

    /**
     * @return array<int, array{id: int, keyword: string, current_position: ?int, best_position: ?int, position_change: int}>
     */
    private function rankDropRows(string $websiteId): array
    {
        return RankTrackingKeyword::query()
            ->where('website_id', $websiteId)
            ->where('is_active', true)
            ->whereNotNull('position_change')
            ->where('position_change', '<=', -self::RANK_DROP_THRESHOLD)
            ->orderBy('position_change') // most negative (biggest drop) first
            ->get(['id', 'keyword', 'current_position', 'best_position', 'position_change'])
            ->map(fn (RankTrackingKeyword $k): array => [
                'id' => (string) $k->id,
                'keyword' => (string) $k->keyword,
                'current_position' => $k->current_position !== null ? (int) $k->current_position : null,
                'best_position' => $k->best_position !== null ? (int) $k->best_position : null,
                'position_change' => (int) $k->position_change,
            ])
            ->all();
    }

    /**
     * @return array{key: string, title: string, description: string, count: int, severity: string, impact: float, impact_label: ?string, action_label: string}
     */
    private function summary(string $key, string $title, string $description, string $severity, int $count, float $upside, string $actionLabel): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'description' => $description,
            'count' => $count,
            'severity' => $severity,
            // Dollar upside ranks groups when available; otherwise fall back to
            // the count so busier groups float up within the same severity tier.
            // Dollar-upside projections were removed from the UI 2026-07-07
            // (bucketed volumes x generic head terms produced absurd figures
            // like "$2.1M/mo"); groups rank by finding count within severity.
            'impact' => (float) $count,
            'impact_label' => null,
            'action_label' => $actionLabel,
        ];
    }

    private function sumUpside(array $rows): float
    {
        $sum = 0.0;
        foreach ($rows as $row) {
            $sum += (float) ($row['upside_value'] ?? 0);
        }

        return round($sum, 2);
    }

    private function upsideLabel(?float $value): ?string
    {
        return $value !== null && $value > 0 ? '$'.number_format($value).'/mo upside' : null;
    }

    private function tier(string $severity): int
    {
        return match ($severity) {
            self::SEV_CRITICAL => 0,
            self::SEV_HIGH => 1,
            default => 2,
        };
    }

    private function pageUrl(?string $page): ?string
    {
        return $page ? route('pages.show', ['id' => $page]) : null;
    }

    /** Trim a full URL to host + path for compact display. */
    private function shortenUrl(?string $url): string
    {
        if (! $url) {
            return '—';
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        return $path !== '' && $path !== '/' ? $path : $url;
    }
}
