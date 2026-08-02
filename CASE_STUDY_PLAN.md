# Case study section on the Content AI Autopilot landing page

## Context

`/content-autopilot` argues the product works but never shows it working. The only numbers on the
page today are third-party research (HubSpot, Gartner) — nothing first-party.

We now have a real client to point at: **daomarketing.com**, a Dubai brand-strategy consultancy,
switched on 23 July 2026. Verified from our own database this session: **11 articles published
23 Jul → 2 Aug**, avg SEO score **90.5** (84–100), avg **3,249 words** (35,737 total), **42
original images**, **31 keywords tracked**.

Their Search Console shows real movement in the first week. Four decisions are already settled:

1. **Anonymised** — "a Dubai brand-strategy consultancy". No name, domain or logo.
2. **Per-day rates are the headline.** The windows are unequal (21 days before vs 7 after), so raw
   totals mislead — clicks read as flat (40 → 38) when the daily rate nearly tripled.
3. **The CTR fall is shown, not buried.** Volunteering the one metric that went down is what makes
   the rest believable.
4. **Real screenshots** — supplied and inspected. All four are already anonymous: no property
   selector, no URL bar, no site name anywhere.

⚠️ **Numbers are typed literals from the Search Console UI — never read from our
`search_console_data` table.** Query-level sampling makes our stored totals materially lower than
Google's UI (our DB: 323 impressions for the before window; the UI: 383). Anything derived from
the DB would visibly contradict the screenshot printed beside it. For the same reason this is a
**before/after comparison, never a daily time series**.

## The numbers (from the screenshots — authoritative)

| Metric | Before (7/4–7/24, 21d) | After (7/24–7/30, 7d) | Shown as |
|---|---|---|---|
| Impressions / day | 18.2 | 118.3 | **6.5× more** |
| Clicks / day | 1.9 | 5.4 | **2.9× more** |
| Average position | 14.7 | 10.8 | **3.9 places better** (lower is better) |
| CTR | 10.4% | 4.6% | **5.8 points lower** — shown with its explanation |

Audit-trail totals for the footnote: **383 impressions / 40 clicks** before, **828 impressions /
38 clicks** after.

## Implementation

### 1. New section in `resources/views/content-landing.blade.php`

Insert **between the "quality objection" section and "What this normally costs"**. Nobody weighs
$39 against an agency's $3,000 until they believe the thing works, so proof goes before the first
money section. Background alternation stays correct: white → **slate-50** → slate-900.

Structure, matching the page's existing chrome (eyebrow + h2 + subhead, `mt-12` heading gap,
`mx-auto max-w-6xl px-6 py-16 lg:px-8 lg:py-20`):

- **Heading block** — "Real results", the anonymised client, the 23 July start, and *why* the
  comparison is per-day.
- **Production stat strip** — 11 articles · ~3,250 words each · 42 original images · avg SEO 90.
  Our own outputs, verifiable in the app: what was actually *done*, not just what happened after.
- **`$caseMetrics` array in `@php`** — one row per metric: label, before/after (display text +
  numeric for bar width), delta pill, `good` flag, optional note.
- **Comparison chart** — hybrid: labels and numbers stay real HTML (translatable, selectable,
  legible at 390px); only bar geometry is inline SVG (`viewBox="0 0 100 14"`,
  `preserveAspectRatio="none"`, `vector-effect="non-scaling-stroke"`, `overflow-visible` so round
  caps aren't clipped). **Each metric scaled to its own max** — the four units aren't comparable
  to each other. Direction carried by colour plus an explicit "lower is better" note on position;
  bar length always shows raw magnitude, never silently inverted.
- **CTR row** carries its explanation inline: reach grew ~6.5× while clicks grew ~2.9×, so clicks
  now spread across far more searches.
- **Screenshots**, each behind `@if (is_file(public_path(...)))` so a missing asset degrades to
  chart-only rather than a broken image. Plain `<a target="_blank">` to the full-size file is the
  entire "lightbox" — no JS.
- **Honesty footnote** — one website, one week; early movement, not a settled result; we don't
  claim the articles were the only thing that changed.

### 2. The assets (already uploaded to `public/cases/`, inspected this session)

| File | What it shows | Where it goes |
|---|---|---|
| `28-days.png` | Whole month, 85 clicks / 1.33K impressions, with the **visible inflection at 7/24** | **Primary receipt.** The one screenshot that tells the story alone — shown at every breakpoint |
| `from 7-23.png` | Before window: 40 clicks / 383 impr / 10.4% / 14.7 | Audit-trail pair, `lg` and up |
| `from 24-30.png` | After window: 38 clicks / 828 impr / 4.6% / 10.8 | Audit-trail pair, `lg` and up |
| `calendar.png` | Our calendar: empty to the 22nd, then 11 published cards from the 23rd, SEO 84–100, 3 images each | "What we published" block, all breakpoints |

Prep: convert all four to **webp** with hyphenated names (`gsc-28-days.webp`, `gsc-before.webp`,
`gsc-after.webp`, `calendar-run.webp`) — the current filenames contain spaces, and 686KB of PNG on
a landing page is avoidable. Originals left in place; you can delete them once the webp versions
are live. No cropping needed — I checked all four and none carry a property selector, URL bar or
site name. The calendar's article titles are visible: they reveal the *niche*, not the client,
which is consistent with the anonymise decision.

### 3. Responsive strategy

- **Row 1** — comparison chart + `28-days` screenshot: stacked on mobile, side by side from `lg`
  (at `md` each column is ~344px and a GSC panel becomes an unreadable strip). **Chart first in
  the DOM**, so a phone gets the numbers first and the screenshot as corroboration.
- **Row 2** — the before/after pair, `hidden lg:grid`. They are the literal source of the numbers,
  but two near-identical Google panels are noise on a phone where the chart already says it.
- **Row 3** — `calendar` screenshot beside the production stat strip; stacked on mobile.
- "Tap to open full size" hint below `lg` on each screenshot.

### 4. Supporting changes

- `lang/ar.json` — ~15 new keys, or the Arabic page falls back to English mid-section. Date via
  `translatedFormat('j F Y')`, never a hardcoded "23 July 2026".
- `tests/Feature/ContentLandingPageTest.php` — assert the section renders, the per-day figures
  appear, **the CTR decline is present** (guards against a future edit quietly deleting the
  inconvenient number), and no client name or domain leaks onto the page.
- `npm run build` — `overflow-visible`, `first:pt-0`, `last:pb-0` are not in the compiled bundle
  yet, and `overflow-visible` is load-bearing.

## Files

- `resources/views/content-landing.blade.php` (new section)
- `lang/ar.json`
- `tests/Feature/ContentLandingPageTest.php`
- `public/cases/*.webp` (converted from the PNGs you uploaded)
- `infra/content-autopilot/README.md` — the numbers, their source, and the
  never-derive-these-from-our-GSC-tables rule
- `CASE_STUDY_PLAN.md` at the repo root (project convention for plan docs)

## Verification

1. `php artisan config:clear` → confirm sqlite `:memory:` → `php artisan test
   tests/Feature/ContentLandingPageTest.php tests/Feature/PricingPagesTest.php` (the latter guards
   supplier names; "Search Console" is a whitelisted client-owned integration, so the copy is safe).
2. `npm run build`, `php artisan view:clear`, restart php8.3-fpm (opcache).
3. Headless-Chrome QA at **390 / 768 / 1024 / 1440**: no horizontal overflow
   (`scrollWidth === clientWidth`), chart legible on a phone, screenshot side-by-side only from
   `lg`, section spacing matching its neighbours (80px desktop / 64px mobile).
4. Read the rendered section end to end at 390px and check every claim against the table above.
5. Deploy: commit, push, rsync box B (**never `--delete`**).

## Open item

Seven days is a short window. Recommend revisiting in ~30 days and updating the figures — same
section, better evidence — rather than leaving first-week numbers on the page indefinitely.
