# Lifecycle emails (segment-matched onboarding funnel)

Daily, automatic, reply-driven onboarding emails signed "Fuaad from SERFIX"
(Reply-To `fuaad@serfix.io`, see `LifecycleEmailConfig::REPLY_TO_ADDRESS`),
plus the `/admin/lifecycle` report/controls screen. Shipped 2026-08-10.

## Segments (precedence 2 → 3 → 4 → 1, at most ONE per user)

Resolved by `app/Services/Lifecycle/LifecycleSegmentResolver.php` — the
predicates deliberately mirror the product's own checks:

| # | Meaning | Predicate | CTA |
|---|---------|-----------|-----|
| 2 | Registered, never added a website | `! User::hasAccessibleWebsites()` (`app/Models/User.php:344`) | `content.get-started` |
| 3 | Website added, wizard/strategy never finished | EVERY owned site: no `content_plans` row OR `status='draft'` (`ContentPlan::STATUS_DRAFT`; only `ContentCalendar::launch()` and `ContentOnboardingConverter` flip draft→active) | `content.index?ebq_site=…` |
| 4 | Strategy active, no publish connection | some owned site: active plan AND no `content_integrations` row with `status='connected'` (same predicate as `ContentAutopilotDispatcher` publish pass / `PublishContentArticleJob` skip) | `content.integrations?ebq_site=…` |
| 1 | Articles flowing (feedback ask) | `content_articles → content_topics → content_plans → websites` chain exists for an owned site | none |

Eligibility base (`eligibleUsersQuery()`): not system/admin/disabled, email
verified, not opted out (`users.marketing_emails_opted_out_at`), account older
than `lifecycle.min_account_age_days` (default 3 — keeps the first touch off
the signup emails and the day-2 trial-discount promo). Owned websites only —
pivot-only team members are never emailed about "their" site. CTA site pick is
covered-first (mirrors `ContentEntitlements::preferredWebsite()`).

## Send engine — `ebq:send-lifecycle-emails` (daily 10:05, routes/console.php)

`app/Console/Commands/SendLifecycleEmails.php`, three passes in order:

1. **Conversions** — sent+unconverted `lifecycle_email_sends` rows whose user
   no longer resolves to that segment get `converted_at` stamped. Runs even
   when sending is disabled (report honesty).
2. **Follow-ups** — initial sent ≥ delay days ago (seg 1: 3d, rest: 2d,
   `LifecycleEmailConfig::followupDelayDays()`), user still eligible AND still
   in the SAME segment, no sent follow-up row yet. Runs before initials so the
   cap can't starve it. **Reply detection is impossible** (no Postal inbound
   webhook) — seg 1's follow-up sends even if the user replied; its copy is
   written to read fine either way.
3. **Initials** — `eligibleUsersQuery()->chunkById(200)` (ULID pk ⇒ oldest
   users first), each resolved to at most one segment.

Budget = `--limit` ?? `lifecycle.daily_cap` (default 50, the launch-backlog
ramp), shared across passes 2+3, counts successes only. `--dry-run` prints
would-do lines with zero writes.

**Idempotency is the DB unique key** `(user_id, segment, stage)` on
`lifecycle_email_sends` (migration `2026_08_11_100000`); sends
`updateOrCreate` on that natural key and flip `status` to `sent` only AFTER
`Mail::send` returns — a `failed` row (error in `meta.error`) is retried next
run; a crash mid-send can at worst duplicate one email, never silently skip.

Guards: bails when `lifecycle.enabled` is off (conversions still stamp) or
when `Route::has('content.get-started')` is false (`CONTENT_AUTOPILOT_UI` off
— CTA `route()` calls would throw).

## Mailable + copy

`app/Mail/LifecycleMail.php` — ONE class for all 8 emails (segment × stage),
NOT queued (the command's bookkeeping needs the sync result). View
`resources/views/emails/lifecycle.blade.php` branches on segment/stage; copy
is the owner's doc verbatim via `__()` (Arabic in `lang/ar.json`). Headers:
`X-EBQ-Lifecycle-Segment/Stage`, `List-Unsubscribe` +
`List-Unsubscribe-Post: List-Unsubscribe=One-Click`.

CTA deep-links use `?ebq_site=<normalized_domain>` — `ApplyWebsiteHint`
middleware pins the session website and survives the login redirect. An
uncovered site bouncing to Get started via `EnsureContentAccess` is intended.

## Unsubscribe (first opt-out infra in the app)

- Signed routes `email.unsubscribe` (GET, confirm page — GET must never
  mutate; scanners prefetch) and `email.unsubscribe.store` (POST, stamps
  `users.marketing_emails_opted_out_at` idempotently) —
  `app/Http/Controllers/EmailUnsubscribeController.php`.
- POST is CSRF-exempt (`bootstrap/app.php` `email/unsubscribe/*`) for RFC
  8058 one-click; the signed URL is the protection.
- **Scope: lifecycle/marketing mail only.** Transactional mail (verification,
  trial-deletion countdown, published-article, growth reports) ignores the
  flag. Any FUTURE marketing-flavored mail must check it.

## Admin — /admin/lifecycle

`app/Http/Controllers/Admin/LifecycleController.php` +
`resources/views/admin/lifecycle/index.blade.php` (routes in the
`['auth','admin']` group; nav item in `components/layouts/app.blade.php`).
Segment tiles (live eligible counts — `countsBySegment()`, cached 10 min under
`lifecycle:segment-counts`) + sent/follow-up/converted(%) sub-rows, settings
card (master + per-segment toggles, daily cap, min age), test-send form, and
the filtered send log. Test sends (UI form or `ebq:lifecycle-test-mail
{email} --segment= --stage=`) write NO log row and never affect the funnel.

## Settings keys (seeded by the create migration — Setting::get forever-caches
defaults for absent rows, so the rows MUST exist)

`lifecycle.enabled`, `lifecycle.segment.{1..4}.enabled` ('1'/'0'),
`lifecycle.daily_cap`, `lifecycle.min_account_age_days`. Read via
`app/Support/LifecycleEmailConfig.php` (fail-safe try/catch wrapper).

## Gotchas

- **route:cache**: prod caches routes (`bootstrap/cache/routes-v7.php`) — the
  unsubscribe/admin routes 404 (and the phpunit suite sees stale routes!)
  until `php artisan route:cache` reruns. Bit us on first test run.
- Segment resolution is per-user but sites are per-website: a mixed portfolio
  resolves to the most-blocked segment; "all draft" (seg 3) is defeated by one
  active site.
- `users.marketing_emails_opted_out_at` must be honored by any future
  marketing email, not just this command.

Tests: `tests/Feature/Lifecycle/` (resolver matrix, command timing/cap/
conversion, unsubscribe, mailable headers/CTAs, admin screen).
