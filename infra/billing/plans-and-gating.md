# Plans & feature gating

How a `plans` row defines a tier, and how every read-path answers "what plan is this
user on, what features can they use, and how many sites / crawl pages do they get".

## The four tiers

Seeded idempotently by `PlanSeeder` (`updateOrCreate` by `slug`). Caps below are the
*seed defaults* — operators tune them live from `/admin/plans/<id>/edit` with no deploy.

| slug | name | yearly $ | trial | max_websites | max_crawl_pages | KE credits/mo | Serper/mo | Mistral tok/mo | tracker kw |
|---|---|---|---|---|---|---|---|---|---|
| `free` | Free | 0 | 0 | 1 | 300 | 100 | 100 | 100k | 10 |
| `pro` | Pro | 60 | 30 | 2 | 5,000 | 750 | 1,000 | 1M | 50 |
| `startup` | Startup | 180 | 0 | 10 | 25,000 | 4,000 | 4,000 | 5M | 200 |
| `agency` | Agency | 420 | 0 | 50 | 50,000 | 8,000 | 6,000 | 12M | 500 |

`null` on any cap = **unlimited**. Slug taxonomy was renamed 2026-05-17
(starter→pro, pro→startup). Tier ordinal ranking lives in `User::TIER_ORDER`
(`free`=0 < `pro`=1 < `startup`=2 < `agency`=3), used by `User::isAtLeast()`.

## Plan data model (`app/Models/Plan.php`)

| Column | Cast | Meaning |
|---|---|---|
| `slug` | string | Immutable public identifier (Stripe webhook lookup, WP plugin, in-flight checkout). |
| `price_monthly_usd` / `price_yearly_usd` | int | Display + checkout. Only yearly is charged. |
| `stripe_price_id_monthly` / `_yearly` | string? | Stripe price IDs (nullable; `price_*` regex-validated). Checkout uses yearly. |
| `trial_days` | int | Cashier `trialDays()` at checkout. |
| `max_websites` | int? | Site cap. null=unlimited. → `User::websiteLimit()`. |
| `max_crawl_pages` | int? | Pages a crawl run may fetch for this plan's sites. null=global crawler budget. → `Website::crawlPageCap()`. |
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

### `FEATURE_KEYS` (the 9 entitlement flags)

`chatbot, ai_writer, ai_inline, live_audit, hq, redirects, dashboard_widget,
post_column, report_whitelabel`. Mirrored across three places that **must stay in
sync**: `Plan::FEATURE_KEYS`, `Website::FEATURE_KEYS` (8 keys — excludes the
platform-only `report_whitelabel`), and the WP plugin's
`EBQ_Feature_Flags::KNOWN_FEATURES`. `report_whitelabel` is a *platform* feature
(branded report emails / per-tenant mail transport), not a plugin flag.

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
- **Crawl cap** — `Website::crawlPageCap()` (line 683): owner's `max_crawl_pages`
  when set+positive, else `config('crawler.max_pages_per_run')`. Always a positive int
  the crawler uses directly as the run budget. (Cross-ref `infra/crawler/data-model.md`
  — the shared crawl is fetched at the **max cap among subscribers**.)

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
