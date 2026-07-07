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
| `price_monthly_usd` / `price_yearly_usd` | int | Display + checkout. Both intervals are billable at checkout — see ⛔ below. |
| `stripe_price_id_monthly` / `_yearly` | string? | Stripe price IDs (nullable; `price_*` regex-validated). New checkout uses whichever interval was requested; subscription resolution afterward matches either via `Plan::findByStripePrice()`. |
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
- `isCheckoutReady($interval = 'annual')` (line 177) — for `monthly`: `price_monthly_usd > 0 && stripe_price_id_monthly` set; otherwise same check against the yearly columns. Free always false.
- `requiredPlanFor($key)` (line 222) — cheapest active plan (by `display_order`) that enables a feature; powers the plugin's "Upgrade to <tier>" copy.

### `FEATURE_KEYS` (the 10 entitlement flags)

`chatbot, ai_writer, ai_inline, live_audit, hq, redirects, dashboard_widget,
post_column, report_whitelabel, scheduled_reports`. Mirrored across three places that
**must stay in sync**: `Plan::FEATURE_KEYS`, `Website::FEATURE_KEYS` (8 keys — excludes
the two platform-only flags), and the WP plugin's `EBQ_Feature_Flags::KNOWN_FEATURES`.
`report_whitelabel` and `scheduled_reports` are *platform* features — they must NOT be
added to `Website::FEATURE_KEYS` or the WP plugin. `scheduled_reports` is seeded but
not yet enforced (no Scheduled Reports feature exists to gate).

## Trial expiry & data cleanup (2026-07-07)

Trial length = the Trial plan's **`trial_days`** (admin-editable at /admin/plans; **0 disables
the whole system**; currently 14). `App\Support\TrialStatus` is the single source of truth for
"expired" — used by BOTH the cleanup command and the lockout middleware so they can't disagree.
Exempt always: admins, active Stripe subscribers, comped (`current_plan_slug` = a paid slug).

- **`ebq:trial-cleanup`** (hourly): countdown emails `expired → 48h → 24h → 12h`
  (`TrialExpiryMail`, one email per user per run), then deletes the user's **websites**
  (tenant data cascades via the existing `Website::deleted` wiring — shared crawl_sites are
  GC'd only when the LAST subscriber leaves, so another client on the same domain is safe).
  **The 72h countdown anchors to the first 'expired' email actually sent**, not the
  theoretical schedule — accounts predating the feature get the full 3-day buffer from first
  contact (dry-run caught 3 users who'd otherwise have had 12h). Login survives; expiry
  derives from `created_at`, so no second trial. `--dry-run` prints without sending/deleting.
- **Team members (2026-07-07)**: only users **owning ≥1 website** (`has('websites')`) enter
  the email/deletion pipeline — a member-only user (rows in `website_user`, zero owned sites)
  gets no emails and nothing deleted; their memberships are never touched. Deletion **clears
  `trial_deletion_notices`** so a re-added site restarts a FRESH countdown (never instant
  delete, never free-forever — the old one-shot `trial_data_deleted_at` query gate was
  removed; the column remains as an audit timestamp). Stale-anchor guard: if every owned
  site was created after the first warning (`created_at <= anchor` check), the countdown
  resets (`TrialCleanup.php` handle()).
- **`EnsureTrialNotExpired`** (web middleware): lockout uses `TrialStatus::isLockedOut()` =
  expired **AND not a team member anywhere** — a user managing other owners' websites keeps
  full app access (works under those owners' plans) while their own sites still expire.
  Locked users are confined to `billing.*`/`cashier.*`/logout/impersonation-stop; everything
  else redirects to /billing with an explanatory flash. Impersonating admins pass through.
- **Winback offer (2026-07-07, 30% since same day)**: **30% off any plan** — Stripe coupon
  `TRIAL-WINBACK-30` (percent_off 30, duration `once`) exposed as promotion code **`SAVE30`**
  (`promo_1TqNrMETsnSIf8R5LDCYCIDu`; the earlier 20% `SAVE20`/`TRIAL-WINBACK-20` campaign is
  deactivated/deleted). Config: `services.stripe.winback_promo_code` (env
  `STRIPE_WINBACK_PROMO_CODE`, default `SAVE30`; empty disables the offer everywhere) +
  `winback_promo_percent` (display copy only — the REAL discount lives on the Stripe coupon,
  keep them in sync). Three surfaces:
  1. **h24 expiry email** — offer box, CTA lands on `/billing?promo=SAVE30`;
     `BillingController::show()` parks it in `session('billing_promo')`.
  2. **Billing page banner** — `SubscriptionPanel` shows a large gradient offer banner to any
     `TrialStatus::isExpired()` user ("auto-applied, no code needed").
  3. **checkout()** — trial-EXPIRED users get the campaign discount auto-applied
     unconditionally; otherwise the ?promo=/session code applies; the campaign code (and only
     it) resolves to its promotion-code ID (cached 1h) → `withPromotionCode()`; any other/absent
     code falls back to `allowPromotionCodes()` (Stripe forbids combining the two).
- Tests: `tests/Feature/TrialCleanupTest.php` (stages, anchor, dedupe, shared-crawl safety,
  exemptions, lockout, team-member exemptions, re-add countdown restart, stale anchor,
  h24 winback offer).

## Stale Stripe customers (account switch 2026-07-06)

The Stripe business account was replaced 2026-07-06 — `cus_*` IDs minted under the old
account don't exist under the new keys and 500 checkout with "No such customer".
`BillingController::checkout()` self-heals: while `stripe_id` is set and no local
subscription exists, it verifies the customer and clears `stripe_id`/`pm_*` on
"No such customer" so Cashier mints a fresh customer. The 3 known stale users were swept
2026-07-07 (old IDs in the git log / laravel.log).

## Resolving the user's plan (`User::effectivePlan()`, line 291)

Resolution order:
1. **Free-promo** (`config('app.free')`): upgrade to the **Pro** row, but only if it
   *raises* the tier (never downgrades Startup/Agency). Falls through if Pro row missing.
2. **Active Cashier subscription** → `Plan::findByStripePrice()` matches `stripe_price`
   against either `stripe_price_id_monthly` or `_yearly` (fixed 2026-07-06 — previously
   yearly-only, silently dropped monthly subscribers; see
   [billing/README.md](./README.md#gotchas--known-issues)).
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
