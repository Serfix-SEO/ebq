<?php

namespace App\Services;

use App\Enums\BacklinkType;
use App\Models\Backlink;
use App\Models\Website;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Pulls the website's OWN backlinks from Keywords Everywhere and upserts
 * them into the `backlinks` table. Distinct from `CompetitorBacklinkService`,
 * which targets competitor domains and writes to `competitor_backlinks`.
 *
 * Freshness
 * ─────────
 * Delegated to `BacklinkFreshnessGate`, which applies the SAME rule across
 * every code path (own / competitor / page audit / WP plugin). KE never
 * gets re-billed for a domain we already fetched in the last
 * `services.keywords_everywhere.backlinks_ttl_days` (default 30, env-tunable
 * via `KE_BACKLINKS_TTL_DAYS`).
 */
class OwnBacklinkSyncService
{
    public function __construct(
        private readonly KeywordsEverywhereBacklinkClient $client,
        private readonly BacklinkFreshnessGate $gate,
    ) {}

    /**
     * Runs the KE → Backlink upsert. No-op when the gate says the domain
     * was fetched within the TTL window. Returns the number of rows written.
     */
    public function syncForWebsite(Website $website, ?string $ownerUserId = null): int
    {
        // Paid KE backlink endpoint suppressed (services.keywords_everywhere
        // .backlinks_enabled, default off since 2026-07-14) — Site Explorer
        // (DataForSEO) is the backlink source of record now.
        if (! (bool) config('services.keywords_everywhere.backlinks_enabled', false)) {
            return 0;
        }

        $domain = $this->extractDomain((string) $website->domain);
        if ($domain === '') {
            return 0;
        }

        if ($this->gate->isFresh($domain)) {
            return 0;
        }

        $limit = (int) config('services.keywords_everywhere.own_backlinks_limit', 200);
        $limit = max(50, min(1000, $limit));

        $items = $this->client->backlinksForDomain(
            $domain,
            $limit,
            websiteId: $website->id,
            ownerUserId: $ownerUserId,
            source: 'backlinks',
        );

        // ALWAYS mark fresh — even on null/empty — so we don't retry until
        // the TTL window elapses. The gate respects this for every caller,
        // not just this service.
        $this->gate->markFetched($domain);

        if (! is_array($items) || $items === []) {
            Log::info('OwnBacklinkSyncService: KE returned no backlinks', [
                'website_id' => $website->id,
                'domain' => $domain,
            ]);

            return 0;
        }

        $today = Carbon::today();
        $written = 0;

        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }
            $referring = trim((string) ($row['url_source'] ?? ''));
            $target = trim((string) ($row['url_target'] ?? ''));
            if ($referring === '' || $target === '') {
                continue;
            }
            $anchor = isset($row['anchor_text']) && is_string($row['anchor_text'])
                ? mb_substr(trim($row['anchor_text']), 0, 250)
                : null;

            // Use updateOrCreate by (website, referring, target) so re-syncs
            // refresh `tracked_date` instead of duplicating rows.
            Backlink::query()->updateOrCreate(
                [
                    'website_id' => $website->id,
                    'referring_page_url' => $referring,
                    'target_page_url' => $target,
                ],
                [
                    'tracked_date' => $today,
                    'anchor_text' => $anchor,
                    'type' => BacklinkType::Other->value,
                    'is_dofollow' => true,
                ],
            );
            $written++;
        }

        Log::info('OwnBacklinkSyncService: synced from KE', [
            'website_id' => $website->id,
            'domain' => $domain,
            'returned' => count($items),
            'written' => $written,
        ]);

        return $written;
    }

    /**
     * Bare host, lowercased, www-stripped. KE wants a domain, not a URL.
     */
    private function extractDomain(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $forParse = str_contains($raw, '://') ? $raw : 'https://' . $raw;
        $host = parse_url($forParse, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return strtolower($raw);
        }

        return preg_replace('/^www\./', '', strtolower($host)) ?: $host;
    }
}
