# Content Autopilot — Offer-Spine Plan

> Goal: every wizard artifact (competitors, keywords, first articles, guard
> posture, article style) must visibly derive from **what the site offers and
> who buys it** — for every client type (blog / affiliate / brand / reseller /
> local service / SaaS / B2B / nonprofit). Benchmark: the competitor product's
> Kayali onboarding (product-ranked offers → 5 buyer-intent winnable terms →
> 5 articles 1:1 → peer-class competitor gap).
>
> Status: **BUILT 2026-07-23** (all phases A–F implemented on the staging
> branch with tests; see `infra/content-autopilot/README.md` for the
> as-built docs). Prod deploy pending owner verdict. Product is LIVE
> (prod + staging), so every phase is additive and backfillable; null
> `site_type` degrades to the exact pre-spine pipeline everywhere.

---

## 1. Diagnosis (why results feel "out of context" today)

Grounded in the current code:

| # | Gap | Where it lives today |
|---|-----|----------------------|
| G1 | **No site-type concept.** One pipeline for all clients; nothing parameterizes intent shapes, CTA style, guard posture, or query generation. | `content_plans` has no type column; `SiteProfileExtractor` returns only `{description, sell, dont_sell}` |
| G2 | **Keyword seeds are truncated offering heads, ranked by volume.** `seeds()` reduces `offerings['sell']` to ≤5-word heads; "top searches" ranked purely by volume; "opportunities" = volume × competition-tier weight. No buyer-intent weighting, no calibration against the site's own authority, no offer lineage in the output. | `ContentKeywordInsights::seeds()` (~:1092), `build()` (~:1374, weight table ~:1501) |
| G3 | **Competitors are SERP-tally-ordered, not peer-banded.** GiantDomains is a static ~90-entry list; anything not on it (mid-size directories, magazines) still outranks real rivals. No "same weight class" concept, so the authority gap display can compare a DA-14 client against a DA-70 magazine — demoralizing and useless. | `ContentSetupInsights::build()` (~:348-469), `GiantDomains` |
| G4 | **First articles are not 1:1 from user-confirmed terms.** Topics come from one ideation LLM call (GSC gap → DFS gap → LLM filler). Fine for GSC-rich sites; for fresh sites the list is whatever the prompt hallucinates as plausible. The keyword-research step is read-only — the user never confirms/prunes the terms the articles will be built on. | `ContentTopicPlanner::ideate()` (~:287), wizard step 6 read-only digest |
| G5 | **Guard is binary Protect/Off.** Correct for brands/local services; actively wrong for affiliate (brands ARE the content) and resellers (stocked brands must be allowed). Invisible after setup — no evidence of value. | `CompetitorMentionGuard`, `toggles.block_competitor_mentions` |
| G6 | **Writer style/CTA are type-blind.** `templateInstructions()` applies the same rules for a perfume brand, a plumber, and a hobby blog; `cta_url` is one URL with no type-appropriate framing. | `ContentArticleProducer::templateInstructions()` (~:717) |

Keyword difficulty from the DFS harvest is already captured in `keyword_metrics`
but never surfaced/used for selection — free ammunition for G2.

## 2. Architecture: one spine, type-parameterized

```
extract OFFERS + SITE TYPE ──► user confirms (wizard)
        │
        ▼
INTENT PROFILE  = ContentSiteTypeProfiles[site_type]
  (query shapes, intent weights, CTA style, guard default, article mix)
        │
        ├──► QUERY GENERATION   offer × intent shape → candidate terms
        ├──► TERM SELECTION     winnability(own authority vs difficulty) × buyer-intent; volume = tiebreak
        ├──► PEER COMPETITORS   rival-classified + DA-band → gap display; giants/refs demoted
        ├──► FIRST ARTICLES     1:1 from user-confirmed terms (fresh sites); GSC gap still wins when present
        ├──► GUARD POSTURE      Protect / Off now; Brands-required / Stocked-only later
        └──► WRITER RULES       CTA framing, voice, YMYL-conservative flag
```

**Site types (v1 enum):** `blog`, `affiliate`, `brand`, `ecommerce_reseller`,
`local_service`, `saas`, `b2b_services`, `nonprofit`, `other`.

**`ContentSiteTypeProfiles`** (new, `app/Support/`): pure config map per type —
intent weights (informational/commercial/transactional/navigational), query
shape templates ("how to choose {offer}", "{offer} cost in {city}", "best
{offer} for {audience}", "{rival} alternative"…), default guard posture,
CTA framing, voice, TOFU/MOFU/BOFU mix ratio. No I/O; unit-testable.

### Live-product ground rules (apply to every phase)

1. **Additive only.** New columns nullable, new cache keys version-bumped
   (`content:kw-insights:v1:` → `:v2:` etc.) — never mutate a live cache shape.
2. **Flag-gated.** Each phase behind a `Setting` toggle
   (`content.spine.{phase}`) so rollback = flip, no deploy. Respect the
   `Setting::set` cache-bust landmine.
3. **Both wizard hosts.** Logic goes in the `ContentWizard` trait; anything
   host-specific must land in BOTH `ContentCalendar` and `PublicOnboarding`
   (the 2026-07-22 guard-drift trap). Every phase's test list includes a
   public-onboarding test.
4. **Existing plans keep working untouched.** Classification is lazy/backfilled;
   in-flight topics, calendars, caches never invalidated destructively.
5. **All new LLM calls**: flash tier via `modelFor('ideate')`,
   `__unmetered => true` (UsageMeter exemption landmine), charged to
   `ContentLlmSpendMeter`, `tries=1` on jobs.
6. **Staging first, prod only on explicit approval** (standing hard rule).
7. **Docs**: each phase updates `infra/content-autopilot/README.md` in the same
   change.

---

## 3. Phases

### Phase A — Site-type classification + intent profiles (foundation)

**Code:**
- `SiteProfileExtractor`: extend the one existing LLM call (no extra call) to
  also return `site_type` (enum + confidence) and `audience` (one line, "who
  buys / reads this"). Bump profile cache key `content:site-profile:v1:` → `:v2:`.
- Migration: `content_plans.site_type` (nullable string), `site_type_source`
  (`auto|user`), `audience` (nullable string).
- New `app/Support/ContentSiteTypeProfiles.php` (config map above).
- Wizard step 1 (trait + shared blade): detected type shown as a selectable
  chip row ("Looks like a **brand that sells its own products** — right?"),
  wrong guess = one click to fix. Persisted at the existing step-2
  `updateOrCreate`. Confirm parity in `PublicOnboarding`.
- Backfill: `ebq:content-classify-plans` artisan command — for live plans with
  `business_description` and null `site_type`, one flash call each (reuses the
  extractor prompt against stored profile text, no fetches). `--dry-run`,
  `--limit`, spend-metered, idempotent. Run on staging → review outputs →
  prod after approval.

**Guard rails:** classification failure → `site_type = null` → every consumer
falls back to today's behavior (null = current pipeline). `other` behaves like
today's defaults too.

**Tests:** extractor returns type per fixture site (brand/local/blog/affiliate
fixture pages); wizard chip override persists; public host parity; backfill
dry-run touches nothing.

### Phase B — Offer-grounded keyword generation + winnability ranking

**Code:**
- New `app/Services/Content/OfferQueryGenerator.php`: one LLM call — inputs
  offers (full items, not truncated heads), audience, site type's query shapes,
  country/language → ~30 candidate queries, each tagged
  `{query, offer, intent, shape}`. Replaces/augments `seeds()`'s head-chopping:
  top candidates become the keyword-server seeds (cap 20 unchanged); the full
  candidate set becomes the lineage backbone.
- `ContentKeywordInsights` ranking flip:
  - **Winnability**: use `keyword_metrics.keyword_difficulty` (already
    harvested, currently unused) + competition tier, calibrated against own
    authority (`domain_metrics.moz_da` / `dfs_referring_domains`, already
    stored). A DA-15 site: difficulty ≤25 = winnable; DA-40: ≤45. Simple
    banded function in `ContentSiteTypeProfiles` or a small
    `KeywordWinnability` helper — deterministic, unit-tested.
  - **Selection**: `score = winnability × intentWeight(site_type)`; volume
    breaks ties only. Top 5-8 become "best terms".
  - **Lineage**: each selected term carries its source offer; digest payload
    gains `origin` per term.
- Cache key bump `content:kw-insights:v1:` → `:v2:` (old cache serves old
  clients until natural expiry; new renders compute fresh).
- Keep every degradation path (keyword server down → partial payload) — the
  LLM candidates + KE fallback still produce offer-grounded terms with
  unknown volumes, which is strictly better than today's fallback.
- UI (step 6): terms rendered as chips with ♥/✕ (their screenshot), sub-label
  "because you sell: {offer}". Public host keeps `$showVolumes = false`.

**Tests:** generator output shape per site type; winnability bands; ranking
prefers winnable-intent over high-volume; lineage present; degradation paths.

### Phase C — Confirmed terms → first articles 1:1

**Code:**
- Wizard step 6 becomes lightly interactive: user prunes/keeps "best terms"
  (default all kept — zero-friction path unchanged). Kept terms persist to
  `content_plan_keywords` with new `type = confirmed`.
- `ContentTopicPlanner`: new top-priority source `confirmed` — one topic per
  confirmed term, 1:1, title generated from term + offer + type shape
  (source stays visible in `content_topics.source`). Priority order becomes:
  `confirmed` → `gsc_gap` → `gap` → `llm`. GSC-rich sites keep their
  striking-distance advantage; fresh sites get the deterministic 1:1 chain.
- Step 7 shows the derivation ("from your term: *fragrance sampler for
  women*"). Google-mockup teaser panel above step 6 ("this is what your future
  customers are searching right now / your article could show up here") —
  pure blade, no API, high perceived value; compiled-Tailwind caveat: check
  the bundle for any new utility classes.
- Since the planner tops up continuously: confirmed terms only seed the FIRST
  batch; top-up ideation now also receives site type + audience + intent mix
  in its prompt (one prompt edit in `ContentTopicPlanner::ideate()`).

**Tests:** 1:1 mapping; pruned term produces no topic; priority order; top-up
respects type mix; both wizard hosts; dropTopic status guard still holds.

### Phase D — Peer competitor split + honest gap display

**Code:**
- `ContentSetupInsights::build()` output gains per-competitor `class`:
  `peer` (guard-classified rival AND DA within band of own DA, e.g. ±25 or
  ≤3× referring domains) / `aspirational` (rival above band) / `reference`
  (guard reference class) / `giant` (GiantDomains). Pure re-labeling of data
  already fetched — **zero new API cost**.
- Wizard step 5 UI: main table = peers + aspirationals with the client's own
  row inserted in rank position ("YOU" badge — their screenshot 2); gap
  multiplier computed **vs peer median** (honest, beatable), not vs whole
  list. References/giants collapse into "Also on these results pages" —
  still visible (they're real SERP context) but excluded from gap math.
- `rankAndFilter()` stays the single research-pick authority (landmine rule);
  peers feed it naturally since blocked rivals already rank first.
- Cache bump `content:setup-insights:v1:` → `:v2:`.

**Tests:** banding math; own-row insertion; gap-vs-peer-median; giants never in
gap; manual adds classified on merge; sandbox path unaffected.

### Phase E — Guard evolution (high-value, partly independent)

Ship E1-E4 in any order once A lands; E5 depends on site types.

- **E1 Value counter**: revise loop already strips mentions via
  `competitor_mentions` lint issue — log each strip into
  `content_plans.competitor_guard['stats']`; card shows "14 articles checked ·
  3 mentions removed". Makes the feature visible forever.
- **E2 Chip grouping UI**: split the flat chip list into **Blocked rivals** /
  **Allowed references** (classification already exists in
  `guard['auto']`/references — just never rendered); move-between = existing
  add/remove plumbing. Chip hover = the classifier's context line
  (title/meta already cached at `content:guard-ctx:{host}`). Auto/manual badge.
- **E3 Alias expansion**: per blocked brand, one flash call → aliases,
  abbreviations, product names, domains ("Urban Company" → "UC",
  "urbancompany.ae"). Stored under the parent term; lint gets the expanded
  set. Cached; spend-metered.
- **E4 Retroactive scan**: on new block-term add (manual or re-assess), queued
  job lints PUBLISHED articles for the term; hits flag the topic
  ("Needs attention" pill + review-page note, client-safe copy per the
  copy-rules memory). Never auto-edits published content.
- **E5 Policy modes** (needs Phase A): `guard['mode']` =
  `protect` (today) / `off` / `brands_required` (affiliate: no blocking; lint
  inverts to WARN when a comparison article names zero brands; disclosure
  reminder in writer rules) / `stocked_only` (reseller: allowed brand list =
  offers; competing *retailers* still blocked). Default from
  `ContentSiteTypeProfiles`, auto-enable banner logic unchanged (human
  decision still never overridden). Review page gains an explicit
  "Brand safety" check row.

**Tests:** counter increments once per strip; retro scan flags without
mutating HTML; mode matrix (affiliate article naming brands passes;
brand-site article naming rival fails); alias hits lint.

### Phase F — Type-aware writing

**Code:** `ContentArticleProducer::templateInstructions()` +
`ContentTopicPlanner::ideate()` consume the profile:
- CTA framing per type (product page / book-a-call / trial / subscribe /
  donate) around the existing `cta_url`.
- Voice: personal-first-person (blog/creator) vs corporate (B2B/SaaS);
  YMLY-conservative rule (no bold claims, cite-or-omit) when the profile or
  topic smells health/finance/legal.
- Article mix: planner prompt requests the type's TOFU/MOFU/BOFU ratio.

**Tests:** prompt snapshot per type; pipeline test still green (chunked
protocol untouched — these are instruction-text-only changes).

---

## 4. Sequencing, effort, cost

| Order | Phase | Effort | New recurring cost |
|---|---|---|---|
| 1 | A foundation | 2-3 d | ~$0.00 (same extractor call); backfill one-off ~$0.01/plan |
| 2 | B keyword flip | 3-4 d | +1 flash call/onboarding (~$0.01) |
| 3 | C 1:1 articles | 2-3 d | none |
| 4 | D peer split | 2 d | none (relabels fetched data) |
| 5 | E guard (E1-E4 anytime after A; E5 after A) | 3-4 d | E3 ~$0.01/brand, E4 negligible |
| 6 | F type-aware writing | 1-2 d | none |

Total ≈ 3 working weeks on staging including QA. Phases independently
shippable/testable; each is a separate staging deploy + regression run.

## 5. QA matrix (staging, before any prod ask)

One real domain per type through the FULL public-onboarding funnel (the
weaker host historically) + dashboard wizard:

| Type | Domain | Must see |
|---|---|---|
| brand | kayali.com | product-shaped terms w/ lineage; peer gap ≈ their screenshot; guard Protect |
| local_service | mkccleaningservices.com | geo terms; justlife/urban-company blocked; E2 grouping |
| blog | (pick a content site) | informational terms; guard Off-by-default; no CTA pushiness |
| affiliate | (pick a review site) | "best/vs" terms; E5 brands_required — NO blocking |
| reseller | (pick a shop) | stocked-brand allowed list |

Regression: full `tests/Feature/Content/*` (after the mandatory
`config:clear` + sqlite `:memory:` verification), plus the 7-day empty-profile
cache bust when retesting any previously-failed site.

## 6. Risks & mitigations

| Risk | Mitigation |
|---|---|
| Type misclassification steers whole pipeline wrong | user-confirm chip at step 1; `null`/`other` = today's behavior; `site_type_source` records who decided |
| Cache-shape drift on live traffic | version-bumped keys only; old keys expire naturally |
| Keyword server latency (concurrency-1) worsens | no new server requests added; MAX_COMPETITORS stays 1; new signals ride existing DFS harvest + one flash call |
| Backfill spend across live plans | dry-run + limit + meter; staged rollout |
| Wizard host drift (the recurring trap) | all logic in trait; per-phase public-onboarding test is a merge requirement |
| Affiliate mode weakens protection for misclassified brand sites | mode change is explicit UI action or confirmed type; auto-enable still biases to Protect |
| Existing clients see terms/competitors change under them | new ranking applies on cache expiry / refetch only; no forced invalidation; changelog note in UI not needed (client copy rules) |

## 7. Explicitly deferred

- Automated peer *discovery* beyond current SERP method (LLM-proposed peer
  candidates verified via DFS) — current SERP tally + banding should get 80%
  there; revisit after Phase D data.
- `brands_required` affiliate-link insertion (needs affiliate-network
  awareness) — v1 only stops the guard from fighting affiliate content.
- GSC-country signal for geo (noted in infra docs as future).
- Marketplace/news types (thin demand; `other` covers).
