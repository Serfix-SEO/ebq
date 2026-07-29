<?php

namespace App\Services\Content;

use App\Models\ContentKeywordRankHistory;
use App\Models\ContentPlan;
use App\Models\ContentTrackedKeyword;
use App\Models\Website;
use App\Services\SerperSearchClient;
use App\Support\ContentAutopilotConfig;

/**
 * Live Google SERP position for a tracked keyword, via the Serper API — the
 * "real SERP position" shown next to the GSC average. Checked weekly (see
 * CheckTrackedKeywordSerpJob); GSC lag means a fresh article has no GSC data for
 * days, so the live check is the immediate signal.
 *
 * Deliberately a thin, content-specific reader (not the RankTrackingKeyword
 * subsystem, which is tenant-connection + its own snapshot model). Same Serper
 * client + billing attribution the rank tracker uses.
 */
class ContentSerpChecker
{
    public function __construct(private SerperSearchClient $serper) {}

    /**
     * Query Serper for the keyword and record the website's organic position.
     * On a transient failure (no key / API down) it does NOT stamp, so the row
     * stays stale and is retried next run instead of being blanked for a week.
     *
     * Returns the movement against the previous recorded check when the keyword
     * IMPROVED enough to tell the client about (see isNotableGain) — the caller
     * batches those into one digest email. Null = no move worth reporting.
     *
     * @return array{keyword_id:string,keyword:string,previous:?int,current:int,gain:?int,milestone:?string}|null
     */
    public function check(ContentTrackedKeyword $kw): ?array
    {
        $website = $kw->website;
        $domain = $website?->normalized_domain;
        if ($website === null || ! $domain) {
            return null;
        }

        [$gl, $hl] = $this->locale($website);

        $json = $this->serper->query([
            'q' => $kw->keyword,
            'type' => 'organic',
            'num' => 100,
            'gl' => $gl,
            'hl' => $hl,
            '__website_id' => $website->id,
            '__owner_user_id' => $website->user_id,
            '__source' => 'content_tracker',
        ]);

        // Not an array = no API key or a hard failure → leave the row untouched
        // (still stale) so the next run retries.
        if (! is_array($json)) {
            return null;
        }

        [$position, $url] = $this->findDomain($json['organic'] ?? [], $domain);

        // Read the previous recorded position BEFORE writing today's row —
        // otherwise a same-day re-check would compare against itself.
        $previous = $this->previousPosition($kw);

        // Valid response (even if the site isn't in the top 100 → null) → stamp.
        $kw->forceFill([
            'serp_position' => $position,
            'serp_url' => $url ? mb_substr($url, 0, 600) : null,
            'serp_checked_at' => now(),
        ])->save();

        $this->recordHistory($kw, $position, $url);

        return $this->notableGain($kw, $previous, $position);
    }

    /** Latest recorded position from a day BEFORE today, or null if none. */
    private function previousPosition(ContentTrackedKeyword $kw): ?int
    {
        return ContentKeywordRankHistory::query()
            ->where('website_id', $kw->website_id)
            ->where('normalized_keyword', $kw->normalized_keyword)
            ->where('checked_on', '<', now()->toDateString())
            ->orderByDesc('checked_on')
            ->value('position');
    }

    /**
     * Is this move worth emailing the client about? A plain climb must clear
     * the noise floor (rankAlertMinGain, default 3 places), but crossing a
     * milestone — onto page 1, into the top 3, to #1, or ranking at all for the
     * first time — always counts even if it's a single place.
     *
     * @return array{keyword_id:string,keyword:string,previous:?int,current:int,gain:?int,milestone:?string}|null
     */
    private function notableGain(ContentTrackedKeyword $kw, ?int $previous, ?int $current): ?array
    {
        if ($current === null) {
            return null; // dropped out of / still outside the top 100
        }

        $milestone = match (true) {
            $current === 1 && $previous !== 1 => 'number_one',
            $current <= 3 && ($previous === null || $previous > 3) => 'top_3',
            $current <= 10 && ($previous === null || $previous > 10) => 'page_1',
            $previous === null => 'now_ranking',
            default => null,
        };

        $gain = $previous !== null ? $previous - $current : null;
        $clearsFloor = $gain !== null && $gain >= ContentAutopilotConfig::rankAlertMinGain();

        if ($milestone === null && ! $clearsFloor) {
            return null;
        }

        return [
            'keyword_id' => (string) $kw->id,
            'keyword' => (string) $kw->keyword,
            'previous' => $previous,
            'current' => $current,
            'gain' => $gain,
            'milestone' => $milestone,
        ];
    }

    /**
     * Append the check to the keyword's rank history. The row on
     * content_tracked_keywords only ever holds the LATEST position, so without
     * this the climb is unrecoverable. Keyed by (website, keyword, day) so a
     * same-day re-check corrects the point instead of duplicating it, and so
     * history survives untrack → re-track (see the migration's note).
     */
    private function recordHistory(ContentTrackedKeyword $kw, ?int $position, ?string $url): void
    {
        ContentKeywordRankHistory::query()->updateOrCreate(
            [
                'website_id' => $kw->website_id,
                'normalized_keyword' => $kw->normalized_keyword,
                'checked_on' => now()->toDateString(),
                'source' => ContentKeywordRankHistory::SOURCE_SERP,
            ],
            [
                'tracked_keyword_id' => $kw->id,
                'position' => $position,
                'url' => $url ? mb_substr($url, 0, 600) : null,
            ],
        );
    }

    /** @return array{0:?int,1:?string} [position, url] of the first result on $domain */
    private function findDomain(mixed $results, string $domain): array
    {
        if (! is_array($results)) {
            return [null, null];
        }
        foreach ($results as $idx => $row) {
            if (! is_array($row)) {
                continue;
            }
            $link = (string) ($row['link'] ?? $row['url'] ?? '');
            if ($link === '') {
                continue;
            }
            $host = $this->host($link);
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return [(int) ($row['position'] ?? ($idx + 1)), $link];
            }
        }

        return [null, null];
    }

    /** Bare lowercase host of a URL, www. stripped. */
    private function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $host = strtolower(trim($host));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /** @return array{0:?string,1:?string} [gl, hl] from the website's content plan. */
    private function locale(Website $website): array
    {
        $plan = ContentPlan::query()->where('website_id', $website->id)->first();
        $country = $plan?->country;
        $lang = (string) ($plan?->language ?? 'en');
        $gl = (is_string($country) && strlen($country) === 2) ? strtolower($country) : null;
        $hl = substr(strtolower($lang), 0, 2) ?: 'en';

        return [$gl, $hl];
    }
}
