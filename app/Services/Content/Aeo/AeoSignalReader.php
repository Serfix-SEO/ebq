<?php

namespace App\Services\Content\Aeo;

use App\Models\ContentAeoAudit;
use App\Models\ContentAeoBotHit;
use App\Models\Website;
use App\Support\Aeo\AiAgents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reads the two "are they coming?" signals for the AI Visibility page.
 *
 * Neither costs anything. AI referral sessions are already in `analytics_data`
 * — `SyncAnalyticsData` has been storing GA4 sessions per (website, date,
 * sessionSource) daily all along, so measuring "people arrived from ChatGPT"
 * is a WHERE clause over data we have had for months, not a new integration.
 * Bot hits come from the client's own server via our plugin or kit.
 *
 * Every method distinguishes "nothing happened" from "we cannot see" —
 * a site with no GA connected and a site with no AI visits look identical in
 * the numbers and must never look identical in the UI.
 */
class AeoSignalReader
{
    /** A reporting site that has gone quiet this long is treated as broken. */
    public const STALE_AFTER_DAYS = 3;

    /**
     * AI-referred sessions by engine over the window.
     *
     * @return array{connected: bool, total: int, engines: array<string, int>, series: list<array{date: string, sessions: int}>}
     */
    public function referrals(Website $website, int $days = 90): array
    {
        $connected = filled($website->ga_property_id);
        if (! $connected) {
            return ['connected' => false, 'total' => 0, 'engines' => [], 'series' => []];
        }

        $since = now()->subDays($days)->toDateString();
        $rows = DB::table('analytics_data')
            ->where('website_id', $website->id)
            ->where('date', '>=', $since)
            ->whereIn('source', AiAgents::referralSourceValues())
            ->select('date', 'source', DB::raw('SUM(sessions) as sessions'))
            ->groupBy('date', 'source')
            ->orderBy('date')
            ->get();

        $map = AiAgents::referralSources();
        $engines = [];
        $byDate = [];
        $total = 0;
        foreach ($rows as $row) {
            $engine = $map[strtolower((string) $row->source)] ?? (string) $row->source;
            $sessions = (int) $row->sessions;
            $engines[$engine] = ($engines[$engine] ?? 0) + $sessions;
            $byDate[(string) $row->date] = ($byDate[(string) $row->date] ?? 0) + $sessions;
            $total += $sessions;
        }
        arsort($engines);

        // Zero-fill: only days with visits come back from the query, and a line
        // drawn straight from one of those to the next silently turns a quiet
        // fortnight into a gentle slope. Every day in the window gets a point.
        $series = [];
        $cursor = now()->subDays($days)->startOfDay();
        $end = now()->startOfDay();
        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $series[] = ['date' => $key, 'sessions' => (int) ($byDate[$key] ?? 0)];
            $cursor->addDay();
        }

        return [
            'connected' => true,
            'total' => $total,
            'engines' => $engines,
            'series' => $series,
        ];
    }

    /**
     * Which AI crawlers fetched the site, and when.
     *
     * `instrumented` is the important field: false means nobody is reporting,
     * so zero rows say nothing about crawler behaviour. `stale` means a site
     * that used to report has stopped — usually a dead WordPress cron, which
     * is common enough that it needs to be visible rather than read as "the
     * bots left".
     *
     * @return array{instrumented: bool, stale: bool, last_report: ?string, total: int, bots: list<array<string, mixed>>}
     */
    public function crawlerHits(Website $website, int $days = 30): array
    {
        $since = now()->subDays($days)->toDateString();

        $everReported = ContentAeoBotHit::query()->where('website_id', $website->id)->exists();
        if (! $everReported) {
            return ['instrumented' => false, 'stale' => false, 'last_report' => null, 'total' => 0, 'bots' => []];
        }

        $lastReport = ContentAeoBotHit::query()
            ->where('website_id', $website->id)
            ->max('hit_on');

        $rows = ContentAeoBotHit::query()
            ->where('website_id', $website->id)
            ->where('hit_on', '>=', $since)
            ->select('bot', DB::raw('SUM(hits) as hits'), DB::raw('SUM(pages) as pages'), DB::raw('MAX(hit_on) as last_seen'))
            ->groupBy('bot')
            ->orderByDesc(DB::raw('SUM(hits)'))
            ->get();

        $bots = [];
        $total = 0;
        foreach ($rows as $row) {
            $agent = AiAgents::find((string) $row->bot);
            $total += (int) $row->hits;
            $bots[] = [
                'bot' => (string) $row->bot,
                'label' => AiAgents::label((string) $row->bot),
                'engine' => $agent['engine'] ?? '',
                'kind' => $agent['kind'] ?? '',
                'hits' => (int) $row->hits,
                'pages' => (int) $row->pages,
                'last_seen' => (string) $row->last_seen,
            ];
        }

        return [
            'instrumented' => true,
            'stale' => $lastReport !== null
                && Carbon::parse($lastReport)->lt(now()->subDays(self::STALE_AFTER_DAYS)->startOfDay()),
            'last_report' => $lastReport !== null ? Carbon::parse($lastReport)->toDateString() : null,
            'total' => $total,
            'bots' => $bots,
        ];
    }

    /** The most recent readiness check, or null if the site has never been audited. */
    public function latestAudit(Website $website): ?ContentAeoAudit
    {
        return ContentAeoAudit::query()
            ->where('website_id', $website->id)
            ->orderByDesc('checked_at')
            ->first();
    }

    /**
     * Agents that never fetch anything — they exist only as robots.txt opt-out
     * tokens. The crawler table must not list them as "never seen".
     *
     * @return list<string>
     */
    public function policyOnlyAgents(): array
    {
        return array_keys(array_filter(AiAgents::all(), static fn (array $a): bool => ! $a['crawls']));
    }
}
