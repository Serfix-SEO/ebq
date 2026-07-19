<?php

namespace App\Livewire\Content;

use App\Models\ContentArticle;
use App\Models\ContentTopic;
use App\Services\AiToolRunner;
use App\Services\Content\ContentSeoScorer;
use App\Services\Content\HumanizerService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Review one topic's current article: rendered preview, SEO score, the
 * plain-language improvement list, and the approve / new-draft actions.
 *
 * Now also an EDITOR (2026-07-18): the body is a contenteditable surface
 * (Alpine-managed inside wire:ignore so Livewire re-renders never clobber
 * the caret), the meta fields are editable, on-page checks re-score LIVE on
 * a debounce (same ContentSeoScorer the pipeline uses — one source of
 * truth with the WP plugin's rules), text selection offers AI actions
 * (rewrite/simplify/shorten/expand/grammar/tone) through the SAME
 * AiToolRunner tools the WP plugin's inline editor calls, and Save stores
 * a new article version (append-only, is_current moves forward).
 *
 * Tenancy: the topic must belong to a website the auth user can access —
 * resolved through accessibleWebsitesQuery(), never trusted from the URL.
 */
class ArticleReview extends Component
{
    public string $topicId;

    // ── Editable state ──
    public bool $editing = false;
    public string $bodyHtml = '';
    public string $editH1 = '';
    public string $editMetaTitle = '';
    public string $editMetaDescription = '';

    /** @var list<array{code:string, passed:bool, label:string}> */
    public array $liveChecks = [];
    public int $liveScore = 0;

    /** Cached once per request-cycle: site_urls/existing_titles are DB reads. */
    protected ?array $scorerContext = null;

    public function mount(string $topicId): void
    {
        $this->topicId = $topicId;
        abort_unless($this->topic() !== null, 404);
        $this->hydrateFromArticle();
    }

    private function topic(): ?ContentTopic
    {
        $websiteIds = Auth::user()?->accessibleWebsitesQuery()->select('id');
        if ($websiteIds === null) {
            return null;
        }

        return ContentTopic::query()
            ->whereKey($this->topicId)
            ->whereIn('website_id', $websiteIds)
            ->first();
    }

    private function hydrateFromArticle(): void
    {
        $article = $this->topic()?->currentArticle;
        // Sanitized at hydration: this is a public Livewire prop, so raw
        // script/on* markup would otherwise ride into the page snapshot.
        $this->bodyHtml = $this->sanitize((string) ($article?->html ?? ''));
        $this->editH1 = (string) ($article?->h1 ?? '');
        $this->editMetaTitle = (string) ($article?->meta_title ?? '');
        $this->editMetaDescription = (string) ($article?->meta_description ?? '');
        $this->refreshChecks();
    }

    public function startEditing(): void
    {
        $this->editing = true;
        $this->refreshChecks();
    }

    public function cancelEditing(): void
    {
        $this->editing = false;
        $this->hydrateFromArticle();
    }

    /** Debounced from the editor (body) and the meta inputs. */
    public function rescore(?string $html = null): void
    {
        if ($html !== null) {
            $this->bodyHtml = $html;
        }
        $this->refreshChecks();
    }

    /** Meta-field edits re-score live too. */
    public function updated(string $property): void
    {
        if (in_array($property, ['editH1', 'editMetaTitle', 'editMetaDescription'], true)) {
            $this->refreshChecks();
        }
    }

    /** Persist edits as a NEW article version (append-only audit trail). */
    public function saveEdits(?string $html = null): void
    {
        if ($html !== null) {
            $this->bodyHtml = $html;
        }
        $topic = $this->topic();
        $article = $topic?->currentArticle;
        if ($topic === null || $article === null || trim($this->bodyHtml) === '') {
            return;
        }

        $clean = $this->sanitize($this->bodyHtml);
        $result = $this->scoreCurrent($clean);
        $text = trim(html_entity_decode(strip_tags($clean)));

        ContentArticle::storeVersion($topic, [
            'h1' => mb_substr(trim($this->editH1) !== '' ? trim($this->editH1) : (string) $article->h1, 0, 300),
            'meta_title' => mb_substr(trim($this->editMetaTitle), 0, 300),
            'meta_description' => mb_substr(trim($this->editMetaDescription), 0, 500),
            'slug' => $article->slug,
            'outline' => $article->outline,
            'html' => $clean,
            'markdown' => $article->markdown,
            'word_count' => str_word_count($text),
            'seo_score' => $result['score'],
            'seo_issues' => $result['issues'],
            'style_issues' => $result['style_issues'],
            'generation_meta' => ['edited_by' => 'client', 'edited_at' => now()->toIso8601String(), 'base_version' => $article->version],
        ]);

        $this->editing = false;
        $this->hydrateFromArticle();
        session()->flash('review-status', __('Your changes are saved as a new draft version.'));
    }

    /**
     * Select-text AI action — the SAME tools the WP plugin's inline editor
     * uses (rewrite-content, simplify-content, shorten-content,
     * expand-content, fix-grammar, change-tone), run through AiToolRunner so
     * gating/credits behave identically. Returns the replacement text (or
     * null + an error flash).
     */
    public function aiEdit(string $tool, string $text, ?string $tone = null): ?string
    {
        $allowed = ['rewrite-content', 'simplify-content', 'shorten-content', 'expand-content', 'fix-grammar', 'change-tone'];
        $text = trim($text);
        if (! in_array($tool, $allowed, true) || $text === '' || mb_strlen($text) > 6000) {
            return null;
        }
        $topic = $this->topic();
        $website = $topic?->website;
        if ($website === null) {
            return null;
        }

        $input = ['text' => $text];
        if ($tool === 'change-tone') {
            $input['target_tone'] = in_array($tone, ['formal', 'casual', 'empathetic', 'authoritative', 'playful', 'concise'], true) ? $tone : 'formal';
        }

        $result = app(AiToolRunner::class)->run($tool, $website, Auth::id(), $input);
        if (! $result->ok || ! is_string($result->value) || trim($result->value) === '') {
            $this->dispatch('ai-edit-failed', message: (string) ($result->message ?: __('The AI edit did not complete. Try again.')));

            return null;
        }

        return trim($result->value);
    }

    public function approve(): void
    {
        $topic = $this->topic();
        if ($topic !== null && $topic->status === ContentTopic::STATUS_READY) {
            $topic->update(['status' => ContentTopic::STATUS_SCHEDULED]);
        }
    }

    /** Re-run generation after a failure (from the in-flight progress card). */
    public function retryGeneration(): void
    {
        $topic = $this->topic();
        if ($topic === null || in_array($topic->status, ContentTopic::IN_FLIGHT, true)) {
            return;
        }
        $topic->forceFill(['status' => ContentTopic::STATUS_APPROVED, 'last_error' => null, 'stage_started_at' => now()])->save();
        \Illuminate\Support\Facades\Cache::put('content:gen-start:'.$topic->id, now()->timestamp, now()->addHour());
        \App\Jobs\ProduceContentArticleJob::dispatch($topic->id);
    }


    // ── live scoring ────────────────────────────────────────────────────

    private function refreshChecks(): void
    {
        $result = $this->scoreCurrent($this->sanitize($this->bodyHtml));
        $this->liveScore = (int) $result['score'];
        $this->liveChecks = array_map(fn (array $c) => [
            'code' => (string) $c['code'],
            'passed' => (bool) $c['passed'],
            'label' => self::checkLabel((string) $c['code']),
        ], $result['checks']);
    }

    /** @return array{score:int, issues:array, checks:array, style_issues:array} */
    private function scoreCurrent(string $html): array
    {
        $topic = $this->topic();
        $article = $topic?->currentArticle;
        if ($topic === null || $article === null) {
            return ['score' => 0, 'issues' => [], 'checks' => [], 'style_issues' => []];
        }

        $context = $this->scorerContext ??= $this->buildScorerContext($topic);
        $styleIssues = app(HumanizerService::class)->lint(html_entity_decode(strip_tags($html)));
        $context['style_issues'] = $styleIssues;

        $result = app(ContentSeoScorer::class)->score(
            $html,
            trim($this->editMetaTitle),
            trim($this->editMetaDescription),
            trim($this->editH1) !== '' ? trim($this->editH1) : (string) $article->h1,
            (string) $article->slug,
            $context,
        );
        $result['style_issues'] = $styleIssues;

        return $result;
    }

    private function buildScorerContext(ContentTopic $topic): array
    {
        $plan = $topic->plan;
        $website = $topic->website;
        $siteUrls = [];
        $existingTitles = [];
        try {
            if ($website?->crawl_site_id) {
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
            // no crawl data — scorer renormalizes
        }

        return [
            'target_keyword' => (string) $topic->target_keyword,
            'secondary_keywords' => (array) ($topic->secondary_keywords ?? []),
            'site_host' => mb_strtolower((string) ($website?->domain ?? '')),
            'site_urls' => $siteUrls,
            'existing_titles' => $existingTitles,
            'article_length' => (int) ($plan?->article_length ?? 2000),
            'toggles' => [
                'toc' => (bool) $plan?->toggle('toc'),
                'key_takeaways' => (bool) $plan?->toggle('key_takeaways'),
                'faq' => (bool) $plan?->toggle('faq'),
                'external_links' => (bool) $plan?->toggle('external_links'),
                'cta_enabled' => (bool) $plan?->toggle('cta_enabled'),
            ],
            'cta_url' => (string) ($plan?->cta_url ?? ''),
            'language' => (string) ($plan?->language ?: 'en'),
        ];
    }

    // ── labels ──────────────────────────────────────────────────────────

    /** Plain-language labels for scorer issue codes (client-safe copy). */
    public static function issueLabel(string $code): string
    {
        return match ($code) {
            'kw_in_meta_title', 'kw_in_h1', 'kw_in_meta_description',
            'kw_in_first_words', 'kw_in_slug', 'kw_in_a_heading', 'kw_in_intro' => __('Keyword placement can be stronger'),
            'meta_title_length', 'meta_description_length', 'h1_length' => __('Title or description length needs a tweak'),
            'title_power_word' => __('Title could use a stronger word'),
            'word_count' => __('Article length is off target'),
            'h2_count', 'no_orphan_h3', 'heading_not_stuffed' => __('Section structure can be improved'),
            'kw_density', 'kw_distribution', 'secondary_coverage' => __('Keyword usage can be more natural'),
            'internal_links', 'internal_links_valid', 'link_density' => __('Internal linking can be improved'),
            'external_link' => __('An authoritative source link is missing'),
            'img_alt_text' => __('Image descriptions need work'),
            'sentence_length', 'paragraph_length' => __('Readability can be improved'),
            'title_unique' => __('Too similar to an existing page'),
            'key_takeaways_present', 'faq_present', 'cta_present' => __('A requested section is missing'),
            'style_clean' => __('Writing style needs polish'),
            default => __('Minor improvement available'),
        };
    }

    /** Short per-check labels for the live checklist (plugin-style rows). */
    public static function checkLabel(string $code): string
    {
        return match ($code) {
            'kw_in_meta_title' => __('Keyphrase in SEO title'),
            'meta_title_length' => __('SEO title length (40–60)'),
            'title_power_word' => __('Power word in title'),
            'kw_in_h1' => __('Keyphrase in H1'),
            'h1_length' => __('H1 length'),
            'kw_in_meta_description' => __('Keyphrase in meta description'),
            'meta_description_length' => __('Meta description length (130–155)'),
            'kw_in_first_words' => __('Keyphrase in the opening words'),
            'kw_in_intro' => __('Keyphrase in the first paragraph'),
            'kw_in_slug' => __('Keyphrase in URL'),
            'kw_density' => __('Keyphrase density (0.5–2.5%)'),
            'kw_distribution' => __('Keyphrase spread across the article'),
            'secondary_coverage' => __('Additional keyphrases covered'),
            'word_count' => __('Article length'),
            'h2_count' => __('Enough sections (H2s)'),
            'kw_in_a_heading' => __('Keyphrase in a subheading'),
            'no_orphan_h3' => __('Heading structure'),
            'heading_not_stuffed' => __('Headings not keyword-stuffed'),
            'key_takeaways_present' => __('Key takeaways box'),
            'faq_present' => __('FAQ section'),
            'cta_present' => __('Call-to-action link'),
            'internal_links' => __('Internal links'),
            'internal_links_valid' => __('Internal links point to real pages'),
            'external_link' => __('Authoritative external source'),
            'link_density' => __('Link density'),
            'img_alt_text' => __('Image alt text'),
            'sentence_length' => __('Sentence length'),
            'paragraph_length' => __('Paragraph length'),
            'title_unique' => __('Title is unique on your site'),
            'style_clean' => __('Natural writing style'),
            default => __('Quality check'),
        };
    }

    /**
     * A FAIR per-article traffic estimate from `keyword_volume` (no extra API
     * cost). Deliberately conservative: this is what a NEW article realistically
     * earns settling mid-page-1 over time — NOT the ~28% a #1 ranking captures.
     * The headline is the low end so the number reads as achievable, not hype
     * (e.g. 550 searches/mo → "~8 extra visitors/mo", band 8–28).
     *
     * @return array{volume:int, low:int, high:int, ctr_low:float, ctr_high:float}|null
     */
    public static function trafficWorth(ContentTopic $topic): ?array
    {
        $band = ContentCalendar::fairMonthlyVisits($topic);
        if ($band === null) {
            return null;
        }

        return [
            'volume' => (int) $topic->keyword_volume,
            'low' => $band['low'],
            'high' => $band['high'],
            'ctr_low' => 1.5,
            'ctr_high' => 5.0,
        ];
    }

    /** Strip anything active before rendering/storing. */
    private function sanitize(string $html): string
    {
        $html = preg_replace('/<(script|style|iframe|object|embed)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $html = preg_replace('/\son\w+="[^"]*"/i', '', $html) ?? $html;
        $html = preg_replace("/\son\w+='[^']*'/i", '', $html) ?? $html;

        return $html;
    }

    /**
     * Live generation progress for the in-flight state (article not written
     * yet). Same five client-facing steps the calendar uses.
     *
     * @return array{steps:list<array{label:string,state:string}>, etaText:string, failed:bool}
     */
    private function generationProgress(ContentTopic $topic, int $genStart): array
    {
        $stageOf = [
            ContentTopic::STATUS_APPROVED => 'research',
            ContentTopic::STATUS_RESEARCHING => 'research',
            ContentTopic::STATUS_WRITING => 'write',
            ContentTopic::STATUS_SCORING => 'polish',
            ContentTopic::STATUS_REVISING => 'polish',
        ];
        $order = ['research', 'write', 'polish', 'images', 'done'];
        $labels = [
            'research' => __('Researching your topic'),
            'write' => __('Writing the first draft'),
            'polish' => __('Optimizing for SEO & readability'),
            'images' => __('Creating images'),
            'done' => __('Ready for review'),
        ];
        $failed = $topic->status === ContentTopic::STATUS_FAILED;
        $currentIdx = array_search($stageOf[$topic->status] ?? 'research', $order, true) ?: 0;

        $steps = [];
        foreach ($order as $i => $key) {
            $steps[] = [
                'label' => $labels[$key],
                'state' => $failed ? ($i === 0 ? 'failed' : 'pending')
                    : ($i < $currentIdx ? 'done' : ($i === $currentIdx ? 'active' : 'pending')),
            ];
        }

        $elapsed = $genStart > 0 ? max(0, now()->timestamp - $genStart) : 0;
        $etaSeconds = max(0, 165 - $elapsed);
        $etaText = $failed ? __('Stopped')
            : ($etaSeconds > 90 ? __('about 2–3 minutes left')
                : ($etaSeconds > 30 ? __('about a minute left')
                    : __('almost there…')));

        return ['steps' => $steps, 'etaText' => $etaText, 'failed' => $failed];
    }

    public function render()
    {
        $topic = $this->topic();
        $article = $topic?->currentArticle;

        // No article yet: are we actively generating it? True while the topic
        // is in a pipeline stage, or just-dispatched (gen-start cached) and
        // still APPROVED before the worker picks it up.
        $generating = false;
        $progress = null;
        if ($topic !== null && $article === null) {
            $genStart = (int) \Illuminate\Support\Facades\Cache::get('content:gen-start:'.$topic->id, 0);
            $generating = in_array($topic->status, ContentTopic::IN_FLIGHT, true)
                || ($genStart > 0 && $topic->status === ContentTopic::STATUS_APPROVED);
            if ($generating || $topic->status === ContentTopic::STATUS_FAILED) {
                $progress = $this->generationProgress($topic, $genStart);
            }
        }

        $issueLabels = collect((array) ($article?->seo_issues ?? []))
            ->pluck('code')
            ->map(fn ($code) => self::issueLabel((string) $code))
            ->unique()
            ->values();

        return view('livewire.content.article-review', [
            'topic' => $topic,
            'article' => $article,
            'generating' => $generating,
            'progress' => $progress,
            'previewHtml' => $this->sanitize((string) ($article?->html ?? '')),
            'issueLabels' => $issueLabels,
            'traffic' => $topic ? self::trafficWorth($topic) : null,
            'presentation' => $topic ? ContentCalendar::statusPresentation($topic->status) : null,
        ]);
    }
}
