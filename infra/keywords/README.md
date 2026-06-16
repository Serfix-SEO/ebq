# Keywords & Rank Tracking subsystem

Everything that turns a keyword string into **search volume, opportunity, and a live
SERP position** in EBQ. Three data sources feed it, each with its own billing and
freshness model:

| Source | What it gives | Sync? | Billing |
|---|---|---|---|
| **Self-hosted Keyword Planner fleet** (`keyword_finder`) | volume, competition, bid range, *related-keyword discovery* | **async** (webhook) | self-hosted, no per-call cost; needs maintained Google Ads logins |
| **Keywords Everywhere** (`keywords_everywhere`) | volume, CPC, competition, 12-mo trend | sync | credit-billed, metered per keyword |
| **Serper** (`serper`) | live Google SERP (positions, features, PAA) | sync | per-call USD, metered |

The volume provider is **admin-switchable at runtime** via the `keyword.volume_provider`
Setting (`app/Support/KeywordProviderConfig.php`); defaults to Keywords Everywhere so
behaviour is unchanged until an admin opts into the self-hosted fleet.

## Read in this order

| Doc | What it covers |
|---|---|
| [keyword-finder.md](./keyword-finder.md) | **The self-hosted fleet.** Load-balancer + failover, async webhook flow, server health, the `keyword_metrics` cache, location/language bridging, caps. |
| [keyword-research.md](./keyword-research.md) | The user-facing research UI: Idea Finder, Volume Finder, Keyword Gap, Fix Playbook, keyword table/detail. Caps, polling, cross-tool handoff. |
| [rank-tracking.md](./rank-tracking.md) | Tracked keywords → Serper SERP checks → snapshots. Scheduling, plan caps, GSC overlay, observers. |

## One paragraph

A keyword's **search-volume metrics** are cached once per `(keyword_hash, country)` in
`keyword_metrics` (`data_source='gkp'`), 30-day fresh. Reads are **DB-first**
(`KeywordMetricsService`); a miss/stale row triggers a background refresh against whichever
provider is configured. The self-hosted provider is **asynchronous** — a dispatch ACKs
instantly and results land later via `/webhooks/keyword-finder`, which warms the cache with
the asked-for keyword *plus* every related keyword the discovery returned. **Rank tracking**
is separate: each `rank_tracking_keyword` is re-checked on a per-keyword interval by
`ebq:track-rankings` (hourly cron) → `TrackKeywordRankJob` → Serper, producing a
`rank_tracking_snapshot` time series. GSC data overlays both surfaces read-time.

## Key invariants

1. **Every volume read goes through `KeywordMetricsService`** — never hit a provider client
   directly from a view/component (`app/Services/KeywordMetricsService.php:14`). This enforces
   "DB-first, fetch last, never re-bill on fresh data".
2. **Metrics are stored under `data_source='gkp'` regardless of provider** so reads (which
   ignore `data_source`) don't duplicate rows across providers
   (`KeywordMetricsService.php:256`).
3. **The cache key is `hash('sha256', lower(trim(keyword)))`** + a country key; always write
   under the hash of *our* input keyword, not the provider's returned string, or skip-fresh
   logic never matches on re-run (`KeywordMetricsService.php:170`).
4. **Async requests are idempotent end-to-end** — the webhook no-ops a redelivery for an
   already-finished `KeywordApiRequest` (`KeywordFinderWebhookController.php:55`).

## Key code

- Self-hosted fleet — `app/Services/KeywordFinder/{KeywordFinderPool,KeywordFinderClient}.php`,
  `app/Models/{KeywordApiServer,KeywordApiRequest}.php`,
  `app/Http/Controllers/Webhooks/KeywordFinderWebhookController.php`,
  `app/Http/Controllers/Admin/KeywordApiServerController.php`,
  `app/Console/Commands/CheckKeywordServers.php`
- Metrics — `app/Services/{KeywordMetricsService,KeywordValueCalculator,KeywordsEverywhereClient}.php`,
  `app/Models/KeywordMetric.php`, `app/Jobs/FetchKeywordMetricsJob.php`
- Config/support — `app/Support/{KeywordProviderConfig,KeywordFinderLocations,KeywordsEverywhereCountries,RankTrackerConfig}.php`
- Research UI — `app/Livewire/Keywords/*`, `app/Services/Competitive/KeywordGapService.php`,
  `app/Models/{KeywordGapAnalysis,KeywordGapRow}.php`, `app/Jobs/RunKeywordGapVerification.php`
- Rank tracking — `app/Services/RankTrackingService.php`, `app/Jobs/TrackKeywordRankJob.php`,
  `app/Livewire/RankTracking/*`, `app/Models/RankTrackingKeyword*.php`,
  `app/Observers/RankTrackingKeywordObserver.php`, `app/Console/Commands/TrackRankings.php`
</content>
</invoke>
