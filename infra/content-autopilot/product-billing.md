# Content Autopilot — separately-billed product (entitlement + billing)

Content Autopilot is sold as its own product, decoupled from the dashboard
plans. Built 2026-07-19 (Phases 1–6a). **PROD LIVE 2026-07-20** (`CONTENT_AUTOPILOT_UI=true`).

## Pricing (admin-configurable via /admin/settings → "Content product")

| Item | Price | Stripe (LIVE) |
|---|---|---|
| Base monthly | $39/mo | `price_1TulILETsnSIf8R58ENQp0Q5` |
| Base annual | $29/mo billed yearly ($348) | `price_1TulIMETsnSIf8R5Pw5SEYQi` |
| Extra website monthly | **$20/mo** (raised 2026-07-20 from $15) | `price_1Tv7uHETsnSIf8R5RCzzYTz5` (prod `prod_UuaTi4OFxtHcBU`, 2000/mo) |
| Extra website annual | **$15/mo billed yearly ($180)** (raised from $10) | `price_1Tv7uHETsnSIf8R5Bjg4Fcsg` (18000/yr) |
| First month | $1 (monthly only) | coupon `serfix_content_first_month` ($38 off once) |
| Trial | 5 days, 3 articles, no card | app-managed |
| Cap | 60 articles/mo/website | admin key |

All ids/amounts/caps live in `settings` (`content.pricing.*`, `content.limits.*`),
NOT the `plans` table — keeping content price ids out of `plans` is what keeps
the dashboard webhook plan-slug sync safe. Accessors on `ContentAutopilotConfig`.

## Entitlement — `app/Services/Content/ContentEntitlements.php` (stateless, singleton)

- **Access** = active Cashier **`content`** named subscription OR a live 5-day
  app-managed trial (`users.content_trial_started_at/ends_at`, one per user ever).
- **Coverage**: a subscription covers 1 website + N addon websites. WHICH sites
  consume slots = `content_plans.billing_covered_at` (explicit, not derived).
  `sitesAllowed` = 1 + addon subscription-item quantity. `reconcileCoverage`
  clamps on downgrade (newest uncovered first).
- **Usage**: a "generation" = a topic's **version-1** article (revisions never
  count) + in-flight reservations. `blockReason(topic)` → `null | no_access |
  not_covered | trial_limit | monthly_limit` — the shared gate.
- `coverWebsite` creates a covered **DRAFT** stub plan (baked cadence defaults)
  so a just-activated site opens the wizard.
- User proxies: `hasContentAccess()`, `isContentOnly()` (access && dashboard
  `TrialStatus::isExpired`).

## Billing — `app/Http/Controllers/ContentBillingController.php`

Named `content` Cashier sub (isolation from dashboard `default`). checkout
(`skipTrial` + $1 coupon monthly-only, dead-coupon retry), success covers the
site, add/remove-website (addon item quantity), cancel/resume. Routes
`content.billing.*`. Webhooks: the EXISTING `serfix.io/stripe/webhook` covers
`customer.subscription.*`; `StripeWebhookController` adds `reconcileContentFrom
StripeCustomer` (coverage clamp + crawl-cap recompute, try/catch-guarded) and
its plan-slug sync ignores non-`default` subs. Fixed `BillingController::
syncSubscriptionsFromStripe` to resolve the sub type from Stripe metadata
(was hardcoded `default`).

## Gating & caps (Phase 4 = the switch)

- `PlanSeeder`: `content_autopilot => false` on all tiers (re-seed = the prod flip).
- `Website::effectiveFeatureFlags()` overrides `content_autopilot` with
  `ContentEntitlements::hasContentAccessFor(owner, site)` — **guarded** (falls
  back to off; runs on every nav render + plugin API).
- `EnsureContentAccess` middleware on content product routes → no access redirects
  to `content.get-started`; a site with existing content stays reachable so lapsed
  users can still PUBLISH (publishing is never gated).
- Nav collapses to a single "Get started" when the current site lacks access.
- `ProduceContentArticleJob::handle` early-returns on `blockReason` (the ONE
  choke point for all 5 dispatch paths); dispatcher skips blocked sites;
  Livewire `writeNow/retry/retryGeneration` flash reason-specific upsell copy.

## Cross-product policy (Phase 3)

- `TrialStatus::isLockedOut` + `TrialCleanup` EXEMPT content-access users (their
  websites are never deleted); `is_system` (content-leads user) excluded.
- Content-only users (content access, dashboard trial lapsed): `EnsureDashboardAccess`
  middleware teasers dashboard report/crawl routes (`dashboard/content-only-teaser`);
  `Website::crawlPageCap` clamps to `content_only_crawl_pages` (default 200) so the
  pipeline still gets site profile / internal links / keyword seeds.
- `EnsureTrialNotExpired` allowlist adds `content.` so lapsed dashboard users can buy.

## In-app Get started — `app/Livewire/Content/GetStarted.php`

Branches: never-trialed → Start trial; trial spent → pricing → checkout; sub with
free slot → Activate; slots full → Add website (addon). `content.get-started`.

## Public onboarding

`/content-autopilot` landing (`content-landing.blade.php`, hero has a domain
input carrying `?domain=`) → `/content-autopilot/start` (`PublicOnboarding`
Livewire, full-page layout `components.layouts.content-onboarding`). It is the
**FULL dashboard wizard run anonymously** (upgraded 2026-07-19), not a cut-down
form:
1. **domain-capture screen** → provisional `Website` under the seeded `is_system`
   "content-leads" user + shared-crawl subscribe (`ContentOnboardingConverter::
   begin`); `content_onboarding_sessions` token in the visitor session. Guards:
   SSRF (`SafeHttpGuard`), domain normalize, reCAPTCHA, per-IP/global RateLimiter
   (`content.onboarding.*` admin keys).
2. **7-step wizard** (business → offerings → how-it-works → images → competitors
   → keyword research → first articles) — identical to the dashboard, so the
   visitor sees crawl/competitor/keyword/topic value BEFORE signup. Runs the real
   pipeline on the provisional site (profile extraction, competitor authority,
   keyword research, topic ideation) with **NO entitlement gate**.
3. **create account** (name/email/phone/password) → `ContentOnboardingConverter::
   convert` re-parents the site (or folds into an existing owned domain), persists
   a covered DRAFT plan, `startTrial`, dispatches ideation + keyword research →
   login → `content.settings`. (Password collected here — the app has NO
   password-reset flow, so no passwordless email.)

   ⚠️ **The fold branch deletes a plan.** When the registrant already owns the
   domain, convert() keeps their site and deletes the provisional one — and
   `content_plans.website_id` is **ON DELETE CASCADE**, so the plan the wizard
   spent the whole funnel writing to is destroyed with it. `convert()` is also
   documented to accept an EMPTY `$profile` (a Google-SSO round-trip loses the
   Livewire state), and its "the plan already carries the profile" reasoning only
   holds in the *re-parent* branch. Both together = the user lands on a bare stub:
   no business description, no offerings, default cadence (prod 2026-07-20 —
   the calendar still filled up, because `ContentTopicPlanner` ideates from GSC
   gap data and treats `business_description` as optional, casting null to `''`
   at `ContentTopicPlanner.php:219` with a fail-open relevance filter).
   Fixed by `carryOverProfile()`: snapshot the provisional plan's wizard-authored
   columns *before* the delete, apply them under `$profile` (explicit input still
   wins). A brand-new plan takes all of it — the stub's 7/2000 are defaults, not
   choices; an existing plan only gets its blanks filled, so a site the user
   configured earlier is never clobbered by a later funnel run. Regression tests:
   `ContentPublicOnboardingTest::test_folding_into_an_owned_domain_keeps_the_
   wizard_profile_when_convert_gets_none` (+ the precedence and no-clobber cases).

**Shared-blade architecture** (so dashboard + public stay pixel-identical without
duplicating markup): the wizard markup lives in
`resources/views/livewire/content/partials/wizard.blade.php` (+ `wizard-account.
blade.php` for step 8), `@include`d by both `content-calendar.blade.php` and
`public-onboarding.blade.php`. Public-only bits (8th step, "Continue"→`toAccount`,
account panel) gate behind `$publicOnboarding` (false for dashboard → byte-
identical render — **dashboard `ContentCalendar` PHP is untouched**). The wizard
logic is shared via trait `App\Livewire\Content\Concerns\ContentWizard` (the
anonymous twin: provisional `website()`/`plan()` resolvers, no auth/entitlement
gate); `PublicOnboarding::dehydrate()` persists `wizardStep` for reload-resume.

`ebq:content-onboarding-gc` (hourly) deletes abandoned unconverted sessions
>7 days + their provisional sites.

## Rollout (PROD — DONE 2026-07-20)

Prod runs on LIVE Stripe; staging has NO live Stripe keys (test-mode only).
Go-live steps executed (repeat pattern for future content deploys):
1. Merge `staging`→`main` (ff), push.
2. Box A: `migrate --force` (additive) + `db:seed --class=PlanSeeder` (idempotent)
   + `config/route/view:clear` (NEVER `optimize` — cached-config landmine) +
   `systemctl restart php8.3-fpm` + `horizon:terminate`.
3. Box B worker (10.0.0.3): rsync (NO `--delete`) + **`docker restart` the
   ebq-horizon container** — a plain `compose up -d` won't recreate an
   unchanged-config container, so the long-lived Horizon keeps OLD classes.
4. Flip prod `.env` `CONTENT_AUTOPILOT_UI=true`.
5. Verify: `/content-autopilot` 200, landing renders correct prices,
   `horizon:supervisors` shows `worker-content` running — on **box A** since
   2026-07-20 (moved off box B, see the image-storage incident in
   [README.md](./README.md)).

**Setting-cache landmine**: settings reads use `Cache::rememberForever`; write via
`Setting::set()` (busts cache), NOT raw `updateOrCreate` (leaves stale reads).
