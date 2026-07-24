<?php

namespace App\Livewire\Content;

use App\Jobs\ProduceContentArticleJob;
use App\Jobs\PublishContentArticleJob;
use App\Models\ContentArticle;
use App\Models\ContentImage;
use App\Models\ContentIntegration;
use App\Models\ContentTopic;
use App\Services\AiToolRunner;
use App\Services\Content\CompetitorMentionGuard;
use App\Services\Content\ContentEntitlements;
use App\Services\Content\ContentSeoScorer;
use App\Services\Content\HumanizerService;
use App\Support\ContentAutopilotConfig;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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

    // ── Per-article SEO overrides (mirror the WP plugin's post-edit sidebar).
    //    Edit-only: they apply to THIS article and ride into the plugin's
    //    `_ebq_*` meta / webhook payload on publish. ──
    public string $editFocusKeyword = '';

    public string $editSlug = '';

    public string $editCanonical = '';

    public bool $editNoindex = false;

    public bool $editNofollow = false;

    public string $editOgTitle = '';

    public string $editOgDescription = '';

    public string $editOgImage = '';

    public string $editTwitterTitle = '';

    public string $editTwitterDescription = '';

    public string $editTwitterImage = '';

    public string $editTwitterCard = 'summary_large_image';

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
        // SEO overrides — focus keyword defaults to the topic's target when the
        // article carries no explicit override (so the field is never blank).
        $this->editFocusKeyword = (string) ($article?->focus_keyword ?: $this->topic()?->target_keyword ?? '');
        $this->editSlug = (string) ($article?->slug ?? '');
        $this->editCanonical = (string) ($article?->canonical_url ?? '');
        $this->editNoindex = (bool) ($article?->robots_noindex ?? false);
        $this->editNofollow = (bool) ($article?->robots_nofollow ?? false);
        $this->editOgTitle = (string) ($article?->og_title ?? '');
        $this->editOgDescription = (string) ($article?->og_description ?? '');
        $this->editOgImage = (string) ($article?->og_image ?? '');
        $this->editTwitterTitle = (string) ($article?->twitter_title ?? '');
        $this->editTwitterDescription = (string) ($article?->twitter_description ?? '');
        $this->editTwitterImage = (string) ($article?->twitter_image ?? '');
        $this->editTwitterCard = (string) ($article?->twitter_card ?: 'summary_large_image');
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
        if (in_array($property, ['editH1', 'editMetaTitle', 'editMetaDescription', 'editFocusKeyword', 'editSlug'], true)) {
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

        // Focus keyword is stored as an override only when it diverges from the
        // topic's target — an unchanged value stays null so re-targeting the
        // topic later still flows through.
        $focus = trim($this->editFocusKeyword);
        $focusOverride = ($focus !== '' && $focus !== trim((string) $topic->target_keyword)) ? mb_substr($focus, 0, 200) : null;
        $slug = trim($this->editSlug) !== '' ? mb_substr(trim($this->editSlug), 0, 200) : $article->slug;

        ContentArticle::storeVersion($topic, [
            'h1' => mb_substr(trim($this->editH1) !== '' ? trim($this->editH1) : (string) $article->h1, 0, 300),
            'meta_title' => mb_substr(trim($this->editMetaTitle), 0, 300),
            'meta_description' => mb_substr(trim($this->editMetaDescription), 0, 500),
            'slug' => $slug,
            'focus_keyword' => $focusOverride,
            'canonical_url' => mb_substr(trim($this->editCanonical), 0, 500) ?: null,
            'robots_noindex' => $this->editNoindex,
            'robots_nofollow' => $this->editNofollow,
            'og_title' => mb_substr(trim($this->editOgTitle), 0, 300) ?: null,
            'og_description' => mb_substr(trim($this->editOgDescription), 0, 500) ?: null,
            'og_image' => mb_substr(trim($this->editOgImage), 0, 500) ?: null,
            'twitter_title' => mb_substr(trim($this->editTwitterTitle), 0, 300) ?: null,
            'twitter_description' => mb_substr(trim($this->editTwitterDescription), 0, 500) ?: null,
            'twitter_image' => mb_substr(trim($this->editTwitterImage), 0, 500) ?: null,
            'twitter_card' => in_array($this->editTwitterCard, ['summary', 'summary_large_image', 'app', 'player'], true) ? $this->editTwitterCard : 'summary_large_image',
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

    /**
     * Publish this article to the connected destination(s) immediately, bypassing
     * the plan's publish window. Mirrors ContentCalendar::publishNow — requires a
     * connected integration, flips to PUBLISHING so the page shows progress.
     */
    public function publishNow(): void
    {
        $topic = $this->topic();
        if (! ContentCalendar::publishableNow($topic)) {
            return; // gone, wrong status, or scheduled for a future day
        }
        $connected = (bool) $topic->plan?->website
            ?->contentIntegrations()
            ->where('status', ContentIntegration::STATUS_CONNECTED)
            ->exists();
        if (! $connected) {
            session()->flash('review-status', __('Connect a site in Settings → Integrations before publishing.'));

            return;
        }
        $topic->enterStage(ContentTopic::STATUS_PUBLISHING);
        PublishContentArticleJob::dispatch($topic->id);
        session()->flash('review-status', __('Publishing now — it can take a moment to appear on your site.'));
    }

    /** Re-run generation after a failure (from the in-flight progress card). */
    public function retryGeneration(): void
    {
        $topic = $this->topic();
        if ($topic === null || in_array($topic->status, ContentTopic::IN_FLIGHT, true)) {
            return;
        }
        if (($reason = app(ContentEntitlements::class)->blockReason($topic)) !== null) {
            session()->flash('review-status', ContentCalendar::generationBlockMessage($reason));

            return;
        }
        $topic->forceFill(['status' => ContentTopic::STATUS_APPROVED, 'last_error' => null, 'stage_started_at' => now()])->save();
        Cache::put('content:gen-start:'.$topic->id, now()->timestamp, now()->addHour());
        ProduceContentArticleJob::dispatch($topic->id);
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
            // Only failing rows carry a fix hint (passing rows need none).
            'hint' => ((bool) $c['passed']) ? '' : self::checkHint((string) $c['code']),
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
        // Focus-keyword override: when the client edits the focus keyphrase for
        // this article, the live audit re-scores against IT (falls back to the
        // topic's target_keyword baked into the context otherwise).
        if (($fk = trim($this->editFocusKeyword)) !== '') {
            $context['target_keyword'] = $fk;
        }
        // Same competitor-mention rules the producer enforced, so the live
        // checks and the pipeline can never disagree about an edit.
        $guard = app(CompetitorMentionGuard::class);
        $guardPlan = $topic->plan;
        $styleIssues = app(HumanizerService::class)->lint(
            html_entity_decode(strip_tags($html)),
            $guardPlan !== null ? $guard->termsForTopic($guardPlan, $topic) : [],
            // strip_tags removes hrefs, so link-lint the RAW html separately is
            // pointless here; mentions are the editable risk in the editor.
        );
        $context['style_issues'] = $styleIssues;

        $result = app(ContentSeoScorer::class)->score(
            $html,
            trim($this->editMetaTitle),
            trim($this->editMetaDescription),
            trim($this->editH1) !== '' ? trim($this->editH1) : (string) $article->h1,
            trim($this->editSlug) !== '' ? trim($this->editSlug) : (string) $article->slug,
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
     * Actionable "how to fix" for a FAILING check — shown under the row so the
     * client knows what to change, not just that something's off. Client-safe
     * copy (no internal jargon). Keyed to the same scorer codes as checkLabel.
     */
    public static function checkHint(string $code): string
    {
        return match ($code) {
            'kw_in_meta_title' => __('Add your focus keyphrase to the SEO title.'),
            'meta_title_length' => __('Aim for 40–60 characters so it doesn\'t get cut off in Google.'),
            'title_power_word' => __('Add a compelling word (e.g. “best”, “proven”, “ultimate”) to lift clicks.'),
            'kw_in_h1' => __('Include the focus keyphrase in the headline (H1).'),
            'h1_length' => __('Keep the headline concise — aim for under ~60 characters.'),
            'kw_in_meta_description' => __('Work the focus keyphrase naturally into the meta description.'),
            'meta_description_length' => __('Write 130–155 characters that earn the click.'),
            'kw_in_first_words' => __('Use the focus keyphrase within the first sentence or two.'),
            'kw_in_intro' => __('Mention the focus keyphrase in the opening paragraph.'),
            'kw_in_slug' => __('Add the focus keyphrase to the URL slug.'),
            'kw_density' => __('Use the keyphrase a little more (or less) — target 0.5–2.5% of the text.'),
            'kw_distribution' => __('Spread the keyphrase more evenly across the whole article.'),
            'secondary_coverage' => __('Cover your additional keyphrases at least once each.'),
            'word_count' => __('Adjust the length toward the target for this topic.'),
            'h2_count' => __('Break the article into more sections with H2 subheadings.'),
            'kw_in_a_heading' => __('Put the focus keyphrase in at least one subheading.'),
            'no_orphan_h3' => __('Fix the heading order — an H3 should sit under an H2.'),
            'heading_not_stuffed' => __('Make headings read naturally — don\'t repeat the keyphrase in every one.'),
            'key_takeaways_present' => __('Add the Key takeaways box (enabled in this plan).'),
            'faq_present' => __('Add an FAQ section (enabled in this plan).'),
            'cta_present' => __('Add the call-to-action link (enabled in this plan).'),
            'internal_links' => __('Link to a few relevant pages on your own site.'),
            'internal_links_valid' => __('Point internal links to pages that actually exist.'),
            'external_link' => __('Cite at least one authoritative external source.'),
            'link_density' => __('Ease up on the number of links relative to the text.'),
            'img_alt_text' => __('Add descriptive alt text to your images.'),
            'sentence_length' => __('Shorten some long sentences for easier reading.'),
            'paragraph_length' => __('Break up long paragraphs — aim for short, scannable blocks.'),
            'title_unique' => __('This title looks similar to another page on your site — make it distinct.'),
            'style_clean' => __('Smooth out a few phrases so it reads more naturally.'),
            default => __('A small tweak will improve this.'),
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
    /**
     * True while the article is written (topic READY) but its images are still
     * being created by the chained job — part of "finalized" for the user. Self
     * limits: stops once ≥1 image lands or after a bounded wait, and only when
     * images are actually enabled for this plan.
     */
    private function imagesStillGenerating(ContentTopic $topic, ?ContentArticle $article, int $genStart): bool
    {
        if ($article === null || $topic->status !== ContentTopic::STATUS_READY) {
            return false;
        }
        $plan = $topic->plan;
        if ($plan === null || $plan->images_enabled === false
            || ! ContentAutopilotConfig::imagesEnabled()) {
            return false;
        }
        // The images job is actively running (it sets this flag for its whole
        // lifetime, so we keep the overlay up until ALL images are done, not
        // just until the first one lands).
        if (Cache::has('content:images:running:'.$topic->id)) {
            return true;
        }
        // Job finished and produced at least one image → done.
        if ($article->images()->where('status', ContentImage::STATUS_GENERATED)->exists()) {
            return false;
        }
        // Just became READY inside an ACTIVE generation (gen-start cached by
        // writeNow/dispatch): the chained images job is dispatched but may not
        // have started yet (queue latency). Hold the overlay for a short grace
        // from the READY moment so there's no flash of the un-imaged draft
        // before the job picks up. Old/manually-seeded ready articles have no
        // gen-start, so they show their finalized view immediately.
        if ($genStart <= 0) {
            return false;
        }
        $readyTs = $article->updated_at?->timestamp ?? 0;

        return $readyTs > 0 && (now()->timestamp - $readyTs) < 120;
    }

    private function generationProgress(ContentTopic $topic, int $genStart, bool $imagesPending = false): array
    {
        $stageOf = [
            ContentTopic::STATUS_APPROVED => 'research',
            ContentTopic::STATUS_RESEARCHING => 'research',
            ContentTopic::STATUS_WRITING => 'write',
            ContentTopic::STATUS_SCORING => 'polish',
            ContentTopic::STATUS_REVISING => 'polish',
            ContentTopic::STATUS_READY => 'images',
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

        // On failure, mark the stage that ACTUALLY failed (from the error
        // marker) rather than always blaming "research" — research is fail-soft,
        // so real failures are almost always the write/optimize stage.
        $failedIdx = 0;
        if ($failed) {
            $err = (string) $topic->last_error;
            $failedKey = str_contains($err, 'below_publish_floor') ? 'polish'
                : (str_contains($err, 'missing plan') ? 'research' : 'write');
            $failedIdx = array_search($failedKey, $order, true) ?: 0;
        }

        $steps = [];
        foreach ($order as $i => $key) {
            $steps[] = [
                'label' => $labels[$key],
                'state' => $failed
                    ? ($i < $failedIdx ? 'done' : ($i === $failedIdx ? 'failed' : 'pending'))
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

        // Keep the live progress overlay (with the draft blurred behind it) for
        // the WHOLE build, not just before the first draft exists: the writer
        // stores a draft the moment writing starts, but the topic is still
        // researching/writing/scoring/REVISING — and images generate after the
        // READY flip — so the user must keep seeing progress until everything is
        // finalized, not a half-optimised draft.
        $generating = false;
        $progress = null;
        $imagesPending = false;
        if ($topic !== null) {
            $genStart = (int) Cache::get('content:gen-start:'.$topic->id, 0);
            // GENERATION stages only — PUBLISHING is in-flight too but must NOT
            // trigger the "writing" overlay; it keeps the article view + a
            // Publishing badge.
            $inFlight = in_array($topic->status, [
                ContentTopic::STATUS_RESEARCHING, ContentTopic::STATUS_WRITING,
                ContentTopic::STATUS_SCORING, ContentTopic::STATUS_REVISING,
            ], true) || ($genStart > 0 && $topic->status === ContentTopic::STATUS_APPROVED);
            $imagesPending = $this->imagesStillGenerating($topic, $article, $genStart);
            // Actively finalizing, OR failed before any draft (show the retry card).
            $generating = $inFlight || $imagesPending;
            $failedNoDraft = $article === null && $topic->status === ContentTopic::STATUS_FAILED;
            if ($generating || $failedNoDraft) {
                $progress = $this->generationProgress($topic, $genStart, $imagesPending);
                $generating = $generating || $failedNoDraft;
            }
        }

        $issueLabels = collect((array) ($article?->seo_issues ?? []))
            ->pluck('code')
            ->map(fn ($code) => self::issueLabel((string) $code))
            ->unique()
            ->values();

        // Featured image is generated even when the "in article" toggle is off
        // (it's the WP thumbnail). Surface it here with a note so the reviewer
        // still sees it — but only when it isn't already embedded in the body.
        $featuredImage = null;
        if ($article !== null && $topic?->plan !== null && ! $topic->plan->toggle('featured_image')) {
            $featuredImage = $article->images()
                ->where('role', ContentImage::ROLE_FEATURED)
                ->where('status', ContentImage::STATUS_GENERATED)
                ->latest()->first();
        }

        // Default social-preview image: the article's featured image (or any
        // generated image) — so the OG/Twitter card shows a picture even before
        // the client sets an explicit social image URL.
        $socialImageFallback = '';
        if ($article !== null) {
            $img = $article->images()
                ->where('status', ContentImage::STATUS_GENERATED)
                ->orderByRaw("CASE WHEN role = ? THEN 0 ELSE 1 END", [ContentImage::ROLE_FEATURED])
                ->latest()
                ->first();
            $socialImageFallback = (string) ($img?->url() ?? '');
        }

        return view('livewire.content.article-review', [
            'topic' => $topic,
            'article' => $article,
            'generating' => $generating,
            'progress' => $progress,
            'featuredImage' => $featuredImage,
            'socialImageFallback' => $socialImageFallback,
            'previewHtml' => $this->sanitize((string) ($article?->html ?? '')),
            'issueLabels' => $issueLabels,
            'traffic' => $topic ? self::trafficWorth($topic) : null,
            // Bare host for the Google snippet preview breadcrumb (no scheme/www).
            'siteHost' => preg_replace('#^www\.#', '', mb_strtolower((string) preg_replace('#^https?://#', '', (string) ($topic?->website?->domain ?? '')))),
            'publishConnected' => (bool) $topic?->plan?->website
                ?->contentIntegrations()->where('status', ContentIntegration::STATUS_CONNECTED)->exists(),
            'presentation' => $topic ? ContentCalendar::statusPresentation($topic->status) : null,
        ]);
    }
}
