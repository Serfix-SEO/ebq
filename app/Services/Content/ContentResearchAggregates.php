<?php

namespace App\Services\Content;

use App\Models\Website;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The research page's expensive whole-site GSC aggregations, cached hard.
 * The striking query scans every GSC row of the last 90 days (10-20s on
 * 1.7M-row sites) — it must only ever run inside this cache, warmed daily
 * by ebq:warm-research-cache, never per interaction.
 */
class ContentResearchAggregates
{
    private const TTL = 86400; // date-scoped key → effectively "today's entry"

    /**
     * Top queries by 90-day impressions with average position — the raw
     * material for "Almost on page 1".
     *
     * @return list<array{query: string, impressions: int, position: float}>
     */
    public function strikingQueries(Website $website): array
    {
        return Cache::remember(
            'content_research:striking:v1:'.$website->id.':'.now()->toDateString(),
            self::TTL,
            fn () => DB::table('search_console_data')
                ->where('website_id', $website->id)
                ->where('date', '>=', now()->subDays(90)->toDateString())
                ->groupBy('query')
                ->havingRaw('SUM(impressions) >= 20')
                ->orderByRaw('SUM(impressions) DESC')
                ->selectRaw('query, SUM(impressions) as impressions, AVG(position) as position')
                ->limit(60)
                ->get()
                ->filter(fn ($r) => (float) $r->position >= 6.0 && (float) $r->position <= 30.0)
                ->take(10)
                ->map(fn ($r) => [
                    'query' => (string) $r->query,
                    'impressions' => (int) $r->impressions,
                    'position' => round((float) $r->position, 1),
                ])
                ->values()
                ->all(),
        );
    }
}
