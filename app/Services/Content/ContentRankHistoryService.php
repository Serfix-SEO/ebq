<?php

namespace App\Services\Content;

use App\Models\ContentKeywordRankHistory;
use App\Models\ContentTrackedKeyword;
use App\Models\Website;
use Illuminate\Support\Carbon;

/**
 * Read side of the Content Tracker's rank history page: the recorded live-Google
 * checks (content_keyword_rank_history, weekly) merged with the daily Search
 * Console series (via ContentPerformanceService) onto ONE calendar so both lines
 * share an x-axis.
 *
 * Two positions on purpose — they answer different questions and routinely
 * disagree: the live check is where the page ranks right now for one location,
 * Search Console is the impression-weighted average across everyone who actually
 * saw it. Neither is "wrong"; the UI labels both.
 */
class ContentRankHistoryService
{
    /** Selectable windows on the history page (days). */
    public const RANGES = [30, 90, 180, 365];

    public function __construct(private ContentPerformanceService $performance) {}

    /**
     * @return array{
     *   window: array{start:string,end:string,days:int},
     *   points: array<int,array{date:string,rank:?int,rank_checked:bool,gsc:?float,clicks:int,impressions:int}>,
     *   checks: array<int,array{date:string,position:?int,url:?string}>,
     *   stats: array{current:?int,best:?int,worst:?int,change:?int,first_checked:?string,last_checked:?string,checks:int,gsc_position:?float,clicks:int,impressions:int,top3:int,top10:int},
     *   has_rank_history: bool,
     *   has_gsc: bool
     * }
     */
    public function series(Website $website, ContentTrackedKeyword $keyword, int $days = 90): array
    {
        $days = in_array($days, self::RANGES, true) ? $days : 90;
        $end = Carbon::today(config('app.timezone'));
        $start = $end->copy()->subDays($days - 1);

        $history = ContentKeywordRankHistory::query()
            ->where('website_id', $website->id)
            ->where('normalized_keyword', $keyword->normalized_keyword)
            ->whereBetween('checked_on', [$start->toDateString(), $end->toDateString()])
            ->orderBy('checked_on')
            ->get();

        // GSC daily series for the same keyword (already lag-anchored + cached).
        $gsc = $this->performance->keywordSeries($website, $keyword->normalized_keyword, $days);
        $gscByDate = [];
        foreach ($gsc['days'] as $d) {
            $gscByDate[$d['date']] = $d;
        }

        $rankByDate = [];
        foreach ($history as $row) {
            $rankByDate[$row->checked_on->toDateString()] = $row;
        }

        $points = [];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $date = $day->toDateString();
            $check = $rankByDate[$date] ?? null;
            $g = $gscByDate[$date] ?? null;
            $points[] = [
                'date' => $date,
                // null rank on a checked day = outside the top 100; the chart
                // draws that as a break, never as position 0.
                'rank' => $check?->position,
                'rank_checked' => $check !== null,
                'gsc' => $g['position'] ?? null,
                'clicks' => (int) ($g['clicks'] ?? 0),
                'impressions' => (int) ($g['impressions'] ?? 0),
            ];
        }

        $ranked = $history->filter(fn ($r) => $r->position !== null);
        $first = $ranked->first();
        $last = $ranked->last();

        return [
            'window' => ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'days' => $days],
            'points' => $points,
            'checks' => $history->sortByDesc('checked_on')->take(30)->values()
                ->map(fn ($r) => [
                    'date' => $r->checked_on->toDateString(),
                    'position' => $r->position,
                    'url' => $r->url,
                ])->all(),
            'stats' => [
                'current' => $keyword->serp_position,
                'best' => $ranked->min('position'),
                'worst' => $ranked->max('position'),
                // Positive = moved UP the results (rank number went down).
                'change' => ($first && $last && $first->id !== $last->id)
                    ? (int) $first->position - (int) $last->position
                    : null,
                'first_checked' => $history->first()?->checked_on->toDateString(),
                'last_checked' => $history->last()?->checked_on->toDateString(),
                'checks' => $history->count(),
                'gsc_position' => $gsc['totals']['position'] ?? null,
                'clicks' => (int) ($gsc['totals']['clicks'] ?? 0),
                'impressions' => (int) ($gsc['totals']['impressions'] ?? 0),
                'top3' => $ranked->filter(fn ($r) => $r->position <= 3)->count(),
                'top10' => $ranked->filter(fn ($r) => $r->position <= 10)->count(),
            ],
            'has_rank_history' => $history->isNotEmpty(),
            'has_gsc' => (bool) ($gsc['has_data'] ?? false),
        ];
    }
}
