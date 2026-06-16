# Topical authority & content strategy

Three services answer "what topics do you own, and where are the content gaps?". One is pure
GSC math (no embeddings, no LLM); two are LLM-backed gap analyzers that read the audit blob /
Serper.

## TopicalAuthorityService (GSC clustering)

`app/Services/TopicalAuthorityService.php` — `map(Website)`. **Phase 3 #4 — topical authority
map.** Clusters GSC queries into topics and scores authority per cluster, *without* an
embedding pipeline.

Approach: GSC queries that rank for the **same page** are already pre-clustered for free by
Google's NLU. So it clusters on a **two-signal co-occurrence join**: queries that share at
least one significant token (≥3 chars, non-stopword) AND share at least one ranking page. The
two-signal join filters generic-token false positives ("a guide to X" / "a guide to Y").

Flow:
1. Pull aggregated `query × page` rows over a **90-day window**, `>=5` impressions, top 2000 by
   impressions.
2. Build `query→tokens`, `query→pages`, `query→stats` maps + an inverted token index.
3. **Union-find** queries via the two-signal join (skipping ultra-frequent tokens `>200`
   queries, which act as stop-tokens at scale).
4. Materialize clusters; drop single-query clusters and clusters with `<20` impressions (noise).
5. Score each cluster (`authorityScore()` `:244`) — a 0–100 blend: avg position (40%, log
   decay), clicks (25%, saturates 1000), impression breadth (20%, saturates 5000), page
   coverage (10%), query breadth (5%). Sort desc, cap `MAX_CLUSTERS = 30`.
6. **Gaps** = clusters with `authority_score < 40` AND `>=200` impressions/90d → "you get
   traffic on this topic but rank poorly — content opportunity" with a suggested action.

Cached 24h per website (`ebq_topical_authority:{id}`). The output schema is embedding-pass-
ready: a future embedding step can replace the token-overlap clustering transparently.

## TopicalGapService (Serper + LLM subtopics)

`app/Services/TopicalGapService.php` — `analyze(Website, focusKeyword, content, country, language)`.
Grabs the **top-5 SERP results** (Serper) for a focus keyword, then asks the LLM
(`LlmClient`/Mistral, strict JSON) to extract the subtopics competitors cover vs the user's
draft, returning the **missing** subtopics (covered by 2+ competitors, absent from the draft)
with one-line rationales + source links, plus `covered`.

Preconditions return an `unavailable(reason)` shape: `missing_focus_keyword`,
`llm_not_configured`, `content_too_short` (`<200` chars), `no_serp_data`, `llm_parse_failed`.

Caching: 7d keyed on `(website × keyword × country × language × content-hash)` — the
content-hash auto-invalidates on edit. **Deliberately not `Cache::remember`**: only successful
(`available=true`) results are cached. A cached *failure* is treated as a miss AND evicted, so
the live retry path self-heals (older deploys that cached failures don't poison for 7 days).
LLM budget: `max_tokens=2200`, `timeout=28` — the old 1200/18s combo truncated the strict-JSON
mid-stream and surfaced as "AI returned malformed output".

## EntityCoverageService (entity diff, E-E-A-T)

`app/Services/EntityCoverageService.php` — `analyze(Website, url)` / `preflight(Website, url)`.
**Phase 3 #11 — entity coverage analyzer.** Reads the **existing audit blob** (no fetch): the
page body (`result.content.body_text`) + top-3 SERP competitor titles (`result.benchmark.competitors`),
sends both to the LLM, and diffs → "expected entities for this topic that you don't mention"
(`{entity, type, why}`, max 8). Reads like a Wikidata-style coverage report without SPARQL/KG API.

- `preflight()` — dependency-only check for the editor sidebar (renders the "Analyze" button or
  not). **Never calls the LLM** — safe to fire on every editor mount.
- `unavailable(reason)`: `llm_not_configured`, `no_audit` (needs a completed `PageAuditReport`),
  `no_body_text`, `llm_parse_failed`.
- Caching: 7d per `(url × content-hash)` (`ebq_entity_coverage:{xxh3}`). Body capped 5000 chars.

## MOAT note

All three require server-side data the WP plugin can't reproduce offline: the GSC join +
cross-page co-occurrence (Topical Authority), the Serper SERP + LLM extraction (Topical Gap),
and the audit pipeline + SERP benchmark + LLM (Entity Coverage).

## Gotchas / known issues

- **TopicalAuthority needs real GSC volume.** No GSC data → `{ok:false, reason:'no_gsc_data'}`
  (still cached 24h). Thin sites produce few/no clusters by design (noise filters).
- **LLM-backed services degrade to `available/ok:false`** when Mistral is unconfigured or
  returns non-JSON — callers must branch on `reason`, never assume lists exist.
- **EntityCoverage depends on a prior audit.** No completed `PageAuditReport` for the URL →
  `no_audit`; competitor diff is one-sided if the audit had no SERP benchmark (e.g. lite/guest).
- **Topical-gap cache poisoning is handled** for the *current* code path, but only successful
  results are persisted — a site with a flaky LLM will recompute (and re-spend Serper credits)
  on every call until it succeeds.

## Key files

- `app/Services/TopicalAuthorityService.php`
- `app/Services/TopicalGapService.php`
- `app/Services/EntityCoverageService.php`
