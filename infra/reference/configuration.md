# Configuration reference

Cross-cutting tour of the 16 `config/*.php` files and the `.env` knobs that drive
them. Companion to [server-deployment.md](../server-deployment.md) (the running box
inventory) and [deployment-and-queues.md](../deployment-and-queues.md) (topology +
queues) — this file is the *config-surface* view: what each file controls, the
load-bearing knobs, and the gotchas. **No secret values appear here — env var NAMES
and non-secret defaults only.**

> ⚠️ Production runs `APP_ENV=local` / `APP_DEBUG=true`, and a **stale cached config**
> (`bootstrap/cache/config.php`) once overrode `phpunit.xml` and wiped the DB
> (`CLAUDE.md`). Treat the cached config as authoritative over these files at runtime;
> `php artisan config:clear` before trusting what a file says.

---

## The 16 config files at a glance

| File | Purpose | Most load-bearing knob(s) |
|---|---|---|
| `app.php` | Identity, env, URLs, feature toggles | `APP_KEY`, `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_PUBLIC_URL`, `FREE` |
| `auth.php` | Guards / providers / password reset | `web` session guard → `User` model; reset token TTL 60 min |
| `cache.php` | Cache store wiring | `CACHE_STORE` (prod = redis); `REDIS_CACHE_CONNECTION=cache` (DB 1) |
| `database.php` | DB + Redis connections | `DB_CONNECTION` (sqlite default!), `mysql`/`mariadb` knobs, two Redis conns |
| `queue.php` | Queue backends + retry | `QUEUE_CONNECTION`, `REDIS_QUEUE_RETRY_AFTER` (prod **1320**) |
| `filesystems.php` | Disks | `FILESYSTEM_DISK`, `s3` (`AWS_*`) |
| `logging.php` | Monolog channels | `LOG_CHANNEL=stack`, `LOG_STACK`, `LOG_LEVEL` |
| `mail.php` | Mailers incl. custom **`postal`** | `MAIL_MAILER=postal`, `POSTAL_SMTP_*`, `MAIL_FROM_*` |
| `session.php` | Session driver/cookie | `SESSION_DRIVER` (DB), `SESSION_SECURE_COOKIE`, `SESSION_DOMAIN` |
| `sanctum.php` | API token / SPA auth | `SANCTUM_STATEFUL_DOMAINS`; guard `web`; expiration `null` (no TTL) |
| `sentry.php` | Error/perf reporting | `SENTRY_LARAVEL_DSN`, sample rates, ignore lists |
| `services.php` | **All external integrations** | google / serper / lighthouse / keywords_everywhere / keyword_finder / mistral / stripe / recaptcha / microsoft / **hetzner** (fleet autoscaler: `HCLOUD_TOKEN` + network/ssh/firewall/snapshot ids — web box only) |
| `crawler.php` | Self-hosted crawler tuning | batch/passes/recrawl/simhash/proxy — see [crawler/](../crawler/) |
| `audit.php` | Page-audit GSC window | `gsc_keyword_lookback_days_*`, competitor-KE default |
| `reports.php` | Growth-report freshness | `REPORT_GSC_LAG_DAYS` (default 3) |

---

## app.php  (`config/app.php`)

Standard Laravel identity file with three EBQ additions:

- **`public_url`** (`app.php:67`) — browser-facing URL returned to the WordPress plugin
  for "Open in EBQ" deep-links, when `APP_URL` is an internal host. Resolution chain:
  `APP_PUBLIC_URL` → `EBQ_PUBLIC_URL` → `APP_URL` → `https://ebq.io`. Mirrored in
  `services.ebq.public_url` (`services.php:190`) for signed plugin links.
- **`free`** (`app.php:78`) — `FREE` env flag. When true, **every website is treated as
  Pro** for feature gating and the pricing page switches to a free-access announcement.
  A promotional kill-switch — verify before toggling (it bypasses all plan gates).
- **`previous_keys`** (`app.php:125`) — `APP_PREVIOUS_KEYS` (comma list) lets `APP_KEY`
  rotate without invalidating already-encrypted values.
- Timezone hard-coded **UTC** (`app.php:91`); per-user TZ is applied in views, not here.

## auth.php / sanctum.php / session.php

- **auth**: single `web` session guard → eloquent `User` (`AUTH_MODEL`). Password reset
  tokens expire 60 min, throttle 60 s. Password-confirmation window `AUTH_PASSWORD_TIMEOUT`
  (3 h).
- **sanctum**: guards `['web']`; **`expiration => null`** — issued API tokens never expire
  by TTL (the WordPress-plugin per-website tokens rely on this). `SANCTUM_STATEFUL_DOMAINS`
  must list any first-party SPA origin.
- **session**: `SESSION_DRIVER` defaults **database** (`sessions` table). Watch
  `SESSION_SECURE_COOKIE` (must be true behind HTTPS), `SESSION_DOMAIN`, and `same_site=lax`.

## cache.php  (`config/cache.php`)

- `CACHE_STORE` — framework default is `database`; **production uses `redis`** (see
  server-deployment). The `redis` cache store uses `REDIS_CACHE_CONNECTION=cache` →
  **Redis DB 1** (`database.php:175`), separate from the queue/default DB 0.
- `CACHE_PREFIX` defaults to `<app-name>-cache-`.
- ⚠️ Redis runs `maxmemory-policy noeviction` — cache and queues share the instance, so a
  cache-key flood cannot evict queued jobs (correct, but cache can OOM instead).

## database.php  (`config/database.php`)

- **`DB_CONNECTION` default is `sqlite`** — production sets `mysql` (driving **MariaDB
  10.11**; the `mysql` driver speaks to it fine). The TestCase guard (`CLAUDE.md`) refuses
  to run the suite unless the resolved connection is sqlite `:memory:` or a `*test*` DB.
- `mysql`/`mariadb` blocks: `DB_HOST` (prod `10.0.0.2`), `DB_PORT`, `DB_DATABASE` (`ebq`),
  `DB_USERNAME`, `DB_PASSWORD` **(secret)**, `DB_SOCKET`, `DB_CHARSET=utf8mb4`, optional
  `MYSQL_ATTR_SSL_CA`.
- **Two Redis connections** (`database.php:156`/`169`): `default` → DB 0 (queues), `cache`
  → DB 1. Shared knobs: `REDIS_HOST`, `REDIS_PASSWORD` **(secret)**, `REDIS_PORT`,
  `REDIS_CLIENT=phpredis`, `REDIS_PREFIX`, backoff (`REDIS_BACKOFF_*`).

## queue.php  (`config/queue.php`)

- `QUEUE_CONNECTION` — framework default `database`; production uses **`redis`** (workers
  poll the `default`/`interactive`/`crawl`/`sync` queues across both boxes).
- **`REDIS_QUEUE_RETRY_AFTER` — config default is 90, but production MUST set it to
  `1320`** (`queue.php:71`). It has to exceed the longest job `--timeout` (1200 s for
  `AnalyzeSiteJob` on the worker box); if `retry_after < timeout`, a still-running job is
  re-dispatched as a duplicate. Memory `large-site-enrichment`.
- `QUEUE_FAILED_DRIVER=database-uuids` → `failed_jobs`. Batching table `job_batches`.
- Queue names are centralized in `App\Support\Queues` (`INTERACTIVE` etc.), not in env.

## mail.php  (`config/mail.php`)

See [mail-and-wiring.md](./mail-and-wiring.md) for the full mailer + transport story. Key
facts: `MAIL_MAILER=postal` selects the custom `postal` mailer (`mail.php:56`), a plain SMTP
transport pointed at `127.0.0.1:25` (`POSTAL_SMTP_*`). **`MAIL_HOST=smtp.sendgrid.net` in
`.env` is stale/leftover** — it belongs to the unused `smtp` mailer, not `postal`. Global
sender = `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME`.

## logging.php  (`config/logging.php`)

Stock channels. `LOG_CHANNEL=stack`, `LOG_STACK` (default `single`) → `storage/logs/laravel.log`,
`LOG_LEVEL` (default `debug`). Sentry is wired separately (see below), not as a log channel.

## sentry.php  (`config/sentry.php`)

- `SENTRY_LARAVEL_DSN` **(secret)** — empty DSN fully disables Sentry (safe for local/dev).
  Falls back to `SENTRY_DSN`.
- `sample_rate` 1.0 (all errors). **Performance/profiling tracing is opt-in** —
  `SENTRY_TRACES_SAMPLE_RATE` / `SENTRY_PROFILES_SAMPLE_RATE` resolve to `null` (off) unless
  explicitly set, so no perf overhead ships by default.
- `send_default_pii=false` — IPs/emails/usernames scrubbed before leaving the box (GDPR
  commitment). Do not flip without a privacy review.
- `ignore_exceptions` (`sentry.php:91`): 404, 405, auth challenge, validation — never paged.
  `ignore_transactions`: the `/up` health check.
- Wired into the framework in `bootstrap/app.php:47` via `Integration::handles($exceptions)`.

## crawler.php  (`config/crawler.php`)

Self-hosted crawler tuning. Full semantics live in [crawler/](../crawler/); the load-bearing
knobs:

| Knob | Default | Why |
|---|---|---|
| `CRAWLER_BATCH_SIZE` | 25 | pages per `CrawlPageBatchJob` |
| `CRAWLER_PAGES_PER_PASS` | 1000 | **fairness** — max pages a single pass enqueues before yielding the queue (stops one big site monopolising the shared crawl queue). Does NOT cap total pages |
| `CRAWLER_MAX_PASSES` | 6 | **deprecated** — superseded by `pages_per_pass`; `CrawlPassJob` derives its own runaway ceiling |
| `CRAWLER_MAX_PAGES_PER_RUN` | 200000 | per-run page budget (fallback when `effective_cap` is 0) |
| `CRAWLER_STALL_MINUTES` / `CRAWLER_MAX_RUN_HOURS` | 10 / 6 | `ebq:crawl-supervisor` watchdog: resume a run idle this long; force-finalize past this age |
| `CRAWLER_DELAY_MS` / `CRAWLER_TIMEOUT` | 250 / 20 | local per-batch politeness delay / per-page fetch timeout (s) |
| `CRAWLER_RATE_MAX_WAIT_MS` | 5000 | `DomainRateLimiter` max wait for a domain token before fail-open (fleet-wide per-domain rate = the `autoscaler.per_domain_rate` setting) |
| `CRAWLER_RECRAWL_MIN/BASE/MAX_DAYS` | 3 / 7 / 30 | adaptive recrawl backoff window |
| `CRAWLER_SIMHASH_THRESHOLD` | 3 | Hamming distance = "significant change" (memory `incremental-crawling`) |
| `CRAWLER_SITEMAP_TRUST_MIN_SAMPLE` / `_RATIO` | 20 / 0.3 | when to trust sitemap `<lastmod>` |
| `CRAWLER_MAX_EXTERNAL_CHECKS` | 500 | broken-external-link cap per run |
| `CRAWLER_STRIP_QUERY_PARAMS` | true | collapse `?utm=…` variants; `keep_query_params` = page/p/paged |
| `CRAWLER_PRUNE_BODY_TEXT` | **false** | irreversible body_text trim — OFF (no DB backups). Memory `term-extraction` |
| `CRAWLER_EGRESS_IP` | — | worker box public IP shown to clients for WAF allowlisting |
| `CRAWLER_PROXY_ENABLED` / `_MODE` / `_FILE` / `_MAX_FAILURES` / `_AUTO_IMPORT` | false / on_block / `proxylist.txt` / 5 / **false** | proxy pool (`App\Services\Crawler\ProxyPool`). `_AUTO_IMPORT` gates only the scheduled free-list import (`ebq:proxy-list-refresh`); the always-on prune (`ebq:proxy-pool-prune`, deletes dead proxies every 15min) is unaffected by it — see [crawler/known-issues.md](../crawler/known-issues.md) |

## audit.php  (`config/audit.php`)

Page-audit GSC keyword window. `gsc_keyword_lookback_days_default` 28 (per-website override
in Settings → Reports, clamped 7–480). `competitor_keywords_everywhere_default` **false** —
audits don't bill Keywords Everywhere for competitor SERP domains unless enabled in
Admin → Page audits.

## reports.php  (`config/reports.php`)

`REPORT_GSC_LAG_DAYS` (default **3**). GSC finalizes daily numbers 24–72 h late, so the
automatic growth report snaps "current day" to the most recent `search_console_data` date
that is also ≥ this many days old, then compares periods. Without it the email compares two
partial days and reads as a false regression. Floored at 1 in the helper.

---

## services.php — external integrations  (`config/services.php`)

The consolidated credentials surface. Every block reads from env; **all `*_key`/`*_secret`/
`*_API_KEY` values are secrets.** Non-secret knobs (URLs, cost-per-call, TTLs, caps) are
listed because they change behaviour/billing.

| Integration | Block | Secret env | Non-secret knobs (default) |
|---|---|---|---|
| **Google OAuth + CAP** | `services.php:80` | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | `GOOGLE_REDIRECT_URI`, `GOOGLE_CAP_AUDIENCE`, `GOOGLE_CAP_JWKS_URL` (Google certs), `GOOGLE_CAP_ISSUERS` (accounts.google.com) |
| **Mistral (LLM)** | `services.php:212` | `MISTRAL_API_KEY` | `MISTRAL_MODEL` (`mistral-small-latest`), `MISTRAL_INPUT_USD_PER_M` (0.10), `MISTRAL_OUTPUT_USD_PER_M` (0.30) |
| **DeepSeek (LLM, admin-switchable alternative)** | `services.php:227` | `DEEPSEEK_API_KEY` | `DEEPSEEK_MODEL` (`deepseek-chat`), `DEEPSEEK_INPUT_USD_PER_M` (0.27), `DEEPSEEK_OUTPUT_USD_PER_M` (1.10), `DEEPSEEK_COST_PER_TOKEN_USD` (0.0000011). Active provider = Setting `ai.llm.provider`, see `infra/ai/llm.md` |
| **Serper (SERP)** | `services.php:90` | `SERPER_API_KEY` | `SERPER_SEARCH_URL`, `SERPER_COST_PER_CALL_USD` (0.0003) |
| **Lighthouse (PageSpeed)** | `services.php:99` | `LIGHTHOUSE_API_KEY` | `LIGHTHOUSE_API_URL`, `LIGHTHOUSE_TIMEOUT_S` (90) |
| **Keywords Everywhere** | `services.php:105` | `KEYWORDS_EVERYWHERE_API_KEY` | `_BASE_URL`, `_FRESH_DAYS` (30), `_COST_PER_KEYWORD_USD` (0.0001), `_BACKLINKS_ENDPOINT/COUNTRY/CURRENCY/DATASOURCE`, `KE_BACKLINKS_TTL_DAYS` (30) |
| **Keyword Finder** (self-hosted fleet) | `services.php:132` | per-server creds in DB (`keyword_api_servers`) | `KEYWORD_FINDER_WEBHOOK_PATH`, `_SIGNATURE_HEADER`, `_FRESH_DAYS` (30), `_REQUEST_TIMEOUT_S` (15), `_POLL_TTL_MINUTES` (5), default location/language. Memory `keyword-finder-limits` |
| **Competitor backlinks** | `services.php:149` | — | `COMPETITOR_BACKLINKS_LIMIT` (50), `_FRESH_DAYS` (30) |
| **Competitive intel** | `services.php:157` | — | discovery/opportunity/gap caps + `COMPETITIVE_SERP_CACHE_DAYS` (7); all are SERP/keyword cost controls |
| **Stripe (billing)** | `services.php:30` | `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` | `STRIPE_WEBHOOK_TOLERANCE` (300 s) |
| **reCAPTCHA v2** | `services.php:61` | `RECAPTCHA_SECRET_KEY` | `RECAPTCHA_SITE_KEY` (both empty = disabled, for local/tests) |
| **Microsoft OAuth** (report-send) | `services.php:73` | `MICROSOFT_CLIENT_SECRET` | `MICROSOFT_CLIENT_ID`, `MICROSOFT_REDIRECT_URI`, `MICROSOFT_TENANT` (`common`) |
| **Language detection** | `services.php:180` | — | `LANGUAGE_DETECTION_ENABLED` (true) |
| **EBQ public URL** | `services.php:189` | — | `EBQ_PUBLIC_URL` (signed WP plugin deep-links) |

Also present but **not** EBQ-active: `postmark`, `resend`, `ses`, `slack` (stock Laravel
stubs). The active LLM is Mistral (bound in `AppServiceProvider`, see mail-and-wiring.md).

---

## Consolidated `.env` knobs by concern

Secrets are marked **🔒**. Defaults shown are the *config-file* defaults (production may
override — cross-ref server-deployment).

### App
| Env | Default | Notes |
|---|---|---|
| `APP_NAME` | Laravel | |
| `APP_ENV` | production (prod sets **local**) | ⚠️ debug behaviour |
| `APP_DEBUG` | false (prod sets **true**) | ⚠️ leaks traces |
| `APP_KEY` 🔒 | — | + `APP_PREVIOUS_KEYS` for rotation |
| `APP_URL` | http://localhost | |
| `APP_PUBLIC_URL` / `EBQ_PUBLIC_URL` | → `APP_URL` / `https://ebq.io` | plugin deep-links |
| `FREE` | false | promo: all sites Pro |
| `APP_LOCALE` / `_FALLBACK_LOCALE` | en | |

### Database
| Env | Default | Notes |
|---|---|---|
| `DB_CONNECTION` | sqlite (prod **mysql**) | |
| `DB_HOST` | 127.0.0.1 (prod `10.0.0.2`) | MariaDB |
| `DB_PORT` | 3306 | |
| `DB_DATABASE` | laravel (prod `ebq`) | |
| `DB_USERNAME` | root | |
| `DB_PASSWORD` 🔒 | — | |
| `DB_CHARSET` / `DB_COLLATION` | utf8mb4 / utf8mb4_unicode_ci | |
| `MYSQL_ATTR_SSL_CA` | — | optional TLS |

### Redis / queue
| Env | Default | Notes |
|---|---|---|
| `REDIS_HOST` | 127.0.0.1 (prod `10.0.0.2`) | |
| `REDIS_PASSWORD` 🔒 | — | `requirepass` set |
| `REDIS_PORT` | 6379 | |
| `REDIS_DB` / `REDIS_CACHE_DB` | 0 / 1 | queue vs cache |
| `REDIS_CLIENT` | phpredis | |
| `QUEUE_CONNECTION` | database (prod **redis**) | |
| `REDIS_QUEUE_RETRY_AFTER` | 90 (prod **1320**) | must exceed max job timeout |
| `CACHE_STORE` | database (prod **redis**) | |
| `SESSION_DRIVER` | database | |

### Mail (see mail-and-wiring.md)
| Env | Default | Notes |
|---|---|---|
| `MAIL_MAILER` | log (prod **postal**) | |
| `POSTAL_SMTP_HOST` | 127.0.0.1 | |
| `POSTAL_SMTP_PORT` | 25 | |
| `POSTAL_SMTP_ENCRYPTION` | — | `ssl` → smtps scheme |
| `POSTAL_SMTP_USERNAME` 🔒 / `POSTAL_SMTP_PASSWORD` 🔒 | — | |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | hello@example.com / `APP_NAME` | |
| `MAIL_HOST` | 127.0.0.1 | ⚠️ stale `smtp.sendgrid.net` in prod, unused |

### Crawler
See the crawler.php table above — all `CRAWLER_*` plus `CRAWLER_EGRESS_IP`.

### Integrations (all secret keys 🔒)
`GOOGLE_CLIENT_ID/SECRET/REDIRECT_URI`, `GOOGLE_CAP_*`, `MISTRAL_API_KEY` (+ model/cost),
`DEEPSEEK_API_KEY` (+ model/cost),
`SERPER_API_KEY` (+ url/cost), `LIGHTHOUSE_API_URL/KEY/TIMEOUT_S`,
`KEYWORDS_EVERYWHERE_API_KEY` (+ ttl/cost/backlinks), `KEYWORD_FINDER_*`,
`MICROSOFT_CLIENT_ID/SECRET/REDIRECT_URI/TENANT`, `COMPETITIVE_*`, `LANGUAGE_DETECTION_ENABLED`.

### Billing / abuse / errors / storage
| Env | Default | Notes |
|---|---|---|
| `STRIPE_KEY` 🔒 / `STRIPE_SECRET` 🔒 / `STRIPE_WEBHOOK_SECRET` 🔒 | — | + `STRIPE_WEBHOOK_TOLERANCE` 300 |
| `RECAPTCHA_SITE_KEY` / `RECAPTCHA_SECRET_KEY` 🔒 | '' | empty = disabled |
| `SENTRY_LARAVEL_DSN` 🔒 | — | empty = disabled |
| `FILESYSTEM_DISK` | local | `s3` → `AWS_*` 🔒 |
| `REPORT_GSC_LAG_DAYS` | 3 | growth-report freshness floor |
