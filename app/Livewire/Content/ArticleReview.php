<?php

namespace App\Livewire\Content;

use App\Jobs\CheckTrackedKeywordSerpJob;
use App\Jobs\Content\RewriteArticleJob;
use App\Models\ContentRewriteRequest;
use App\Services\Content\ContentArticleProducer;
use App\Services\Content\Exceptions\InsufficientRewriteCreditsException;
use App\Services\Content\RewriteCredits;
use App\Services\Content\RewritePromptGuard;
use App\Jobs\GenerateInlineImageJob;
use App\Jobs\ProduceContentArticleJob;
use App\Jobs\PublishContentArticleJob;
use App\Models\ContentArticle;
use App\Models\ContentArticleFeedback;
use App\Models\ContentImage;
use App\Models\ContentIntegration;
use App\Models\ContentPublication;
use App\Models\ContentTopic;
use App\Models\ContentTrackedKeyword;
use App\Services\AiToolRunner;
use App\Services\Content\CompetitorMentionGuard;
use App\Services\Content\ContentEntitlements;
use App\Services\Content\ContentKeywordTracker;
use App\Services\Content\ContentLlmSpendMeter;
use App\Services\Content\ContentSeoScorer;
use App\Services\Content\HumanizerService;
use App\Services\Content\IdeogramClient;
use App\Services\Content\IdeogramSpendMeter;
use App\Services\Content\KeywordTrackerQuota;
use App\Support\ContentAutopilotConfig;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithFileUploads;

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
    use WithFileUploads;

    public string $topicId;

    // ── Editable state ──
    public bool $editing = false;

    public string $bodyHtml = '';

    /** Transient upload holder for in-editor image insertion (WithFileUploads). */
    public $inlineImage;

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

    // ── Client feedback ("Do you like this article?") ──
    public string $feedbackRating = '';

    public string $feedbackComment = '';

    public bool $feedbackSaved = false;

    // ── Credit-gated rewrite (Rewrite button on the feedback widget) ──
    public string $rewriteError = '';

    public string $rewriteSuggestion = '';

    public bool $showPacksModal = false;

    /** Version-history preview: article id being previewed ('' = current). */
    public string $previewVersionId = '';

    // ── Keyword Tracker CTA state ──
    public bool $isTracked = false;

    public int $trackerUsed = 0;

    public int $trackerLimit = 0;

    /** @var list<array{code:string, passed:bool, label:string}> */
    public array $liveChecks = [];

    public int $liveScore = 0;

    /**
     * Which article version the editable state above was hydrated from, so a
     * poll can notice the pipeline moved on underneath us. See
     * syncIfArticleChanged().
     */
    public string $hydratedArticleId = '';

    /** Cached once per request-cycle: site_urls/existing_titles are DB reads. */
    protected ?array $scorerContext = null;

    public function mount(string $topicId): void
    {
        $this->topicId = $topicId;
        abort_unless($this->topic() !== null, 404);
        $this->hydrateFromArticle();
        $this->loadFeedback();
        $this->loadTrackerState();
    }

    /** Refresh the "are this article's keywords tracked?" CTA state. */
    private function loadTrackerState(): void
    {
        $topic = $this->topic();
        $website = $topic?->website;
        if ($topic === null || $website === null) {
            return;
        }
        $this->isTracked = ContentTrackedKeyword::query()
            ->where('website_id', $website->id)
            ->where('topic_id', $topic->id)
            ->exists();
        $quota = app(KeywordTrackerQuota::class);
        $this->trackerUsed = $quota->used($website);
        $this->trackerLimit = $quota->limitFor($website);
    }

    /** Track this article's targeted keywords (primary + secondaries). */
    public function trackKeywords(): void
    {
        $topic = $this->topic();
        $website = $topic?->website;
        if ($topic === null || $website === null) {
            return;
        }
        $tracker = app(ContentKeywordTracker::class);
        $pageUrl = ContentPublication::query()
            ->whereIn('article_id', $topic->articles()->select('id'))
            ->whereNotNull('external_url')
            ->latest('created_at')
            ->value('external_url');
        $result = $tracker->track(
            website: $website,
            keywords: $tracker->keywordsFor($topic),
            topic: $topic,
            source: ContentTrackedKeyword::SOURCE_MANUAL,
            user: Auth::user(),
            pageUrl: $pageUrl,
        );
        $this->loadTrackerState();
        if ($result['added'] > 0) {
            CheckTrackedKeywordSerpJob::dispatch($website->id);
            session()->flash('review-status', trans_choice('{1}Added 1 keyword to your tracker.|[2,*]Added :count keywords to your tracker.', $result['added'], ['count' => $result['added']]));
        } elseif ($result['capped']) {
            session()->flash('review-status', __('Your tracker is full — remove a keyword in the Tracker to add these.'));
        }
    }

    /** Stop tracking this article's keywords. */
    public function untrackKeywords(): void
    {
        $topic = $this->topic();
        $website = $topic?->website;
        if ($topic === null || $website === null) {
            return;
        }
        ContentTrackedKeyword::query()
            ->where('website_id', $website->id)
            ->where('topic_id', $topic->id)
            ->delete();
        $this->loadTrackerState();
    }

    private function loadFeedback(): void
    {
        $fb = ContentArticleFeedback::query()
            ->where('topic_id', $this->topicId)
            ->where('user_id', Auth::id())
            ->first();
        $this->feedbackRating = (string) ($fb?->rating ?? '');
        $this->feedbackComment = (string) ($fb?->comment ?? '');
    }

    /** Client verdict on this article — one current row per (topic, user). */
    public function rateArticle(string $rating): void
    {
        if (! in_array($rating, ContentArticleFeedback::RATINGS, true)) {
            return;
        }
        $topic = $this->topic();
        $userId = Auth::id();
        if ($topic === null || $userId === null) {
            return;
        }
        ContentArticleFeedback::updateOrCreate(
            ['topic_id' => $topic->id, 'user_id' => $userId],
            [
                'article_id' => $topic->currentArticle?->id,
                'website_id' => $topic->website_id,
                'rating' => $rating,
            ],
        );
        $this->feedbackRating = $rating;
        $this->feedbackSaved = true;
    }

    /** Optional free-text note attached to the current verdict. */
    public function saveFeedbackComment(): void
    {
        $topic = $this->topic();
        $userId = Auth::id();
        if ($topic === null || $userId === null || $this->feedbackRating === '') {
            return;
        }
        ContentArticleFeedback::query()
            ->where('topic_id', $topic->id)
            ->where('user_id', $userId)
            ->update(['comment' => mb_substr(trim($this->feedbackComment), 0, 2000)]);
        $this->feedbackSaved = true;
        session()->flash('review-status', __('Thanks — your feedback was sent to our team.'));
    }

    // ── Credit-gated rewrite ────────────────────────────────────────────

    /** The rewrite currently queued/running for this topic, if any. */
    private function activeRewrite(): ?ContentRewriteRequest
    {
        return ContentRewriteRequest::query()
            ->where('topic_id', $this->topicId)
            ->whereIn('status', [ContentRewriteRequest::STATUS_QUEUED, ContentRewriteRequest::STATUS_RUNNING])
            ->latest()
            ->first();
    }

    /**
     * "Rewrite" on the feedback widget: records the feedback (admin monitor
     * always sees what clients want — owner rule), validates the instruction,
     * spends one credit and queues the rewrite. The credit is spent HERE, in
     * one transaction with the request row — the job only ever refunds.
     */
    public function requestRewrite(): void
    {
        $this->rewriteError = $this->rewriteSuggestion = '';
        $topic = $this->topic();
        $user = Auth::user();
        if ($topic === null || $user === null || $topic->currentArticle === null) {
            return;
        }
        if (! in_array($this->feedbackRating, [ContentArticleFeedback::RATING_REWRITES, ContentArticleFeedback::RATING_WRONG], true)) {
            return;
        }
        if (in_array($topic->status, ContentTopic::IN_FLIGHT, true)
            || in_array($topic->status, [ContentTopic::STATUS_PUBLISHING, ContentTopic::STATUS_SCHEDULED], true)
            || $this->activeRewrite() !== null) {
            session()->flash('review-status', __('This article is busy right now — try again in a few minutes.'));

            return;
        }

        // 1. Feedback row FIRST — recorded even when validation or credits
        //    stop the rewrite, so the admin monitor keeps the signal.
        ContentArticleFeedback::updateOrCreate(
            ['topic_id' => $topic->id, 'user_id' => $user->id],
            [
                'article_id' => $topic->currentArticle?->id,
                'website_id' => $topic->website_id,
                'rating' => $this->feedbackRating,
                'comment' => mb_substr(trim($this->feedbackComment), 0, 2000),
            ],
        );
        $this->feedbackSaved = true;

        // 2. Validate a non-blank instruction (blank = generic quality pass).
        $instruction = trim($this->feedbackComment);
        if ($instruction !== '') {
            $verdict = app(RewritePromptGuard::class)->check($instruction);
            if (($verdict['ok'] ?? false) !== true) {
                $this->rewriteError = (string) ($verdict['reason'] ?? __('This request can\'t be used for a rewrite.'));
                $this->rewriteSuggestion = (string) ($verdict['suggestion'] ?? '');

                return;
            }
        }

        // 3. Credits.
        $credits = app(RewriteCredits::class);
        if ($credits->summary($user)['total'] < 1) {
            $this->showPacksModal = true;

            return;
        }

        // 4. Spend + queue.
        try {
            $request = DB::transaction(function () use ($topic, $user, $instruction, $credits): ContentRewriteRequest {
                $request = ContentRewriteRequest::query()->create([
                    'topic_id' => $topic->id,
                    'user_id' => $user->id,
                    'website_id' => $topic->website_id,
                    'prompt' => $instruction !== '' ? $instruction : null,
                    'status' => ContentRewriteRequest::STATUS_QUEUED,
                    'prior_status' => $topic->status,
                ]);
                $event = $credits->spend($user, $topic, $request->id);
                $request->update(['credit_event_id' => $event->id]);

                return $request;
            });
        } catch (InsufficientRewriteCreditsException) {
            $this->showPacksModal = true; // lost the race to another tab

            return;
        }

        Cache::put('content:gen-start:'.$topic->id, now()->timestamp, now()->addHour());
        RewriteArticleJob::dispatch($request->id, $topic->id);
        session()->flash('review-status', __('We\'re rewriting your article — this takes a few minutes.'));
    }

    public function applyRewriteSuggestion(): void
    {
        if ($this->rewriteSuggestion !== '') {
            $this->feedbackComment = $this->rewriteSuggestion;
        }
        $this->rewriteError = $this->rewriteSuggestion = '';
    }

    /** Dismiss the "credit returned" banner for the latest failed rewrite. */
    public function dismissRewriteNotice(): void
    {
        ContentRewriteRequest::query()
            ->where('topic_id', $this->topicId)
            ->where('user_id', Auth::id())
            ->where('status', ContentRewriteRequest::STATUS_FAILED)
            ->whereNull('client_seen_at')
            ->update(['client_seen_at' => now()]);
    }

    // ── Version history ─────────────────────────────────────────────────

    /** @return list<array{id:string, version:int, date:string, label:string, is_current:bool}> */
    private function versions(ContentTopic $topic): array
    {
        $doneRewriteVersions = ContentRewriteRequest::query()
            ->where('topic_id', $topic->id)
            ->where('status', ContentRewriteRequest::STATUS_DONE)
            ->pluck('article_version')->filter()->map(fn ($v) => (int) $v)->all();

        return $topic->articles()
            ->orderByDesc('version')
            ->limit(20)
            ->get(['id', 'version', 'is_current', 'generation_meta', 'created_at'])
            ->map(function (ContentArticle $a) use ($doneRewriteVersions): array {
                $stage = (string) ($a->generation_meta['stage'] ?? '');
                // Client-safe labels only — internal stage vocabulary never leaks.
                $label = match (true) {
                    in_array((int) $a->version, $doneRewriteVersions, true),
                    str_starts_with($stage, 'client_rewrite') => __('Your rewrite'),
                    (int) $a->version === 1 => __('Draft'),
                    str_contains($stage, 'revise') => __('Optimized'),
                    str_starts_with($stage, 'brand_scrub'),
                    $stage === 'de_ai', $stage === 'context_rescore' => __('Cleanup'),
                    str_contains($stage, 'edit') || str_contains($stage, 'manual') => __('Your edit'),
                    default => __('Update'),
                };

                return [
                    'id' => (string) $a->id,
                    'version' => (int) $a->version,
                    'date' => (string) $a->created_at?->translatedFormat('M j, H:i'),
                    'label' => $label,
                    'is_current' => (bool) $a->is_current,
                ];
            })->all();
    }

    public function previewVersion(string $articleId): void
    {
        $topic = $this->topic();
        if ($topic === null) {
            return;
        }
        // Tenancy: only this topic's versions are previewable.
        if ($topic->articles()->whereKey($articleId)->exists()) {
            $this->previewVersionId = $articleId;
        }
    }

    public function clearVersionPreview(): void
    {
        $this->previewVersionId = '';
    }

    /** Make an older version current again ("Use this version"). */
    public function useVersion(string $articleId): void
    {
        $topic = $this->topic();
        if ($topic === null
            || in_array($topic->status, ContentTopic::IN_FLIGHT, true)
            || $topic->status === ContentTopic::STATUS_PUBLISHING
            || $this->activeRewrite() !== null) {
            return;
        }
        $article = $topic->articles()->whereKey($articleId)->first();
        if ($article === null) {
            return;
        }

        app(ContentArticleProducer::class)->makeVersionCurrent($topic, $article);
        $this->previewVersionId = '';
        $this->hydrateFromArticle();
        session()->flash('review-status', $topic->status === ContentTopic::STATUS_PUBLISHED
            ? __('Done — this version is now current. Republish to update it on your site.')
            : __('Done — this version is now current.'));
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
        $this->hydratedArticleId = (string) ($article?->id ?? '');
        $this->refreshChecks();
    }

    /**
     * Re-hydrate when the pipeline stored a newer version underneath us.
     *
     * The page polls every 3s while an article is being built, and a poll
     * re-renders WITHOUT re-running mount(). So the moment the writer finishes
     * — or the image pass, which stores a FURTHER version after the article is
     * already readable — the component is still holding the body and live
     * score it hydrated when the page first opened: an earlier draft, or, if
     * the page was opened before any draft existed, nothing at all.
     *
     * Symptom (prod 2026-07-30): open a freshly written article straight from
     * the progress modal, click Edit, and the SEO score jumps, because the
     * non-edit ring had fallen back to the stored score while the edit ring
     * scored the stale body. Refreshing first made both agree. The worse half
     * was silent: Save writes `$bodyHtml` back as a new version, so editing
     * from that state could have overwritten the finished article with the
     * draft (or the empty string) the page loaded with.
     *
     * Never fires mid-edit — an in-progress edit must not be clobbered.
     */
    private function syncIfArticleChanged(?ContentTopic $topic): void
    {
        if ($this->editing) {
            return;
        }
        $currentId = (string) ($topic?->currentArticle?->id ?? '');
        if ($currentId !== '' && $currentId !== $this->hydratedArticleId) {
            $this->hydrateFromArticle();
        }
    }

    public function startEditing(): void
    {
        // Before the flag flips: syncIfArticleChanged() deliberately refuses to
        // touch state while editing, and this is the last moment it can.
        $this->syncIfArticleChanged($this->topic());
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

        // Content Autopilot has its OWN AI meter (ContentLlmSpendMeter) — the
        // same monthly circuit-breaker the writer/ideation calls use. Refuse when
        // it's exhausted rather than falling through to the client's dashboard pool.
        $meter = app(ContentLlmSpendMeter::class);
        if ($meter->exhausted()) {
            $this->dispatch('ai-edit-failed', message: __('The monthly AI limit for Content Autopilot has been reached. It resets next month.'));

            return null;
        }

        // Content editor: the reviewer reached this page through the content
        // access gate, so the inline AI tools are part of the Content Autopilot
        // product they already pay for — bypass the SEO-plan `ai_writer` Pro-gate
        // (content-product independence; otherwise these silently no-op for
        // content-only customers). `__unmetered` + `__source` route the LLM spend
        // to the CONTENT meter (below), NOT the reviewer's dashboard token pool.
        $result = app(AiToolRunner::class)->run($tool, $website, Auth::id(), $input, allowWithoutPro: true, llmMeta: [
            '__unmetered' => true,
            '__user_id' => Auth::id(),
            '__website_id' => $website->id,
            '__source' => 'content_autopilot.edit',
        ]);
        if (! $result->ok || ! is_string($result->value) || trim($result->value) === '') {
            $this->dispatch('ai-edit-failed', message: (string) ($result->message ?: __('The AI edit did not complete. Try again.')));

            return null;
        }

        // Bill the Content Autopilot AI meter (cached results carry no fresh
        // token cost, so only meter a genuine LLM run).
        if (! $result->cached) {
            $meter->add(ContentLlmSpendMeter::EST_EDIT_USD);
        }

        // Brand gate: the generic rewrite tools know nothing about this plan's
        // blocked competitors, so a "rewrite" can helpfully name one. Reject a
        // replacement that INTRODUCES a blocked term the selection didn't have.
        $replacement = trim($result->value);
        $guard = app(CompetitorMentionGuard::class);
        $plan = $topic?->plan;
        if ($plan !== null) {
            foreach ($guard->termsForTopic($plan, $topic) as $term) {
                $pattern = '/\b'.preg_quote(mb_strtolower($term), '/').'\b/u';
                if (preg_match($pattern, mb_strtolower($replacement))
                    && ! preg_match($pattern, mb_strtolower($text))) {
                    $this->dispatch('ai-edit-failed', message: __('The AI edit mentioned a blocked brand (“:term”), so it was not applied. Try again.', ['term' => $term]));

                    return null;
                }
            }
        }

        return $replacement;
    }

    // ── In-editor images ────────────────────────────────────────────────

    /**
     * Store a device-uploaded image on the content images disk and return its
     * public URL for the editor to insert as a <figure class="content-image">.
     * Reuses ContentImage::disk() (one source of truth with the pipeline + WP
     * sideload). storePublicly() so S3 objects are readable by the browser/WP.
     *
     * @return array{url:string, id:string}
     */
    public function uploadInlineImage(): array
    {
        $this->validate([
            'inlineImage' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);
        $topic = $this->topic();
        $article = $topic?->currentArticle;
        abort_if($topic === null || $article === null, 404);

        $path = $this->inlineImage->storePublicly('content/images/inline/'.$topic->id, ContentImage::disk());

        $image = ContentImage::query()->create([
            'article_id' => $article->id,
            'role' => ContentImage::ROLE_INLINE,
            'disk_path' => $path,
            'bytes' => (int) $this->inlineImage->getSize(),
            'filename' => basename((string) $path),
            'alt_text' => '',
            'params' => ['source' => 'upload'],
            'status' => ContentImage::STATUS_GENERATED,
        ]);

        $this->reset('inlineImage');

        return ['url' => (string) $image->url(), 'id' => $image->id];
    }

    /**
     * Kick off an on-demand Ideogram generation for an in-article image. Gated
     * on the platform image kill-switch + a configured key + the monthly spend
     * cap (a manual request ignores the per-plan AUTO toggle — the client is
     * explicitly asking for this image). Returns the pending ContentImage id
     * for the editor to poll, or null with a friendly notice.
     */
    public function requestInlineImage(string $prompt): ?string
    {
        $prompt = trim($prompt);
        if ($prompt === '' || mb_strlen($prompt) > 500) {
            return null;
        }
        $topic = $this->topic();
        $article = $topic?->currentArticle;
        if ($topic === null || $article === null) {
            return null;
        }
        if (! ContentAutopilotConfig::imagesEnabled()
            || ! app(IdeogramClient::class)->isConfigured()
            || app(IdeogramSpendMeter::class)->exhausted()) {
            $this->dispatch('ai-edit-failed', message: __('Image generation is unavailable right now.'));

            return null;
        }

        $image = ContentImage::query()->create([
            'article_id' => $article->id,
            'role' => ContentImage::ROLE_INLINE,
            'prompt' => mb_substr($prompt, 0, 500),
            'params' => ['source' => 'editor-generate'],
            'status' => ContentImage::STATUS_PENDING,
        ]);
        GenerateInlineImageJob::dispatch($image->id, $prompt);

        return $image->id;
    }

    /**
     * Poll a requested inline image. Returns ['url'=>...] once generated,
     * ['failed'=>true] on failure/not-found (tenancy-checked), or null while
     * still pending.
     *
     * @return array{url?:string, failed?:bool}|null
     */
    public function pollInlineImage(string $id): ?array
    {
        $topic = $this->topic();
        if ($topic === null) {
            return ['failed' => true];
        }
        $image = ContentImage::query()->whereKey($id)->with('article')->first();
        if ($image === null || $image->article === null || $image->article->topic_id !== $topic->id) {
            return ['failed' => true];
        }
        if ($image->status === ContentImage::STATUS_GENERATED && $image->disk_path) {
            return ['url' => (string) $image->url()];
        }
        if ($image->status === ContentImage::STATUS_FAILED) {
            return ['failed' => true];
        }

        return null; // still pending
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

    /**
     * "Remove blocked mentions" — the article (usually already published, or
     * held by the brand gate) carries a blocked competitor term. Queue the
     * scrub job: it rewrites just those mentions and re-sends the clean
     * version to the connected platforms as an update.
     */
    public function cleanBlockedMentions(): void
    {
        $topic = $this->topic();
        if ($topic === null || in_array($topic->status, ContentTopic::IN_FLIGHT, true)) {
            return;
        }
        $flagged = ! empty((($topic->meta ?? [])['brand_safety'] ?? null))
            || str_starts_with((string) $topic->last_error, 'brand_safety');
        if (! $flagged) {
            return;
        }
        \App\Jobs\Content\CleanBlockedTermsJob::dispatch($topic->id);
        session()->flash('review-status', __('Removing the blocked mentions — the cleaned article will be sent to your site in a few minutes.'));
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
            // RAW html: the competitor lint reads hrefs and alt attributes too.
            $html,
            $guardPlan !== null ? $guard->termsForTopic($guardPlan, $topic) : [],
            $guardPlan !== null && $guard->enabled($guardPlan) ? $guard->blockedDomains($guardPlan) : [],
            // The editable SEO fields ship with the article — a blocked brand
            // in the meta description or slug is just as published.
            implode(' ', [
                trim($this->editMetaTitle),
                trim($this->editMetaDescription),
                trim($this->editH1),
                trim($this->editOgTitle), trim($this->editOgDescription),
                trim($this->editTwitterTitle), trim($this->editTwitterDescription),
                str_replace('-', ' ', trim($this->editSlug) !== '' ? trim($this->editSlug) : (string) $article->slug),
            ]),
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
        // Poll requests re-render without re-running mount(), so pick up a
        // version the pipeline stored since the page opened.
        $this->syncIfArticleChanged($topic);
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
                // Client-requested rewrite in flight → overlay says
                // "Rewriting your article", not "Creating".
                if ($this->activeRewrite() !== null) {
                    $progress['rewrite'] = true;
                }
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
                ->orderByRaw('CASE WHEN role = ? THEN 0 ELSE 1 END', [ContentImage::ROLE_FEATURED])
                ->latest()
                ->first();
            $socialImageFallback = (string) ($img?->url() ?? '');
        }

        // Version-history preview: swap the article preview to an older
        // version (banner in the blade; tenancy enforced in previewVersion()).
        $previewingVersion = null;
        if ($this->previewVersionId !== '' && $topic !== null) {
            $previewingVersion = $topic->articles()->whereKey($this->previewVersionId)->first();
            if ($previewingVersion === null || $previewingVersion->is_current) {
                $previewingVersion = null;
                $this->previewVersionId = '';
            }
        }

        $user = Auth::user();
        $rewriteCredits = $user !== null ? app(RewriteCredits::class)->summary($user) : null;
        $failedRewrite = $topic === null ? null : ContentRewriteRequest::query()
            ->where('topic_id', $topic->id)
            ->where('status', ContentRewriteRequest::STATUS_FAILED)
            ->whereNull('client_seen_at')
            ->latest()
            ->first();

        return view('livewire.content.article-review', [
            'topic' => $topic,
            'article' => $article,
            'generating' => $generating,
            'progress' => $progress,
            'featuredImage' => $featuredImage,
            'socialImageFallback' => $socialImageFallback,
            'rewriteCredits' => $rewriteCredits,
            'activeRewrite' => $topic !== null ? $this->activeRewrite() : null,
            'failedRewrite' => $failedRewrite,
            'versions' => $topic !== null && $article !== null ? $this->versions($topic) : [],
            'previewingVersion' => $previewingVersion,
            'rewritePacks' => ContentAutopilotConfig::rewritePacks(),
            'previewHtml' => $this->sanitize((string) ($previewingVersion?->html ?? $article?->html ?? '')),
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
