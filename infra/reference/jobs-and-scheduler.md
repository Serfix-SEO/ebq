# Jobs, console commands & scheduler

Cross-cutting reference for everything that runs **off the web request** in EBQ:
the 25 queued jobs (`app/Jobs/`), the 17 artisan commands (`app/Console/Commands/`),
and the cron scheduler (`routes/console.php`).

- Queue **topology** (which box runs which queue, retry_after, deploy gotchas):
  [../deployment-and-queues.md](../deployment-and-queues.md). Not repeated here.
- The **crawl pipeline** (`CrawlWebsitePagesJob → CrawlPassJob → CrawlPageBatchJob →
  AnalyzeSiteJob`, the multi-pass loop, `Bus::batch`): see
  [../crawler/pipeline.md](../crawler/pipeline.md). Crawl jobs are listed below for
  completeness but the flow lives there — **link, don't duplicate**.

## Queues (`app/Support/Queues.php`)

Four named queues. Defaults: a job with no `$tries`/`$timeout` uses the worker's CLI
flags / framework defaults (effectively `tries=1` the way these workers are run).

| Const | value | Runs on | For |
|-------|-------|---------|-----|
| `INTERACTIVE` | `interactive` | web box (supervisor ×2) | user actively waiting (guest tools, live audits, "check rank now") |
| `DEFAULT` | `default` | web box (supervisor) | mail, notifications, misc |
| `SYNC` | `sync` | **worker box** (docker) | scheduled/bulk GA·GSC·keyword syncs |
| `CRAWL` | `crawl` | **worker box** (docker ×5) | site-crawl pipeline (high volume, ≤1200s/job) |

**retry_after vs timeout:** the `redis` connection's `REDIS_QUEUE_RETRY_AFTER=1320` covers the
≤300s crawl/sync jobs. The long `AnalyzeSiteJob` (3600s) runs on a **separate `redis-long`
connection** whose `retry_after=3900` is a **code default** in `config/queue.php` (env-overridable
via `REDIS_LONG_QUEUE_RETRY_AFTER`) — deliberately NOT a per-box `.env` knob, so the ceiling
travels with the deploy and never needs a manual edit on either box.

### Horizon (queue observability) — LIVE

All queues (except `fleet`) now run under **Laravel Horizon**. Dashboard at **`/horizon`**.
Topology in `config/horizon.php`:
- Supervisors `web` (interactive+default, 300s), `worker-crawl` (crawl, 300s), `worker-heavy`
  (sync+crawl-finalize, **3600s**, `redis-long` connection). The **`fleet` queue is NOT under Horizon** — it runs as **root**
  (SSH keys), so it stays a standalone root `queue:work` (supervisor `ebq-queue-fleet`).
- **Per-box scoping is via the `environments` map keyed by APP_ENV.** ⚠️ Horizon merges
  `defaults` into EVERY environment (`array_replace_recursive`), so `defaults` is kept **empty**
  and each supervisor is defined only inside the environment(s) that should run it — otherwise a
  supervisor in `defaults` runs on every box. Mapping: web box `APP_ENV=local`→`web`; pinned
  worker `APP_ENV=worker`→`worker-crawl`+`worker-heavy`; ephemeral `APP_ENV=worker-ephemeral`→
  `worker-crawl`. The per-box APP_ENV/FLEET_NODE_ID/HORIZON_NAME are stamped into each box's `.env`
  (pinned: manually; ephemeral: by `WorkerFleetService::bootstrap`).
- **How it runs per box:** web box → Supervisor program `ebq-horizon` (`php artisan horizon`,
  www-data, in `/etc/supervisor/conf.d/ebq.conf`). Pinned worker B + ephemeral → a single
  `horizon` Docker service (`docker-compose.worker.yml` / `docker-compose.ephemeral.yml`),
  replacing the old per-queue `queue:work` services. Horizon owns process count (no `replicas`).
- **`/horizon` is admin-gated.** ⚠️ The parent provider's default auth is
  `Gate::check('viewHorizon') || app()->environment('local')`; since this box runs **APP_ENV=local
  in production**, `HorizonServiceProvider::boot()` re-sets `Horizon::auth()` to the gate **without**
  the local bypass (admins only). Do not revert that, or `/horizon` becomes world-readable.
- **Metrics:** `horizon:snapshot` runs every 5 min (`routes/console.php`). On code deploy, run
  `horizon:terminate` so each box's master restarts on the new code (the Supervisor/Docker
  `restart` brings it back). Horizon metadata lives in its own Redis connection (`database.redis.horizon`, DB 3).
- **Per-physical-box counts** (Horizon is per-queue, not per-box): `App\Support\FleetMetrics`
  keeps in-flight/finished/failed counters per box (queue events keyed by `FLEET_NODE_ID`), shown
  on the crawler fleet page for safe-drain decisions.

## Jobs (`app/Jobs/*.php`)

Legend: **U** = `ShouldBeUnique` (`uniqueId` shown). All times in seconds.

### Sync / data jobs (`sync` queue)

| Job (`file:line`) | timeout | tries | U / uniqueId | Dispatched by | Purpose |
|---|---|---|---|---|---|
| `SyncSearchConsoleData.php:17` | 600 | 2 | — | onboarding `ConnectGoogle`, `Website` model, `SyncDailyData`, `ImportHistoricalData`, `ResyncGsc`, `IntegrationsPanel`, `ConnectSourcesModal`, `SyncWebsiteData` | Pull GSC search-analytics rows (date·country·device dims) and upsert into `search_console_data`; chains a keyword-metrics fetch. |
| `SyncAnalyticsData.php:13` | 600 | 2 | — | same set as above (`SyncDailyData`, `ImportHistoricalData`, `Website`, onboarding, `SyncWebsiteData`, `IntegrationsPanel`) | Refresh GA4 traffic data into `analytics_data`. |
| `SyncPageIndexingStatus.php:15` | 600 | 1 | — | **none in app code (orphan/manual)** | Pull GSC URL-inspection indexing status per page into `page_indexing_statuses`. |
| `FetchKeywordMetricsJob.php:17` | 180 | 2 | **U** `sha256(country\|websiteId\|sorted keywords)`, `uniqueFor=300` | `RankTrackingKeywordObserver`, `KeywordMetricsService`, `FetchKeywordMetrics` cmd, **chained from `SyncSearchConsoleData`** | Fetch Keywords Everywhere volume/CPC for a keyword batch → `keyword_metrics` cache (service chunks to KE's 100/req cap). |
| `SyncOwnBacklinksFromKeywordsEverywhere.php:29` | 180 | 1 | **U** `sync_own_backlinks:{websiteId}` | `LiveSeoScoreService` | Sync the site's own backlinks from KE. tries=1: KE costs credits, never auto-retry. |
| `FetchCompetitorBacklinks.php:24` | 180 | 2 | **U** `sha256(websiteId\|sorted domains)`, `uniqueFor=300` | `CompetitorBacklinkService` | Fire-and-forget cache-fill of up to N backlinks per competitor domain after an audit. |
| `ReprocessCompetitiveData.php:17` | 180 | 1 | **U** `competitive-reprocess:{websiteId}` | `Website` model (on GSC connect) | Upgrade competitive data once GSC connects; debounced so GSC+GA back-to-back reprocess once. |
| `DetectTrafficDrops.php:12` | default | default | — | `ebq:detect-traffic-drops` cmd (per website) | Run `TrafficAnomalyDetector` for one website; 24h dedupe const. |
| `TrackKeywordRankJob.php:13` | 120 | 2 (`backoff=30`) | — | rank-tracking Livewire (`RankTrackingDetail/Manager`, `KeywordDetail`, `TracksKeyword`), `TrackRankings` cmd, `PluginHqController` | One SERP rank check for a tracked keyword → snapshot. |

### Interactive jobs (`interactive` queue — user is waiting)

| Job (`file:line`) | timeout | tries | U / uniqueId | Dispatched by | Purpose |
|---|---|---|---|---|---|
| `RunCustomPageAudit.php:28` | 300 | 1 | **U** `custom-page-audit:{auditId}` | `StrikingDistanceFixService`, `LiveSeoScoreService`, `CustomAudit` Livewire, `PluginHqController` | Run a logged-in custom page audit. tries=1: Serper/DFS cost money, no auto-retry. |
| `RunPageSpeedStrategy.php:22` | 88 | 1 | — | `PageSpeed` Livewire | One Lighthouse strategy (mobile\|desktop); parsed report cached for the poller. |
| `RunCompetitorDiscovery.php:22` | 180 | 1 | **U** `competitor-discovery:{runId}` | `CompetitorDiscoveryService` | One competitor auto-discovery fan-out (SERP sample + tally). Unique per run so no double-billing. |
| `RunKeywordGapVerification.php:22` | 180 | 1 | **U** `keyword-gap-verify:{analysisId}` | `KeywordGapService` | Verify a Keyword-Gap "Missing" bucket against the live SERP. |
| `MatchRedirectFor404Job.php:27` | 120 | 1 | **U** `match_404:{websiteId}:xxh3(sourcePath)` | `AnalyzeSiteJob` (crawl), `PluginInsightsController` | AI-match a 404 path to a redirect target so the WP heartbeat returns immediately. |

### Guest (public, no-signup) jobs (`interactive` queue)

All tries=1 (paid external calls; never auto-retry), all `ShouldBeUnique`.

| Job (`file:line`) | timeout | uniqueId | Dispatched by | Purpose |
|---|---|---|---|---|
| `RunGuestPageAudit.php:27` | 120 | `guest-page-audit:{auditId}` | `GuestAuditController` | Anonymous landing-page audit. |
| `RunGuestPageSpeedStrategy.php:27` | 88 | `guest-page-speed:{id}:{strategy}` | `GuestPageSpeedController` | One PageSpeed strategy of a public test (two dispatched per test for a full worker cycle each). |
| `RunGuestRankCheck.php:25` | 60 | `guest-rank-check:{id}` | `GuestRankCheckController` | One public keyword rank check (single Serper query); emails link. |
| `RunGuestKeywordVolume.php:27` | 45 | `guest-keyword-volume:{id}` | `GuestKeywordVolumeController` | One public keyword search-volume lookup, DB-first off the shared `keyword_metrics` cache; emails link. |

### Default queue

| Job (`file:line`) | timeout | tries | Dispatched by | Purpose |
|---|---|---|---|---|
| `GenerateAiInsights.php:11` | default | default | **none in app code (placeholder/orphan)** | Stub: writes a placeholder `ai_insights` row. Not wired to any caller. |

### Crawl jobs (`crawl` queue) — flow in [../crawler/pipeline.md](../crawler/pipeline.md)

| Job (`file:line`) | timeout | tries | U / uniqueId | Dispatched by | Role |
|---|---|---|---|---|---|
| `CrawlWebsitePagesJob.php:23` | 600 | 1 | **U** `crawl-site-{crawlSiteId\|wID}`, `uniqueFor=6h` | `CrawlSiteBootstrapper`, `SitemapPrompt`, `SiteHealthStats`, `ClientController`, `ebq:crawl-websites`, `CrawlSitemapDeltaJob` | Entry point: resolve crawl_site, create run, seed frontier, dispatch pass 1. |
| `CrawlPassJob.php:28` | 600 | 1 | — | `CrawlWebsitePagesJob` (and self, pass+1), `CrawlSupervisor` | Multi-pass loop driver; selects ≤`crawler.pages_per_pass` (1000) due pages for **fairness** and **`Bus::batch(CrawlPageBatchJob…)`** with `.finally → CrawlPassJob(pass+1)`; stops → `AnalyzeSiteJob`. (A lost `.finally` = wedge → recovered by `ebq:crawl-supervisor`.) |
| `CrawlPageBatchJob.php:18` | 300 | 2 | — | `CrawlPassJob` (batched) | Crawl one page batch (conditional GET, classify, SimHash, adaptive next_crawl_at). No-ops if `batch()?->cancelled()`. |
| `AnalyzeSiteJob.php` | **3600** | 2 | — | `CrawlPassJob`/`CrawlSupervisor` (terminal) | Post-crawl analysis/scoring; **`crawl-finalize`** queue (pinned box only) on the dedicated **`redis-long`** connection (retry_after **3900**, code-default in `config/queue.php` — NOT the `redis` env knob, so it travels with the deploy). 3600s because a ~168k-page/~1.5M-edge site runs many minutes; `WithoutOverlapping` + `tries=2` + per-job 1024M `ini_set`. Dispatches `MatchRedirectFor404Job` per impactful 404. |
| `CrawlSitemapDeltaJob.php:22` | 180 | 2 | **U** `sitemap-delta-{crawlSiteId\|wID}`, `uniqueFor=3h` | `ebq:crawl-websites --sitemap-deltas` | Daily: detect brand-new sitemap URLs and dispatch a crawl for just those. |
| `SyncSitemaps.php:18` | 120 | 2 | — | `CrawlSiteBootstrapper`, `SitemapsManager` Livewire | Read-only pull of GSC-known sitemaps into local store (source=gsc); manual sitemaps untouched. |

> Batches & chains: only the crawl pipeline uses `Bus::batch` (`CrawlPassJob`).
> `SyncSearchConsoleData` chains a `FetchKeywordMetricsJob`. No other batches/chains.

## Console commands (`app/Console/Commands/*.php`)

| Signature (`file`) | What it does | Scheduled | Destructive? |
|---|---|---|---|
| `ebq:sync-daily-data` (`SyncDailyData.php`) | Dispatch GA4 + GSC sync jobs for all websites | **daily** | no |
| `ebq:detect-traffic-drops` (`DetectTrafficDrops.php`) | Dispatch a `DetectTrafficDrops` job per website | **daily 07:30** | no |
| `ebq:send-reports` (`SendGrowthReports.php`) | Queue one daily growth-report email per recipient, snapped to last fully-synced GSC day | **daily 08:00** | no |
| `ebq:track-rankings {--force}` (`TrackRankings.php`) | Dispatch SERP rank checks for keywords past `next_check_at` (`--force`=all) | **hourly** | no |
| `ebq:auto-discover-prospects {--days=30}` (`AutoDiscoverProspects.php`) | Discover backlink prospects from recent audits; idempotent + freshness-gated | **daily 03:30** | no |
| `ebq:publish-scheduled-plugin-releases` (`PublishScheduledPluginReleases.php`) | Publish WP plugin releases whose scheduled time has passed | **every minute** | no |
| `ebq:failed-jobs-alert {--dry-run}` (`SendFailedJobsAlert.php`) | Drain the shared-Redis failed-job buffer (fed by `Queue::failing()` on every box — `FailedJobAlertBuffer`) + flag crawl_sites with subscribers stuck `pending` >24h; mail digest to admins. Empty = no mail. `--dry-run` peeks without consuming. Companion UI: `/admin/ops` | **every 15 min** (`withoutOverlapping`) | no |
| `ebq:recheck-audit-content {--limit=100}{--older-than=1}{--dry-run}` (`RecheckAuditContent.php`) | Re-fetch oldest completed page audits, hash extracted body text vs stored `content_hash`, queue a re-audit only on real content drift (catches WP not bumping `modified`) | **hourly** (`--limit=200`, `withoutOverlapping`) | no |
| `ebq:crawl-websites {--website=} {--force} {--backfill} {--sitemap-deltas} {--reanalyze}` (`CrawlWebsites.php`) | Dispatch crawls (full / backfill / sitemap-delta) | **weekly Mon 02:00** + **daily 04:30** (`--sitemap-deltas`) | no (`--reanalyze` only clears conditional-GET validators, not data) |
| `ebq:crawl-supervisor` (`CrawlSupervisor.php`) | Watchdog: resume/finalize crawl runs whose multi-pass `Bus::batch` chain died (worker recycle). Time-based on `crawler.stall_minutes` (10) / `max_run_hours` (6) | **every 5 min** (`withoutOverlapping`) | no (dispatches `CrawlPassJob`/`AnalyzeSiteJob`) |
| `ebq:fleet-autoscale {--dry-run}` (`FleetAutoscale.php`) | Scale worker boxes up/down to match crawl backlog (Hetzner API). No-op until `autoscaler.enabled`; `--dry-run` logs the decision only | **every 2 min** (`withoutOverlapping`) | no (provisions/drains boxes when enabled) |
| `ebq:check-worker-nodes` (`CheckWorkerNodes.php`) | Refresh fleet health from the Hetzner API | **every 5 min** | no |
| `ebq:fleet-worker {action}` (`FleetWorker.php`) | Manual fleet control: `list/provision/bootstrap/drain/destroy/reconcile/register-pinned` | manual | provision/destroy = creates/deletes Hetzner servers |
| `ebq:check-keyword-servers {--id=}` (`CheckKeywordServers.php`) | Refresh health/queue snapshot for self-hosted keyword API fleet | **every 5 min** | no |
| `ebq:fetch-keyword-metrics {--website=}{--country=}{--min-impressions=100}{--days=28}{--limit=500}{--force}{--sync}{--dry-run}` (`FetchKeywordMetrics.php`) | Queue KE lookups for GSC queries above an impression threshold | manual | no |
| `ebq:import-historical {--days=480}{--website=}` (`ImportHistoricalData.php`) | Dispatch full GA4+GSC history import (upsert) | manual | no (additive upsert) |
| `ebq:resync-gsc {--days=30}{--website=}` (`ResyncGsc.php`) | Queue `SyncSearchConsoleData` with extended lookback to backfill country/device dims | manual | **caution** — see below |
| `ebq:apply-plugin-version {…}` (`ApplyPluginVersionCommand.php`) | Bump Version/`EBQ_SEO_VERSION` in `ebq-wordpress-plugin/ebq-seo.php` | manual | no (file edit) |
| `ebq:package-plugin {--output=}` (`PackageWordPressPlugin.php`) | Zip the WP plugin source for public download | manual | no (writes a file) |
| `ebq:purge-empty-country-gsc {--older-than=30}{--website=}{--dry-run}` (`PurgeEmptyCountryGsc.php`) | Delete legacy `country=''` GSC rows older than N days | manual | **YES — deletes rows** (see below) |
| `ebq:purge-sync-data {--website=}{--dry-run}{--force}` (`PurgeSyncData.php`) | Wipe GSC+GA + derived data so next sync re-pulls fresh | manual | **YES — bulk delete + `Cache::flush()`** (see below) |
| `ebq:delete-website-data {website?}{--all}{--dry-run}{--force}` (`DeleteWebsiteData.php`) | Delete a website (FK-cascades all its data); `--all` wipes every site | manual | **YES — irreversible cascade delete** (see below) |
| `ebq:demo-data {action}{--force}` (`DemoData.php`) | `seed`=wipe+regenerate demo site (user 1); `clear`=remove it | manual | **YES — `seed`/`clear` delete demo data** (see below) |

`ebq:apply-plugin-version`, `ebq:package-plugin` are deploy/build helpers (no DB).
`inspire` is the framework demo command (ignore).

## ⛔ Destructive commands — read before running

> Production DB, **no backups, binlog off** — deletes are permanent (CLAUDE.md).
> Every command below supports `--dry-run` (except `demo-data`); **always dry-run
> first**, and never pass `--force`/`--all` casually.

| Command | What it destroys | Guards |
|---|---|---|
| **`ebq:delete-website-data --all`** | EVERY website + all FK-cascaded data (audits, keywords, GSC/GA, AI, writer, redirects…). Single-ID mode deletes one site's full cascade. | Per-website txn; `--all` requires typing **`delete all websites`** (a plain y/N is rejected); `--dry-run` prints a count plan. |
| **`ebq:purge-sync-data`** | `search_console_data`, `analytics_data`, `page_indexing_statuses`, `ai_insights`; resets `last_*_sync_at`. With no `--website` → **all sites**, and `clearCaches()` runs **`Cache::flush()`** (flushes the whole shared Redis cache, both boxes). Preserves websites/users/google_accounts/audits/rank-tracking/backlinks. | `--dry-run`, confirm prompt (skipped by `--force`), wrapped in one DB txn. Follow with `ebq:resync-gsc`. |
| **`ebq:purge-empty-country-gsc`** | Legacy `country=''` GSC rows **older than `--older-than` days** (default 30). | Safer by design: only historical rows outside the resync window; `--dry-run` prints counts. Still a raw `DELETE`. |
| **`ebq:demo-data seed`** | "wipe + regenerate": clears then reseeds the demo site (user id 1) — **deletes existing demo data**. | None on `seed` (no confirm). Scoped to the demo domain/user only. |
| **`ebq:demo-data clear`** | Removes the demo website + all its data + demo `keyword_metrics`. | Confirm prompt unless `--force`. Scoped to demo domain/user. |
| **`ebq:resync-gsc`** | No direct delete, but dispatches `SyncSearchConsoleData` whose **upsert replaces** `country=''` rows with re-dimensioned ones over the lookback window — intended, but a heavy re-pull. | Per-site `--website`; `chunkById`. |

Not in scope but worth knowing: `ebq:demo-data seed`/`clear --force` plus any
`migrate:fresh`/`migrate:refresh`/`migrate:rollback`/`db:wipe`/raw `DROP`·`TRUNCATE`
are the CLAUDE.md "explicit per-command confirmation required" list. None of the
scheduled commands are destructive.

## Scheduler (`routes/console.php`)

| Cadence | Command |
|---|---|
| every minute | `ebq:publish-scheduled-plugin-releases` |
| every 5 min | `ebq:check-keyword-servers` |
| every 5 min | `ebq:crawl-supervisor` (`withoutOverlapping`) — crawl watchdog |
| every 2 min | `ebq:fleet-autoscale` (`withoutOverlapping`) — worker-fleet autoscaler (no-op until `autoscaler.enabled`) |
| every 5 min | `ebq:check-worker-nodes` (`withoutOverlapping`) — fleet health from Hetzner |
| hourly | `ebq:track-rankings` |
| daily (midnight) | `ebq:sync-daily-data` |
| daily 03:30 | `ebq:auto-discover-prospects` |
| daily 04:30 | `ebq:crawl-websites --sitemap-deltas` |
| daily 07:30 | `ebq:detect-traffic-drops` |
| daily 08:00 | `ebq:send-reports` |
| weekly Mon 02:00 | `ebq:crawl-websites` (full recrawl) |

`schedule:work` runs on the **web box** (it only dispatches jobs; the heavy work
lands on the `sync`/`crawl` queues on the worker box). The weekly full recrawl is
cheap because conditional-GET + content-hash skip unchanged pages; the daily
sitemap-delta picks up brand-new URLs within a day — see
[../crawler/pipeline.md](../crawler/pipeline.md).
