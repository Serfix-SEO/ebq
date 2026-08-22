# Referral program (2026-08-22)

Client shares a referral URL; when the referred account pays its **first FULL
Content Autopilot bill** (the $1 intro month never counts), the referrer gets
**50% off their next bill**, computed on the **base subscription only** — never
the $15 additional-website addons. Owner decision: **unlimited stacking** (each
successful referral adds one credit; credits accumulate on the Stripe balance).

## Why a balance credit, not a coupon

Cashier 16.5.3 has no per-subscription-item discount API — `applyCoupon` is
subscription-wide and would discount the addon items too. Instead the reward is
a **Stripe customer-balance credit** (`Billable::creditBalance`) of exactly
50% of the referrer's base content price in cents: precise amount, one-time by
nature, auto-applies to the next invoice, stacks naturally. The credit lands on
the customer (not a subscription), so a referrer without an active subscription
still earns it — it sits on the balance until their next invoice.

## Pipeline

1. **Capture** — `app/Http/Middleware/CaptureReferralCode.php` (web append
   stack, bootstrap/app.php): any request with `?ref=CODE` (`[a-z0-9]{4,16}`)
   queues the `ebq_ref` cookie for 60 days, last touch wins. Cookie stays
   encrypted (standard EncryptCookies) — server-side reads decrypt
   transparently, and encryption blocks trivial forgery.
2. **Attribution** — `User::booted()` `static::created` (the same choke point
   as `Lead::markConvertedFor`, so ALL three signup paths are covered: register
   POST, Google SSO callback, PublicOnboarding wizard) calls
   `ReferralProgram::attributeFromRequest`: console-safe, skips `is_system`
   lead users, resolves the cookie code, blocks self-referral, creates a
   `pending` `referrals` row, forgets the cookie. Never throws.
3. **Qualification** — `StripeWebhookController::handleInvoicePaymentSucceeded`
   (first `invoice.*` handler in the app; Cashier dispatches by StudlyCase
   event name) → `ReferralProgram::qualifyFromInvoicePayload`:
   - fast exits: customer → user by `stripe_id` → must hold a `pending`
     referral as the REFERRED side; invoice id already recorded → webhook retry.
   - invoice lines must contain a content **base** price line (monthly or
     annual id from settings) — dual line shape
     `price.id ?? pricing.price_details.price` (basil fallback, same as
     ContentSubscriptionPanel). Addon-only and SEO-product invoices never match.
   - full-price rule: `amount_paid >= expected_base * 0.9` (expected = Stripe
     price `unit_amount`, fallback `displayPrice*100`; ×12 for annual). The $1
     intro invoice (~100¢) fails; a full monthly invoice (≥3900¢ before
     addons) passes; an annual first invoice passes immediately (it was never
     couponed); a coupon-skipped monthly first invoice passes immediately —
     correct, it WAS a full bill.
   - claim inside a `lockForUpdate` transaction (pending → qualified +
     `stripe_invoice_id` + `qualified_at`), then `grant()` OUTSIDE the
     transaction (no Stripe call inside a DB tx).
4. **Grant** — `ReferralProgram::grant`: the only Stripe-touching method.
   `creditCentsFor(referrer)` = 50% of their base price (interval from their
   own `content` subscription items; no sub → monthly rate);
   `createOrGetStripeCustomer` + `creditBalance`; row → `credited`; queued
   `ReferralRewardEarned` mail (locale-aware, never names the referred person);
   `client_activities` type `referral_credited`. Failure → `credit_failed` +
   `last_error`, swept hourly by **`ebq:grant-referral-rewards`**
   (routes/console.php) — the inline grant is best-effort.

## Data

- `users.referral_code` string(16) nullable unique — lazily generated
  (`ReferralProgram::codeFor`, 8-char lowercase alnum) on first referral-page
  visit. Never mass-assignable.
- **Custom (vanity) IDs (2026-08-22)**: the referral page lets the user edit
  their ID (`ReferralProgram::setCustomCode`) — shape
  `^[a-z0-9][a-z0-9-]{2,14}[a-z0-9]$` (shared with the capture middleware via
  `isValidCode`; keep the two in sync), stored lowercase, honored only when no
  other user holds it (pre-check + the unique index as race arbiter →
  'taken'). Changing the ID kills previously shared links (old code resolves
  to nobody) — the UI says so on save.
- `referrals`: **no DB foreign keys** (content_generations precedent — reward
  history must survive account deletion; read paths null-check).
  `referred_user_id` UNIQUE = one reward per referred account, ever.
  `stripe_invoice_id` UNIQUE = webhook-retry idempotency.
  Lifecycle: `pending → qualified → credited`, or `credit_failed` (retried).

## Client page — /referrals ("Refer & earn")

`Route::view` in the `['auth','verified','onboarded']` group;
`referrals*` is in the `EnsureOnboarded` allowlist (zero-website users can
share their link). Livewire `App\Livewire\Referrals\ReferralHub`
(view `livewire/referrals/referral-hub.blade.php`): copyable URL
(`{public_url}/?ref={code}`, Alpine clipboard), how-it-works, stat cards
(sign-ups / pending / rewards earned / total saved) and a per-referral table
with masked emails (`j***@domain`). Client-facing status vocabulary is TWO
values only — "Awaiting first payment" / "Reward credited" — internal
`qualified`/`credit_failed` states collapse into the pending bucket (see
client-facing copy rules). Nav item "Refer & earn" lives in the trailing
null-label group (survives `SEO_PLATFORM_UI=false`), `feature => null`.

## Ops notes

- The prod Stripe webhook endpoint MUST have `invoice.payment_succeeded` among
  its enabled events (Cashier's default list includes it — verified at launch).
- Non-goals v1: refund/chargeback clawback (exposure ≈ $19.50/event),
  multi-currency, fraud tooling beyond self-referral block +
  one-reward-per-account + the real-$39 cost barrier. Admin visibility =
  `referrals` table + `client_activities` type `referral_credited`.
- Tests: `tests/Feature/Referrals/ReferralProgramTest.php` (14) — attribution
  paths, qualify matrix ($1 intro / full monthly / annual-first / addon-only /
  basil line shape), idempotency, credit math, sweep retry, page rendering,
  allowlist. Stripe touches are constructor-injected closures (spy creditor +
  fixed price resolver) — zero network in tests.
