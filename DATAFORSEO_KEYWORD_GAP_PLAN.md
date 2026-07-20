# DataForSEO keyword gap — 3 competitors, monthly accumulation

**Status:** approved 2026-07-20. Staging-first, prod only after approval.

## Decisions
- **Competitor gap mining → DataForSEO Labs** (`ranked_keywords/live`). Fast (seconds
  vs the concurrency-1 keyword server), real Google volumes.
- **3 competitors** (was 1).
- **Shared per-domain harvest** — a competitor domain is harvested ONCE per month
  regardless of how many plans reference it; all plans reuse the cached rankings.
- **Self-hosted keyword server stays UNTOUCHED** — it serves the other product
  (normal SEO keyword finder). The content flow may still call it for client seeds;
  we do not remove or modify it. DFS is additive for the competitor gap.
- Incremental: **+1,000 keywords/competitor/month** via a volume cursor; when a
  competitor is exhausted, the active window slides to the next competitor.

## Data model
- **`keyword_metrics`** (exists) — add DFS Labs fields where missing:
  `keyword_difficulty`, `search_intent`, `cpc`, `competition`. `source='dfs_labs'`.
  Shared; paid once per keyword.
- **`domain_keyword_rankings`** (NEW pivot — links many domains ↔ one keyword):
  `id, domain, keyword_hash, keyword, country, rank_absolute, se_type, page_url,
  etv, is_new, is_up, is_down, is_lost, previous_rank, search_volume, fetched_at,
  timestamps`. UNIQUE `(domain, keyword_hash, country)`. Indexed on
  `keyword_hash` (keyword→domains) and `domain` (domain→keywords). Shared globally,
  rows never deleted on churn (asset compounds, like domain_metrics).
- **`domain_keyword_harvest`** (NEW, per domain+country cursor):
  `id, domain, country, volume_cursor (nullable), keywords_fetched, exhausted bool,
  last_run_at, timestamps`. UNIQUE `(domain, country)`.

## Client method
`DataForSeoBacklinkClient::rankedKeywords(string $domain, array $opts): array`
- POST `/dataforseo_labs/google/ranked_keywords/live`:
  `target`, `language_name`, `location_code=2840`, `limit` (≤1000),
  `order_by=["keyword_data.keyword_info.search_volume,desc"]`,
  `item_types=["organic"]`, and (when cursor set)
  `filters=[["keyword_data.keyword_info.search_volume","<",$cursor]]`.
- Returns normalized rows: `keyword, search_volume, cpc, competition,
  keyword_difficulty, search_intent, rank_absolute, page_url, etv`.
- Reuses existing auth/sandbox/spend-meter (`firstResult`/`items`, cost accrual).

## Harvest job (shared, per domain)
`App\Jobs\Content\HarvestDomainKeywordsJob(string $domain, string $country, int $limit=1000)`
(content queue, `onConnection('redis-long')`):
1. Load/create `domain_keyword_harvest`; read `volume_cursor`.
2. If `exhausted` → return.
3. `rankedKeywords($domain, {limit, cursor, country})` (admin→sandbox, else metered).
4. Upsert `keyword_metrics` (facts) + `domain_keyword_rankings` (this domain's rank).
5. `volume_cursor = min(search_volume of batch)`; `keywords_fetched += count`;
   if `count < limit` → `exhausted=true`. `last_run_at=now`.
6. Spend-metered; skip when `DataForSeoSpendMeter::exhausted()`.

## 3-competitor selection + rotation (per plan, DERIVED — no manual state)
- Ordered competitor list = `ContentSetupInsights::competitorAuthority()` order.
- **Active 3 = first 3 competitors whose `domain_keyword_harvest.exhausted=false`.**
  When one exhausts, the window auto-slides to include the next competitor.

## Monthly accumulation
`ebq:content-keyword-harvest` (scheduled monthly): for each active ContentPlan,
resolve active-3 competitors + client domain, dispatch `HarvestDomainKeywordsJob`
for each domain NOT already harvested this month (`last_run_at` guard → per-domain
sharing). Onboarding path harvests the first 1,000×3 immediately (parallel, seconds).

## Gap computation (ContentKeywordInsights)
Gap = union of `domain_keyword_rankings` for the active-3 competitors, MINUS the
client domain's own ranked `keyword_hash`es, ranked by the existing LLM relevance
filter (`relevanceKeep`) + volume. Replaces the keyword-server competitor mining
for the gap. `gap_total` = accumulated count (grows monthly).

## Cost
~$0.011/1,000 rows → 3 competitors + client ≈ **$0.04/plan/month**, shared across
plans on the same domains, under the $150/mo `DataForSeoSpendMeter` cap.

## Phases (staging-first)
1. Migrations (2 tables + keyword_metrics columns) + models.
2. `rankedKeywords()` client method + unit test (fixture response shapes).
3. `HarvestDomainKeywordsJob` + spend/sandbox + tests.
4. Gap rewrite in `ContentKeywordInsights` (active-3, DFS gap, LLM filter) + tests.
5. `ebq:content-keyword-harvest` command + scheduler + onboarding fast-path.
6. Wizard/UI: 3 competitors, growing gap total; i18n; infra docs.
7. Staging QA → prod rollout (migrate + both boxes + scheduler).
