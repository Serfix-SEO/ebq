<?php

namespace App\Services\KeywordResearch;

use App\Services\Llm\LlmClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * On-demand AI clustering of keyword-research results: one batched LLM call
 * (active provider — DeepSeek/Mistral via the admin setting) groups keywords
 * into named topic clusters a user could target with one page.
 *
 * Cost discipline mirrors the ideas lookup itself: the cluster map is cached
 * for the calendar month against the SAME result-set identity, so clustering
 * a cached result set costs nothing and re-clicking never re-bills. Caps at
 * MAX_KEYWORDS (volume-first) to bound tokens.
 *
 * Returns keyword(lowercased) => cluster label, or null when the LLM is
 * unavailable/malformed — the UI falls back to algorithmic term groups.
 */
class AiKeywordClusterService
{
    // v2: dynamic cluster-count target + minimum-2-per-cluster instruction +
    // a deterministic singleton-merge safety net (v1 fragmented small result
    // sets into mostly 1-keyword "clusters" — no better than the flat list).
    private const PROMPT_VERSION = 'v2';

    public const MAX_KEYWORDS = 300;

    /** Below this, clustering adds no value over the flat list — decline. */
    private const MIN_KEYWORDS = 6;

    /** A cluster with fewer members than this is noise; folded into "Other". */
    private const MIN_CLUSTER_SIZE = 2;

    public function __construct(private readonly LlmClient $llm) {}

    public function isAvailable(): bool
    {
        return $this->llm->isAvailable();
    }

    /**
     * @param  list<array{keyword:string, volume:?int}>  $rows  normalized rows
     * @param  bool  $force  bypass the cache and re-run even if a map exists —
     *                       the user's escape hatch when a clustering attempt
     *                       is unsatisfying (v1 had no way to retry all month).
     * @return array<string, string>|null  keyword => cluster label
     */
    public function cluster(array $rows, string $resultSetKey, bool $force = false): ?array
    {
        $keywords = $this->topKeywords($rows);
        if (count($keywords) < self::MIN_KEYWORDS) {
            return null;
        }

        $cacheKey = sprintf('kw-ideas-clusters:%s:%s', self::PROMPT_VERSION, hash('xxh3', $resultSetKey.'|'.implode("\n", $keywords)));
        if (! $force) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        if (! $this->llm->isAvailable()) {
            return null;
        }

        // Target scales with input size so small sets don't get fragmented
        // into one-keyword "clusters" and large sets don't get 5 giant ones.
        // ~1 cluster per 7 keywords, clamped to a sane, actionable range.
        $target = max(3, min(20, (int) round(count($keywords) / 7)));

        $payload = $this->llm->completeJson([
            [
                'role' => 'system',
                'content' => 'You are an expert SEO strategist. Group keywords into topic clusters where each cluster '
                    .'could be targeted by ONE page. Use short, descriptive cluster labels (2-4 words, plain language, '
                    .'same language as the keywords) that name the TOPIC, not a single keyword. Every keyword must '
                    ."appear in exactly one cluster. Aim for about {$target} clusters. EVERY cluster must contain AT "
                    .'LEAST 2 keywords — never create a cluster for a single keyword; if a keyword has no close match, '
                    .'put it in a cluster named "Other" instead. Respond ONLY with JSON: '
                    .'{"clusters":[{"label":"...","keywords":["..."]}]}',
            ],
            [
                'role' => 'user',
                'content' => "Cluster these keywords:\n".implode("\n", $keywords),
            ],
        ], [
            'model' => \App\Support\AiModelConfig::premiumModel(),
            'temperature' => 0.2,
            'max_tokens' => 4000,
            'json_object' => true,
            'timeout' => 90,
        ]);

        $clusters = is_array($payload) ? ($payload['clusters'] ?? null) : null;
        if (! is_array($clusters)) {
            Log::warning('AiKeywordClusterService: malformed LLM response', ['result_set' => $resultSetKey]);

            return null;
        }

        // Validate membership strictly against the input list; anything the
        // model dropped or invented lands in "Other".
        $valid = array_fill_keys(array_map('mb_strtolower', $keywords), true);
        $map = [];
        foreach ($clusters as $cluster) {
            if (! is_array($cluster)) {
                continue;
            }
            $label = trim((string) ($cluster['label'] ?? ''));
            if ($label === '' || ! is_array($cluster['keywords'] ?? null)) {
                continue;
            }
            foreach ($cluster['keywords'] as $kw) {
                $key = mb_strtolower(trim((string) $kw));
                if ($key !== '' && isset($valid[$key]) && ! isset($map[$key])) {
                    $map[$key] = mb_substr($label, 0, 60);
                }
            }
        }
        foreach (array_keys($valid) as $kw) {
            $map[$kw] ??= __('Other');
        }

        $map = $this->mergeSingletonClusters($map);

        if (count(array_unique($map)) < 2) {
            return null;
        }

        Cache::put($cacheKey, $map, now()->endOfMonth());

        return $map;
    }

    /**
     * Deterministic safety net: never trust the model to honor "min 2 per
     * cluster" on its own. Any label left with fewer than MIN_CLUSTER_SIZE
     * members gets folded into "Other" — guarantees no single-keyword
     * "clusters" reach the UI regardless of prompt compliance.
     *
     * @param  array<string, string>  $map
     * @return array<string, string>
     */
    private function mergeSingletonClusters(array $map): array
    {
        $counts = array_count_values($map);
        foreach ($map as $kw => $label) {
            if ($label !== __('Other') && ($counts[$label] ?? 0) < self::MIN_CLUSTER_SIZE) {
                $map[$kw] = __('Other');
            }
        }

        return $map;
    }

    /**
     * Volume-first cap so the biggest opportunities always get clustered.
     *
     * @param  list<array{keyword:string, volume:?int}>  $rows
     * @return list<string>
     */
    private function topKeywords(array $rows): array
    {
        $rows = array_values(array_filter($rows, fn ($r) => trim((string) ($r['keyword'] ?? '')) !== ''));
        usort($rows, fn ($a, $b) => ((int) ($b['volume'] ?? 0)) <=> ((int) ($a['volume'] ?? 0)));

        $seen = [];
        $out = [];
        foreach ($rows as $r) {
            $kw = trim((string) $r['keyword']);
            $key = mb_strtolower($kw);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $kw;
            if (count($out) >= self::MAX_KEYWORDS) {
                break;
            }
        }

        return $out;
    }
}
