# Testing setup & CI

> Cross-cutting reference for the EBQ test suite. **The production box is not a safe place
> to run tests** — read the safety section first, every time.

Framework: **plain PHPUnit 11** (`phpunit/phpunit ^11.5.50`), class-based, not Pest. All 97
test files extend `Tests\TestCase` (`grep` confirms 0 Pest-style `it()`/`test()` functions).
`composer.json` carries a leftover `pestphp/pest-plugin` allow-plugin entry and `composer.lock`
references the Laravel skeleton's Pest packages, but no Pest runner is wired up — ignore it.

---

## ⛔ Safe test running (READ FIRST)

**Why this is dangerous here.** This repo is deployed on a **production server** whose default
DB connection is **live MariaDB/MySQL `ebq`**. Binary logging is OFF and there are **no
backups** — any data loss is permanent.

**The 2026-06-07 incident.** A `php artisan test` run **wiped the production `ebq` database.**
`phpunit.xml` pins the test connection to sqlite `:memory:` via `<env>` overrides, but a
**cached config** — `bootstrap/cache/config.php`, written by `php artisan optimize` — silently
overrode those env values at runtime. The suite resolved `database.default = mysql`, and
`RefreshDatabase`'s `migrate:fresh` dropped every table on the live DB. No backup; data lost.

**The cause in one line:** a cached config beats `phpunit.xml`'s `<env>` values, so the only
thing standing between the suite and production was a config file that wasn't there.

### The procedure (do this every time)

```bash
php artisan config:clear            # delete bootstrap/cache/config.php (the cause)
php artisan tinker --execute='echo config("database.default");'   # MUST print: sqlite
```

- Confirm the resolved connection is **sqlite `:memory:`**, NOT `mysql`, before running anything.
- Prefer `composer test` — its script runs `php artisan config:clear --ansi` **then**
  `php artisan test` (`composer.json:59`), baking the safe step in.
- `opcache.enable_cli = 0` on this box, so CLI/tinker always compiles fresh — but that does
  **not** protect you from a *cached config file*; only `config:clear` does.

### The guard — `tests/TestCase.php` (DO NOT remove or weaken)

The last line of defense. Laravel boots the app in `refreshApplication()` and only *then*
calls `setUpTraits()` (where `RefreshDatabase` migrates). `TestCase` overrides `setUpTraits()`
to inspect the **resolved** connection *before* a single table is touched, regardless of
cached config:

| Location | Behavior |
|---|---|
| `tests/TestCase.php:26` `setUpTraits()` | Calls `guardAgainstNonTestDatabase()`, then `parent::setUpTraits()`. |
| `tests/TestCase.php:33` `guardAgainstNonTestDatabase()` | Reads `database.default` → driver + database name. |
| `tests/TestCase.php:39` | Passes only if **sqlite `:memory:`** (`$isMemorySqlite`)… |
| `tests/TestCase.php:40` | …or DB name contains `test` (`$looksLikeTestDb`). |
| `tests/TestCase.php:46` | Otherwise throws `RuntimeException` → **whole run aborts**, naming the offending connection and telling you to `php artisan config:clear`. |

Runs per test class before any migration, so the first failing test stops the suite before
`migrate:fresh` can fire. **CLAUDE.md forbids removing or weakening this guard.**

---

## Configuration — `phpunit.xml`

Two suites: `Unit` → `tests/Unit`, `Feature` → `tests/Feature` (`phpunit.xml:7`). Coverage
source is `app/`. The `<php>` block sets the test environment (`phpunit.xml:20`):

| Env | Value | Why |
|---|---|---|
| `DB_CONNECTION` | `sqlite` | In-memory test DB (the safety pin). |
| `DB_DATABASE` | `:memory:` | Never touches a file or real server. |
| `APP_ENV` | `testing` | |
| `CACHE_STORE` | `array` | No shared cache state. |
| `QUEUE_CONNECTION` | `sync` | Jobs run inline unless faked. |
| `MAIL_MAILER` | `array` | Mail captured, never sent. |
| `SESSION_DRIVER` | `array`, `BROADCAST_CONNECTION` | `null` | No external side effects. |
| `BCRYPT_ROUNDS` | `4` | Fast hashing. |
| `PULSE/TELESCOPE/NIGHTWATCH_ENABLED` | `false`, `RECAPTCHA_*` | `""` | Disable monitoring/captcha. |
| `REDIS_DB`/`REDIS_CACHE_DB`/`REDIS_PREFIX` | `13`/`14`/`serfix-testing-` | Tests can never touch the prod Redis keyspace (2026-07-06). |
| `KEYWORDS_EVERYWHERE_API_KEY` | `""` | **Tests were making real, credit-billed KE API calls** (found 2026-07-11): `QUEUE_CONNECTION=sync` runs `RankTrackingKeywordObserver`'s `FetchKeywordMetricsJob` inline, and the prod `.env` key leaked through — every `RankTrackingKeyword::create()` in a test hit the live API and silently overwrote seeded `KeywordMetric` rows with real data. Any other billable provider key added to `.env` needs the same blanking here. |
| `MISTRAL_API_KEY` / `DEEPSEEK_API_KEY` | `""` | Same landmine class (added 2026-07-11 with the DeepSeek provider): a leaked LLM key lets any test resolving `LlmClient` — or hitting the admin settings form, which fetches the live `/models` list during validation — make real network calls; completions are token-billed. Tests needing HTTP use `Http::fake()` + a fake key via `config()`. |

**Gotcha:** these are `<env>` *defaults*. A cached `bootstrap/cache/config.php` overrides them
— the whole reason the incident happened and the guard exists. And they only cover the keys
*listed*: anything in `.env` that phpunit.xml doesn't override leaks into tests verbatim
(that's how the KE key above billed real credits).

---

## Database & factories

- **77 of 97 files use `RefreshDatabase`** (`Illuminate\Foundation\Testing\RefreshDatabase`).
  Zero use `DatabaseTransactions`. On sqlite `:memory:` `RefreshDatabase` runs `migrate:fresh`
  once and wraps each test in a transaction — fast and isolated. On a *real* server it is a
  data-destroying `migrate:fresh`. That asymmetry is the whole risk.
- **Factories** (`database/factories/`, 4 total):
  - `UserFactory` — **verified by default** (`email_verified_at => now()`, stock Laravel
    convention; fixed 2026-07-11 — the old null default made every HTTP test behind the
    `verified` middleware 302 to verify-email). Use `->unverified()` for the
    unverified state.
  - `WebsiteFactory` — default leaves source FKs null; states encode the GSC/GA presence
    matrix used across the app: `withBothSources()`, `withGaOnly()`, `withGscOnly()`,
    `withNoSources()` (`WebsiteFactory.php:36-80`). Maps to `Website::hasGa()`/`hasGsc()`.
  - `GoogleAccountFactory`, `BacklinkFactory`.

---

## Test organization

`tests/Unit` (18 files) — pure logic, mostly no DB: SERP locale/country catalogs
(`SerpGlCatalogTest`, `SerpLocaleDefaultsTest`), keyword math (`KeywordValueCalculatorTest`,
`KeywordStrategyAnalyzerTest`), audit config, URL/domain normalization
(`CrawlSiteNormalizeDomainTest`, `ResolveStoredPageUrlTest`), page-locale resolution.

`tests/Feature` (78 files) — boot the app + DB. Grouped:

| Area | Examples |
|---|---|
| `Feature/Api/V1` | `InsightsAuthTest`, `InsightsPayloadTest`, `SeoSuggestionsTest` (plugin/HQ API). |
| `Feature/Dashboard` | `ActionQueueTest`, `SitemapPromptTest`. |
| `Feature/Keywords` | `KeywordResearchHubTest`. |
| `Feature/Competitive` | `CompetitorDiscoveryTest`, `KeywordGap*`, `OpportunityScore*`, `SerpCacheTest`. |
| Onboarding / sources | `OnboardingTest`, `OnboardingDataSourcesTest`, `ConnectSource*Test`, `MultiAccountSourceTest`, `DegradedReportTest` (the GSC/GA presence matrix). |
| Guest tools | `GuestPageAuditTest`, `GuestRankCheckTest`, `GuestKeywordVolumeTest`, `GuestPageSpeedTest`. |
| Sync/reports | `SyncDailyDataTest`, `SyncJobsTimestampsTest`, `SendGrowthReportsTest`, `TrafficDropDetectionTest`. |

### Crawl-related tests

| File | Covers |
|---|---|
| `tests/Feature/SharedCrawlTest.php` | Shared crawl-store invariants: www+apex collapse to one `crawl_site`, per-user cap windows, per-user finding ignore/impact. |
| `tests/Feature/CrawlerPipelineTest.php` | End-to-end pipeline: frontier → `CrawlPageBatchJob` → `PageCrawlProcessor` → `SiteGraphAnalyzer`/`SiteIssueDetector`/`AnalyzeSiteJob`. |
| `tests/Feature/CrawlParamAndExternalTest.php` | URL param handling + external-link treatment. |
| `tests/Feature/CrawlSchedulingTest.php` | Recrawl scheduling / due-date logic. |
| `tests/Feature/AdminClientCrawlTest.php` | Admin-side crawl views. |
| `tests/Unit/CrawlSiteNormalizeDomainTest.php` | Domain normalization (apex/www/scheme). |

### Notable patterns

- **Shared seed helpers are private methods on the test class**, not global fixtures —
  e.g. `SharedCrawlTest::page()` (`SharedCrawlTest.php:24`) builds a `WebsitePage` with
  `crawl_site_id`/`url_hash`. `CrawlerPipelineTest::allowAllGuard()` swaps a permissive
  `SafeHttpGuard` into the container so crawl tests never do real DNS/HTTP.
- **External calls are faked, not made:** `Http::fake` (16 files), `Queue::fake` (23),
  `Bus::fake` (2), `Mockery`/`mock()` (17). Livewire components tested via `Livewire::test`
  (5 files). `QUEUE_CONNECTION=sync` means un-faked jobs run inline.
- **Baseline is GREEN (0 failures) as of 2026-07-11** — a 42-failure backlog was cleared
  in one sweep (see main.md changelog). Recurring landmines to avoid reintroducing:
  - **sqlite date-string artifact (FIXED at the model):** Eloquent's `'date'` cast writes
    `'Y-m-d H:i:s'`; MySQL `DATE` columns truncate it server-side but sqlite (TEXT) keeps
    the full string, so `whereBetween('date', [..,'Y-m-d'])` string-compares the row OUT
    of the window — reports empty **in tests only**. Fixed via
    `SearchConsoleData::setDateAttribute()` (normalizes to `Y-m-d` on write). Any new
    model with a DATE column + window queries needs the same mutator, or seed via
    `DB::table()` with plain strings.
  - **`#[Lazy]` Livewire components render only their placeholder** in both HTTP GETs and
    `Livewire::test()` — call `Livewire::withoutLazyLoading()` (setUp) before asserting on
    their real markup. Bit `DashboardCacheTest`, `SyncAndReportPanelTest`,
    `InsightsViewsTest`.
  - **Tests without `RefreshDatabase` 500 on any request** now that `SetLocale` reads the
    `settings` table each request (`LocaleConfig` fails safe to English if the table is
    missing, but other `Setting::get` callers — e.g. the plugin version endpoint — don't).
  - **Unit tests are not DB-free by default:** `SerperSearchClient` logs every call to
    `client_activities`, so even "pure" client tests need `RefreshDatabase`.

---

## CI — `.github/workflows/tests.yml`

| Aspect | Value |
|---|---|
| Triggers | `push` to `master`/`*.x`, every `pull_request`, nightly `cron 0 0 * * *`. |
| Matrix | PHP `8.2`, `8.3`, `8.4` (`fail-fast: true`). |
| Steps | checkout → `setup-php` (incl. `sqlite`, `pdo_sqlite`) → `composer install` → `cp .env.example .env` → `php artisan key:generate` → `php artisan test`. |

Companion workflows are Laravel-skeleton helpers, not test runs: `issues.yml`,
`pull-requests.yml`, `update-changelog.yml`.

### Why CI is safe but the prod box is not

- CI runs on a **throwaway `ubuntu-latest` runner** with no DB server. `.env.example` says
  `DB_CONNECTION=mysql` (`.env.example:27`), but **there is no MySQL to hit** — and
  `phpunit.xml`'s `<env>` pins sqlite `:memory:` at runtime. `RefreshDatabase`'s
  `migrate:fresh` only ever touches an ephemeral in-memory DB that vanishes with the runner.
- CI does **not** run `php artisan optimize`, so no `bootstrap/cache/config.php` exists to
  override `phpunit.xml`. The exact precondition of the incident is absent.
- The production box, by contrast, has a **live `ebq` DB as the default connection** and
  *can* carry a cached config from `php artisan optimize`. Same command, opposite blast radius.

### Gotchas

- **Trigger/branch mismatch:** CI pushes only run on `master`/`*.x`, but the repo's default
  branch is **`main`**. Pushes to `main` don't trigger the workflow — only PRs and the nightly
  cron cover it. Worth aligning if push-CI on the trunk is expected.
- CI is the only environment where you can run the suite without first thinking about the
  safety procedure above. On the prod box, always `config:clear` and verify the connection.
