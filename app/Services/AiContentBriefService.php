<?php

namespace App\Services;

use App\Models\SearchConsoleData;
use App\Models\Website;
use App\Services\Llm\LlmClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AI-generated content brief from a target keyword.
 *
 * Flow
 * ────
 *  1. Serper top-10 SERP for the keyword (paid; cached 7d).
 *  2. LLM extracts: subtopics-to-cover, recommended_word_count, suggested
 *     schema_type, must-have entities/people/places, suggested H2/H3
 *     outline, content angle (commercial / informational / comparison).
 *  3. We bolt on internal-link targets from the user's own site by
 *     pulling top GSC-clicked URLs that semantically match the keyword.
 *
 * The output is everything a writer needs to draft a competitive page:
 * structure, depth, schema, and where to internally link.
 *
 * Caching
 * ───────
 * Per (website × keyword × country) for 7 days. Re-clicks are free. New
 * content rarely shifts the brief in <1 week.
 *
 * MOAT
 * ────
 *  - Serper SERP scrape (paid)
 *  - Full prompt + few-shot examples (server-side)
 *  - Internal-link-target bolt-on requires GSC join (per-site data gravity)
 *  - All prompt-engineering tweaks happen on EBQ; plugin sees only output
 */
class AiContentBriefService
{
    private const CACHE_TTL_DAYS = 7;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly SerperSearchClient $serper,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *   ok: bool,
     *   brief?: array<string, mixed>,
     *   cached?: bool,
     *   error?: string,
     * }
     */
    /**
     * Cache-only lookup — never triggers a fresh SERP/LLM run. Used by
     * lightweight callers (e.g. block-toolbar AI ops) that want to enrich
     * prompts when a brief exists but must not pay for one when it doesn't.
     *
     * @return array<string, mixed>|null
     */
    public function cachedBrief(Website $website, string $focusKeyword, string $country = 'us', string $language = 'en'): ?array
    {
        $keyword = trim($focusKeyword);
        if ($keyword === '') {
            return null;
        }
        $country = $country !== '' ? strtolower($country) : 'us';
        $language = $language !== '' ? strtolower($language) : 'en';
        $cacheKey = sprintf(
            'ai_content_brief_v4:%s:%s:%s:%s',
            $website->id,
            hash('xxh3', mb_strtolower($keyword)),
            $country,
            $language,
        );
        $cached = Cache::get($cacheKey);
        return is_array($cached) ? $cached : null;
    }

    public function brief(Website $website, int $postId, array $input): array
    {
        if (! $this->llm->isAvailable()) {
            return ['ok' => false, 'error' => 'llm_not_configured'];
        }

        $keyword = trim((string) ($input['focus_keyword'] ?? ''));
        if ($keyword === '') {
            return ['ok' => false, 'error' => 'missing_focus_keyword'];
        }
        $country = is_string($input['country'] ?? null) && $input['country'] !== ''
            ? strtolower($input['country']) : 'us';
        $language = is_string($input['language'] ?? null) && $input['language'] !== ''
            ? strtolower($input['language']) : 'en';

        // v4 cache namespace — bump when the brief schema/prompt changes.
        // v2 added: suggested_h1 field.
        // v3 sharpened the suggested_outline vs. subtopics distinction so
        //     they're no longer near-duplicates in the UI.
        // v4 added the output-language rule + language cache segment — an
        //     Arabic project must neither get nor cache an English brief.
        $cacheKey = sprintf(
            'ai_content_brief_v4:%s:%s:%s:%s',
            $website->id,
            hash('xxh3', mb_strtolower($keyword)),
            $country,
            $language,
        );
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            $cached['cached'] = true;
            return $cached;
        }

        $serp = $this->serper->search(
            $keyword,
            10,
            $country,
            $language,
            websiteId: $website->id,
            ownerUserId: $website->user_id,
            source: 'plugin_writer',
        );
        if (! is_array($serp) || empty($serp['organic'])) {
            return ['ok' => false, 'error' => 'no_serp_data'];
        }

        $organic = array_slice(array_filter($serp['organic'], 'is_array'), 0, 10);
        $brief = $this->llm->completeJson($this->buildPrompt($keyword, $organic, $language), [
            'temperature' => 0.4,
            'max_tokens' => 1500,
            'json_object' => true,
            'timeout' => 45,
        ]);

        if (! is_array($brief) || empty($brief['subtopics'])) {
            Log::warning('AiContentBriefService: malformed LLM response', [
                'website_id' => $website->id,
                'keyword' => $keyword,
            ]);
            return ['ok' => false, 'error' => 'llm_parse_failed'];
        }

        // Internal-link targets — top-clicked URLs on this site that
        // semantically relate to the keyword. Lightweight bolt-on; reuses
        // the existing resolver helper to avoid duplicating GSC logic.
        $internalTargets = $this->internalLinkTargets($website, $keyword);

        $result = [
            'ok' => true,
            'brief' => [
                'keyword' => $keyword,
                'country' => $country,
                'angle' => (string) ($brief['angle'] ?? 'informational'),
                'recommended_word_count' => max(0, (int) ($brief['recommended_word_count'] ?? 0)),
                'suggested_schema_type' => (string) ($brief['suggested_schema_type'] ?? 'Article'),
                'suggested_h1' => trim((string) ($brief['suggested_h1'] ?? '')),
                'subtopics' => $this->normalizeStringList($brief['subtopics'] ?? [], 20),
                'must_have_entities' => $this->normalizeStringList($brief['must_have_entities'] ?? [], 12),
                'suggested_outline' => $this->normalizeOutline($brief['suggested_outline'] ?? []),
                'people_also_ask' => $this->normalizeStringList($brief['people_also_ask'] ?? [], 10),
                'internal_link_targets' => $internalTargets,
                'serp_titles' => array_values(array_filter(array_map(
                    fn ($r) => is_array($r) && isset($r['title']) ? (string) $r['title'] : null,
                    $organic,
                ))),
            ],
            'cached' => false,
            'generated_at' => Carbon::now()->toIso8601String(),
        ];

        Cache::put($cacheKey, $result, Carbon::now()->addDays(self::CACHE_TTL_DAYS));
        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $organic
     * @return list<array{role: string, content: string}>
     */
    private function buildPrompt(string $keyword, array $organic, string $language = 'en'): array
    {
        $serpBlock = '';
        foreach ($organic as $i => $row) {
            $title = (string) ($row['title'] ?? '');
            $snippet = (string) ($row['snippet'] ?? '');
            $link = (string) ($row['link'] ?? '');
            $serpBlock .= sprintf(
                "%d. %s\n   %s\n   %s\n",
                $i + 1,
                mb_substr($title, 0, 120),
                mb_substr($link, 0, 120),
                mb_substr($snippet, 0, 240),
            );
        }

        $langName = AiWriterService::LANGUAGE_NAMES[$language] ?? '';
        $langRule = ($langName !== '' && $langName !== 'English')
            ? "\n\nOUTPUT LANGUAGE (HARD RULE): write EVERY brief value — suggested_h1, "
                ."suggested_outline headings, subtopics, people_also_ask questions, the angle — "
                ."in {$langName}. The SERP results may be in another language: translate the "
                ."concepts, never echo untranslated headings. Keep the target keyword itself "
                ."verbatim; proper nouns and brand names stay as-is."
            : '';

        $system = <<<'SYS'
You are a senior SEO content strategist. Given a target keyword and the
top-ranking pages, you produce a content brief sharp enough that a writer
can draft a winning page in one sitting.

Constraints:
- suggested_h1: ONE compelling, click-worthy H1 title (≤ 65 chars). Must
  contain the target keyword naturally; not just a duplicate of one of
  the SERP titles — improve on them.
- subtopics: 8–14 specific concepts, sub-points, entities, or angles
  the page must cover. These are NOT section headlines — they're the
  granular ideas that may appear inside any section. They MUST NOT
  duplicate items in `suggested_outline`. Examples of good subtopics:
  "macro vs. micro nutrients", "leucine threshold", "plant-based
  bioavailability", "post-workout timing window". Bad subtopics
  (because they read like H2s): "What is vegan protein?", "Best
  vegan protein sources" — those belong in `suggested_outline`.
- recommended_word_count: integer; based on the median word depth implied
  by the SERP titles + snippets. 600 floor, 4500 ceiling.
- suggested_schema_type: one of Article, HowTo, FAQ, Review, Product,
  Recipe, Event, Course, LocalBusiness — pick the SINGLE best fit.
- must_have_entities: people, brands, products, places, frameworks the
  competitors mention in their titles/snippets.
- suggested_outline: 6–10 H2-level section titles in narrative order.
  These ARE the article's structural backbone (intro → body sections
  → conclusion-style). Phrased as headlines, not concepts. MUST NOT
  duplicate items in `subtopics`.
- people_also_ask: 5–10 questions a reader is likely to want answered.
- angle: commercial | informational | comparison | guide | listicle.

Return STRICTLY valid JSON. No prose, no markdown.
SYS;
        $system .= $langRule;

        $user = <<<USER
Target keyword: "{$keyword}"

Top 10 SERP results:
{$serpBlock}

Return JSON exactly in this shape:
{
  "suggested_h1": "Compelling H1 with keyword",
  "angle": "...",
  "recommended_word_count": 1800,
  "suggested_schema_type": "Article",
  "subtopics": ["...", "..."],
  "must_have_entities": ["...", "..."],
  "suggested_outline": ["H2 title 1", "H2 title 2", "..."],
  "people_also_ask": ["...", "..."]
}
USER;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * Top-clicked URLs on this site whose GSC queries semantically match
     * the brief's keyword. Built directly from `search_console_data` so the
     * brief works even before any post is published — we only need the
     * site's existing GSC footprint, not a canonical URL.
     *
     * Returns up to 6 candidates with the matched query as the anchor hint.
     * Pass $excludeUrl to drop a page from the results — e.g. the page we're
     * trying to rank, which always matches the keyword and would otherwise
     * surface as a useless self-link.
     *
     * @return list<array{url: string, anchor_hint: string, clicks_30d: int}>
     */
    public function internalLinkTargets(Website $website, string $keyword, ?string $excludeUrl = null): array
    {
        $tokens = $this->significantTokens($keyword);
        if ($tokens === []) {
            return [];
        }

        try {
            $tz = config('app.timezone');
            $end = Carbon::yesterday($tz)->endOfDay();
            $start = $end->copy()->subDays(89)->startOfDay();

            // Exclude the page itself at the SQL level, NOT in PHP afterwards.
            // The page we're ranking matches the keyword across dozens of query
            // variations, so it would otherwise fill the top-40 rows and starve
            // out the genuine source-page candidates once removed.
            $excludeUrls = [];
            if ($excludeUrl !== null) {
                $u = trim($excludeUrl);
                $excludeUrls = array_values(array_unique([$u, rtrim($u, '/'), rtrim($u, '/') . '/']));
            }

            $rows = SearchConsoleData::query()
                ->where('website_id', $website->id)
                ->whereDate('date', '>=', $start->toDateString())
                ->whereDate('date', '<=', $end->toDateString())
                ->where('page', '!=', '')
                ->where('query', '!=', '')
                ->when($excludeUrls !== [], fn ($q) => $q->whereNotIn('page', $excludeUrls))
                ->where(function ($w) use ($tokens) {
                    foreach ($tokens as $tok) {
                        $w->orWhere('query', 'LIKE', '%' . $tok . '%');
                    }
                })
                ->selectRaw('page, query, SUM(clicks) AS clicks')
                ->groupBy('page', 'query')
                ->orderByDesc('clicks')
                ->limit(40)
                ->get();

            // Belt-and-suspenders: also drop case-only variants the SQL IN missed.
            $excludeKey = $excludeUrl !== null ? rtrim(mb_strtolower(trim($excludeUrl)), '/') : null;

            $byPage = [];
            foreach ($rows as $r) {
                $page = (string) $r->page;
                if ($excludeKey !== null && rtrim(mb_strtolower(trim($page)), '/') === $excludeKey) {
                    continue;
                }
                if (! isset($byPage[$page])) {
                    $byPage[$page] = [
                        'url' => $page,
                        'anchor_hint' => (string) $r->query,
                        'clicks_30d' => (int) $r->clicks,
                    ];
                } else {
                    $byPage[$page]['clicks_30d'] += (int) $r->clicks;
                }
            }
            uasort($byPage, fn ($a, $b) => $b['clicks_30d'] <=> $a['clicks_30d']);
            return array_values(array_slice($byPage, 0, 6));
        } catch (\Throwable $e) {
            Log::warning('AiContentBriefService: internal link candidate lookup failed', [
                'website_id' => $website->id,
                'keyword' => $keyword,
                'message' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function significantTokens(string $text): array
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') return [];
        $parts = preg_split('/[^a-z0-9]+/u', $text) ?: [];
        $stop = ['the', 'and', 'for', 'with', 'a', 'an', 'of', 'to', 'in', 'on', 'is', 'are', 'or'];
        $out = [];
        foreach ($parts as $p) {
            if (mb_strlen($p) >= 3 && ! in_array($p, $stop, true)) {
                $out[$p] = true;
            }
            if (count($out) >= 5) break;
        }
        return array_keys($out);
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    private function normalizeStringList($raw, int $cap): array
    {
        if (! is_array($raw)) return [];
        $out = [];
        foreach ($raw as $v) {
            if (! is_string($v)) continue;
            $s = trim($v);
            if ($s === '') continue;
            $out[] = mb_substr($s, 0, 200);
            if (count($out) >= $cap) break;
        }
        return $out;
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    private function normalizeOutline($raw): array
    {
        return $this->normalizeStringList($raw, 12);
    }
}
