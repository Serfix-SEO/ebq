<?php

namespace App\Services\Content;

use App\Models\ContentArticle;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\Website;
use App\Services\AiContentBriefService;
use App\Services\AiWriterService;
use App\Services\Llm\LlmClientFactory;
use App\Support\ContentAutopilotConfig;
use Illuminate\Support\Facades\DB;
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
            'custom_prompt' => $this->templateInstructions($plan)
                .(($urls = array_slice((array) ($context['site_urls'] ?? []), 0, 15)) === [] ? ''
                    : "\nInternal pages you may link to (2-3, exact URLs only):\n".implode("\n", $urls)),
            '__user_id' => $website->user_id,
        ];
        if (! empty($writeModel['model'])) {
            $draftInput['model'] = $writeModel['model'];
        }

        try {
            $draft = $writer->draft($website, 0, $draftInput);
        } catch (\App\Exceptions\QuotaExceededException $e) {
            // The owner's plan ran out of AI tokens — an EXPECTED operational
            // state, not a crash. The topic parks as failed with an internal
            // marker; client copy stays neutral ("Needs attention").
            $topic->fail('llm_quota_exhausted');

            return null;
        }
        $this->meter->add(ContentLlmSpendMeter::EST_WRITE_USD);
        if (! ($draft['ok'] ?? false)) {
            $topic->fail('draft_failed: '.(string) ($draft['error'] ?? 'unknown'));

            return null;
        }

        $html = $this->humanizer->clean($this->assembleHtml($draft));
        $h1 = (string) ($draft['h1'] ?? '') !== '' ? (string) $draft['h1'] : $topic->title;
        $metaTitle = mb_substr($h1, 0, 60);
        $metaDescription = mb_substr(trim((string) ($draft['summary'] ?? '')), 0, 158);
        $slug = Str::slug(mb_substr($topic->target_keyword.' '.Str::limit($h1, 40, ''), 0, 90));

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

        while ($article->seo_score < $target && $iteration < $maxRevisions) {
            $iteration++;
            $topic->enterStage(ContentTopic::STATUS_REVISING);

            try {
                $revised = $this->revise($article, $topic, $plan);
            } catch (\App\Exceptions\QuotaExceededException) {
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

            if ($article->seo_score <= $previousScore + 2) {
                break; // diminishing returns
            }
        }

        // ── Verdict ─────────────────────────────────────────────────────
        if ($article->seo_score < ContentAutopilotConfig::publishFloor()) {
            $topic->fail('below_publish_floor: score '.$article->seo_score);

            return $article;
        }

        $topic->enterStage(ContentTopic::STATUS_READY);

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
            $html = $this->buildToc($html).$html;
        }

        return $html;
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
            $label = trim(strip_tags($m[3]));
            if ($label === '') {
                continue;
            }
            $isH3 = strtolower($m[1]) === 'h3';
            $items[] = '<li class="content-toc__item'.($isH3 ? ' content-toc__item--sub' : '').'">'
                .'<a href="#'.e($m[2]).'">'.e($label).'</a></li>';
        }

        return '<nav class="content-toc" aria-label="Table of contents">'
            .'<p class="content-toc__title">In this article</p>'
            .'<ul>'.implode('', $items).'</ul></nav>'."\n";
    }

    /** Score + persist as the next version, folding in the style lint. */
    private function storeScoredVersion(ContentTopic $topic, array $context, array $attributes): ContentArticle
    {
        $topic->enterStage(ContentTopic::STATUS_SCORING);

        $attributes['html'] = $this->normalizeStructure(
            (string) ($attributes['html'] ?? ''),
            (string) ($attributes['h1'] ?? ''),
            (bool) (($context['toggles'] ?? [])['toc'] ?? false),
        );
        $html = (string) $attributes['html'];
        $styleIssues = $this->humanizer->lint($html);
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
    private function templateInstructions(ContentPlan $plan): string
    {
        $rules = [];
        if ($plan->toggle('key_takeaways')) {
            $rules[] = 'Open with a "Key takeaways" box: 3-5 short bullet points summarizing the article.';
        }
        if ($plan->toggle('faq')) {
            $rules[] = 'End with an FAQ section (H2) answering 3-5 real questions searchers ask.';
        }
        if ($plan->toggle('cta_enabled') && $plan->cta_url) {
            $rules[] = 'Include one natural call-to-action linking to '.$plan->cta_url.' where it genuinely helps the reader.';
        }
        if ($plan->toggle('external_links')) {
            $rules[] = 'Cite at least one authoritative external source with a link.';
        }
        $rules[] = 'Article length target: about '.$plan->article_length.' words.';
        $rules[] = "Today's date is ".now()->toFormattedDateString().'. Any year you mention must be '
            .now()->year.' unless you are referring to a genuinely historical fact.';
        if (trim((string) $plan->custom_instructions) !== '') {
            $rules[] = trim((string) $plan->custom_instructions);
        }

        return implode("\n", $rules)."\n".$this->humanizer->promptRules();
    }
}
