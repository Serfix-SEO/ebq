<?php

namespace App\Services;

use App\Services\Llm\LlmClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AI-generated related keyphrase suggestions.
 *
 * Replaces the previous GSC + rank-tracker SERP pull. Users frequently
 * have NO Search Console history when they're starting a new post, so
 * GSC-only suggestions returned empty exactly when they were most useful.
 * The AI generator works from the focus keyphrase alone, returns a
 * curated list of search-style queries (modifier patterns, synonyms,
 * intent variants), and is cached 7 days per (website × keyword) to
 * keep latency + cost bounded.
 *
 * Output contract matches the previous GSC-based shape so the WP-side
 * consumer (`RelatedKeyphrases.jsx`) doesn't change:
 *   { keyword: string, source: 'ai', volume?: null, impressions?: null }
 */
class AiRelatedKeywordsService
{
    private const CACHE_TTL_DAYS = 7;

    /** Cache-buster for prompt revisions. */
    private const PROMPT_VERSION = 'v1';

    /** How many suggestions to ask for / accept. */
    private const SUGGESTIONS_PER_RUN = 12;

    public function __construct(private readonly LlmClient $llm) {}

    /**
     * @return list<array{keyword:string, source:string, volume:?int, impressions:?int}>
     */
    public function suggest(string $websiteId, string $keyword): array
    {
        $keyword = trim($keyword);
        if ($keyword === '' || mb_strlen($keyword) < 3) {
            return [];
        }
        if (! $this->llm->isAvailable()) {
            return [];
        }

        $cacheKey = sprintf(
            'ai_related_keywords:%s:%s:%s',
            self::PROMPT_VERSION,
            $websiteId,
            hash('xxh3', mb_strtolower($keyword))
        );
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $messages = $this->buildPrompt($keyword);
        $payload = $this->llm->completeJson($messages, [
            'model' => \App\Support\AiModelConfig::premiumModel(),
            'temperature' => 0.5,
            'max_tokens' => 700,
            'json_object' => true,
            'timeout' => 30,
        ]);

        if (! is_array($payload) || ! isset($payload['suggestions']) || ! is_array($payload['suggestions'])) {
            Log::warning('AiRelatedKeywordsService: malformed LLM response', [
                'website_id' => $websiteId,
                'keyword' => $keyword,
            ]);
            return [];
        }

        $needle = mb_strtolower($keyword);
        $seen = [mb_strtolower($keyword) => true];
        $out = [];
        foreach ($payload['suggestions'] as $item) {
            $candidate = is_string($item)
                ? $item
                : (is_array($item) ? (string) ($item['keyword'] ?? $item['query'] ?? '') : '');
            $candidate = trim($candidate);
            if ($candidate === '') continue;
            // Strip trailing punctuation the model sometimes adds.
            $candidate = trim($candidate, " \t\n\r\0\x0B,.;:?!\"'");
            if ($candidate === '') continue;
            $key = mb_strtolower($candidate);
            // Drop exact echoes of the focus keyword and dupes.
            if ($key === $needle || isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = [
                'keyword' => mb_substr($candidate, 0, 120),
                'source' => 'ai',
                'volume' => null,
                'impressions' => null,
            ];
            if (count($out) >= self::SUGGESTIONS_PER_RUN) break;
        }

        Cache::put($cacheKey, $out, Carbon::now()->addDays(self::CACHE_TTL_DAYS));
        return $out;
    }

    /**
     * @return list<array{role:string, content:string}>
     */
    private function buildPrompt(string $keyword): array
    {
        $count = self::SUGGESTIONS_PER_RUN;

        $system = <<<'SYS'
You are an SEO keyword strategist. You generate "related keyphrases" for a
given focus keyword, in the same shape Google's "People also ask" /
"Related searches" SERP blocks present. Suggestions should be the kind of
real queries people type into a search box (specific, intent-bearing,
2 to 6 words, no questions ending with punctuation unless the query is
itself a question).

Rules:
- Each suggestion must be a real search-style query that semantically
  relates to the focus keyword: modifier variants (best, cheap, fast,
  for beginners, vs alternatives), intent variants (how to, what is,
  is X worth it), tighter or broader scopes, and adjacent topics a
  person searching the focus keyword might also need.
- Suggestions must be visibly different from each other. No two should
  read as paraphrases.
- Do NOT echo the focus keyword verbatim as one of the suggestions.
- Lowercase only (search box convention) unless a proper noun requires capitals.
- No quotation marks, no brackets, no trailing punctuation, no numbering.
- Return STRICTLY valid JSON in the schema below. No prose, no markdown.
SYS;

        $user = <<<USER
Focus keyword: "{$keyword}"

Generate exactly {$count} related keyphrase suggestions for this query.

Return JSON exactly in this shape:
{
  "suggestions": [
    "suggestion one",
    "suggestion two",
    ...
  ]
}
USER;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }
}
