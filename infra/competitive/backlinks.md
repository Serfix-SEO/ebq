# Backlinks subsystem

Two distinct backlink datasets, one vendor (Keywords Everywhere), one freshness rule:

- **Own backlinks** (`backlinks` table) — the tracked site's inbound links, synced from KE +
  manual entry. Used for auditing, impact attribution, and excluding already-owned referring
  domains from prospecting.
- **Competitor backlinks** (`competitor_backlinks` table) — referring pages for any competitor
  domain anyone on EBQ has audited. The cross-network gravity set that powers prospecting and DA.

## Key components

| Component | File | Role |
|---|---|---|
| `KeywordsEverywhereBacklinkClient` | `app/Services/KeywordsEverywhereBacklinkClient.php` | The only KE backlinks HTTP wrapper. `POST /v1/get_domain_backlinks`. Pre-flights meter, logs usage, returns null on any failure. |
| `BacklinkFreshnessGate` | `app/Services/BacklinkFreshnessGate.php` | **The single "should we call KE for this domain?" rule**, applied across every call path. |
| `OwnBacklinkSyncService` | `app/Services/OwnBacklinkSyncService.php` | KE → `backlinks` upsert for the site's own domain. |
| `CompetitorBacklinkService` | `app/Services/CompetitorBacklinkService.php` | Sole read/write entrypoint for `competitor_backlinks`; cap + freshness enforced. |
| `BacklinkAuditService` | `app/Services/BacklinkAuditService.php` | Fetches a referring page and verifies the link is present / dofollow / anchor matches. |
| `BacklinkProspectingService` | `app/Services/BacklinkProspectingService.php` | Mines competitor backlinks for outreach targets; LLM-drafts emails (Pro). |
| `BacklinkImpactService` | `app/Services/BacklinkImpactService.php` | GSC click delta 28d-before vs 28d-after each link's `tracked_date`. |
| Jobs | `app/Jobs/{SyncOwnBacklinksFromKeywordsEverywhere,FetchCompetitorBacklinks}.php` | Background sync; both no-op on fresh domains. |
| UI | `app/Livewire/Backlinks/BacklinksManager.php` | Manual entry sheet, filters, on-demand audit. |

## The freshness gate (central)

`BacklinkFreshnessGate::isFresh($domain)` returns true when we already have data younger than
`KE_BACKLINKS_TTL_DAYS` (default 30). **Callers MUST early-return without billing KE when true.**
It checks three sources (`BacklinkFreshnessGate.php:67`):

1. A **cache sentinel** (`ke_backlinks_fetched:<domain>`) — set by `markFetched()` after *every*
   KE round-trip including 0-result ones. WHY: KE legitimately returns zero backlinks for small/new
   domains; without the sentinel we'd re-bill on every page load forever. (:48, :117)
2. `competitor_backlinks` rows with `fetched_at >= cutoff` (exact `competitor_domain` match). (:86)
3. `backlinks` rows with `created_at >= cutoff` (LIKE host match on referring/target URLs). (:99)

`forget()` clears one sentinel (manual "refresh now"); `forgetAll($websiteId?)` enumerates
candidate domains from both tables (no Redis pattern-scan dependency — works on any cache driver)
and forgets each. (:136, :163)

## Own backlink sync

`OwnBacklinkSyncService::syncForWebsite()` (`OwnBacklinkSyncService.php:35`):
1. Gate check — no-op if fresh.
2. KE call (`own_backlinks_limit`, clamped 50–1000).
3. **`markFetched()` always** — even null/empty — so we don't retry until TTL elapses. (:60)
4. `updateOrCreate` keyed `(website_id, referring_page_url, target_page_url)` so re-syncs refresh
   `tracked_date` instead of duplicating. Defaults `type=Other`, `is_dofollow=true`.

Job `SyncOwnBacklinksFromKeywordsEverywhere`: `tries=1` (KE costs credits, never auto-retry),
`uniqueFor=86400` (one pending sync per website per day) — safe to dispatch on every score request.

## Competitor backlink fetch

`CompetitorBacklinkService::refresh()` (`CompetitorBacklinkService.php:91`):
- Gate-checked, then KE call capped at `limit_per_competitor` (50, clamped 1–1000).
- **Field-shape tolerant parser** (`firstString()` :246) accepts many provider spellings
  (`url_source`/`url_from`/`page_url`…, `domain_rating`/`dr`/`da`…) so the same code survives API
  drift. DA clamped 0–100; rel/type normalized to dofollow/nofollow/sponsored/ugc.
- Upsert keyed `(competitor_domain, referring_page_hash)`; **prunes rows outside the new top-N**
  so stale links from an older, larger fetch don't linger. (:217)

`queueRefresh($domains, $websiteId, $ownerUserId)` (:70) skips fresh domains and dispatches
`FetchCompetitorBacklinks` (`tries=2`, `backoff=30`, `uniqueFor=300`, deduped on websiteId+domains).
Called fire-and-forget from page audits and opportunity scoring — the audit completes first, the
cache fills async, the UI reveals backlinks as they land.

## Backlink audit (link verification)

`BacklinkAuditService::audit()` fetches the **referring** page (15s timeout, custom UA, follows
redirects) and looks for an `<a>` to the target. Statuses: `matched` / `mismatched` / `missing` /
`unreachable`. URL match uses `normalizeUrl()` (:256): lowercase host, strip `www`, strip trailing
slash, strip `utm_*` params, sort remaining query. `pickBestLink()` scores candidate links by
anchor + dofollow match. `isDofollow()` treats empty rel as dofollow; `nofollow`/`ugc`/`sponsored`
tokens flip it. Result JSON stored on `audit_status`/`audit_result`/`audit_checked_at`.

## Prospecting & outreach

`BacklinkProspectingService` (`BacklinkProspectingService.php`) — the network-effect outreach feature:

- `prospect($website, $competitorDomains)` :66 — pulls every `competitor_backlinks` row for the
  given competitors, groups by **referring domain**, **excludes domains we already have a link
  from** (`ownedReferringDomains()` :453), keeps highest DA seen, ranks by DA then competitor
  overlap, caps at 100. Cached 6h per `(website × competitors hash)` for a stable working list.
- `upsertProspects()` :299 persists into `outreach_prospects` — **merges** competitor lists on
  re-run (never wipes status/notes), keeps highest DA. Turns "compute on demand" into a real
  workflow with a status histogram (`new`/`drafted`/`contacted`/`replied`/`converted`/…).
- `autoDiscoverFromAudits()` :178 — derives competitors from recent page audits'
  `benchmark.competitors` so the user never has to paste domains. Idempotent.
- `draftOutreach()` :369 — LLM-drafts a 90-word email (Pro tier, gated at controller). Cached 7d;
  persists `latest_draft` on the prospect and bumps `new → drafted`.

## Impact attribution

`BacklinkImpactService::impactByTargetPage()` — for each target page that got a link, sums GSC
clicks in the 28d **after** the link's latest `tracked_date` vs the 28d **before**. Fetches the
widest date window in one query then buckets in PHP; ranks pages by click delta. Answers "did this
link move the needle?" Requires GSC click data (`SearchConsoleData`).

## Gotchas

- **`markFetched` must fire on every KE return path, including failures**, or small/no-backlink
  domains re-bill on each load. Both sync services already do this immediately after the call.
- **Pruning is by hash, not by domain** — `refresh()` deletes competitor rows whose
  `referring_page_hash` wasn't in the latest top-N. A smaller subsequent fetch shrinks the set.
- **Prospecting excludes by referring *domain*, not URL** — if you have even one link from a domain,
  it never appears as a prospect (by design).
- DA values come straight from KE and can be revised between fetches; both upserts keep the
  **highest** value seen.

## Key files

- `app/Services/{BacklinkFreshnessGate,OwnBacklinkSyncService,CompetitorBacklinkService,BacklinkAuditService,BacklinkProspectingService,BacklinkImpactService,KeywordsEverywhereBacklinkClient}.php`
- `app/Jobs/{SyncOwnBacklinksFromKeywordsEverywhere,FetchCompetitorBacklinks}.php`
- `app/Models/{Backlink,CompetitorBacklink,OutreachProspect}.php`
- `app/Livewire/Backlinks/BacklinksManager.php`
- Config — `config/services.php` `keywords_everywhere` + `competitor_backlinks` (`:105`, `:149`)
