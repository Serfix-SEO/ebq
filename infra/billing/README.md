# Billing, Plans & Usage subsystem

How EBQ charges money, decides what a customer is allowed to use, and meters
paid external-API spend. Three loosely-coupled layers:

| Layer | One-liner |
|---|---|
| **Billing** | Stripe Checkout + Customer Portal + in-app swap/cancel/resume via Laravel Cashier. **Per-USER** subscription (yearly only). |
| **Plans & gating** | `plans` rows define caps + a feature entitlement matrix; `User`/`Website` read-paths resolve "what plan / which features / how many sites". |
| **Usage** | `client_activities` rows log every paid external-API call (KE, Serper, Mistral, crawl-reuse); `UsageMeter` enforces monthly caps; admin sees cost. |

## Read in this order

| Doc | Covers |
|---|---|
| **README.md** (this file) | Overview, key components, billing/Stripe webhook flow, gotchas. |
| [plans-and-gating.md](./plans-and-gating.md) | Plan data model, the 4 tiers, `max_websites`/`max_crawl_pages` caps, the feature-flag resolution chain, how a feature is checked end-to-end. |
| [usage.md](./usage.md) | `client_activities` schema, `ClientActivityLogger` attribution rules, `UsageMeter` windows + quota enforcement, the admin usage dashboard. |

## One paragraph

Billing is **per-user, not per-website**: one Cashier subscription per `users` row;
the active plan's `max_websites` caps how many sites the user may keep active, and
over-limit sites freeze read-only (`User::frozenWebsiteIds()`). EBQ **only sells yearly**
subscriptions (`stripe_price_id_yearly`); the monthly price column is display copy only.
`User->current_plan_slug` is the **hot-path snapshot** of the active plan, written
authoritatively by the Stripe webhook and optimistically on checkout-success / swap.
Plans carry a `plan_features` boolean matrix (the WP-plugin entitlement ceiling) and
per-provider `api_limits`; the `UsageMeter` enforces those caps over a per-user
subscription-anchored monthly window, throwing a 402 `QuotaExceededException`.

## Key components

| Component | File | Role |
|---|---|---|
| `BillingController` | `app/Http/Controllers/BillingController.php` | Checkout, success/cancel, portal, in-app swap/cancel/resume, Stripe-race fallback sync. |
| `StripeWebhookController` | `app/Http/Controllers/StripeWebhookController.php` | Extends Cashier's webhook controller; snapshots `current_plan_slug` on sub created/updated/deleted. **Source of truth.** |
| `SubscriptionPanel` (Livewire) | `app/Livewire/Billing/SubscriptionPanel.php` | Read-only presenter for `/billing`; all mutations are plain form POSTs to `BillingController`. |
| `Plan` model | `app/Models/Plan.php` | One row per tier. `featureMap()`, `apiLimit()`, `isCheckoutReady()`, `requiredPlanFor()`. |
| `PlanSeeder` | `database/seeders/PlanSeeder.php` | Idempotent `updateOrCreate` seed of free/pro/startup/agency. Never seeds Stripe IDs. |
| `Admin\PlanController` | `app/Http/Controllers/Admin/PlanController.php` | Operator edits pricing, caps, feature matrix, api_limits live (no deploy). |
| `Admin\BillingController` | `app/Http/Controllers/Admin/BillingController.php` | Read-only roster of users + plan/trial/site-count. |
| `Admin\WebsiteFeatureController` | `app/Http/Controllers/Admin/WebsiteFeatureController.php` | Per-site feature override grid + the global kill-switch map. |
| `Admin\UsageController` | `app/Http/Controllers/Admin/UsageController.php` | "API Usage" dashboard: per-client/website cost + cap utilisation. |
| `ClientActivityLogger` | `app/Services/ClientActivityLogger.php` | The single funnel that writes `client_activities`; resolves billed owner + actor. |
| `UsageMeter` | `app/Services/Usage/UsageMeter.php` | Monthly window accounting + `assertCanSpend()` quota gate. |
| `QuotaExceededException` | `app/Exceptions/QuotaExceededException.php` | 402 with structured upgrade payload. |
| `InjectFeatureFlags` | `app/Http/Middleware/InjectFeatureFlags.php` | Stamps `tier` + `features` onto every authed API response for the WP plugin. |

## Stripe / Cashier flow

Billing uses **Laravel Cashier**. Cashier's own `subscriptions` /
`subscription_items` tables + the Stripe columns on `users` (`stripe_id`, `pm_type`,
`pm_last_four`, `trial_ends_at`) are the underlying bookkeeping; EBQ adds only the
`current_plan_slug` snapshot.

### Checkout (new subscription)

`GET /billing/checkout?plan=<slug>` (`BillingController::checkout`, line 46):
1. Load `Plan` by slug + `is_active`; reject unless `isCheckoutReady()` (yearly price + price ID present).
2. If already `subscribed('default')` → redirect to `/billing` swap flow (avoid double-charge).
3. `newSubscription('default', $plan->stripe_price_id_yearly)->trialDays($plan->trial_days)->checkout(...)` → Stripe Hosted Checkout.
4. `success_url`/`cancel_url` carry a sanitised `return_to` so the WP-plugin wizard lands back correctly.

### Success redirect — the webhook race (`success`, line 103)

Stripe redirects the customer to `/billing/success` **before** the
`customer.subscription.created` webhook lands. Without a fallback, the local
`subscriptions` table is empty → `subscription('default')` is null → Billing page
shows "no plan" **and every site past index 0 freezes** (`websiteLimit()` falls back
to free-tier 1). So `success()` (and `show()`, line 200) call
`syncSubscriptionsFromStripe()` (line 325) to pull active subs from Stripe directly
and upsert local rows. **Idempotent** — the webhook overwrites with the same data
moments later. `success()` then optimistically writes `current_plan_slug`.

### In-app management

| Route | Method | Cashier call |
|---|---|---|
| `POST /billing/swap` | `swap` | `$subscription->swap($priceId)` — immediate, Stripe-prorated. No active sub → route to checkout. |
| `POST /billing/cancel` | `cancelSubscription` | `$subscription->cancel()` — keeps Pro until period end (grace). |
| `POST /billing/resume` | `resume` | `$subscription->resume()` — only while `onGracePeriod()`. |
| `GET /billing/portal` | `portal` | `redirectToBillingPortal()` — cards/invoices. |

swap/cancel also optimistically update `current_plan_slug`; the webhook reconfirms.

### Webhook (authoritative — `StripeWebhookController`)

`POST /stripe/webhook` (route `cashier.webhook`, `routes/web.php:91`), CSRF-exempt in
`bootstrap/app.php`, signature-verified by Cashier via `STRIPE_WEBHOOK_SECRET`.
The controller overrides three events — `customer.subscription.created` / `updated` /
`deleted` — and after the parent does its row bookkeeping, calls
`syncPlanSlugFromStripeCustomer()` (line 69):

1. `User::where('stripe_id', <customer>)`.
2. If `subscribed('default')` → match active sub's `stripe_price` to a `Plan` by
   `stripe_price_id_yearly` → that plan's slug; else `null` (free).
3. Write `current_plan_slug` only if changed.

**Why the snapshot:** website-limit checks, frozen-site decisions, tier badges and
the plugin's `tier` field all read `current_plan_slug` on the hot path. Computing it
live each request would force a `subscriptions`+`plans` join per request. The WP
editor flips in/out of Pro within seconds of the Stripe state change.

> `created` reuses the parent's `updated` handler — Cashier defines no dedicated
> `created` override; the row upsert is identical.

## Free-promo mode (`APP_FREE=true`)

When `config('app.free')` is true, `User::effectivePlan()` (line 291) **upgrades**
every user to at least the **Pro** row regardless of subscription state — but never
*downgrades* a user already on Startup/Agency (a past bug stripped their higher-tier
entitlements). Flipping `FREE=false` snaps everyone back to their real plan on the
next request. The `/billing` page hides the plan grid + danger zone and shows an
"unlocked free" panel instead (`SubscriptionPanel`, line 116).

## Gotchas / known issues

- **Per-user billing, not per-website.** A migration moved Cashier columns from
  `websites` to `users` and dropped per-website `tier`/`feature_flags` as the billing
  unit. Tier is now *derived* (`User::effectiveTier()`); admin billing indexes users.
- **Yearly only.** `checkout`/`swap` always use `stripe_price_id_yearly`. The monthly
  price/`stripe_price_id_monthly` columns are marketing copy ("$X/mo billed yearly").
  `isCheckoutReady()` gates on the yearly price.
- **Webhook-race fallback is best-effort.** If Stripe is unreachable, `success()`/
  `show()` stay silent and rely on the webhook (logged at info). A *stopped queue
  worker* delays the authoritative webhook — the page-render fallback masks this, but
  `current_plan_slug` only persists authoritatively once the webhook processes.
- **Slug is immutable.** Renaming a `Plan` slug orphans in-flight checkout sessions,
  webhook lookups, and the WP plugin. Slugs were last renamed via a dedicated
  migration (`2026_05_17…rename_plan_slugs`), never via the admin form.
- **Stripe price IDs are nullable + never seeded.** `PlanSeeder` omits them; operator
  adds via Stripe Dashboard + tinker. A paid plan with no price ID silently refuses
  checkout (`isCheckoutReady()` false) rather than erroring.
- **`current_plan_slug` can drift if the webhook never fires** (e.g. secret
  misconfigured). `effectivePlan()` still prefers the *live* Cashier subscription's
  price match over the slug, so the snapshot is a fallback, not the only signal.

## Key files

- `app/Http/Controllers/BillingController.php`
- `app/Http/Controllers/StripeWebhookController.php`
- `app/Http/Controllers/Admin/{BillingController,PlanController,UsageController,WebsiteFeatureController}.php`
- `app/Livewire/Billing/SubscriptionPanel.php`
- `app/Models/{Plan,ClientActivity,User,Website}.php`
- `app/Services/ClientActivityLogger.php`, `app/Services/Usage/UsageMeter.php`
- `app/Exceptions/QuotaExceededException.php`, `app/Http/Middleware/InjectFeatureFlags.php`
- `database/seeders/PlanSeeder.php`, `database/migrations/2026_0*_*plans*`, `*client_activities*`
- Routes: `routes/web.php` (`/billing/*`, `cashier.webhook`, `/admin/{plans,billing,usage,website-features}`)
