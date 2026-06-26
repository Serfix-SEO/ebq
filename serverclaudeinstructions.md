# Instructions for server-side Claude Code session

I need you to implement a 5-tier pricing rework (Trial/Solo/Pro/Agency/Enterprise) on this
repo, following CLAUDE.md's database-safety rules exactly (never run destructive
migrate/refresh/wipe commands without my explicit per-command confirmation; clear config
before any DB work; use `updateOrCreate`-based seeders and plain `migrate --force`). Below
is the full approved plan — implement it, then run its own "Deploy & Verification checklist"
section at the end, pausing for my confirmation before `migrate --force`, `db:seed`, and the
PHP-FPM restart.

---

# New 5-tier pricing & resource-limit model (Trial / Solo / Pro / Agency / Enterprise)

## Context

The business is replacing the current 4-tier pricing (Free/Pro/Startup/Agency, set up
2026-05-17) with a new 5-tier structure from a pricing sheet the user supplied directly.
The new tiers are **Trial / Solo / Pro / Agency / Enterprise**, each with its own resource
caps (projects, seats, crawl budget, tracked keywords, AI tokens, etc.). `Trial` is the new
default/no-subscription resting tier — it takes over the exact role `Free` plays today
(see "Trial is the standalone default tier" below), not a substitution mechanism tied to
the Pro plan's Stripe trial.

This must follow the existing pattern already proven by the `Plan` model / `PlanSeeder` /
admin `PlanController`: every tier is a DB row with sensible seeded defaults that an
operator can then retune live from `/admin/plans/<id>/edit` with **no deploy**.

Goal of this change: extend that exact mechanism to cover the new tiers and the new
resource dimensions from the sheet, reusing every existing enforcement point that already
exists (website cap, crawl-page cap, rank-tracker cap, report-whitelabel flag), wiring up
the one new cheap enforcement point that's obviously safe (Quick Win Finder cap), adding a
seat cap that only gates *future* invites (so no existing team gets silently broken), and
explicitly declaring-but-not-yet-enforcing the genuinely new metering axes (AI Studio
tokens/mo, Long Form Articles/mo, Keyword Research searches & results/search) — mirroring
the repo's own existing precedent of `research_limits` being declared on `Plan` without a
consumer yet.

## Sheet → schema mapping

| Sheet row | Plan field | Status |
|---|---|---|
| Monthly / Annual price | `price_monthly_usd`, `price_yearly_usd` (annual total = monthly-equiv × 12, same convention as today) | existing, display + checkout |
| Total Projects | `max_websites` | existing, enforced (`frozenWebsiteIds()`) |
| Team Seats | **new** `max_seats` | new, enforced on new invites only |
| Extra Seat price | **new** `extra_seat_price_usd` | new, display-only (no per-seat billing engine exists; same "declared, not wired to Stripe" treatment as other display fields) |
| Monthly Crawl Budget | `max_crawl_pages` | existing, enforced (`Website::crawlPageCap()`) |
| Rank Tracker tracked keywords | `api_limits.rank_tracker.max_active_keywords` | existing, enforced (`RankTrackingKeywordObserver`) |
| Keyword Research searches/mo, results/search | **new** `api_limits.keyword_research.{monthly_searches,max_results_per_search}` | new, **seeded only, not enforced** (real keyword-research caps today are env-driven per-request limits in `KeywordIdeaFinder`/`KeywordVolumeFinder`/`KeywordGapService` — a different, already-built mechanism; wiring a monthly count is new metering infra, out of scope here) |
| AI Studio tokens/mo | **new** `api_limits.ai_studio.monthly_tokens` | new, **seeded only, not enforced** (distinct from the existing, already-enforced raw-cost cap `api_limits.mistral.monthly_tokens` used by `UsageMeter`) |
| Long Form Articles/mo | **new** `api_limits.long_form.monthly_articles` | new, **seeded only, not enforced** (no monthly article-count metering exists anywhere today) |
| Quick Win Finder results shown | **new** `api_limits.quick_win_finder.results_shown` | new, **enforced** — replaces the hardcoded `5` in `app/Livewire/Dashboard/QuickWinsCard.php` |
| Backlink Analysis, Orphan Link Detection, Bilingual Audit, Arabic KW Support, WordPress Plugin, Action Insights | — | all "Yes/Full" on every tier including Trial → no gate exists today, none needed |
| Scheduled Reports | **new** `plan_features.scheduled_reports` (10th key) | new flag, **seeded only** — no Scheduled Reports feature exists in the codebase yet to gate |
| White-label Reports | `plan_features.report_whitelabel` (existing key) | existing, enforced — just new per-tier values |

`Plan::FEATURE_KEYS` grows from 9 → 10 keys (adds `scheduled_reports`). Confirmed via
`Website::FEATURE_KEYS`/`FEATURE_DEFAULTS` (`app/Models/Website.php:160-193`) that this
stays **platform-only**, same as `report_whitelabel` — it must NOT be added to
`Website::FEATURE_KEYS` and must NOT be added to the WordPress plugin's
`EBQ_Feature_Flags::KNOWN_FEATURES` list (separate repo, not touched here).

## Slug collision: old `pro` / `agency` vs. new tier names

The new sheet names ("Pro", "Agency") collide with the **existing** `plans.slug` values
from the 2026-05-17 rename. There is direct precedent for solving this safely:
`database/migrations/2026_05_17_000100_rename_plan_slugs.php` already did a two-step
slug rename across `plans.slug` + `users.current_plan_slug` + a defensive `settings` scan,
inside a transaction, with the explicit, verified rationale that **Cashier subscriptions
key off Stripe price IDs, not slugs** — so renaming a slug never touches live billing.

Plan: write a new migration following that exact pattern to move the *old* tiers out of
the way, then let `PlanSeeder` create the five new tiers under their natural slugs
(`trial`, `solo`, `pro`, `agency`, `enterprise`):

```
free    → legacy_free
pro     → legacy_pro
startup → legacy_startup
agency  → legacy_agency
```
+ same rewrite on `users.current_plan_slug`, + the defensive `settings` scan, + set
`is_active = false` on all four renamed rows (they stop appearing in any plan listing, but
existing subscribers keep resolving to them unchanged via `current_plan_slug`/price-ID
matching — nobody's entitlements change without their consent).

## Trial is the standalone default tier (not a Pro-trial substitution)

Per user correction: **don't** build a special "swap in Trial while Pro is on its Stripe
trial" mechanism. `trial` is a normal, standalone Plan row — it plays exactly the role
`free` plays today: the resting tier a user is on with no active/resolved subscription.

`User::resolveSubscribedPlan()` (`app/Models/User.php:337-356`) already has the right
shape — it falls through Cashier subscription match → `current_plan_slug` snapshot →
a hardcoded final fallback (`Plan::where('slug', self::TIER_FREE)->first()`, currently
`'free'`). The only change needed: **point that fallback at `'trial'` instead of `'free'`**
by renaming the tier constants (next section). No new branches, no `onTrial()` check, no
substitution logic — every existing read path (`effectivePlan()`, `effectiveTier()`,
`isPro()`, `isAtLeast()`) keeps working unmodified because they all go through
`resolveSubscribedPlan()`.

This also answers "what happens after 14 days": **nothing automatic** — same as today's
`free` tier never expiring. There's no `trial_started_at` countdown/lockout job in this
pass; a user simply stays on `trial` until they start a paid subscription. Flagged as a
known gap (a follow-up could add a timestamp + scheduled job to nudge/restrict after 14
days), consistent with the user's confirmed answer that this is out of scope now.

### Tier constants rename (`app/Models/User.php:54-69`)

```
TIER_FREE = 'free'        →  TIER_TRIAL = 'trial'
TIER_PRO = 'pro'          →  (unchanged string 'pro' — now refers to the NEW $49 tier,
                              see slug-collision section below)
TIER_STARTUP = 'startup'  →  removed (no "startup" tier in the new sheet)
TIER_AGENCY = 'agency'    →  unchanged string 'agency' — now the NEW $99 tier
(new)                     →  TIER_SOLO = 'solo'
(new)                     →  TIER_ENTERPRISE = 'enterprise'

TIER_ORDER = [trial=>0, solo=>1, pro=>2, agency=>3, enterprise=>4]
```

`config('app.free')` free-promo override (`effectivePlan()` lines 316-325) currently
upgrades everyone to `TIER_PRO`-or-above; with `TIER_PRO` now pointing at the new $49
tier this keeps working unchanged — re-verify behavior in testing since the rank
distances changed (trial→pro is now 2 ranks, not 1).

### Cross-repo coordination flag (WordPress plugin)

The WP plugin (separate, gitignored repo — not editable here) reads `effectiveTier()`'s
string value as its `tier` field and very likely hardcodes the old `{free,pro,startup,
agency}` vocabulary in its own tier comparator / `KNOWN_FEATURES` gating. Swapping the
live slug vocabulary to `{trial,solo,pro,agency,enterprise}` **requires a matching plugin
update shipped in lockstep** (per the two-box/lockstep deploy invariant in
`infra/main.md`) — call this out explicitly to the user as a coordination dependency,
not something this change can verify or fix from this repo.

## Seed values (`PlanSeeder` rewrite)

Replace the current 4-plan array with 5 plans, slugs `trial`/`solo`/`pro`/`agency`/
`enterprise`, using `updateOrCreate(['slug' => ...], $data)` exactly like today (idempotent,
won't trample manually-tuned Stripe IDs since those are omitted from the seed array same as
now). Values straight from the sheet:

| Field | trial | solo | pro | agency | enterprise |
|---|---|---|---|---|---|
| price_monthly_usd | 0 | 19 | 49 | 99 | null |
| price_yearly_usd | null | 168 (14×12) | 444 (37×12) | 888 (74×12) | null |
| trial_days | 0 | 0 | 0 | 0 | 0 |
| max_websites | 1 | 3 | 10 | 30 | null |
| max_seats | 1 | 1 | 3 | 10 | null |
| extra_seat_price_usd | null | 10 | 10 | 8 | null |
| max_crawl_pages | 20000 | 100000 | 300000 | 1000000 | null |
| api_limits.rank_tracker.max_active_keywords | 20 | 100 | 500 | 2000 | null |
| api_limits.keyword_research.monthly_searches | 50 | 250 | 1000 | 4000 | null |
| api_limits.keyword_research.max_results_per_search | 1000 | 5000 | 10000 | 30000 | null |
| api_limits.ai_studio.monthly_tokens | 25000 | 60000 | 150000 | 600000 | null |
| api_limits.long_form.monthly_articles | 2 | 5 | 15 | 50 | null |
| api_limits.quick_win_finder.results_shown | 5 | 10 | 20 | 30 | null |
| plan_features.scheduled_reports | false | false | true | true | true |
| plan_features.report_whitelabel | false | false | false | true | true |
| is_active | true | true | true | true | true |
| display_order | 1 | 2 | 3 | 4 | 5 |

`trial_days` is left `0` everywhere — the sheet's "14 days" is a marketing label on the
Trial column header, not a real Stripe-trial config in this design (see the "nothing
automatic after 14 days" gap noted above). `null` = unlimited, consistent with the
existing convention everywhere in this model.
Existing `plan_features` keys other than `report_whitelabel`/`scheduled_reports`
(`chatbot`, `ai_writer`, `ai_inline`, `live_audit`, `hq`, `redirects`, `dashboard_widget`,
`post_column`) aren't in the sheet — carry forward the same "true except gate the
genuinely premium ones" judgment the current seeder already makes (e.g. `chatbot: false`
on trial/solo, `true` from pro up — mirrors today's free/pro split).

## Code changes

1. **Migration A** — `rename_legacy_plan_slugs` (new file): the slug-rename migration
   described above, modeled directly on `2026_05_17_000100_rename_plan_slugs.php`.
2. **Migration B** — `add_seat_fields_to_plans_table`: additive nullable columns
   `max_seats` (int), `extra_seat_price_usd` (int) on `plans`. Everything else new
   (`api_limits.*` namespaces, `plan_features.scheduled_reports`) lives inside the
   existing JSON columns — no schema change needed for those.
3. **`app/Models/Plan.php`** — add `max_seats`, `extra_seat_price_usd` to `$fillable`;
   add `scheduled_reports` to `FEATURE_KEYS`; add a `maxSeatsLabel()` helper mirroring
   `maxWebsitesLabel()` for the admin/marketing views.
4. **`app/Models/User.php:54-69`** — rename/add the `TIER_*` constants and `TIER_ORDER`
   map per the "Tier constants rename" section above. This single change is what makes
   `resolveSubscribedPlan()`'s existing final fallback (`Plan::where('slug',
   self::TIER_FREE)->first()`, line 355) resolve to `'trial'` instead of `'free'` —
   no other line in that method needs to change.
5. **`database/seeders/PlanSeeder.php`** — full rewrite per the table above.
6. **`app/Http/Controllers/Admin/PlanController.php::validatePlanInput()`** — add
   validation rules for `max_seats`, `extra_seat_price_usd`, and the four new
   `api_limits.*` dot-paths; extend the existing namespace whitelist loop
   (`['keywords_everywhere','serper','mistral','rank_tracker']`, line ~175) to include
   `keyword_research`, `ai_studio`, `long_form`, `quick_win_finder`. `plan_features`
   handling already iterates `Plan::FEATURE_KEYS` dynamically, so `scheduled_reports`
   needs no extra code there — just the constant change in step 3.
7. **`resources/views/admin/plans/edit.blade.php`** — add: Team Seats + Extra Seat Price
   inputs (blank = unlimited, same pattern as `max_websites`); new API-limits fields for
   Keyword Research (2 fields), AI Studio tokens/mo, Long Form Articles/mo — each
   annotated "not yet enforced" using the same inline-help-text convention already used
   on the page; Quick Win Finder results field annotated as enforced; a `scheduled_reports`
   checkbox in the existing plugin-features grid, annotated platform-only.
8. **`app/Livewire/Dashboard/QuickWinsCard.php`** — replace the hardcoded `quickWins($this
   ->websiteId, 5)` literal with a read of the owning user's effective plan
   `api_limits.quick_win_finder.results_shown` (via the existing `Plan::apiLimit()`
   dot-path helper), falling back to `5` when null/unlimited or unresolved. Need to check
   at implementation time how `ReportDataService::quickWins()` should treat "no cap" —
   either pass a large int or confirm it already tolerates no limit.
9. **Team-seat enforcement** — in `app/Livewire/Websites/WebsiteTeam.php`'s
   `inviteMember()`, add a check against `max_seats` (current member count for the
   website vs. the owner's effective plan cap) and block **new** invites past the cap
   with a friendly message. Deliberately does **not** retroactively remove any existing
   teammate on teams that already exceed a new, lower cap — mirrors the
   non-destructive spirit of `frozenWebsiteIds()` (existing over-cap state is grandfathered,
   only forward growth is gated).

## Docs to update in the same change (per `infra/main.md` protocol)

- `infra/billing/plans-and-gating.md` — replace the 4-tier table with the 5-tier one,
  document the slug-rename migration, the renamed `TIER_*` constants and that `trial` is
  now the no-subscription default fallback (was `free`), the 10th `FEATURE_KEYS` entry,
  and clearly mark which new `api_limits` namespaces are enforced vs. seeded-only (same
  style as the existing `research_limits` callout).
- `infra/accounts/README.md` — document the new `max_seats` cap and that it only gates
  new invites, not existing membership.
- `infra/main.md` — Knowledge Changelog entry for this change.

## Decisions made without explicit user confirmation (flagging for review)

- **Old plan rows are renamed (`legacy_*`) and deactivated, not deleted** — preserves
  existing subscribers' entitlements untouched; no destructive DB op.
- **Enterprise is a real, `is_active = true` row with `price_yearly_usd = null`** so it
  shows in any public plan listing but `isCheckoutReady()` naturally stays false (no
  self-serve checkout) — i.e. it's a "Contact Sales" row, not hidden.
- **Trial is `is_active = true` and is the new no-subscription default**, exactly
  replacing `free`'s old role — no countdown/expiry job is built; flagged as a known gap.
- **Team-seat cap enforces going forward only**, never removes existing teammates.
- **WordPress plugin coordination is a hard dependency, not optional** — shipping this
  without a matching plugin update breaks its tier vocabulary; flagged for the user to
  schedule/own since that repo isn't editable from here.
- **AI Studio tokens/mo, Long Form Articles/mo, Keyword Research searches & results/search
  are seeded but NOT enforced** in this pass — each would need its own new
  `client_activities` provider + `UsageMeter::PROVIDER_LIMIT_PATHS` entry + call-site
  instrumentation, which is materially larger, separable follow-up work.
- **Scheduled Reports flag is seeded but not enforced** — no such feature exists yet to
  gate.
- Yearly-only checkout is left as-is (no real monthly Stripe billing is built); the
  sheet's monthly price is, as today, display copy only.

## Deploy & Verification checklist (run in this exact order)

Every step below runs against the **live production** `ebq` MySQL database (no backups, no
binary log). Per `CLAUDE.md` rule 2, the migration step and the seeder step are run one at a
time with a pause to inspect output before continuing — none of these are run blind/batched.

1. `php artisan config:clear` — mandatory first step per `CLAUDE.md`; also clears any stale
   cached config left over from before these code changes.
2. `php artisan migrate --status` — read-only sanity check: confirm the two new migrations
   (legacy-slug rename, `max_seats`/`extra_seat_price_usd` columns) show as pending and
   nothing unexpected is queued.
3. `php artisan migrate --force` — additive + the one slug-rename migration (transactional,
   modeled on the proven 2026-05-17 precedent). Inspect output before proceeding.
4. `php artisan db:seed --class=PlanSeeder --force` — idempotent `updateOrCreate`, safe to
   re-run. Inspect output before proceeding.
5. `php artisan tinker` checks (read-only):
   - `Plan::pluck('slug', 'is_active')` → expect `legacy_free/legacy_pro/legacy_startup/
     legacy_agency` inactive, `trial/solo/pro/agency/enterprise` active.
   - For a user with no subscription and `current_plan_slug = null`: `effectivePlan()`/
     `effectiveTier()` resolves to `'trial'` (was `'free'`).
   - For a user with an active Cashier subscription on the new `pro` Stripe price:
     resolves to the new ~$49 `pro` row's caps, not the legacy row's.
6. **Restart PHP-FPM**: `sudo systemctl restart php8.3-fpm` (graceful reload is insufficient
   — opcache with `validate_timestamps=0` serves stale bytecode until a full restart, per
   `CLAUDE.local.md`). Confirm the FPM master PID changed.
7. Manual smoke test in the browser (post-restart, since these are web-served):
   - `/admin/plans` — open each of the 5 new tiers' edit pages, confirm every new field
     (Team seats, Extra seat price, the 4 new `api_limits.*` fields, `scheduled_reports`
     checkbox) renders and round-trips on save, including blank-=-unlimited fields.
   - `/admin/billing` — confirm the user list, summary cards, and plan-slug column still
     render correctly for both legacy and new-tier users.
   - Dashboard Quick Wins card on websites across a couple of different tiers — displayed
     count matches each tier's `quick_win_finder.results_shown`.
   - Invite a teammate on a website at/over its plan's `max_seats` cap — invite is blocked
     with the friendly message; existing teammates are untouched.
8. **Do not forget the cross-repo dependency**: the WordPress plugin's tier vocabulary must
   be updated to `{trial,solo,pro,agency,enterprise}` in lockstep, or sites running the old
   plugin will start receiving an unrecognized `tier` value from `effectiveTier()` the
   moment this deploys. This repo's deploy does not block on it, but it should not be
   deployed silently without that plugin update scheduled.
