# Usage metering & client activity

How EBQ records every paid external-API call, attributes it to the billed account,
enforces per-plan monthly caps, and surfaces cost to operators.

## Data model — `client_activities`

Migration `2026_04_25_091000_create_client_activities_table.php` (+ `…150000_add_units_consumed…`).
Model: `app/Models/ClientActivity.php`.

| Column | Type | Meaning |
|---|---|---|
| `user_id` | FK? nullOnDelete | **The BILLED account** — who pays for this work. |
| `actor_user_id` | FK(users)? | The human who *triggered* it (admin during impersonation, not the client). |
| `website_id` | FK? nullOnDelete | The site the work was for (nullable for account-level events). |
| `type` | string(80) | Event name, e.g. `crawl.subscribed`, login, OAuth, keyword lookups. |
| `provider` | string(80)? | The metered external provider (see below). null for non-metered events. |
| `meta` | json? | Free-form context (chunk sizes, crawl_site_id, cap, etc.). |
| `units_consumed` | uint? | Denormalised metered quantity — what the cap/cost math SUMs. |

Indexes: `(type, created_at)`, `(provider, created_at)`, plus
`(provider, created_at, user_id)` and `(provider, created_at, website_id)` added with
`units_consumed` specifically so the admin usage page can `SUM(units_consumed) WHERE
provider IN (...)` cheaply.

### Metered providers + unit semantics

| `provider` | Unit (`units_consumed`) | Cost source |
|---|---|---|
| `keywords_everywhere` | KE credit cost (≈ keywords looked up) | `services.keywords_everywhere.cost_per_keyword_usd` (~$0.0001) |
| `serp_api` | one call = one unit (legacy name; plan JSON namespaces as `serper`) | `services.serper.cost_per_call_usd` (~$0.0003) |
| `mistral` | total tokens | `services.mistral.cost_per_token_usd` (~$0.0000003) |
| `crawl_reuse` | `min(crawled_pages, cap)` — visibility/analytics only, **not capped** | not priced in the usage dashboard |
| `brief` | (research-engine; logged, not yet capped) | — |

> `serp_api` vs `serper`: the *stored provider string* is `serp_api` (legacy); the
> *plan `api_limits` namespace* is `serper`. `UsageMeter::PROVIDER_LIMIT_PATHS` bridges
> them. Don't "fix" one without the other.

## Attribution — `ClientActivityLogger` (`app/Services/ClientActivityLogger.php`)

The **single funnel** for writing `client_activities`. Two attribution rules decide who
appears on the admin usage page:

- **`user_id` = billed owner.** When `website_id` is set, `user_id` is **forced** to the
  site's owner — `website_user` row with `role='owner'` (modern, team-aware), falling
  back to `websites.user_id` (legacy creator). Any caller-supplied `userId` is only a
  fallback. A per-request `websiteOwnerCache` avoids N DB hits for a 100-keyword call.
- **`actor_user_id` = real human.** During admin impersonation `Auth::id()` is the
  *impersonated client*, so the logger prefers `session('impersonator_id')`. On queue
  workers / CLI (no session) it falls through to `Auth::id()` (often null).

Callers wrap each external client (`KeywordsEverywhereClient`, `SerperSearchClient`,
`Llm/MistralClient`, the backlink client) and `CrawlSiteBootstrapper` (the
`crawl_reuse` charge). Pattern: `assertCanSpend()` **before** the call, then
`log(..., unitsConsumed: <n>)` **after** a successful call.

## Enforcement — `UsageMeter` (`app/Services/Usage/UsageMeter.php`)

One **monthly window per user**, anchored to the **subscription start day** (active
Cashier sub's `created_at`, else the user's `created_at`, else start-of-month). Billing
is yearly but **usage caps reset monthly**.

- `currentWindowStart($user)` — most-recent anchor-day boundary ≤ now, with month-length
  clamping (anchor day 31 in a 30-day month → last day) and never rolling before the
  account/sub start.
- `consumedInWindow($user, $provider)` — `SUM(units_consumed)` since window start.
- `limit($user, $provider)` — plan's `api_limits` cap via `PROVIDER_LIMIT_PATHS`; null = unlimited.
- `remaining()` — `max(0, limit - consumed)`.
- `assertCanSpend($user, $provider, $units=1)` — throws `QuotaExceededException` (402)
  if `used + units > limit`; **no-op when limit is null** (unlimited).

### Rank-tracker cap (different shape)

Tracked keywords are capped by **active row count**, not a monthly sum:
`activeTrackedKeywordCount()` (active `RankTrackingKeyword` rows) vs
`rankTrackerCap()` (plan `rank_tracker.max_active_keywords`). Enforced in
`RankTrackingKeywordObserver`.

### The 402 — `QuotaExceededException`

`app/Exceptions/QuotaExceededException.php` extends `HttpException(402)`. `toPayload()`
emits `{error: quota_exceeded, provider, limit, used, message, upgrade_url}` so the WP
plugin and platform UI render a friendly banner + Upgrade CTA pointing at `/billing`.

## Admin "API Usage" dashboard (`Admin\UsageController`)

`/admin/usage`. Aggregates `client_activities` over a window (7/30/90/custom) for the
three priced providers (`keywords_everywhere`, `serp_api`, `mistral` — note
`crawl_reuse`/`brief` are excluded from cost views). Surfaces:

- **Summary cards** — period / this-month / lifetime units + cost.
- **Top clients** — provider×user pivot → per-user units + cost (capped 50).
- **Top websites** — same by `website_id` (capped 30).
- **Daily series** — gap-filled sparkline per provider.
- **Per-client utilisation** — for each top client, current **subscription-anchored
  window** consumption vs plan cap (uses `UsageMeter` per user; bounded loop). This is
  the canonical "about to hit their cap?" view, independent of the date filter.
- **Recent calls feed** — most-recent 50.

Cost rates come from `config/services.php` (`rates()`, line 189) so contracts can be
tuned without a redeploy.

## Gotchas

- **Fixed 2026-07-06 — `assertCanSpend()` now reserves atomically.**
  `units_consumed` is still only logged to `client_activities` *after* the external
  call completes (seconds to minutes later), so the DB sum alone can't see an
  in-flight call. `UsageMeter::assertCanSpend()` now also atomically increments a
  Redis-backed reservation counter (`Cache::add()`+`increment()`, 600s TTL —
  self-expires if a request crashes without logging) once the check passes, and
  folds `pendingReserved()` into the next check's `used` total. `ClientActivityLogger
  ::log()` (the single funnel — release now lives there, not in every call site)
  releases the reservation once the real row is written. `consumedInWindow()`/
  `remaining()` deliberately still read pure DB history — only the enforcement
  check accounts for in-flight reservations. Covered by
  `tests/Feature/UsageMeterReservationTest.php`.
- **`crawl_reuse` is metered but uncapped + unpriced** — it's a visibility row
  (`min(crawled_pages, cap)`), excluded from the admin cost dashboard's provider list.
- **Window is per-user, not per-website** — usage rolls up to the billed owner; a
  teammate's calls count against the owner's window.
- **`serp_api` (stored) ≠ `serper` (plan namespace).** Bridged in
  `UsageMeter::PROVIDER_LIMIT_PATHS` + `UsageController::rates()`.
- **`research_limits` plan column is not yet enforced** by `UsageMeter` — only
  `api_limits` paths gate spend today.
- **Owner resolution prefers the pivot `owner` role**, falling back to legacy
  `websites.user_id`; mis-set pivot roles mis-attribute spend.

## Key files

- `app/Models/ClientActivity.php`
- `app/Services/ClientActivityLogger.php`
- `app/Services/Usage/UsageMeter.php`
- `app/Exceptions/QuotaExceededException.php`
- `app/Http/Controllers/Admin/UsageController.php`
- `app/Observers/RankTrackingKeywordObserver.php` (tracker cap)
- `database/migrations/2026_04_25_091000_create_client_activities_table.php`
- `database/migrations/2026_04_25_150000_add_units_consumed_to_client_activities.php`
- Metered callers: `app/Services/{KeywordsEverywhereClient,SerperSearchClient,Llm/MistralClient,KeywordsEverywhereBacklinkClient}.php`, `app/Services/Crawler/CrawlSiteBootstrapper.php`
