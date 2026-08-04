# Guest tools (public, no-auth lead-gen)

Four anonymous, no-signup SEO tools on the marketing site. A visitor runs a check, sees the
first result on screen, gives name + email for the second (delivered by email, **not** shown),
and is pushed to a free signup on the third. They exist to **capture marketing leads** and feed
the free-plan funnel — every email-gated run writes a `leads` row, and a matching signup later
flips it to `converted`.

All four share one pattern, one set of conventions, and one lead/abuse model. This is the only
doc; per-tool differences are just *which provider the job calls*.

## The shared pattern

```
POST store()                          GET {token}/status (poll)      GET {token} (show)
  rate-limit (per-IP) ─┐                returns {status, results_url}   renders blade by token
  validate input       │
  cookie attempt count │  ── dispatch ──▶  queued Job ──▶ provider ──▶ row.markCompleted(result)
  (3rd → block signup) │   (INTERACTIVE      (one row,        │
  2nd → email gate     │    queue)           token-keyed)     └─▶ if email: send …LinkMail
  reCAPTCHA (once)     │
  Lead::capture (2nd) ─┘
```

Every tool's model is the same shape: an unguessable **`token`** (UUID) as the route key,
a `status` enum (`queued`/`running`/`completed`/`failed`), a JSON `result` cast to array,
plus `ip`, `email`, `name`. `start()` creates the queued row; `markRunning/Completed/Failed`
+ `isFinished()` drive the state machine. The job is `ShouldBeUnique` (`uniqueFor=1800`) so a
double-submit can't run twice, `tries=1` (never silently re-spend a paid/external call), and
implements `failed()` to record a failure so the front-end poller stops waiting.

**The token is the only access control on results** — `show()` and `status()` have no auth.
The "2nd run is emailed, not shown" rule is enforced only by the `store()` JSON withholding the
`results_url`; anyone with the token URL can view the report. Tokens are random UUIDs, so this is
unguessable-by-obscurity, not a permission check.

## Per-tool table

| Tool | Public path | Controller `file` | Job | Model | Provider it calls | LinkMail |
|---|---|---|---|---|---|---|
| SEO audit | `/audit`, `/free-audit` | `GuestAuditController.php` | `RunGuestPageAudit` | `GuestPageAudit` | `PageAuditService::auditGuest()` — **lite** (no GSC/GA, no Serper/Lighthouse) | `GuestAuditLinkMail` |
| PageSpeed | `/pagespeed-test` | `GuestPageSpeedController.php` | `RunGuestPageSpeedStrategy` ×2 | `GuestPageSpeed` | self-hosted `LighthouseClient` (one job per mobile/desktop) | `GuestPageSpeedLinkMail` |
| Rank check | `/rank-tracker` | `GuestRankCheckController.php` | `RunGuestRankCheck` | `GuestRankCheck` | Serper organic SERP (`SerperSearchClient`, depth 100, keep top 20) | `GuestRankCheckLinkMail` |
| Keyword volume | `/keyword-volume-checker` | `GuestKeywordVolumeController.php` | `RunGuestKeywordVolume` | `GuestKeywordVolume` | DB-first `KeywordMetricsService` → Keywords Everywhere on cache miss | `GuestKeywordVolumeLinkMail` |

Routes live in `routes/web.php:44-76` (plain `web` group — default CSRF, the forms ship a token).
Tool landing pages are static blades under `resources/views/tools/{audit,page-speed,rank-tracker,keyword-volume}.blade.php`;
result pages are `resources/views/guest-*/show.blade.php`, which poll `…/status` then render.

### Provider notes

- **Audit** runs lite mode (`PageAuditService::auditGuest()`, `app/Services/PageAuditService.php:154`)
  — skips the paid Serper/Lighthouse/CWV and GSC/GA stages so it costs nothing; the full audit is the upsell.
- **PageSpeed** dispatches **two** jobs (mobile + desktop) so each strategy gets a full worker cycle
  (~80s; a single combined job timed out). They run in parallel and coordinate via a
  `lockForUpdate()` row lock — whichever reports the missing strategy finalizes the row and sends the email.
- **Rank** needs a single Serper query; the country (`gl`) comes from `SerpGlCatalog`.
- **Volume** is **DB-first** against the shared `keyword_metrics` cache (populated by any user, GSC
  import, or prior guest check) — a fresh hit costs **zero** KE calls; only a miss/stale row spends one,
  which then caches for everyone. Country list is `KeywordsEverywhereCountries`.

## Lead capture

`Lead::capture($email, $name, $guestPageAuditId?, $source)` (`app/Models/Lead.php:41`) is called only
on the **2nd (email-gated) run**, `firstOrNew` by lowercased email. Sources tag the funnel origin:
`SOURCE_GUEST_AUDIT` / `SOURCE_GUEST_RANK` / `SOURCE_GUEST_VOLUME` (PageSpeed currently passes no
source, so it falls back to the audit default — see gotchas). If a `User` already exists for the email
the lead is marked converted on capture. `Lead::markConvertedFor($user)` fires from `User::created`
(covers password + Google SSO) and flips any pending lead with the same email to converted.

## Rate limiting & abuse protection

| Lever | Where | Detail |
|---|---|---|
| Per-IP burst | `RateLimiter` keys `guest-*:m:{ip}` / `:d:{ip}` | audit/volume 5/min · 20/day; rank/pagespeed 4/min · 15/day. Checked **before** any work; `hit()` only on a real submit. |
| Progressive friction | signed `ebq_guest_*` cookie (~1yr) | attempt #1 free on-screen · #2 name+email, emailed only · #3+ blocked, returns `require:signup`. |
| reCAPTCHA | `ValidRecaptcha` rule, `Recaptcha::isEnabled()` | validated **exactly once**, last, on an otherwise-valid submit — so the email round-trip never burns the single-use token. |
| SSRF guard | `SafeHttpGuard::check()` (audit + pagespeed) | rejects non-http(s), literal IPs, single-label hosts, and any host resolving to a private/reserved/loopback range — **before** a row is created or a worker spent. |
| Config gate | per tool | returns 503 if the provider key/binary is unconfigured (`services.serper.key`, `services.keywords_everywhere.key`, `LighthouseClient::isConfigured()`). |
| Input caps | model `start()` | `mb_substr` caps on every field (keyword 200, domain 255, url 700, ip 45, email/name 255). |

## Gotchas / known issues

- **Cookie-only friction is trivially bypassable — by design, not a bug.** The 1/2/3 limit lives
  in a client cookie; clearing it (or a fresh browser) resets to free. Per-IP `RateLimiter` is the
  real backstop, and even that is shared NAT-blind. This is intentional (lead-gen, not a paywall)
  — the checked-2026-07-06 sweep left this alone on purpose; don't "fix" it into a hard limit
  without confirming that's actually wanted, it'd change the product's funnel intent.
- **Results are protected only by the unguessable token.** No auth on `show()`/`status()`; the
  "emailed, not shown" rule is just the JSON omitting `results_url`. Don't treat guest reports as private.
- **Fixed 2026-07-06 — PageSpeed lead now tagged `guest_pagespeed`.** `GuestPageSpeedController`
  used to call `Lead::capture($email, $name, null)` with no 4th arg, silently falling back to the
  `guest_audit` source default — PageSpeed funnel attribution was lost. Added
  `Lead::SOURCE_GUEST_PAGESPEED` and passed it explicitly. Covered by
  `test_second_run_lead_is_tagged_with_the_pagespeed_source` in `tests/Feature/GuestPageSpeedTest.php`.
- **No GC / retention.** None of the four guest tables are pruned here; rows (with IP + email) accumulate
  indefinitely. Add a scheduled cleanup if volume grows.
- **Email failures are swallowed.** All four jobs `try/catch` the `Mail::send` and only `Log::warning` —
  a guest who gave their email on run #2 may silently never receive it (the report still completes and is
  viewable by token).
- **`tries=1` means no retry.** A transient provider blip on the single attempt → the run is marked
  failed and the user must resubmit (and burn another attempt/rate-limit slot).
- **reCAPTCHA is enforced server-side on submit (2026-07-24).** All four `store()` methods validate
  `g-recaptcha-response` (`new ValidRecaptcha`) whenever `Recaptcha::isEnabled()` (both keys set), placed
  right after the `auth()->guest()` teaser short-circuit and before any rate-limit/paid work. The page
  always rendered the widget + posted the token, but the controllers previously **ignored it** — a
  scripted/replayed POST skipped the captcha entirely. NOT gated on `!auth()->check()`: the real run only
  happens for signed-in users (guests short-circuit), so gating on guest would make the check inert.
- **Keyword-volume job is provider-aware (2026-07-24).** `KeywordMetricsService::refresh()` is
  **synchronous** for Keywords Everywhere but **asynchronous** for the self-hosted keyword finder — it
  dispatches the node lookup and returns; the row lands in `keyword_metrics` later via the webhook. So
  `RunGuestKeywordVolume` must NOT declare failure on the first cache miss: when
  `KeywordProviderConfig::usingKeywordFinder()`, it kicks the lookup off on attempt 0 then re-queues
  itself (`RETRY_SECONDS`=6, `MAX_ATTEMPTS`=20 ≈ 2 min) until the volume lands, only failing at the
  deadline. Before this fix every public keyword check failed ~1s after submit (updated_at==created_at)
  even though the node delivered the data 40-70s later. Tests: `test_async_provider_*` in
  `GuestKeywordVolumeTest`. (The node's `/status` endpoint separately times out in logs — unrelated;
  results still arrive via the webhook, which is what the poll reads.)

## Key files

- Controllers — `app/Http/Controllers/Guest{Audit,PageSpeed,RankCheck,KeywordVolume}Controller.php`
- Jobs — `app/Jobs/RunGuest{PageAudit,PageSpeedStrategy,RankCheck,KeywordVolume}.php` (queue `INTERACTIVE`)
- Models — `app/Models/Guest{PageAudit,PageSpeed,RankCheck,KeywordVolume}.php`, `app/Models/Lead.php`
- Mail — `app/Mail/Guest{Audit,PageSpeed,RankCheck,KeywordVolume}LinkMail.php`, views `resources/views/emails/guest-*-link.blade.php`
- Views — `resources/views/tools/*.blade.php` (landing), `resources/views/guest-*/show.blade.php` (results)
- Support — `app/Support/Audit/SafeHttpGuard.php`, `app/Support/Recaptcha.php` + `app/Rules/ValidRecaptcha.php`,
  `app/Support/Audit/SerpGlCatalog.php`, `app/Support/KeywordsEverywhereCountries.php`, `app/Support/Queues.php`
- Routes — `routes/web.php:44-76`
- Migrations — `database/migrations/2026_06_0{6,8,9}_*guest_*`

## Cross-linking between the tools (2026-08-02)

All four tool chips live in **one partial**, `resources/views/partials/free-tools-row.blade.php`,
included by the home page (`landing.blade.php`, with `['label' => true]` for the "Free tools:"
prefix) and by every tool page twice — once under the hero, once as a "More free tools" block
after the results. Each include passes `['except' => '<its own route name>']` so a tool never
links to itself.

Why it exists: most visitors reach a tool straight from search, not via the home page. Before
this, the home page listed all four while a tool page carried a single hardcoded "Also free: …"
pill pointing at one sibling — so someone landing on the rank checker had no route to the other
three. Adding a tool now means adding one array entry in the partial, and it appears on all five
pages at once.

Pinned by `tests/Feature/FreeToolsCrossLinkTest.php`: every tool links to every other tool, the
chip count per page is exactly `(tools - 1) × 2` (which is also the self-exclusion check, since a
self-link would make it four per row), and the home page still lists the full set.
