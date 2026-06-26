# Plans & feature gating

How a `plans` row defines a tier, and how every read-path answers "what plan is this
user on, what features can they use, and how many sites / crawl pages do they get".

## The five tiers (2026-06-26 rework)

Seeded idempotently by `PlanSeeder` (`updateOrCreate` by `slug`). Caps below are the
*seed defaults* — operators tune them live from `/admin/plans/<id>/edit` with no deploy.

| slug | name | monthly $ | yearly $ | max_websites | max_seats | max_crawl_pages | tracker kw | quick_win results |
|---|---|---|---|---|---|---|---|---|
| `trial` | Trial | 0 | — | 1 | 1 | 20,000 | 20 | 5 |
| `solo` | Solo | 19 | 168 | 3 | 1 | 100,000 | 100 | 10 |
| `pro` | Pro | 49 | 444 | 10 | 3 | 300,000 | 500 | 20 |
| `agency` | Agency | 99 | 888 | 30 | 10 | 1,000,000 | 2,000 | 30 |
| `enterprise` | Enterprise | — | — (contact sales) | ∞ | ∞ | ∞ | ∞ | ∞ |

`null` on any cap = **unlimited**. The four old tiers (`free`/`pro`/`startup`/`agency`)
were renamed to `legacy_*` and deactivated by migration
`2026_06_26_000100_rename_legacy_plan_slugs` — existing subscribers continue resolving
via Stripe price ID, entitlements unchanged.

Tier ordinal ranking lives in `User::TIER_ORDER`
(`trial`=0 < `solo`=1 < `pro`=2 < `agency`=3 < `enterprise`=4), used by `User::isAtLeast()`.

`trial` is the **no-subscription default tier** (was `free`). `resolveSubscribedPlan()` falls
back to `Plan::where('slug', 'trial')` when no active subscription or snapshot exists.
No countdown/expiry job exists — a user stays on Trial until they start a paid sub.
`TIER_FREE` constant kept as a backward-compat alias for `TIER_TRIAL`.

### Unenforced limits (seeded only — matching `research_limits` precedent)

| api_limits path | Enforced? | Notes |
|---|---|---|
| `keyword_research.monthly_searches` | ❌ no | no monthly counter today |
| `keyword_research.max_results_per_search` | ❌ no | no monthly counter today |
| `ai_studio.monthly_tokens` | ❌ no | distinct from the enforced `mistral.monthly_tokens` raw-cost cap |
| `long_form.monthly_articles` | ❌ no | no article-count metering |
| `plan_features.scheduled_reports` | ❌ no | feature doesn't exist yet |

### New enforced limits

| Field | Enforcement point |
|---|---|
| `max_seats` | `WebsiteTeam::inviteMember()` — blocks new invites; existing members grandfathered |
| `api_limits.quick_win_finder.results_shown` | `QuickWinsCard::render()` — replaces the hardcoded `5` |

## Plan data model (`app/Models/Plan.php`)

| Column | Cast | Meaning |
|---|---|---|
| `slug` | string | Immutable public identifier (Stripe webhook lookup, WP plugin, in-flight checkout). |
| `price_monthly_usd` / `price_yearly_usd` | int | Display + checkout. Only yearly is charged. |
| `stripe_price_id_monthly` / `_yearly` | string? | Stripe price IDs (nullable; `price_*` regex-validated). Checkout uses yearly. |
| `trial_days` | int | Cashier `trialDays()` at checkout. |
| `max_websites` | int? | Site cap. null=unlimited. → `User::websiteLimit()`. |
| `max_crawl_pages` | int? | ACCOUNT-WIDE page budget pooled across all of the owner's sites (not per-site). Each site is still hard-capped at `crawler.max_pages_per_site` regardless. null=no pool (hard per-site cap still applies). → `Website::crawlPageCap()`. |
| `max_seats` | int? | Team member cap per website. null=unlimited. Enforced on new invites only; existing members grandfathered. → `WebsiteTeam::inviteMember()`. |
| `extra_seat_price_usd` | int? | Display-only per-seat add-on price. No per-seat billing engine exists. |
| `features` | array | Marketing bullet list (plain strings) for /pricing + WP wizard. |
| `feature_videos` | array? | Sparse `bulletIndex => YouTube URL` map; kept separate from `features`. |
| `plan_features` | array | **The 9-key boolean entitlement matrix** (the gating ceiling). |
| `api_limits` | array? | Per-provider monthly caps (see usage.md). |
| `research_limits` | array? | Per-plan research-engine caps (keyword_lookup/serp_fetch/llm_call/brief). Column exists; currently only declared on the model. |
| `is_active` / `is_highlighted` / `display_order` | — | Deprecate without orphaning; pricing-card layout. |

Key methods:
- `featureMap()` (line 197) — merges stored `plan_features` over an all-false
  `FEATURE_KEYS` skeleton → always a complete 9-key map (zero-fills new flags).
- `apiLimit('serper.monthly_calls')` (line 127) — dot-path read of `api_limits`; null = unlimited.
- `isCheckoutReady()` (line 177) — `price_yearly_usd > 0 && stripe_price_id_yearly` set. Free always false.
- `requiredPlanFor($key)` (line 222) — cheapest active plan (by `display_order`) that enables a feature; powers the plugin's "Upgrade to <tier>" copy.

### `FEATURE_KEYS` (the 10 entitlement flags)

`chatbot, ai_writer, ai_inline, live_audit, hq, redirects, dashboard_widget,
post_column, report_whitelabel, scheduled_reports`. Mirrored across three places that
**must stay in sync**: `Plan::FEATURE_KEYS`, `Website::FEATURE_KEYS` (8 keys — excludes
the two platform-only flags), and the WP plugin's `EBQ_Feature_Flags::KNOWN_FEATURES`.
`report_whitelabel` and `scheduled_reports` are *platform* features — they must NOT be
added to `Website::FEATURE_KEYS` or the WP plugin. `scheduled_reports` is seeded but
not yet enforced (no Scheduled Reports feature exists to gate).

## Resolving the user's plan (`User::effectivePlan()`, line 291)

Resolution order:
1. **Free-promo** (`config('app.free')`): upgrade to the **Pro** row, but only if it
   *raises* the tier (never downgrades Startup/Agency). Falls through if Pro row missing.
2. **Active Cashier subscription** → match `stripe_price` to a Plan by `stripe_price_id_yearly`.
3. **`current_plan_slug` snapshot** (set by webhook + optimistically on swap/success).
4. **The `free` Plan row** — so admin edits to Free's `max_websites`/features apply to
   free-tier users.

Returns null **only** if the `plans` table is empty (fresh install pre-seeder).
Derived helpers: `effectiveTier()` (slug), `isPro()`, `isAtLeast($slug)`,
`effectivePlanFeatures()`, `websiteLimit()`, `crawlPageLimit()`, `frozenWebsiteIds()`.

## Website caps

- **Site cap / freeze** — `User::frozenWebsiteIds()` (line 452): computed **live**
  (no stored column), oldest sites by `created_at` stay active, sites past the limit
  are frozen. A downgrade therefore freezes the newest sites on the next read.
  `canAddWebsite()` gates onboarding/add-site.
- **Crawl cap** — `Website::crawlPageCap()` (line 705): two layers. (1) a universal
  hard per-site ceiling, `config('crawler.max_pages_per_site')` (default 20,000),
  applied to every website regardless of plan — this bounds `AnalyzeSiteJob`'s
  finalize cost on huge domains. (2) the owner's `max_crawl_pages` is an
  ACCOUNT-WIDE pool shared across all the owner's sites; this site's cap is
  `min(hard cap, pool remaining after the owner's OTHER sites' usage)`, floored
  at 1 (never 0 — a site is never fully blocked, just reduced to homepage-only).
  Pool usage per sibling site is itself capped at the hard ceiling, so the
  formula has no recursion. Always a positive int the crawler uses directly as
  the run budget. (Cross-ref `infra/crawler/data-model.md` — the shared crawl
  is fetched at the **max cap among subscribers**, unchanged by this.)

## Feature-flag resolution chain (`Website::effectiveFeatureFlags()`, line 200)

The plugin's per-site feature map is composed **highest-priority "off" wins**:

1. **Freeze** → all-off, short-circuit (over-limit sites behave like locked trials).
2. **Plan ceiling** → start from owner's `effectivePlanFeatures()` (orphan/userless
   sites fall back to `Website::FEATURE_DEFAULTS` so test fixtures don't 500).
3. **Per-site override** (`websites.feature_flags` JSON) → can only **narrow** (turn a
   plan-allowed flag off); a per-site `true` on a plan-disallowed flag is **ignored**.
4. **Global kill-switch** (`settings.global_feature_flags`) → AND'd last; an emergency
   disable propagates regardless of plan/per-site state.
5. Trimmed to the 8 plugin-shipped `Website::FEATURE_KEYS` (drops `report_whitelabel`).

### How a feature gets gated end-to-end

1. Plugin makes an authed API call. `InjectFeatureFlags` middleware stamps `tier`
   (`User::effectiveTier()`, freeze-aware) + `features` (`effectiveFeatureFlags()`)
   onto the response so the plugin's UI hides locked features.
2. Server-side enforcement: `Website::featureGateInfo($key)` (line 292) returns null
   when allowed, else a 402 payload with one of two error codes:
   - `tier_required` — owner's plan lacks it → `required_tier` = cheapest qualifying
     plan (`featureRequiresUpgrade()` → `Plan::requiredPlanFor()`). **Frozen sites also
     get this** (user can unfreeze by upgrading / removing sites).
   - `feature_disabled` — plan allows it but global kill-switch or per-site override
     turned it off → no upgrade fixes it; a workspace admin must flip it back.

> Note: `TeamPermissions` (`app/Support/TeamPermissions.php`) is a **separate**
> gating axis — it governs which *in-app pages* a teammate (member/admin/owner) can
> see (`User::hasFeatureAccess()`), not which *plan features* are unlocked. Owner/admin
> get everything; members get a permission subset. Don't conflate the two FEATURE maps.

## Admin editing (`Admin\PlanController`)

- Slug required + immutable on create (`unique:plans,slug`); never editable after.
- Stripe price IDs must match `^price_` (defends against pasting `prod_*`).
- `features` textarea → array, one bullet per line; an optional `Bullet | <YouTube URL>`
  suffix populates `feature_videos` position-keyed to the cleaned list.
- `api_limits`: blank field → dropped → null = unlimited (round-trips cleanly).
- `plan_features`: checkbox payload normalised by **explicitly false-filling all 9
  keys** — that's what makes "untick + save" actually remove a flag instead of leaving
  the stale DB value.

## Gotchas

- **Three FEATURE_KEYS lists must stay in sync** (Plan / Website / WP plugin). Adding a
  flag in one place without the others silently no-ops.
- **`research_limits` is declared but not yet consumed** by `UsageMeter` — only
  `api_limits` paths are enforced today.
- **`effectiveFeatureFlags` plan map is wider than the plugin keys** — the final
  `array_intersect_key` trim is what stops platform-only flags leaking into the public
  payload.
- **Freeze is plan-derived, computed live** — there's no `frozen` column. A plan change
  freezes/unfreezes on the next read with no migration; great for correctness, but means
  every consumer that cares must call through the model methods, not read a column.
