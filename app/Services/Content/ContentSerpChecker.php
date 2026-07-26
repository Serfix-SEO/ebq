<?php

namespace App\Services\Content;

use App\Models\ContentPlan;
use App\Models\ContentTrackedKeyword;
use App\Models\Website;
use App\Services\SerperSearchClient;

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
     */
    public function check(ContentTrackedKeyword $kw): void
    {
        $website = $kw->website;
        $domain = $website?->normalized_domain;
        if ($website === null || ! $domain) {
            return;
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
            return;
        }

        [$position, $url] = $this->findDomain($json['organic'] ?? [], $domain);

        // Valid response (even if the site isn't in the top 100 → null) → stamp.
        $kw->forceFill([
            'serp_position' => $position,
            'serp_url' => $url ? mb_substr($url, 0, 600) : null,
            'serp_checked_at' => now(),
        ])->save();
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
