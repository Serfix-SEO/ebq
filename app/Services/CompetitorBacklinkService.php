<?php

namespace App\Services;

use App\Jobs\FetchCompetitorBacklinks;
use App\Models\CompetitorBacklink;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Sole entrypoint for reading / writing competitor-backlink data. Enforces:
 *  - Never re-bill on fresh cache (30-day TTL by default).
 *  - Hard cap at `limit_per_competitor` backlinks per domain.
 *  - Cache is keyed on the normalized domain, so two audits mentioning the
 *    same competitor share one fetch.
 */
class CompetitorBacklinkService
{
    public function __construct(
        private KeywordsEverywhereBacklinkClient $client,
        private BacklinkFreshnessGate $gate,
    ) {
    }

    /**
     * Up to 50 cached backlinks for a single domain (pure DB read).
     *
     * @return Collection<int, CompetitorBacklink>
     */
    public function backlinksFor(string $domain): Collection
    {
        $domain = CompetitorBacklink::extractDomain($domain);
        if ($domain === '') {
            return CompetitorBacklink::query()->whereRaw('1=0')->get();
        }

        return CompetitorBacklink::query()
            ->forDomain($domain)
            ->orderByDesc('domain_authority')
            ->limit($this->limit())
            ->get();
    }

    /**
     * Has this domain been fetched and is the cache still fresh? Delegates
     * to the unified `BacklinkFreshnessGate` so own-domain syncs, page audits,
     * and competitor refreshes all share one definition of "fresh".
     */
    public function isFresh(string $domain): bool
    {
        $domain = CompetitorBacklink::extractDomain($domain);
        if ($domain === '') {
            return false;
        }

        return $this->gate->isFresh($domain);
    }

    /**
     * Queue a background refresh for any domain that isn't already fresh.
     * Safe to call on every audit — it no-ops for cached entries.
     *
     * `$websiteId` / `$ownerUserId` propagate to the activity log when the
     * background job ultimately calls KE, so credit usage attributes back
     * to the right billing scope.
     *
     * @param  list<string>  $domains
     */
    public function queueRefresh(array $domains, ?string $websiteId = null, ?string $ownerUserId = null): void
    {
        // Paid KE backlink endpoint suppressed (services.keywords_everywhere
        // .backlinks_enabled, default off since 2026-07-14) — consumers read
        // whatever is already cached; DA needs are served by Open PageRank.
        if (! (bool) config('services.keywords_everywhere.backlinks_enabled', false)) {
            return;
        }

        $toFetch = [];
        foreach ($domains as $d) {
            $normalized = CompetitorBacklink::extractDomain((string) $d);
            if ($normalized === '' || $this->isFresh($normalized)) {
                continue;
            }
            $toFetch[$normalized] = true;
        }

        $toFetch = array_keys($toFetch);
        if ($toFetch !== []) {
            FetchCompetitorBacklinks::dispatch($toFetch, $websiteId, $ownerUserId);
        }
    }

    /**
     * Synchronous fetch + upsert for a single domain. Returns the number of
     * rows written. Called by the job and by any CLI-driven refresh.
     */
    public function refresh(string $domain, ?string $websiteId = null, ?string $ownerUserId = null): int
    {
        // Defense in depth with queueRefresh(): a directly-dispatched job or
        // CLI call must also never hit the paid KE endpoint while suppressed.
        if (! (bool) config('services.keywords_everywhere.backlinks_enabled', false)) {
            return 0;
        }

        $domain = CompetitorBacklink::extractDomain($domain);
        if ($domain === '') {
            return 0;
        }

        // Universal freshness gate — same rule applied to every backlink
        // call site (own-domain sync, WP plugin live-score, page audit,
        // CLI). If we have rows newer than the TTL window, never re-bill KE.
        if ($this->gate->isFresh($domain)) {
            Log::info('CompetitorBacklinkService.refresh: gate fresh, skipping KE call', [
                'domain' => $domain,
                'website_id' => $websiteId,
            ]);

            return 0;
        }

        Log::info('CompetitorBacklinkService.refresh: starting', ['domain' => $domain, 'website_id' => $websiteId]);

        $items = $this->client->backlinksForDomain(
            $domain,
            $this->limit(),
            websiteId: $websiteId,
            ownerUserId: $ownerUserId,
            source: 'backlinks',
        );
        // Mark fresh as soon as KE returns — including null / empty — so the
        // gate covers 0-result cases and we don't re-bill on the next call.
        // Real rows below also satisfy isFresh() via fetched_at, but the
        // sentinel is what protects us when KE legitimately had nothing.
        $this->gate->markFetched($domain);

        if ($items === null) {
            Log::warning('CompetitorBacklinkService.refresh: client returned null', ['domain' => $domain]);

            return 0;
        }

        $freshDays = max(1, (int) config('services.competitor_backlinks.fresh_days', 30));
        $fetchedAt = Carbon::now();
        $expiresAt = $fetchedAt->copy()->addDays($freshDays);
        $limit = $this->limit();

        // Wipe any prior rows for this domain that fall outside the new top-N —
        // otherwise stale rows from an older fetch would linger forever.
        $keepHashes = [];
        $written = 0;

        foreach (array_slice($items, 0, $limit) as $item) {
            // Accept a handful of common field spellings for each attribute —
            // lets the same parser work across provider-shape variations.
            // url_source + domain_source are the Keywords Everywhere shape.
            $url = $this->firstString($item, ['url_source', 'url_from', 'page_url', 'url', 'source_url', 'referring_url']);
            if ($url === '') {
                continue;
            }

            $hash = CompetitorBacklink::hashUrl($url);
            $keepHashes[] = $hash;

            $refDomainRaw = $this->firstString($item, ['domain_source', 'domain_from', 'domain', 'referring_domain', 'source_domain']);
            $refDomain = $refDomainRaw !== '' ? strtolower($refDomainRaw) : null;

            $anchorRaw = $this->firstString($item, ['anchor_text', 'anchor', 'link_text']);
            $anchor = $anchorRaw !== '' ? mb_substr($anchorRaw, 0, 500) : null;

            $da = null;
            foreach (['domain_rating', 'domain_from_rank', 'domain_rank', 'da', 'dr', 'domain_authority'] as $k) {
                if (isset($item[$k]) && is_numeric($item[$k])) {
                    $da = min(100, max(0, (int) $item[$k]));
                    break;
                }
            }

            $type = null;
            foreach (['dofollow', 'is_dofollow'] as $k) {
                if (array_key_exists($k, $item) && is_bool($item[$k])) {
                    $type = $item[$k] ? 'dofollow' : 'nofollow';
                    break;
                }
            }
            if ($type === null) {
                $rawType = $this->firstString($item, ['backlink_type', 'rel', 'type', 'link_type']);
                if ($rawType !== '') {
                    $type = strtolower($rawType);
                    // Normalize common rel-attribute tokens.
                    if (str_contains($type, 'nofollow')) $type = 'nofollow';
                    elseif (str_contains($type, 'sponsored')) $type = 'sponsored';
                    elseif (str_contains($type, 'ugc')) $type = 'ugc';
                    elseif ($type === 'follow' || $type === '') $type = 'dofollow';
                }
            }

            $firstSeen = null;
            foreach (['first_seen', 'first_seen_at', 'discovered_at', 'date'] as $k) {
                if (isset($item[$k]) && is_string($item[$k]) && $item[$k] !== '') {
                    try {
                        $firstSeen = Carbon::parse($item[$k])->toDateString();
                        break;
                    } catch (\Throwable) {
                        // keep looking
                    }
                }
            }

            CompetitorBacklink::updateOrCreate(
                [
                    'competitor_domain' => $domain,
                    'referring_page_hash' => $hash,
                ],
                [
                    'referring_page_url' => $url,
                    'referring_domain' => $refDomain,
                    'anchor_text' => $anchor,
                    'domain_authority' => $da,
                    'backlink_type' => $type,
                    'first_seen_at' => $firstSeen,
                    'fetched_at' => $fetchedAt,
                    'expires_at' => $expiresAt,
                ]
            );
            $written++;
        }

        // Prune rows that weren't in this top-N (previous fetch had more/different links).
        if ($keepHashes !== []) {
            CompetitorBacklink::query()
                ->forDomain($domain)
                ->whereNotIn('referring_page_hash', $keepHashes)
                ->delete();
        }

        Log::info('CompetitorBacklinkService.refresh: done', [
            'domain' => $domain,
            'written' => $written,
            'capped_at' => $limit,
        ]);

        return $written;
    }

    private function limit(): int
    {
        return max(1, min(1000, (int) config('services.competitor_backlinks.limit_per_competitor', 50)));
    }

    /**
     * Pick the first non-empty string value from $item among the candidate keys.
     * Lets the parser accept multiple API shapes without branchy ifs everywhere.
     *
     * @param  array<string, mixed>  $item
     * @param  list<string>  $keys
     */
    private function firstString(array $item, array $keys): string
    {
        foreach ($keys as $k) {
            if (isset($item[$k]) && is_string($item[$k])) {
                $v = trim($item[$k]);
                if ($v !== '') {
                    return $v;
                }
            }
        }

        return '';
    }
}
