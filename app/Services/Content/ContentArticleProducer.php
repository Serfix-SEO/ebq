<?php

namespace App\Services\Content;

use App\Exceptions\QuotaExceededException;
use App\Models\ContentArticle;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\Website;
use App\Services\AiContentBriefService;
use App\Services\AiWriterService;
use App\Services\Llm\LlmClientFactory;
use App\Support\ContentAutopilotConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The Content Autopilot production line for ONE topic:
 *
 *   research (SERP brief, reused AiContentBriefService)
 *   → write   (reused AiWriterService::draft — v25 prompt, dash defense,
 *              locked anchors — plus the Humanizer style contract and the
 *              plan's template toggles via custom_prompt)
 *   → score   (ContentSeoScorer, deterministic)
 *   → revise  (targeted completeJson patches, only failing checks)
 *   → repeat  until target score / max iterations / diminishing returns.
 *
 * Produces ContentArticle VERSIONS (v1 = first draft, v2+ = revisions).
 * Never throws for quality problems — the topic ends `ready` (score >= floor)
 * or `failed` (below floor / hard errors), and the caller decides what next.
 */
class ContentArticleProducer
{
    public function __construct(
        private readonly AiContentBriefService $briefs,
        private readonly ContentSeoScorer $scorer,
        private readonly HumanizerService $humanizer,
        private readonly ContentLlmSpendMeter $meter,
    ) {}

    /**
     * Run the full produce loop. Returns the final current ContentArticle,
     * or null on hard failure (topic already marked failed).
     */
    public function produce(ContentTopic $topic): ?ContentArticle
    {
        $plan = $topic->plan;
        $website = $topic->website;
        if ($plan === null || $website === null) {
            $topic->fail('missing plan or website');

            return null;
        }

        // Competitor-mention guard: classify once per plan (lazy — covers
        // plans created before the guard existed without a backfill). Errors
        // never block production; the guard fail-softs internally.
        $guard = app(CompetitorMentionGuard::class);
        try {
            if (! $guard->assessed($plan)) {
                $guard->assess($plan);
                $plan->refresh();
            }
        } catch (\Throwable $e) {
            Log::warning('content_autopilot.competitor_guard_assess_failed', ['plan_id' => $plan->id, 'error' => $e->getMessage()]);
        }

        // ── Research ────────────────────────────────────────────────────
        $topic->enterStage(ContentTopic::STATUS_RESEARCHING);

        $brief = $this->briefs->brief($website, 0, [
            'focus_keyword' => $topic->target_keyword,
            'country' => strtolower((string) ($plan->country ?: 'us')),
            'language' => strtolower((string) ($plan->language ?: 'en')),
        ]);
        if (! ($brief['ok'] ?? false)) {
            // A missing SERP is not fatal — the writer can work from the
            // topic + business profile alone (young niches, tiny locales).
            $brief = null;
        }
        $topic->forceFill(['brief' => $brief])->save();

        // ── Write (v1) ──────────────────────────────────────────────────
        $topic->enterStage(ContentTopic::STATUS_WRITING);

        $writeModel = ContentAutopilotConfig::modelFor('write');
        $llm = LlmClientFactory::make($writeModel['provider']);
        $writer = new AiWriterService($llm);

        $context = $this->scorerContext($topic, $plan, $website);

        $draftInput = [
            'focus_keyword' => $topic->target_keyword,
            'title' => $topic->title,
            'brief' => $brief,
            'additional_keywords' => (array) ($topic->secondary_keywords ?? []),
            'language' => strtolower((string) ($plan->language ?: 'en')),
            'country' => strtoupper((string) ($plan->country ?: '')),
            'custom_prompt' => $this->templateInstructions($plan, $topic)
                .(($urls = array_slice((array) ($context['site_urls'] ?? []), 0, 15)) === [] ? ''
                    : "\nInternal pages you may link to (2-3, exact URLs only):\n".implode("\n", $urls)),
            '__user_id' => $website->user_id,
            '__source' => 'content_autopilot.write',
            '__unmetered' => true, // capped by ContentLlmSpendMeter + entitlements, not the dashboard token cap
            // Section-by-section generation — a single mega-call blew the 16k
            // output cap on hub topics ("Ultimate Guide…", prod 2026-07-22)
            // and lost the whole article to llm_parse_failed. Chunked writing
            // is structurally cap-proof; costs more prompt tokens (accepted).
            'chunked' => true,
        ];
        if (! empty($writeModel['model'])) {
            $draftInput['model'] = $writeModel['model'];
        }

        // Draft with ONE retry on a transient failure. The write LLM call
        // occasionally blips (timeout, truncated/invalid JSON) — a single retry
        // turns most of those "sometimes it fails" runs into a clean draft.
        // Quota exhaustion is NOT retried (it would just fail again + re-bill).
        $draft = ['ok' => false];
        $lastErr = 'unknown';
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $draft = $writer->draft($website, 0, $draftInput);
            } catch (QuotaExceededException $e) {
                // The owner's plan ran out of AI tokens — an EXPECTED operational
                // state, not a crash. Neutral client copy ("Needs attention").
                $topic->fail('llm_quota_exhausted');

                return null;
            }
            $this->meter->add(ContentLlmSpendMeter::EST_WRITE_USD);
            if ($draft['ok'] ?? false) {
                break;
            }
            $lastErr = (string) ($draft['error'] ?? 'unknown');
        }
        if (! ($draft['ok'] ?? false)) {
            $topic->fail('draft_failed: '.$lastErr);

            return null;
        }

        $html = $this->humanizer->clean($this->assembleHtml($draft));
        $h1 = (string) ($draft['h1'] ?? '') !== '' ? (string) $draft['h1'] : $topic->title;
        $metaTitle = mb_substr($h1, 0, 60);
        $metaDescription = mb_substr(trim((string) ($draft['summary'] ?? '')), 0, 158);
        // Slug from the H1 when it already contains the keyphrase (it almost
        // always does) — prepending target_keyword otherwise doubled it
        // ("pubg-blank-name-pubg-blank-name-…"). Fall back to keyword+H1 only
        // when the H1 somehow omits the keyphrase, so the slug still carries it.
        $kwLower = mb_strtolower(trim((string) $topic->target_keyword));
        $slugBase = ($kwLower !== '' && str_contains(mb_strtolower($h1), $kwLower))
            ? $h1
            : trim($topic->target_keyword.' '.$h1);
        $slug = Str::slug(mb_substr($slugBase, 0, 80));

        $article = $this->storeScoredVersion($topic, $context, [
            'h1' => $h1,
            'meta_title' => $metaTitle,
            'meta_description' => $metaDescription,
            'slug' => $slug,
            'html' => $html,
            'outline' => array_map(
                static fn ($s) => (string) ($s['title'] ?? ''),
                (array) ($draft['sections'] ?? [])
            ),
            'generation_meta' => [
                'provider' => $writeModel['provider'],
                'model' => $writeModel['model'],
                'stage' => 'write',
            ],
        ]);

        // ── Revision loop ───────────────────────────────────────────────
        $target = ContentAutopilotConfig::targetScore();
        $maxRevisions = ContentAutopilotConfig::maxRevisions();
        $iteration = 0;

        // Natural writing style is a hard requirement (owner 2026-07-18): a
        // draft whose style lint is dirty gets revised even when its numeric
        // score already clears the target — we never ship robotic prose.
        while (($article->seo_score < $target || $this->hasStyleIssue($article)) && $iteration < $maxRevisions) {
            $iteration++;
            $topic->enterStage(ContentTopic::STATUS_REVISING);

            try {
                $revised = $this->revise($article, $topic, $plan);
            } catch (QuotaExceededException) {
                $revised = null; // out of tokens mid-loop: ship the best version
            }
            $this->meter->add(ContentLlmSpendMeter::EST_REVISE_USD);
            if ($revised === null) {
                break; // revision call failed — keep the best version we have
            }

            $previousScore = $article->seo_score;
            $article = $this->storeScoredVersion($topic, $context, $revised + [
                'generation_meta' => [
                    'provider' => $writeModel['provider'],
                    'model' => ContentAutopilotConfig::modelFor('revise')['model'],
                    'stage' => 'revise_'.$iteration,
                ],
            ]);

            // Diminishing returns — but never abandon a dirty style lint
            // while revisions remain: style is a must, not a nice-to-have.
            if ($article->seo_score <= $previousScore + 2 && ! $this->hasStyleIssue($article)) {
                break;
            }
        }

        // ── Final de-AI cleanup ─────────────────────────────────────────
        // The SEO revise optimizes for on-page checks, not for sounding human,
        // so fabrication/filler tells often survive it. Run ONE focused editor
        // pass whose only job is to strip those tells (invented reports/dates/
        // stats, teaching loops, over-repeated exact keyword, sweeping claims,
        // dramatic closers) without touching SEO. Keep the result only if it
        // still clears the publish floor.
        if ($this->hasIntegrityTell($article)) {
            $topic->enterStage(ContentTopic::STATUS_REVISING);
            try {
                $cleaned = $this->deAiCleanup($article, $topic, $plan);
            } catch (QuotaExceededException) {
                $cleaned = null;
            }
            if ($cleaned !== null) {
                $this->meter->add(ContentLlmSpendMeter::EST_REVISE_USD);
                $preCleanScore = $article->seo_score;
                $candidate = $this->storeScoredVersion($topic, $context, $cleaned + [
                    'generation_meta' => [
                        'provider' => $writeModel['provider'],
                        'model' => ContentAutopilotConfig::modelFor('revise')['model'],
                        'stage' => 'de_ai_cleanup',
                    ],
                ]);
                // Keep the cleaned version only if it did NOT regress SEO. The
                // de-AI pass rewrites prose and can dilute keyword density, drop
                // an external link, or strip a keyword from a heading; when that
                // happens we keep the pre-clean version (a 1-point tolerance
                // covers rounding) so the cleanup never drags us below target.
                if ($candidate->seo_score >= $preCleanScore - 1
                    && $candidate->seo_score >= ContentAutopilotConfig::publishFloor()) {
                    $article = $candidate;
                }
            }
        }

        // ── Verdict ─────────────────────────────────────────────────────
        if ($article->seo_score < ContentAutopilotConfig::publishFloor()) {
            $topic->fail('below_publish_floor: score '.$article->seo_score);

            return $article;
        }

        $topic->enterStage(ContentTopic::STATUS_READY);

        // Guard value counter (Phase E): make the protection visible. Checked
        // whenever the guard was active for this topic; "removed" when an
        // earlier version carried a competitor mention the final one doesn't.
        try {
            $guardSvc = app(CompetitorMentionGuard::class);
            if ($guardSvc->termsForTopic($plan, $topic) !== []) {
                $guardSvc->recordArticleChecked($plan);
                $hadMention = $topic->articles()
                    ->where('version', '<', $article->version)
                    ->get(['style_issues'])
                    ->contains(fn ($v) => in_array('competitor_mentions',
                        array_column((array) ($v->style_issues ?? []), 'code'), true));
                $finalHasMention = in_array('competitor_mentions',
                    array_column((array) ($article->style_issues ?? []), 'code'), true);
                if ($hadMention && ! $finalHasMention) {
                    $guardSvc->recordMentionRemoved($plan);
                }
            }
        } catch (\Throwable) {
            // stats are cosmetic — never fail a READY article over them
        }

        return $article;
    }

    // ── internals ───────────────────────────────────────────────────────

    /**
     * Synthetic section titles the writer's strict mode fabricates for
     * edit/replace ops — never render these as headings.
     */
    private const SYNTHETIC_TITLES = [
        'new section', 'edit existing section', 'replace post content',
        'full article replacement', 'article', 'content',
    ];

    /** Concatenate draft sections into article HTML (H2 per section). */
    private function assembleHtml(array $draft): string
    {
        $parts = [];
        foreach ((array) ($draft['sections'] ?? []) as $section) {
            if (! is_array($section)) {
                continue;
            }
            $title = trim(strip_tags((string) ($section['title'] ?? '')));
            $sectionHtml = (string) ($section['proposed_html'] ?? '');
            if ($sectionHtml === '') {
                continue;
            }
            // Sections may already open with their own heading; only add one
            // when missing — and never render strict-mode synthetic titles.
            if ($title !== ''
                && ! in_array(mb_strtolower($title), self::SYNTHETIC_TITLES, true)
                && ! preg_match('/^\s*<h[123]/i', $sectionHtml)) {
                $parts[] = '<h2>'.e($title).'</h2>';
            }
            $parts[] = $sectionHtml;
        }

        $html = implode("\n", $parts);

        // The page H1 is rendered by the publish target — strip a leading
        // in-body <h1>/<h2> that merely repeats the headline.
        $h1 = mb_strtolower(trim(strip_tags((string) ($draft['h1'] ?? ''))));
        if ($h1 !== '' && preg_match('/^\s*<h[12]\b[^>]*>(.*?)<\/h[12]>/is', $html, $m)
            && mb_strtolower(trim(strip_tags($m[1]))) === $h1) {
            $html = (string) preg_replace('/^\s*<h[12]\b[^>]*>.*?<\/h[12]>/is', '', $html, 1);
        }

        return $html;
    }

    /**
     * Mechanical structure fixes applied to EVERY version (write and revise
     * paths alike): drop a leading heading that repeats the H1, promote
     * pre-first-H2 orphan H3s so the hierarchy is valid, give every H2/H3 a
     * stable `id` slug, and (when the plan enables it) build a real
     * anchor-linked table of contents that scrolls to those ids. The TOC is
     * generated deterministically here — asking the LLM for one produced a
     * plain list with no working anchors (owner QA 2026-07-17).
     */
    private function normalizeStructure(string $html, string $h1, bool $withToc = false): string
    {
        $h1Lower = mb_strtolower(trim($h1));
        if ($h1Lower !== '' && preg_match('/^\s*<h[12]\b[^>]*>(.*?)<\/h[12]>/is', $html, $m)
            && mb_strtolower(trim(strip_tags($m[1]))) === $h1Lower) {
            $html = (string) preg_replace('/^\s*<h[12]\b[^>]*>.*?<\/h[12]>/is', '', $html, 1);
        }

        // Promote every h3 that appears before the first h2.
        while (true) {
            $firstH2 = stripos($html, '<h2');
            $firstH3 = stripos($html, '<h3');
            if ($firstH3 === false || ($firstH2 !== false && $firstH2 < $firstH3)) {
                break;
            }
            $html = preg_replace('/<h3\b([^>]*)>(.*?)<\/h3>/is', '<h2$1>$2</h2>', $html, 1) ?? $html;
        }

        // Remove any prior model-authored / previously-injected TOC so a
        // revision pass never stacks duplicates.
        $html = preg_replace('/<nav\b[^>]*class="[^"]*content-toc[^"]*"[^>]*>.*?<\/nav>/is', '', $html) ?? $html;

        // Slug + id every H2/H3 (idempotent: existing ids are respected).
        $used = [];
        $html = preg_replace_callback('/<(h[23])\b([^>]*)>(.*?)<\/\1>/is', function ($m) use (&$used) {
            [$full, $tag, $attrs, $inner] = $m;
            if (preg_match('/\bid="([^"]+)"/i', $attrs, $idm)) {
                $used[$idm[1]] = true;

                return $full;
            }
            $slug = $this->headingSlug(strip_tags($inner), $used);
            $used[$slug] = true;

            return '<'.$tag.$attrs.' id="'.$slug.'">'.$inner.'</'.$tag.'>';
        }, $html) ?? $html;

        if ($withToc) {
            $toc = $this->buildToc($html);
            if ($toc !== '') {
                // The "In this article" TOC sits AFTER the opening paragraph,
                // never before it (owner 2026-07-18): the opener carries the
                // focus keyphrase and the on-page analyzer reads the first
                // <p> as the intro — a leading TOC would bury both. Insert
                // right after the first closing </p>; if the draft opens with
                // no paragraph, fall back to prepending.
                if (preg_match('/<\/p>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
                    $at = $m[0][1] + strlen($m[0][0]);
                    $html = substr($html, 0, $at)."\n".$toc.substr($html, $at);
                } else {
                    $html = $toc.$html;
                }
            }
        }

        return $html;
    }

    /** Trim to <= max chars at a word boundary, dropping trailing punctuation. */
    private function clampLength(string $s, int $max): string
    {
        $s = trim($s);
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        $cut = mb_substr($s, 0, $max);

        // Prefer ending on a COMPLETE SENTENCE when one ends reasonably far
        // in — a word-boundary cut still leaves dangling clauses ("…a
        // detailed checklist, and", live serfix.io meta 2026-07-22), which
        // reads broken in the SERP snippet.
        $sentenceEnd = false;
        foreach (['.', '!', '?'] as $t) {
            $pos = mb_strrpos($cut, $t);
            if ($pos !== false && ($sentenceEnd === false || $pos > $sentenceEnd)) {
                $sentenceEnd = $pos;
            }
        }
        if ($sentenceEnd !== false && $sentenceEnd >= (int) ($max * 0.6)) {
            return trim(mb_substr($cut, 0, $sentenceEnd + 1));
        }

        $sp = mb_strrpos($cut, ' ');
        if ($sp !== false && $sp >= $max - 20) {
            $cut = mb_substr($cut, 0, $sp);
        }
        // A trailing conjunction/preposition dangles just as badly as a
        // half-word — drop it along with trailing punctuation.
        $cut = (string) preg_replace('/\s+(?:and|or|but|with|for|to|the|a|an|of|in|on|at|is|are)$/iu', '', $cut);

        return rtrim($cut, " ,.;:-\u{2013}\u{2014}");
    }

    /** True when the article's persisted style lint found tells. */
    private function hasStyleIssue(ContentArticle $article): bool
    {
        return ! empty((array) ($article->style_issues ?? []));
    }

    /**
     * The subset of style tells that are integrity/credibility problems (as
     * opposed to rhythm nits) — worth a dedicated cleanup pass because the SEO
     * revise won't fix them.
     */
    private function hasIntegrityTell(ContentArticle $article): bool
    {
        $codes = array_column((array) ($article->style_issues ?? []), 'code');

        return array_intersect($codes, [
            'fabricated_consensus', 'fabricated_citation', 'banned_phrases',
            'hype_contrast', 'formal_tone',
        ]) !== [];
    }

    /**
     * One focused editor pass that ONLY removes AI tells — no SEO changes.
     * Different brief from revise(): here the job is to make the prose read as
     * a knowledgeable human wrote it, deleting anything invented or padded.
     *
     * @return array{h1:string, meta_title:string, meta_description:string, slug:string, html:string, outline:mixed}|null
     */
    private function deAiCleanup(ContentArticle $article, ContentTopic $topic, ContentPlan $plan): ?array
    {
        $reviseModel = ContentAutopilotConfig::modelFor('revise');
        $llm = LlmClientFactory::make($reviseModel['provider']);
        if (! $llm->isAvailable()) {
            return null;
        }

        $tells = implode("\n- ", array_filter(array_map(
            static fn ($i) => (string) ($i['message'] ?? ''),
            (array) ($article->style_issues ?? [])
        )));

        $system = 'You are a senior editor whose ONLY job is to make an article read like a knowledgeable human wrote it, not an AI. '
            .'Do NOT restructure, do not change headings, do not add or remove sections, do not touch links, images, tables, or the FAQ. '
            .'Keep the meaning, the useful specifics, and roughly the same length. Edit sentence by sentence to fix these problems:'
            ."\n- {$tells}\n"
            .'Hard rules: DELETE every invented claim outright — no "players reported", no "the community found", no dated events ("in mid-2025..."), no fabricated studies/percentages, no "works on all regions/versions", no "most reliable" unless it is plainly true. When you delete an unsupported claim, do NOT replace it with another guess; either state only what is certain or drop the point. '
            .'Compress teaching loops so each idea is explained ONCE. Remove dramatic sign-offs. Keep contractions and natural rhythm. DO NOT reduce how often the focus keyphrase appears — the article needs it at the current SEO density, so preserve those mentions. '
            .'Respond with valid JSON only: {"html": "<full edited article HTML>", "meta_title": "...", "meta_description": "...", "h1": "..."}. '
            ."\n".$this->humanizer->promptRules();

        $user = "FOCUS KEYWORD (keep its existing density, do not remove mentions): {$topic->target_keyword}\n"
            .'LANGUAGE: '.($plan->language ?: 'en')."\n\n"
            ."CURRENT META TITLE: {$article->meta_title}\n"
            ."CURRENT META DESCRIPTION: {$article->meta_description}\n"
            ."CURRENT H1: {$article->h1}\n\n"
            ."ARTICLE HTML TO CLEAN:\n{$article->html}";

        $options = [
            'temperature' => 0.4,
            'max_tokens' => 16000,
            'timeout' => 240,
            '__user_id' => $topic->website?->user_id,
            '__source' => 'content_autopilot.de_ai',
            '__unmetered' => true,
        ];
        if (! empty($reviseModel['model'])) {
            $options['model'] = $reviseModel['model'];
        }

        $result = $llm->completeJson([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ], $options);

        if (! is_array($result) || trim((string) ($result['html'] ?? '')) === '') {
            return null;
        }

        return [
            'h1' => trim((string) ($result['h1'] ?? $article->h1)) ?: (string) $article->h1,
            'meta_title' => mb_substr(trim((string) ($result['meta_title'] ?? $article->meta_title)), 0, 300),
            'meta_description' => mb_substr(trim((string) ($result['meta_description'] ?? $article->meta_description)), 0, 500),
            'slug' => (string) $article->slug,
            'html' => (string) $result['html'],
            'outline' => $article->outline,
        ];
    }

    /** A unique kebab-case anchor id for a heading. */
    private function headingSlug(string $text, array $used): string
    {
        $base = Str::slug($text);
        if ($base === '') {
            $base = 'section';
        }
        $slug = $base;
        $i = 2;
        while (isset($used[$slug])) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /**
     * Build the anchor-linked TOC from the article's H2 headings (with their
     * H3 children nested). Empty string when there are fewer than 2 sections.
     */
    private function buildToc(string $html): string
    {
        if (! preg_match_all('/<(h[23])\b[^>]*\bid="([^"]+)"[^>]*>(.*?)<\/\1>/is', $html, $matches, PREG_SET_ORDER)) {
            return '';
        }

        $h2s = array_filter($matches, fn ($m) => strtolower($m[1]) === 'h2');
        if (count($h2s) < 2) {
            return '';
        }

        $items = [];
        foreach ($matches as $m) {
            // Decode first: heading text arrives entity-encoded ("&amp;"),
            // and e() would double-escape it into a visible "&amp;" in the TOC.
            $label = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5));
            if ($label === '') {
                continue;
            }
            $isH3 = strtolower($m[1]) === 'h3';
            $items[] = '<li class="content-toc__item'.($isH3 ? ' content-toc__item--sub' : '').'">'
                .'<a href="#'.e($m[2]).'">'.e($label).'</a></li>';
        }

        // Title is a <div>, NOT a <p>: on-page SEO analyzers grab the first
        // <p> as "the intro" — a TOC <p> here would hijack that slot and make
        // every keyphrase-in-intro check read "In this article".
        return '<nav class="content-toc" aria-label="Table of contents">'
            .'<div class="content-toc__title">In this article</div>'
            .'<ul>'.implode('', $items).'</ul></nav>'."\n";
    }

    /** Score + persist as the next version, folding in the style lint. */
    private function storeScoredVersion(ContentTopic $topic, array $context, array $attributes): ContentArticle
    {
        $topic->enterStage(ContentTopic::STATUS_SCORING);

        // Hard length caps so the WP plugin's on-page checks never flag an
        // over-length title/description (LLMs routinely land at 61/156). Trim
        // to a word boundary so nothing is cut mid-word.
        $attributes['meta_title'] = $this->clampLength((string) ($attributes['meta_title'] ?? ''), 60);
        $attributes['meta_description'] = $this->clampLength((string) ($attributes['meta_description'] ?? ''), 155);

        $attributes['html'] = $this->normalizeStructure(
            (string) ($attributes['html'] ?? ''),
            (string) ($attributes['h1'] ?? ''),
            (bool) (($context['toggles'] ?? [])['toc'] ?? false),
        );
        $html = (string) $attributes['html'];
        $guard = app(CompetitorMentionGuard::class);
        $guardPlan = $topic->plan;
        $styleIssues = $this->humanizer->lint(
            $html,
            $guardPlan !== null ? $guard->termsForTopic($guardPlan, $topic) : [],
            $guardPlan !== null && $guard->enabled($guardPlan) ? $guard->blockedDomains($guardPlan) : [],
        );
        $context['style_issues'] = $styleIssues;

        $result = $this->scorer->score(
            $html,
            (string) ($attributes['meta_title'] ?? ''),
            (string) ($attributes['meta_description'] ?? ''),
            (string) ($attributes['h1'] ?? ''),
            (string) ($attributes['slug'] ?? ''),
            $context
        );

        return ContentArticle::storeVersion($topic, $attributes + [
            'word_count' => str_word_count(trim(strip_tags($html))),
            'seo_score' => $result['score'],
            'seo_issues' => $result['issues'],
            'style_issues' => $styleIssues,
        ]);
    }

    /**
     * One targeted revision call: sends ONLY the failing checks and asks for
     * patched fields back. Cheaper and more convergent than a full rewrite.
     *
     * @return array{h1:string, meta_title:string, meta_description:string, slug:string, html:string, outline:mixed}|null
     */
    private function revise(ContentArticle $article, ContentTopic $topic, ContentPlan $plan): ?array
    {
        $reviseModel = ContentAutopilotConfig::modelFor('revise');
        $llm = LlmClientFactory::make($reviseModel['provider']);
        if (! $llm->isAvailable()) {
            return null;
        }

        $issues = array_merge(
            array_map(static fn ($i) => (string) ($i['message'] ?? ''), (array) $article->seo_issues),
        );
        $issueList = implode("\n- ", array_filter($issues));

        $currentWords = str_word_count(trim(strip_tags((string) $article->html)));
        $system = 'You are an expert SEO editor. Fix ONLY the listed problems in the article. '
            .'Keep everything that is not mentioned unchanged. '
            .'Length discipline: the target is about '.$plan->article_length.' words and the article '
            .'is currently '.$currentWords.' words. If it is under target, ADD substantive paragraphs '
            .'with concrete detail; if it is over target, TIGHTEN by cutting redundancy. Never pad. '
            // Style must survive the edit — the revise pass is where the human
            // voice usually degrades (contractions expanded, keyword re-stuffed,
            // tone turned formal). Lock those down explicitly.
            .'PRESERVE THE HUMAN VOICE while you fix things: '
            .'Keep every contraction (it\'s, you\'re, don\'t) — never expand them, and add them where a stiff phrase like "you are" slipped in. '
            .'When you fix a keyword or density issue, DO NOT paste the exact phrase into extra or awkward spots; weave it into a sentence that would exist anyway, and prefer variants or pronouns. Never repeat the exact focus phrase more than a handful of times total. '
            .'Do not make the writing more formal, more corporate, or more "complete" than it was. Do not add hype, dramatic contrasts ("it doesn\'t just X, it Y"), or invented personal experience. Keep sentence-length variety and any deliberate fragments. '
            .'Respond with valid JSON only: '
            .'{"html": "<full corrected article HTML>", "meta_title": "...", "meta_description": "...", "h1": "..."}. '
            ."\n".$this->humanizer->promptRules();

        // Real, existing pages the model may link to — without this list the
        // reviser cannot satisfy the internal-link checks (it would invent
        // URLs, which the scorer rejects).
        $linkTargets = array_slice((array) ($this->scorerContext($topic, $plan, $topic->website)['site_urls'] ?? []), 0, 15);
        $linkBlock = $linkTargets === [] ? ''
            : "INTERNAL PAGES YOU MAY LINK TO (use 2-3 naturally, exact URLs only):\n"
                .implode("\n", $linkTargets)."\n\n";

        $user = "TARGET KEYWORD: {$topic->target_keyword}\n"
            .'LANGUAGE: '.($plan->language ?: 'en')."\n"
            ."PROBLEMS TO FIX:\n- {$issueList}\n\n"
            .$linkBlock
            ."CURRENT META TITLE: {$article->meta_title}\n"
            ."CURRENT META DESCRIPTION: {$article->meta_description}\n"
            ."CURRENT H1: {$article->h1}\n\n"
            ."CURRENT ARTICLE HTML:\n{$article->html}";

        $options = [
            'temperature' => 0.3,
            'max_tokens' => 16000,
            'timeout' => 240,
            '__user_id' => $topic->website?->user_id,
            '__source' => 'content_autopilot.revise',
            '__unmetered' => true,
        ];
        if (! empty($reviseModel['model'])) {
            $options['model'] = $reviseModel['model'];
        }

        $response = $llm->completeJson([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ], $options);

        if (! is_array($response) || trim((string) ($response['html'] ?? '')) === '') {
            return null;
        }

        return [
            'h1' => trim((string) ($response['h1'] ?? $article->h1)) ?: $article->h1,
            'meta_title' => mb_substr(trim((string) ($response['meta_title'] ?? $article->meta_title)), 0, 200),
            'meta_description' => mb_substr(trim((string) ($response['meta_description'] ?? $article->meta_description)), 0, 300),
            'slug' => (string) $article->slug,
            'html' => $this->humanizer->clean((string) $response['html']),
            'outline' => $article->outline,
        ];
    }

    /** The site context the scorer verifies against (built once per run). */
    private function scorerContext(ContentTopic $topic, ContentPlan $plan, Website $website): array
    {
        $siteUrls = [];
        $existingTitles = [];
        try {
            if ($website->crawl_site_id) {
                $pages = DB::table('website_pages')
                    ->where('crawl_site_id', $website->crawl_site_id)
                    ->where('http_status', 200)
                    ->orderByDesc('inbound_link_count')
                    ->limit(300)
                    ->get(['url', 'title']);
                $siteUrls = $pages->pluck('url')->map(fn ($u) => (string) $u)->all();
                $existingTitles = $pages->pluck('title')->filter()->map(fn ($t) => (string) $t)->all();
            }
        } catch (\Throwable) {
            // No crawl data — the scorer renormalizes without link checks.
        }

        return [
            'target_keyword' => $topic->target_keyword,
            'secondary_keywords' => (array) ($topic->secondary_keywords ?? []),
            'site_host' => mb_strtolower((string) $website->domain),
            'site_urls' => $siteUrls,
            'existing_titles' => $existingTitles,
            'article_length' => (int) $plan->article_length,
            'toggles' => [
                'toc' => $plan->toggle('toc'),
                'key_takeaways' => $plan->toggle('key_takeaways'),
                'faq' => $plan->toggle('faq'),
                'external_links' => $plan->toggle('external_links'),
                'cta_enabled' => $plan->toggle('cta_enabled'),
            ],
            'cta_url' => (string) ($plan->cta_url ?? ''),
            'language' => (string) ($plan->language ?: 'en'),
        ];
    }

    /** Template requirements injected via the writer's custom_prompt slot. */
    private function templateInstructions(ContentPlan $plan, ContentTopic $topic): string
    {
        $rules = [];
        // Ordering is load-bearing: the FIRST element must be a short opening
        // paragraph carrying the focus keyphrase (on-page analyzer reads the
        // first <p> as the intro). Anything else — Key takeaways box, TOC —
        // comes after it.
        $rules[] = 'Begin with a short opening paragraph (1-3 sentences) that includes the focus keyphrase. This paragraph must be the very first thing in the article — nothing (no box, no list, no heading) before it.';
        if ($plan->toggle('key_takeaways')) {
            $rules[] = 'Immediately AFTER that opening paragraph, add a "Key takeaways" box: 3-5 short bullet points summarizing the article.';
        }
        if ($plan->toggle('faq')) {
            $rules[] = 'End with an FAQ section (H2) answering 3-5 real questions searchers ask.';
        }
        if ($plan->toggle('cta_enabled') && $plan->cta_url) {
            $rules[] = 'Include one natural call-to-action linking to '.$plan->cta_url.' where it genuinely helps the reader'
                .$this->ctaFraming($plan).'.';
        }
        if ($plan->toggle('external_links')) {
            $rules[] = 'Cite at least one authoritative external source with a link.';
        }
        $rules[] = 'Article length target: about '.$plan->article_length.' words.';
        // Type-aware voice + care rules (Phase F). Null site type adds
        // nothing — exact pre-site-type behavior.
        foreach ($this->siteTypeRules($plan) as $rule) {
            $rules[] = $rule;
        }
        $rules[] = "Today's date is ".now()->toFormattedDateString().'. Any year you mention must be '
            .now()->year.' unless you are referring to a genuinely historical fact.';
        if (trim((string) $plan->custom_instructions) !== '') {
            $rules[] = trim((string) $plan->custom_instructions);
        }
        // Competitor-mention guard (prevention layer; the lint is the cure).
        $guardTerms = app(CompetitorMentionGuard::class)->termsForTopic($plan, $topic);
        if ($guardTerms !== []) {
            $rules[] = 'STRICT BRAND RULE: never mention, recommend, compare against, or link to any of these '
                .'competitors: "'.implode('", "', $guardTerms).'". When an example tool or service is needed, '
                .'refer to '.$topic->website?->normalized_domain.' or describe the category generically '
                .'("an SEO audit tool", "a rank tracker") instead of naming a brand.';
        }

        return implode("\n", array_merge($rules, $this->onPageSeoRules($topic)))
            ."\n".$this->humanizer->promptRules();
    }

    /**
     * Voice + audience + care instructions from the plan's site type
     * (Phase F). Empty for a null/unclassified type — the writer behaves
     * exactly as it did before site types existed.
     *
     * @return list<string>
     */
    private function siteTypeRules(ContentPlan $plan): array
    {
        if (! \App\Support\ContentSiteTypeProfiles::isValid($plan->site_type)) {
            // Type-blind plans still get the care rule when the classifier
            // flagged the SUBJECT as YMYL — safety is type-independent.
            return $plan->ymyl === true
                ? ['CARE: this topic area affects readers\' money, health or legal standing. Make only claims you can support, avoid absolute promises, and recommend consulting a qualified professional where a decision has real consequences.']
                : [];
        }
        $profile = \App\Support\ContentSiteTypeProfiles::profile($plan->site_type);

        $rules = [];
        $voice = match ($profile['voice']) {
            'personal' => 'VOICE: write in a personal first-person voice ("I", "we") — an experienced enthusiast sharing hands-on advice, never a corporate brochure.',
            'brand' => 'VOICE: write in a confident first-person-plural brand voice ("we") that reflects the site\'s own products naturally — helpful first, never a sales pitch.',
            'friendly_professional' => 'VOICE: write in a warm, plain-spoken professional voice a local customer would trust — practical, concrete, no jargon.',
            'professional' => 'VOICE: write in a precise, professional voice — concrete examples and specifics over hype; the reader is evaluating expertise.',
            'warm' => 'VOICE: write in a warm, mission-driven voice that connects the topic to real people and impact.',
            default => null,
        };
        if ($voice !== null) {
            $rules[] = $voice;
        }
        if (filled($plan->audience)) {
            $rules[] = 'AUDIENCE: write for '.trim((string) $plan->audience).' — their vocabulary, their concerns, their level of expertise.';
        }
        if ($profile['ymyl_care'] || $plan->ymyl === true) {
            // Type default OR the classifier's per-site YMYL flag — a
            // supplements brand / finance blog needs the care rule even
            // though 'brand'/'blog' don't set ymyl_care.
            $rules[] = 'CARE: this topic area affects readers\' money, health or legal standing. Make only claims you can support, avoid absolute promises, and recommend consulting a qualified professional where a decision has real consequences.';
        }

        return $rules;
    }

    /** Type-appropriate framing appended to the CTA rule (Phase F). */
    private function ctaFraming(ContentPlan $plan): string
    {
        if (! \App\Support\ContentSiteTypeProfiles::isValid($plan->site_type)) {
            return '';
        }

        return match (\App\Support\ContentSiteTypeProfiles::profile($plan->site_type)['cta_style']) {
            'product' => ' — invite the reader to explore the relevant product or collection there',
            'category' => ' — point the reader to the matching product category there',
            'contact' => ' — invite the reader to request a quote or book a visit there',
            'trial' => ' — invite the reader to try it themselves there',
            'consultation' => ' — invite the reader to book a consultation there',
            'subscribe' => ' — invite the reader to subscribe or read more there',
            'support' => ' — invite the reader to get involved or support the cause there',
            'course' => ' — invite the reader to check out the course or newsletter there',
            'platform' => ' — invite the reader to browse the listings there',
            'enroll' => ' — invite the reader to enroll or start learning there',
            default => '',
        };
    }

    /**
     * On-page SEO rules mirroring the Serfix WP plugin's on-page self-check,
     * so drafts pass it without a revise round: focus keyphrase in the intro
     * + a subheading + spread through the body at a healthy density, every
     * additional keyphrase present in the body (never crammed into title/H1),
     * and a 50-60 char SEO title. Keep it natural — these are targets, not a
     * license to keyword-stuff.
     *
     * @return list<string>
     */
    private function onPageSeoRules(ContentTopic $topic): array
    {
        $kw = trim((string) $topic->target_keyword);
        if ($kw === '') {
            return [];
        }
        // Rules mirror the Serfix WP plugin's on-page analyzer exactly (it
        // grabs the first <p> as the intro, splits the body into thirds for
        // distribution, and matches the EXACT phrase). Getting these right in
        // the draft avoids revise rounds.
        $rules = [
            "ON-PAGE SEO — focus keyphrase is \"{$kw}\". These are STRICT requirements; hit every one:",
            "- Put the EXACT phrase \"{$kw}\" in the FIRST sentence of the opening paragraph.",
            "- Use the EXACT phrase \"{$kw}\" again in the MIDDLE third and again in the CLOSING third — it must appear in the intro, the middle, AND the end (all three).",
            '- Keyphrase DENSITY: the exact phrase must be 0.5%-2.5% of the total words — roughly once every 120-160 words (about 8-12 times in a 1,800-word article). Weave each mention into a natural sentence; spread them evenly, never cluster. This density is required for the on-page score.',
            "- Use the EXACT phrase \"{$kw}\" (or a very close variant) in at least one H2 or H3 subheading.",
            "- SEO/meta title: 50-60 characters MAX (never exceed 60), LEAD with \"{$kw}\", and include one CTR power word (e.g. Ultimate, Complete, Essential, Proven, Best, Easy, Guide).",
            '- Meta description: 130-155 characters (never exceed 155) and it must contain the exact focus keyphrase.',
        ];

        $additional = array_values(array_filter(array_map(
            static fn ($k): string => trim((string) $k),
            (array) ($topic->secondary_keywords ?? [])
        )));
        if ($additional !== []) {
            $list = '"'.implode('", "', array_slice($additional, 0, 8)).'"';
            $rules[] = "- Each of these additional keyphrases must appear VERBATIM in the body at least once (a natural sentence is fine); put at least one or two of them inside an H2/H3 subheading. Do NOT reword them — use the exact phrase. Keep them out of the title and H1 (reserved for the focus keyphrase): {$list}.";
        }

        return $rules;
    }
}
