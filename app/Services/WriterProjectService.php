<?php

namespace App\Services;

use App\Models\ClientActivity;
use App\Models\Website;
use App\Models\WriterProject;
use App\Services\AiToolRunner;
use App\Services\Llm\LlmClient;
use App\Support\CreditTypes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orchestration for the AI Writer wizard (topic → brief → images →
 * summary → completed). Owns project state transitions and the credit
 * accounting layer.
 *
 * Brief / generate are delegated to the existing AiContentBriefService /
 * AiWriterService — this class is the wizard's persistence + chat +
 * image-placement glue, not a duplicate of those services.
 *
 * Credits
 * ───────
 * EBQ Content Credits are a single user-facing metric, computed from
 * the size of the work product (output HTML + brief JSON + chat
 * messages). The ratio lives in config('services.ebq_credits.*') so
 * finance can tune without code changes. Every charge writes one row
 * into `client_activities` (provider='ebq_content_credits',
 * meta.writer_project_id=<uuid>) and increments
 * `writer_projects.credits_used`.
 */
class WriterProjectService
{
    /** Default ratio: 1 credit per 400 chars of work product. */
    private const DEFAULT_CHARS_PER_CREDIT = 400;

    /** Image search costs a flat 1 credit per request (Serper page). */
    private const IMAGE_SEARCH_FLAT_CREDITS = 1;

    public function __construct(
        private readonly AiContentBriefService $briefService,
        private readonly AiWriterService $writerService,
        private readonly TopicalGapService $gapService,
        private readonly SerperSearchClient $serper,
        private readonly LlmClient $llm,
        private readonly ClientActivityLogger $activity,
        private readonly AiToolRunner $toolRunner,
        private readonly KeywordMetricsService $keywordMetrics,
        private readonly AiRelatedKeywordsService $relatedKeywords,
    ) {
    }

    /**
     * Generate the Strategy step's bundle: SEO titles, meta tags
     * (title/description/OG), FAQs, keyword suggestions, and link
     * suggestions. Each is produced by the matching registry tool so
     * the prompt logic stays in one place; results are persisted on
     * the project so the user can edit / regenerate independently.
     *
     * The bundle pulls in the project's locale (country/language),
     * tone, and audience so every output honours the same voice the
     * user picked in step 1.
     *
     * @param  list<string>|null  $only  optional subset of keys to
     *   regenerate; `null` regenerates everything. Allowed keys:
     *   'seo_titles', 'h1', 'meta', 'faqs', 'keyword_suggestions',
     *   'link_suggestions'.
     */
    public function generateStrategy(WriterProject $project, Website $website, ?array $only = null): WriterProject
    {
        @set_time_limit(240);

        $shouldRun = static fn (string $key): bool => $only === null || in_array($key, $only, true);

        $brief = is_array($project->brief) ? $project->brief : [];
        $sectionsArr = is_array($brief['sections'] ?? null) ? $brief['sections'] : [];
        $h2List = array_values(array_map(static fn ($s) => is_array($s) ? (string) ($s['h2'] ?? '') : '', $sectionsArr));
        $summary = $project->title.' (focus: '.$project->focus_keyword.'). Sections: '
            . implode('; ', array_slice(array_filter($h2List), 0, 8));

        $baseInput = [
            'focus_keyword' => $project->focus_keyword,
            'country' => (string) ($project->country ?? ''),
            'language' => (string) ($project->language ?? ''),
        ];

        // SEO titles. Each surfaced candidate must hit the 50-60 char
        // sweet spot AND contain the focus keyword. The prompt asks
        // for both; this is the post-process net.
        if ($shouldRun('seo_titles')) {
            $res = $this->toolRunner->run('seo-title', $website, $project->user_id, $baseInput + [
                'summary' => $summary,
            ]);
            if ($res->ok && is_array($res->value)) {
                $titles = array_values(array_filter(array_map(
                    static fn ($t) => is_string($t) ? trim($t) : '',
                    $res->value,
                ), static fn ($t) => $t !== ''));
                $project->seo_titles = $this->filterCandidates($titles, (string) $project->focus_keyword, 50, 60);
            }
        }

        // H1 heading. Distinct from SEO titles (SERP snippets, 50–60
        // chars): the H1 is the on-page headline — focus keyword up
        // front, ≤65 chars, written in the project's output language.
        // Direct LLM call (like briefChat) rather than a registry tool —
        // this card is wizard-only, not a Studio catalog entry. The
        // user's pick/edit lands on `h1` via PATCH; the writer uses it
        // as the fixed page headline (selected.h1 → brief suggested_h1)
        // and its own generated_h1 is only the fallback.
        if ($shouldRun('h1')) {
            $langName = $this->languageName((string) ($project->language ?? ''));
            $langRule = $langName !== ''
                ? "Write every option in {$langName}. Keep the focus keyword itself verbatim (do not translate it)."
                : 'Match the language of the focus keyword.';
            $resp = $this->llm->completeJson([
                ['role' => 'system', 'content' => 'You write on-page H1 headlines for SEO articles. Return ONE JSON object: {"h1_options": [five strings]}. Rules: each option contains the focus keyword verbatim as close to the front as natural; 65 characters or fewer; compelling but not clickbait; no quotes, brackets, colons-as-gimmick, or trailing brand names; each option takes a different angle (benefit, how-to, list, question, direct). '.$langRule],
                ['role' => 'user', 'content' => 'Focus keyword: "'.$project->focus_keyword.'". Article context: '.$summary],
            ], [
                'temperature' => 0.6,
                'max_tokens' => 500,
                'json_object' => true,
                'timeout' => 30,
            ]);
            $options = is_array($resp['h1_options'] ?? null) ? $resp['h1_options'] : [];
            $options = array_values(array_filter(array_map(
                static fn ($t) => is_string($t) ? \App\Services\AiWriterService::stripDashes(trim($t)) : '',
                $options,
            ), static fn ($t) => $t !== ''));
            if ($options !== []) {
                $project->h1_suggestions = array_slice($options, 0, 5);
                if (trim((string) ($project->h1 ?? '')) === '') {
                    $project->h1 = mb_substr($options[0], 0, 200);
                }
                $this->recordCredits($project, CreditTypes::AI_WRITER_STRATEGY_H1, max(1, (int) ceil(strlen(implode('', $options)) / 200)), [
                    'options' => count($options),
                ]);
            }
        }

        // Meta tags bundle.
        // Two LLM calls in sequence:
        //   1. seo-meta → seeds meta_title (single best), og_title,
        //      og_description, AND a baseline meta_description.
        //   2. seo-description → generates 5 meta-description candidates
        //      so the user can pick one (mirrors the SEO Titles UX).
        // The chosen description still ends up in `meta_description`
        // (single string) which is what gets written to _ebq_description
        // on Save as draft.
        if ($shouldRun('meta')) {
            $bundle = $this->toolRunner->run('seo-meta', $website, $project->user_id, $baseInput + [
                'summary' => $summary,
            ]);
            if ($bundle->ok && is_array($bundle->value)) {
                $v = $bundle->value;
                if (! empty($v['seo_title']) && is_string($v['seo_title'])) {
                    $project->meta_title = mb_substr(trim($v['seo_title']), 0, 200);
                }
                if (! empty($v['seo_description']) && is_string($v['seo_description'])) {
                    $project->meta_description = mb_substr(trim($v['seo_description']), 0, 320);
                }
                if (! empty($v['og_title']) && is_string($v['og_title'])) {
                    $project->og_title = mb_substr(trim($v['og_title']), 0, 200);
                }
                if (! empty($v['og_description']) && is_string($v['og_description'])) {
                    $project->og_description = mb_substr(trim($v['og_description']), 0, 320);
                }
            }

            // Generate 5 meta-description candidates the user can pick from.
            $candidates = $this->toolRunner->run('seo-description', $website, $project->user_id, $baseInput + [
                'summary' => $summary,
                'count' => 5,
            ]);
            if ($candidates->ok && is_array($candidates->value)) {
                $list = $this->normaliseDescriptionPool($candidates->value);
                $filtered = $this->filterCandidates($list, (string) $project->focus_keyword, 120, 158);

                // Count how many survived the strict band filter
                // (`filterCandidates` may include relaxed fallbacks
                // beyond that). If fewer than 3 truly land in 120–158,
                // re-run the tool ONCE with explicit length feedback
                // to coax the model toward the sweet spot.
                $strict = array_values(array_filter($filtered, fn (string $s) => mb_strlen($s) >= 120 && mb_strlen($s) <= 158
                    && $this->stringContainsKeyword($s, (string) $project->focus_keyword)));

                if (count($strict) < 3) {
                    $retry = $this->toolRunner->run('seo-description', $website, $project->user_id, $baseInput + [
                        'summary' => $summary . "\n\nPrevious attempt produced these (some out of band): "
                            . implode(' | ', array_slice($list, 0, 6))
                            . "\nWrite 6 NEW variants, each EXACTLY 120 to 158 characters. Count carefully.",
                        'count' => 6,
                    ]);
                    if ($retry->ok && is_array($retry->value)) {
                        $extra = $this->normaliseDescriptionPool($retry->value);
                        $list = array_values(array_unique(array_merge($list, $extra)));
                        $filtered = $this->filterCandidates($list, (string) $project->focus_keyword, 120, 158);
                    }
                }

                $project->meta_descriptions = $filtered;
                // If the bundle didn't yield a meta_description but the
                // candidates list did, use the first candidate as the
                // initial selection so the user has something workable.
                if (empty($project->meta_description) && $list !== []) {
                    $project->meta_description = $list[0];
                }
            }

            // If the seo-meta call's single best landed without the
            // focus keyword, replace it with the first kw-bearing
            // candidate from the description list (when available).
            if ($project->meta_description && ! $this->stringContainsKeyword((string) $project->meta_description, $project->focus_keyword)) {
                $list = is_array($project->meta_descriptions) ? $project->meta_descriptions : [];
                if ($list !== []) {
                    $project->meta_description = $list[0];
                }
            }
            if ($project->meta_title && ! $this->stringContainsKeyword((string) $project->meta_title, $project->focus_keyword)) {
                $titles = is_array($project->seo_titles) ? $project->seo_titles : [];
                if ($titles !== []) {
                    $project->meta_title = $titles[0];
                }
            }
        }

        // FAQs grounded in PAA + the project body so far.
        if ($shouldRun('faqs')) {
            $res = $this->toolRunner->run('faq-generator', $website, $project->user_id, $baseInput + [
                'article_text' => $summary,
                'count' => 5,
            ]);
            if ($res->ok && is_array($res->value)) {
                $faqs = [];
                foreach ($res->value as $f) {
                    if (! is_array($f)) continue;
                    $q = trim((string) ($f['question'] ?? ''));
                    $a = trim((string) ($f['answer'] ?? ''));
                    if ($q !== '' && $a !== '') {
                        $faqs[] = ['question' => $q, 'answer' => $a];
                    }
                }
                $project->faqs = $faqs;
            }
        }

        // Keyword suggestions (semantic / variant kws to expand coverage).
        if ($shouldRun('keyword_suggestions')) {
            $res = $this->toolRunner->run('keyword-suggestions', $website, $project->user_id, [
                'keyword' => $project->focus_keyword,
                'country' => (string) ($project->country ?? ''),
                'language' => (string) ($project->language ?? ''),
            ]);
            if ($res->ok && is_array($res->value)) {
                $project->keyword_suggestions = array_values(array_filter(array_map(
                    static fn ($k) => is_string($k) ? trim($k) : '',
                    $res->value,
                ), static fn ($k) => $k !== ''));
            }
        }

        // LSI suggestions — semantically-related phrases the user can
        // one-click add to the project's LSI list (Step 1 only offered a
        // free-text box; owner asked for suggestions + data). Source:
        // AiRelatedKeywordsService (LLM + GSC-grounded, 7d cache).
        if ($shouldRun('lsi')) {
            $sugs = $this->relatedKeywords->suggest((string) $website->id, (string) $project->focus_keyword);
            $project->lsi_suggestions = array_slice(array_values(array_filter(array_map(
                static fn ($s) => is_array($s) ? trim((string) ($s['keyword'] ?? '')) : '',
                $sugs,
            ), static fn ($k) => $k !== '')), 0, 20);
        }

        // Keyword data card: search volume / competition / trend for
        // every keyword surfaced on this step (suggestions, LSI, the
        // user's own lists). Cache-first via KeywordMetricsService —
        // reads the shared 30-day keyword_metrics cache for free and
        // queues a provider fetch (admin-selected provider, user-quota
        // metered) for misses; those fill in on the next strategy view.
        // Deliberately NOT a blocking synchronous fetch: the self-hosted
        // finder is async-by-design and KE spends credits — the wizard
        // must render instantly with whatever data exists.
        $project->keyword_data = $this->keywordDataFor($project);
        if ($shouldRun('link_suggestions')) {
            $internal = [];
            $internalRes = $this->toolRunner->run('internal-link-suggestions', $website, $project->user_id, [
                'focus_keyword' => $project->focus_keyword,
                'count' => 6,
            ]);
            if ($internalRes->ok && is_array($internalRes->value)) {
                $internal = $internalRes->value;
            }

            $external = [];
            $externalRes = $this->toolRunner->run('external-link-suggestions', $website, $project->user_id, [
                'focus_keyword' => $project->focus_keyword,
                'article_text' => $summary,
                'count' => 4,
            ]);
            if ($externalRes->ok && is_array($externalRes->value)) {
                $external = $externalRes->value;
            }

            $project->link_suggestions = [
                'internal' => $internal,
                'external' => $external,
            ];
        }

        $project->save();

        return $project->refresh();
    }

    /**
     * Title fallback when the user hasn't named the project yet.
     * "vegan protein powder" → "Vegan Protein Powder — draft".
     */
    public function suggestTitle(string $focusKeyword): string
    {
        $kw = trim($focusKeyword);
        if ($kw === '') {
            return 'Untitled draft';
        }
        return Str::title($kw).' — draft';
    }

    /**
     * @param  array{title?: string, focus_keyword: string, additional_keywords?: list<string>, country?: string, language?: string, tone?: string, audience?: string}  $input
     */
    public function create(Website $website, ?string $userId, array $input): WriterProject
    {
        $kw = trim((string) $input['focus_keyword']);
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            $title = $this->suggestTitle($kw);
        }
        $additional = array_values(array_filter(array_map(
            static fn ($v) => is_string($v) ? trim($v) : '',
            (array) ($input['additional_keywords'] ?? []),
        ), static fn ($v) => $v !== ''));

        $lsi = array_values(array_filter(array_map(
            static fn ($v) => is_string($v) ? trim($v) : '',
            (array) ($input['lsi_keywords'] ?? []),
        ), static fn ($v) => $v !== ''));

        $customPrompt = isset($input['custom_prompt']) && is_string($input['custom_prompt'])
            ? trim($input['custom_prompt'])
            : '';

        return WriterProject::create([
            'website_id' => $website->id,
            'user_id' => $userId,
            'title' => mb_substr($title, 0, 300),
            'focus_keyword' => mb_substr($kw, 0, 200),
            'additional_keywords' => $additional,
            'lsi_keywords' => $lsi,
            'country' => $this->normalizeCountry($input['country'] ?? null),
            'language' => $this->normalizeLanguage($input['language'] ?? null),
            'tone' => $this->normalizeTone($input['tone'] ?? null),
            'audience' => $this->normalizeAudience($input['audience'] ?? null),
            'custom_prompt' => $customPrompt !== '' ? $customPrompt : null,
            'step' => WriterProject::STEP_TOPIC,
        ]);
    }

    /**
     * Filter meta-tag candidates to ones that hit BOTH the sweet-spot
     * length band AND contain the focus keyword.
     *
     * Strategy:
     *   1. Trim any over-band candidate at a clean word boundary so it
     *      fits within maxLen. This salvages the common Mistral pattern
     *      where the model writes 165–180 char descriptions.
     *   2. Drop under-band candidates (we can't honestly pad without
     *      inventing claims).
     *   3. Filter to keyword-bearing.
     *   4. If at least 3 candidates survive, return them.
     *   5. Otherwise, return whatever survived (even if < 3) plus a
     *      relaxed pool — keyword-bearing at any length — so the
     *      picker is never empty. The UI shows the in-band hint via
     *      its colour-coded char counter; the user can still pick.
     *
     * @param  list<string>  $items
     * @return list<string>
     */
    private function filterCandidates(array $items, string $keyword, int $minLen, int $maxLen): array
    {
        $kw = trim($keyword);
        $items = array_values(array_filter($items, static fn ($s) => is_string($s) && trim($s) !== ''));
        if ($items === []) {
            return [];
        }

        // Step 1: trim over-band entries at word boundary.
        $trimmed = array_map(fn (string $s) => $this->trimToBand(trim($s), $maxLen), $items);

        // Step 2 + 3: drop under-band, keep kw-bearing.
        $inBandKw = array_values(array_filter($trimmed, fn (string $s) => mb_strlen($s) >= $minLen
            && mb_strlen($s) <= $maxLen
            && $this->stringContainsKeyword($s, $kw)));

        if (count($inBandKw) >= 3) {
            return $inBandKw;
        }

        // Need more candidates. Try kw-bearing at any length, but
        // prefer the trimmed versions (closer to band) over raw.
        $kwBearing = $kw !== ''
            ? array_values(array_filter($trimmed, fn (string $s) => $this->stringContainsKeyword($s, $kw)))
            : array_values($trimmed);

        // Merge: in-band-kw first (best), then kw-bearing fallbacks
        // that aren't already included.
        $merged = $inBandKw;
        foreach ($kwBearing as $c) {
            if (! in_array($c, $merged, true)) {
                $merged[] = $c;
            }
        }
        if ($merged !== []) {
            return $merged;
        }

        // Last resort — surface the trimmed pool so the user has
        // something to work with, even if no candidate met the rules.
        return $trimmed;
    }

    /**
     * Normalise a raw LLM response into a clean list of description
     * strings — strips empties, leading bullets/numbering, surrounding
     * quotes, and caps each entry at 320 chars (Google's hard cutoff).
     *
     * @param  array<int|string, mixed>  $raw
     * @return list<string>
     */
    private function normaliseDescriptionPool(array $raw): array
    {
        return array_values(array_filter(array_map(
            static function ($c): string {
                if (! is_string($c)) return '';
                $s = trim($c);
                $s = (string) preg_replace('/^\s*(?:[-*•·]|\d+[.)])\s+/u', '', $s);
                $s = trim($s, " \t\n\r\0\x0B\"'`");
                return mb_substr($s, 0, 320);
            },
            $raw,
        ), static fn ($c) => $c !== ''));
    }

    /**
     * Trim a string to maxLen at the nearest word boundary, ensuring
     * it ends with sentence-final punctuation. No-op when already
     * within the cap.
     */
    private function trimToBand(string $s, int $maxLen): string
    {
        if (mb_strlen($s) <= $maxLen) {
            return $s;
        }

        // Cut at the last word boundary inside the cap.
        $cut = mb_substr($s, 0, $maxLen);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > ($maxLen * 0.7)) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }
        $cut = rtrim($cut, ", \t\n\r;:");

        // Ensure sentence-final punctuation. If trimming truncated
        // mid-sentence, a period reads more cleanly than nothing.
        if (! preg_match('/[.!?]$/u', $cut)) {
            // Account for the period we're about to add.
            if (mb_strlen($cut) >= $maxLen) {
                $cut = mb_substr($cut, 0, $maxLen - 1);
                $cut = rtrim($cut, ", \t\n\r;:");
            }
            $cut .= '.';
        }

        return $cut;
    }

    /**
     * Backwards-compatible kw-only filter — used wherever sweet-spot
     * length isn't relevant (keyword suggestions, link anchors, etc.).
     *
     * @param  list<string>  $items
     * @return list<string>
     */
    private function filterContainsKeyword(array $items, string $keyword): array
    {
        $kw = trim($keyword);
        if ($kw === '') {
            return array_values($items);
        }
        $matched = array_values(array_filter($items, fn (string $s) => $this->stringContainsKeyword($s, $kw)));
        return $matched !== [] ? $matched : array_values($items);
    }

    private function stringContainsKeyword(string $haystack, string $keyword): bool
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return true;
        }
        // Exact match (case-insensitive).
        if (mb_stripos($haystack, $keyword) !== false) {
            return true;
        }
        // Stem-tolerant: every keyword token must appear, but not
        // necessarily contiguous. Catches "vegan protein powder" →
        // "best vegan protein in powder form" but rejects only one
        // token landing.
        $tokens = preg_split('/\s+/', mb_strtolower($keyword)) ?: [];
        $tokens = array_values(array_filter($tokens, static fn ($t) => mb_strlen($t) > 2));
        if ($tokens === []) {
            return false;
        }
        $hayLower = mb_strtolower($haystack);
        foreach ($tokens as $t) {
            if (mb_strpos($hayLower, $t) === false) {
                return false;
            }
        }
        return true;
    }

    private function normalizeCountry(mixed $v): ?string
    {
        if (! is_string($v)) return null;
        $v = strtolower(trim($v));
        return preg_match('/^[a-z]{2}$/', $v) ? $v : null;
    }

    private function normalizeLanguage(mixed $v): ?string
    {
        if (! is_string($v)) return null;
        $v = strtolower(trim($v));
        return preg_match('/^[a-z]{2}(-[a-z0-9]{2,5})?$/', $v) ? $v : null;
    }

    private function normalizeTone(mixed $v): ?string
    {
        if (! is_string($v)) return null;
        $v = strtolower(trim($v));
        $allowed = ['professional', 'casual', 'persuasive', 'informational', 'friendly', 'authoritative', 'witty', 'empathetic'];
        return in_array($v, $allowed, true) ? $v : null;
    }

    private function normalizeAudience(mixed $v): ?string
    {
        if (! is_string($v)) return null;
        $v = strtolower(trim($v));
        $allowed = ['beginner', 'general', 'intermediate', 'expert'];
        return in_array($v, $allowed, true) ? $v : null;
    }

    /**
     * Generate (or regenerate) the topic brief for step 2. The brief is
     * persisted on the project and the step advances to 'brief'.
     */
    /**
     * Generate (or regenerate) the topic brief. Returns a tuple-shaped
     * array so the controller can distinguish "brief written" from
     * "Serper/LLM failed and nothing was saved" — the previous version
     * silently wrote an empty brief shape on every failure, which left
     * the user staring at an empty Step 2 with no explanation.
     *
     * @return array{project: WriterProject, ok: bool, error?: string, message?: string, cached?: bool}
     */
    public function generateBrief(WriterProject $project, Website $website): array
    {
        @set_time_limit(180);

        // Project locale drives the SERP (Serper gl/hl) AND the brief's
        // output language — these were hardcoded null until 2026-07-11,
        // so an Arabic project briefed against the US/English SERP and
        // got an English outline (owner report: "still didn't write in
        // arabic" — the user was reading the brief step).
        $briefRes = $this->briefService->brief($website, 0, [
            'focus_keyword' => $project->focus_keyword,
            'country' => (string) ($project->country ?? ''),
            'language' => (string) ($project->language ?? ''),
        ]);

        if (! is_array($briefRes) || ($briefRes['ok'] ?? false) !== true || ! is_array($briefRes['brief'] ?? null)) {
            $error = is_array($briefRes) ? (string) ($briefRes['error'] ?? 'brief_generation_failed') : 'brief_generation_failed';
            Log::warning('WriterProjectService: brief generation failed', [
                'project_id' => $project->id,
                'website_id' => $website->id,
                'focus_keyword' => $project->focus_keyword,
                'error' => $error,
            ]);
            return [
                'project' => $project,
                'ok'      => false,
                'error'   => $error,
                'message' => $this->briefErrorMessage($error),
            ];
        }

        $shaped = $this->shapeBrief($briefRes['brief']);
        $project->brief = $shaped;
        if ($project->step === WriterProject::STEP_TOPIC) {
            $project->step = WriterProject::STEP_BRIEF;
        }
        $project->save();

        $this->recordCredits(
            $project,
            CreditTypes::AI_WRITER_BRIEF,
            $this->charsToCredits(strlen((string) json_encode($shaped))),
            ['cached' => (bool) ($briefRes['cached'] ?? false)],
        );

        return [
            'project' => $project->refresh(),
            'ok'      => true,
            'cached'  => (bool) ($briefRes['cached'] ?? false),
        ];
    }

    /**
     * Translate the briefService's machine error codes into UI copy
     * that points at the actual root cause instead of "something went
     * wrong." Most of these are recoverable on retry; the messages
     * tell the user when a different input would help.
     */
    private function briefErrorMessage(string $error): string
    {
        // LLM error codes are provider-prefixed (mistral_network_error,
        // deepseek_network_error, …) — match the suffix.
        if (str_ends_with($error, '_network_error')) {
            return 'The AI provider didn\'t respond in time. Wait a few seconds and click "Regenerate brief".';
        }

        return match ($error) {
            'no_serp_data'           => 'No search results came back for that keyword. Try a more common phrasing, switch country/language, or wait a minute and retry.',
            'llm_parse_failed'       => 'The AI returned a brief in an unexpected shape. Try again, or use a more specific focus keyword so the model has clearer signals.',
            'llm_not_configured'     => 'The AI provider isn\'t configured. Contact support.',
            'missing_focus_keyword'  => 'A focus keyword is required to build a brief.',
            default                  => 'Could not generate brief ('.$error.'). Try again.',
        };
    }

    /**
     * User chat amendment to the brief. Sends the current brief + chat
     * history + new user message to the LLM and asks it to return an
     * updated brief JSON (same shape as `shapeBrief()`).
     */
    public function applyBriefChat(WriterProject $project, string $userMessage): WriterProject
    {
        $userMessage = trim($userMessage);
        if ($userMessage === '') {
            return $project;
        }

        $chat = is_array($project->chat_history) ? $project->chat_history : [];
        $chat[] = [
            'role' => 'user',
            'content' => mb_substr($userMessage, 0, 2000),
            'ts' => Carbon::now()->toIso8601String(),
        ];

        $currentBrief = is_array($project->brief) ? $project->brief : [];
        $messages = $this->buildBriefChatMessages($project, $currentBrief, $chat);

        $resp = $this->llm->isAvailable()
            ? $this->llm->complete($messages, [
                'temperature' => 0.3,
                'max_tokens' => 3000,
                'json_object' => true,
                'timeout' => 90,
            ])
            : ['ok' => false, 'content' => '', 'usage' => ['prompt' => 0, 'completion' => 0, 'total' => 0]];

        $assistantText = (string) ($resp['content'] ?? '');
        $decoded = $this->safeJsonDecode($assistantText);
        $updatedBrief = is_array($decoded) ? $this->shapeBrief($decoded) : $currentBrief;

        $assistantSummary = $this->summarizeBriefChange($currentBrief, $updatedBrief);

        $chat[] = [
            'role' => 'assistant',
            'content' => $assistantSummary,
            'ts' => Carbon::now()->toIso8601String(),
        ];

        $project->brief = $updatedBrief;
        $project->chat_history = $chat;
        $project->save();

        $usage = is_array($resp['usage'] ?? null) ? $resp['usage'] : [];
        $totalTokens = (int) ($usage['total'] ?? 0);
        $credits = $totalTokens > 0
            ? max(1, (int) ceil($totalTokens / 100))
            : $this->charsToCredits(strlen($userMessage) + strlen($assistantText));

        $this->recordCredits($project, CreditTypes::AI_WRITER_BRIEF_CHAT, $credits, [
            'tokens' => $totalTokens,
        ]);

        return $project->refresh();
    }

    /**
     * Serper image search for the project's focus keyword (or a custom
     * query). Results are not persisted — the client picks which to add
     * via `updateImages()`.
     *
     * @return list<array{url: string, thumbnail_url: string, title: string, width: int, height: int, source: string}>
     */
    public function searchImages(WriterProject $project, ?string $query = null, int $num = 16): array
    {
        $q = trim((string) ($query ?? $project->focus_keyword));
        if ($q === '') {
            return [];
        }

        $resp = $this->serper->query([
            'q' => $q,
            'type' => 'images',
            'num' => max(1, min(40, $num)),
            '__website_id' => $project->website_id,
            '__owner_user_id' => $project->user_id,
            '__source' => 'plugin_writer',
        ]);

        $results = [];
        if (is_array($resp) && is_array($resp['images'] ?? null)) {
            foreach ($resp['images'] as $img) {
                if (! is_array($img)) {
                    continue;
                }
                $url = (string) ($img['imageUrl'] ?? $img['url'] ?? '');
                if ($url === '') {
                    continue;
                }
                $results[] = [
                    'url' => $url,
                    'thumbnail_url' => (string) ($img['thumbnailUrl'] ?? $img['thumbnail'] ?? $url),
                    'title' => (string) ($img['title'] ?? ''),
                    'width' => (int) ($img['imageWidth'] ?? $img['width'] ?? 0),
                    'height' => (int) ($img['imageHeight'] ?? $img['height'] ?? 0),
                    'source' => (string) ($img['source'] ?? ''),
                ];
            }
        }

        $this->recordCredits($project, CreditTypes::AI_WRITER_IMAGE_SEARCH, self::IMAGE_SEARCH_FLAT_CREDITS, [
            'query' => $q,
            'returned' => count($results),
        ]);

        return $results;
    }

    /**
     * Replace the project's image selection. Image entries are validated
     * and normalized; any entry without a `url` is dropped.
     *
     * @param  list<array<string, mixed>>  $images
     */
    public function updateImages(WriterProject $project, array $images): WriterProject
    {
        $normalized = [];
        foreach ($images as $img) {
            if (! is_array($img)) {
                continue;
            }
            $url = trim((string) ($img['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $normalized[] = [
                'source' => in_array(($img['source'] ?? ''), ['serper', 'upload'], true) ? $img['source'] : 'serper',
                'url' => $url,
                'thumbnail_url' => (string) ($img['thumbnail_url'] ?? $url),
                'alt' => mb_substr((string) ($img['alt'] ?? ''), 0, 200),
                'caption' => mb_substr((string) ($img['caption'] ?? ''), 0, 280),
                'assigned_h2' => isset($img['assigned_h2']) && is_string($img['assigned_h2'])
                    ? mb_substr($img['assigned_h2'], 0, 200)
                    : null,
                'width' => (int) ($img['width'] ?? 0),
                'height' => (int) ($img['height'] ?? 0),
            ];
        }
        $project->images = $normalized;
        $project->save();

        return $project->refresh();
    }

    /**
     * Safety net for the async generate flow: a project stuck on
     * queued/running long past any plausible runtime (job lost to a
     * queue flush, worker OOM before failed() ran) is flipped to failed
     * so the wizard's poller stops instead of spinning forever. The job
     * itself times out at 400s — 10 minutes means something upstream
     * already went wrong.
     */
    public function failStaleGeneration(WriterProject $project): void
    {
        $status = (string) $project->generation_status;
        if ($status === WriterProject::GEN_RUNNING) {
            $anchor = $project->generation_started_at ?? $project->updated_at;
        } elseif ($status === WriterProject::GEN_QUEUED) {
            $anchor = $project->updated_at;
        } else {
            return;
        }

        if ($anchor === null || $anchor->lt(now()->subMinutes(10))) {
            $project->generation_status = WriterProject::GEN_FAILED;
            $project->generation_error = 'generation_timeout';
            $project->save();
        }
    }

    /**
     * Final generation step. Calls the existing AiWriterService in
     * strict-selection mode with the curated brief, then post-processes
     * to inject <figure> blocks for each selected image immediately
     * after its assigned (or auto-matched) <h2>.
     */
    public function generate(WriterProject $project, Website $website): WriterProject
    {
        @set_time_limit(360);

        $brief = is_array($project->brief) ? $project->brief : [];
        $h2List = array_values(array_filter(
            (array) ($brief['sections'] ?? []),
            static fn ($s) => is_array($s) && is_string($s['h2'] ?? null) && trim($s['h2']) !== '',
        ));

        $selected = [
            // User's chosen H1 from the Strategy step — the writer treats
            // it as the fixed page headline (selected.h1 -> suggested_h1)
            // and echoes it as the top-level "h1" instead of inventing one.
            'h1' => trim((string) ($project->h1 ?? '')),
            'h2_outline' => array_map(static fn (array $s) => (string) $s['h2'], $h2List),
            'subtopics' => $this->flattenSubtopics($h2List),
            'paa' => array_values(array_filter((array) ($brief['paa'] ?? []), 'is_string')),
            'gap_topics' => array_values(array_filter((array) ($brief['gaps'] ?? []), 'is_string')),
            'competitor_subtopics' => [],
        ];

        // User-curated link picks from the strategy step. Falls back to
        // the empty shape when the user didn't visit the LinkSuggestions
        // card or unticked everything — in which case the writer's
        // existing GSC-derived smart-link path takes over for internal
        // links and the article ships without explicit external links.
        $rawSelected = is_array($project->selected_links) ? $project->selected_links : [];
        $selectedLinks = [
            'internal' => is_array($rawSelected['internal'] ?? null) ? $rawSelected['internal'] : [],
            'external' => is_array($rawSelected['external'] ?? null) ? $rawSelected['external'] : [],
        ];

        $payload = $this->writerService->draft($website, 0, [
            'focus_keyword' => $project->focus_keyword,
            'current_html' => '',
            'exclude_url' => '',
            'brief' => $this->briefForWriter($brief),
            'gaps' => null,
            'wp_pages' => [],
            'selected' => $selected,
            'title' => $project->title,
            'additional_keywords' => is_array($project->additional_keywords) ? $project->additional_keywords : [],
            // LSI / semantically-related terms the user added on Step 1.
            // The writer weaves them naturally for topical breadth — no
            // forced presence per section (that's what additional_keywords
            // is for).
            'lsi_keywords' => is_array($project->lsi_keywords) ? $project->lsi_keywords : [],
            // Strategy-step selections (v25): secondary keywords to weave
            // and the user-approved FAQs that must join the FAQ section.
            // Generated for the user since the strategy step shipped but
            // never handed to the writer until now.
            'keyword_suggestions' => is_array($project->keyword_suggestions) ? $project->keyword_suggestions : [],
            'faqs' => is_array($project->faqs) ? $project->faqs : [],
            // Locale + voice — wired through so the LLM knows the
            // target country / language / tone / reader level. The
            // writer service consumes these in its prompt builder.
            'country' => (string) ($project->country ?? ''),
            'language' => (string) ($project->language ?? ''),
            'tone' => (string) ($project->tone ?? ''),
            'audience' => (string) ($project->audience ?? ''),
            // User's free-form custom prompt from Step 1 — appended as
            // ADVISORY guidance below the strict-output rules in the
            // writer's system message. Validated upstream by
            // CustomPromptGuard before persisting.
            'custom_prompt' => (string) ($project->custom_prompt ?? ''),
            // User-curated links from the strategy step. When internal
            // is non-empty, AiWriterService bypasses the GSC smart-link
            // lookup and uses these instead. External is a new prompt
            // section added when non-empty.
            'selected_links' => $selectedLinks,
            // Billing/telemetry hints for the LLM client. Explicit
            // __user_id because this now also runs inside
            // GenerateWriterDraftJob where there is no Auth user — the
            // token cap must still be enforced and the spend logged.
            '__user_id' => $website->user_id !== null ? (string) $website->user_id : '',
            '__website_id' => (string) $website->id,
            '__source' => 'blog_post_wizard',
        ]);

        if (! is_array($payload) || ($payload['ok'] ?? false) !== true) {
            $error = is_array($payload) ? (string) ($payload['error'] ?? 'unknown') : 'invalid';
            Log::warning('WriterProjectService: writer.draft failed', [
                'project' => $project->id,
                'error' => $error,
            ]);
            $project->generation_status = WriterProject::GEN_FAILED;
            $project->generation_error = mb_substr($error, 0, 120);
            $project->save();

            return $project;
        }

        $sections = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];
        $html = $this->assembleHtml($project, $sections);

        $project->generated_html = $html;
        // SEO-optimized H1 from the writer (keyword-front, output
        // language). Falls back to the user's project title downstream
        // when empty (older cached payloads predate the field).
        $project->generated_h1 = (string) ($payload['h1'] ?? '') !== ''
            ? mb_substr((string) $payload['h1'], 0, 200)
            : $project->generated_h1;
        // Coverage report for the review step — LSI hit/miss and section
        // counts were diagnostics-only log lines before; users asked to
        // SEE what the writer covered.
        $diag = is_array($payload['diagnostics'] ?? null) ? $payload['diagnostics'] : [];
        // NB: diagnostics' lsi_provided is a COUNT; the UIs want the list —
        // rebuild it from the project so present/missing/provided align.
        $project->generation_meta = [
            'sections' => (int) ($diag['sections_returned'] ?? 0),
            'lsi_provided' => array_values(array_filter(array_map('strval', (array) ($project->lsi_keywords ?? [])))),
            'lsi_present' => array_values((array) ($diag['lsi_present_verbatim'] ?? [])),
            'lsi_missing' => array_values((array) ($diag['lsi_missing'] ?? [])),
            'internal_links' => (int) ($diag['internal_links_in_output'] ?? 0),
            'paa_covered' => (int) ($diag['paa_questions_available'] ?? 0),
        ];
        $project->generation_status = WriterProject::GEN_DONE;
        $project->generation_error = null;
        $project->step = WriterProject::STEP_SUMMARY; // user reviews on step 5 (review), but project stays at 'summary' until they Save-as-draft
        $project->save();

        $this->recordCredits(
            $project,
            CreditTypes::AI_WRITER_GENERATE,
            $this->charsToCredits(strlen($html)),
            [
                'sections' => count($sections),
                'images' => count(is_array($project->images) ? $project->images : []),
                'cached' => (bool) ($payload['cached'] ?? false),
            ],
        );

        return $project->refresh();
    }

    /**
     * Sum of credits charged against a specific project. Mirrors the
     * `writer_projects.credits_used` column but pulled live from
     * `client_activities` so the UI always shows authoritative numbers.
     */
    public function totalCreditsForProject(WriterProject $project): int
    {
        $sum = (int) ClientActivity::query()
            ->where('provider', CreditTypes::PROVIDER)
            ->whereIn('type', CreditTypes::TYPES)
            ->whereJsonContains('meta->writer_project_id', $project->external_id)
            ->sum('units_consumed');

        return $sum;
    }

    /* ─────────────────── helpers ─────────────────── */

    /**
     * Internal: write one credit charge and bump the cached column.
     *
     * @param  array<string, mixed>  $extraMeta
     */
    /** English language name for prompt rules, '' when unknown/unset. */
    private function languageName(string $code): string
    {
        return AiWriterService::LANGUAGE_NAMES[strtolower(trim($code))] ?? '';
    }

    /**
     * Volume / competition / trend map for every keyword surfaced by the
     * wizard (focus, additional, LSI + suggestions, strategy keyword
     * suggestions). Keys are lowercased keywords. Cache-first: rows the
     * shared keyword_metrics cache doesn't have yet are queued for the
     * admin-selected provider and appear on the next strategy view. No $
     * projections here — volume/competition/trend only (app-wide rule).
     *
     * @return array<string, array{volume:?int, competition:?float, trend:string}>
     */
    private function keywordDataFor(WriterProject $project): array
    {
        $keywords = array_values(array_unique(array_filter(array_map(
            static fn ($k) => mb_strtolower(trim((string) $k)),
            array_merge(
                [(string) $project->focus_keyword],
                is_array($project->additional_keywords) ? $project->additional_keywords : [],
                is_array($project->lsi_keywords) ? $project->lsi_keywords : [],
                is_array($project->lsi_suggestions) ? $project->lsi_suggestions : [],
                is_array($project->keyword_suggestions) ? $project->keyword_suggestions : [],
            ),
        ), static fn ($k) => $k !== '')));
        if ($keywords === []) {
            return [];
        }

        $rows = $this->keywordMetrics->metricsOrQueue(
            $keywords,
            'global',
            (string) $project->website_id,
            $project->user_id !== null ? (string) $project->user_id : null,
        );

        $out = [];
        foreach ($keywords as $kw) {
            $row = $rows[\App\Models\KeywordMetric::hashKeyword($kw)] ?? null;
            if ($row === null) {
                continue;
            }
            $out[$kw] = [
                'volume' => $row->search_volume,
                'competition' => $row->competition,
                'trend' => KeywordValueCalculator::trendClassify($row->trend_12m),
            ];
        }

        return $out;
    }

    private function recordCredits(WriterProject $project, string $type, int $credits, array $extraMeta = []): void
    {
        if ($credits <= 0) {
            return;
        }

        $meta = array_merge([
            'writer_project_id' => $project->external_id,
            'project_pk' => $project->id,
            'step' => $project->step,
            'focus_keyword' => $project->focus_keyword,
        ], $extraMeta);

        $this->activity->log(
            type: $type,
            userId: $project->user_id,
            websiteId: $project->website_id,
            provider: CreditTypes::PROVIDER,
            meta: $meta,
            unitsConsumed: $credits,
        );

        DB::table('writer_projects')
            ->where('id', $project->id)
            ->increment('credits_used', $credits);

        $project->credits_used = (int) $project->credits_used + $credits;
    }

    private function charsToCredits(int $chars): int
    {
        $perCredit = (int) config('services.ebq_credits.chars_per_credit', self::DEFAULT_CHARS_PER_CREDIT);
        if ($perCredit < 50) {
            $perCredit = self::DEFAULT_CHARS_PER_CREDIT;
        }
        return max(1, (int) ceil($chars / $perCredit));
    }

    /**
     * Reshape an AiContentBriefService brief (or a chat-edited brief)
     * into the wizard's canonical structure:
     *   { h1, sections: [{ h2, subtopics: [string] }], paa: [string], gaps: [string] }
     *
     * Accepts both shapes (raw brief from AiContentBriefService and the
     * already-shaped dict from a previous save / chat round).
     *
     * @param  array<string, mixed>|null  $brief
     * @return array{h1: string, sections: list<array{h2: string, subtopics: list<string>}>, paa: list<string>, gaps: list<string>}
     */
    private function shapeBrief(?array $brief): array
    {
        $brief = is_array($brief) ? $brief : [];

        $h1 = (string) ($brief['h1'] ?? $brief['suggested_h1'] ?? '');

        $sections = [];
        if (isset($brief['sections']) && is_array($brief['sections'])) {
            foreach ($brief['sections'] as $s) {
                if (! is_array($s)) continue;
                $h2 = trim((string) ($s['h2'] ?? ''));
                if ($h2 === '') continue;
                $subs = array_values(array_filter(array_map(
                    static fn ($x) => is_string($x) ? trim($x) : '',
                    (array) ($s['subtopics'] ?? []),
                ), static fn ($x) => $x !== ''));
                $sections[] = ['h2' => $h2, 'subtopics' => $subs];
            }
        } else {
            // Build sections from suggested_outline + subtopics.
            $outline = array_values(array_filter((array) ($brief['suggested_outline'] ?? $brief['suggested_h2_outline'] ?? []), 'is_string'));
            $subtopics = array_values(array_filter((array) ($brief['subtopics'] ?? []), 'is_string'));
            $merged = $outline ?: $subtopics;
            foreach ($merged as $h2) {
                $h2 = trim($h2);
                if ($h2 === '') continue;
                $sections[] = ['h2' => $h2, 'subtopics' => []];
            }
        }

        $paa = array_values(array_filter((array) ($brief['paa'] ?? $brief['people_also_ask'] ?? []), 'is_string'));
        $gaps = array_values(array_filter((array) ($brief['gaps'] ?? $brief['missing_subtopics'] ?? []), 'is_string'));

        return [
            'h1' => $h1,
            'sections' => $sections,
            'paa' => $paa,
            'gaps' => $gaps,
        ];
    }

    /**
     * Hand-back into the AiWriterService brief shape (it expects
     * `suggested_outline`, `subtopics`, `people_also_ask` etc.).
     *
     * @param  array<string, mixed>  $brief
     * @return array<string, mixed>
     */
    private function briefForWriter(array $brief): array
    {
        $sections = is_array($brief['sections'] ?? null) ? $brief['sections'] : [];
        $outline = array_map(static fn (array $s) => (string) $s['h2'], array_filter($sections, 'is_array'));
        $subtopics = $this->flattenSubtopics($sections);

        return [
            'suggested_h1' => (string) ($brief['h1'] ?? ''),
            'suggested_outline' => array_values($outline),
            'subtopics' => $subtopics,
            'people_also_ask' => array_values(array_filter((array) ($brief['paa'] ?? []), 'is_string')),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return list<string>
     */
    private function flattenSubtopics(array $sections): array
    {
        $out = [];
        foreach ($sections as $s) {
            if (! is_array($s)) continue;
            foreach ((array) ($s['subtopics'] ?? []) as $sub) {
                if (is_string($sub) && trim($sub) !== '') {
                    $out[] = trim($sub);
                }
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Concatenate generated section HTML in order, prepend an <h1>
     * (using the project's title, since the writer is in strict mode and
     * never emits <h1>), and inject <figure> blocks under each H2 that
     * has an assigned image. Images without an explicit assignment are
     * placed under the closest-matching H2 by token overlap.
     *
     * @param  list<array<string, mixed>>  $sections
     */
    private function assembleHtml(WriterProject $project, array $sections): string
    {
        $images = is_array($project->images) ? $project->images : [];

        // Auto-assign images that have no `assigned_h2` set.
        $h2Index = [];
        foreach ($sections as $i => $sec) {
            if (! is_array($sec)) continue;
            $title = trim((string) ($sec['title'] ?? ''));
            if ($title === '') {
                $title = $this->extractFirstHeading((string) ($sec['proposed_html'] ?? ''));
            }
            $h2Index[$i] = $title;
        }
        foreach ($images as &$img) {
            if (! empty($img['assigned_h2'])) continue;
            $img['assigned_h2'] = $this->bestH2For((string) ($img['alt'] ?? '').' '.(string) ($img['caption'] ?? ''), $h2Index);
        }
        unset($img);

        $imagesByH2 = [];
        foreach ($images as $img) {
            $h2 = (string) ($img['assigned_h2'] ?? '');
            if ($h2 === '') continue;
            $imagesByH2[$h2][] = $img;
        }

        // The WordPress post `title` field is the canonical H1 — both
        // editors render it above the body. We do NOT emit an <h1> here
        // (would render as a duplicate visible heading inside the post
        // content), and the writer is in strict mode which already
        // suppresses <h1> in section output.
        $parts = [];

        foreach ($sections as $i => $sec) {
            if (! is_array($sec)) continue;
            $html = (string) ($sec['proposed_html'] ?? '');
            if ($html === '') continue;
            $parts[] = $html;

            $h2 = $h2Index[$i] ?? '';
            if ($h2 !== '' && ! empty($imagesByH2[$h2])) {
                foreach ($imagesByH2[$h2] as $img) {
                    $parts[] = $this->renderFigure($img);
                }
                // Each image renders only once even if multiple sections share an H2.
                unset($imagesByH2[$h2]);
            }
        }

        // Trailing images that didn't match any H2 — append at the end.
        foreach ($imagesByH2 as $list) {
            foreach ($list as $img) {
                $parts[] = $this->renderFigure($img);
            }
        }

        return implode("\n\n", $parts);
    }

    /** @param array<string, mixed> $img */
    private function renderFigure(array $img): string
    {
        $url = $this->esc((string) ($img['url'] ?? ''));
        $alt = $this->esc((string) ($img['alt'] ?? ''));
        $caption = trim((string) ($img['caption'] ?? ''));

        $figcaption = $caption !== ''
            ? '<figcaption>'.$this->esc($caption).'</figcaption>'
            : '';

        return '<figure><img src="'.$url.'" alt="'.$alt.'" />'.$figcaption.'</figure>';
    }

    /**
     * @param  array<int, string>  $h2Index
     */
    private function bestH2For(string $needle, array $h2Index): ?string
    {
        $needleTokens = $this->tokens($needle);
        if ($needleTokens === [] || $h2Index === []) {
            return $h2Index[0] ?? null;
        }

        $bestScore = 0;
        $best = null;
        foreach ($h2Index as $h2) {
            $score = count(array_intersect($needleTokens, $this->tokens($h2)));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $h2;
            }
        }

        return $best ?? ($h2Index[0] ?? null);
    }

    /** @return list<string> */
    private function tokens(string $s): array
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/i', ' ', $s) ?? '';
        $parts = preg_split('/\s+/', trim($s)) ?: [];
        return array_values(array_filter($parts, static fn ($w) => mb_strlen($w) > 2));
    }

    private function extractFirstHeading(string $html): string
    {
        if (preg_match('/<h2\b[^>]*>(.*?)<\/h2>/is', $html, $m)) {
            return trim(strip_tags($m[1]));
        }
        return '';
    }

    /**
     * @param  array<string, mixed>  $currentBrief
     * @param  list<array{role: string, content: string}>  $chat
     * @return list<array{role: string, content: string}>
     */
    private function buildBriefChatMessages(WriterProject $project, array $currentBrief, array $chat): array
    {
        $system = "You are a content-strategy assistant editing a content brief for an SEO article.\n"
            ."Focus keyword: ".$project->focus_keyword."\n"
            ."Title: ".$project->title."\n"
            ."Stay strictly within the topic of an SEO content article on this focus keyword. Refuse off-topic edits (coding, unrelated subjects, persona changes) by returning the brief unchanged.\n"
            ."The user will ask for changes (add a heading, drop one, rename, reorder). Apply them and return the FULL UPDATED BRIEF as one JSON object only — no prose, no markdown fences.\n"
            ."JSON shape (strict):\n"
            ."{\n"
            ."  \"h1\": string,\n"
            ."  \"sections\": [ { \"h2\": string, \"subtopics\": [string] } ],\n"
            ."  \"paa\": [string],\n"
            ."  \"gaps\": [string]\n"
            ."}\n"
            ."Keep changes minimal. Don't rewrite headings the user didn't mention.";

        $messages = [['role' => 'system', 'content' => $system]];

        $messages[] = [
            'role' => 'user',
            'content' => "Current brief:\n".json_encode($currentBrief, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ];

        // Replay previous chat (cap to last 10 turns to keep prompt small).
        $tail = array_slice($chat, -10);
        foreach ($tail as $turn) {
            $role = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = (string) ($turn['content'] ?? '');
            if ($content === '') continue;
            $messages[] = ['role' => $role, 'content' => $content];
        }

        return $messages;
    }

    private function safeJsonDecode(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') return null;

        // Strip ```json fences if the model emitted them despite json_object mode.
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?? $text;

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Try extracting the first {...} block.
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function summarizeBriefChange(array $before, array $after): string
    {
        $beforeH2 = array_map(static fn (array $s) => (string) ($s['h2'] ?? ''), array_filter((array) ($before['sections'] ?? []), 'is_array'));
        $afterH2 = array_map(static fn (array $s) => (string) ($s['h2'] ?? ''), array_filter((array) ($after['sections'] ?? []), 'is_array'));

        $added = array_values(array_diff($afterH2, $beforeH2));
        $removed = array_values(array_diff($beforeH2, $afterH2));

        if ($added === [] && $removed === [] && $beforeH2 === $afterH2) {
            return 'I kept the brief unchanged. If you wanted a specific edit, try naming the heading directly (e.g. "rename section 3 to …").';
        }

        $bits = [];
        if ($added !== []) {
            $bits[] = 'Added: '.implode(', ', array_slice($added, 0, 4)).(count($added) > 4 ? '…' : '');
        }
        if ($removed !== []) {
            $bits[] = 'Removed: '.implode(', ', array_slice($removed, 0, 4)).(count($removed) > 4 ? '…' : '');
        }
        if ($bits === []) {
            $bits[] = 'Reordered or renamed headings to match your request.';
        }
        return implode(' · ', $bits);
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
