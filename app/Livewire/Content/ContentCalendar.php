<?php

namespace App\Livewire\Content;

use App\Jobs\AssessCompetitorGuardJob;
use App\Jobs\PlanContentTopicsJob;
use App\Jobs\PrepareContentKeywordInsightsJob;
use App\Jobs\ProduceContentArticleJob;
use App\Jobs\PublishContentArticleJob;
use App\Models\ContentIntegration;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\Website;
use App\Services\Content\CompetitorMentionGuard;
use App\Services\Content\ContentEntitlements;
use App\Services\Content\ContentKeywordInsights;
use App\Services\Content\ContentSetupInsights;
use App\Services\Content\SiteProfileExtractor;
use App\Support\ContentAutopilotConfig;
use App\Support\ContentImageStyles;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Content Autopilot: two separate sidebar pages share this one component,
 * distinguished by `$mode`:
 *
 *  - "calendar" (/content) — the monthly calendar once a plan is ACTIVE;
 *    a lightweight empty state pointing to Settings when no plan exists yet.
 *  - "settings" (/content/settings) — the 5-step wizard, ALWAYS. First use
 *    creates the plan; revisiting later re-opens the SAME wizard to edit an
 *    already-active plan's business profile / offerings (never demotes an
 *    active plan back to draft — see toHowItWorks()).
 *
 * Wizard steps: 1 business profile → 2 offerings (sell / don't-sell lists) →
 * 3 how-it-works → 4 images (enable + style) → 5 competitors & authority →
 * 6 keyword research → 7 first articles. A DRAFT plan is created at end of step 2 (first time
 * only) so topic ideation AND keyword research (self-hosted keyword server,
 * minutes-long — see ContentKeywordInsights) run in the BACKGROUND while the
 * user reads steps 3-4; by steps 5-6 real data is ready to show. Finishing
 * the wizard activates the plan (article writing begins) and returns to the
 * Calendar page.
 *
 * Client copy invariant: pipeline internals (scores below floor, spend caps,
 * model names) NEVER surface here.
 */
class ContentCalendar extends Component
{
    /** Baked cadence defaults (owner decision 2026-07-17: 1 article/day). */
    private const DEFAULT_PER_WEEK = 7;

    private const DEFAULT_LENGTH = 2000; // mid of the 1,500-2,500 band

    public ?string $websiteId = null;

    /** 'calendar' | 'settings' — which sidebar page mounted this component. */
    public string $mode = 'calendar';

    // Calendar state
    public string $month = '';

    public string $view = 'grid';

    // ── Wizard state ──
    public int $wizardStep = 1;

    public ?string $draftPlanId = null;

    public bool $analyzing = false;

    public string $brandName = '';

    public string $language = 'en';

    public string $country = '';

    public string $businessDescription = '';

    /** @var list<string> */
    public array $sellItems = [];

    /** @var list<string> */
    public array $dontSellItems = [];

    public string $newSell = '';

    public string $newDont = '';

    public string $newCompetitorDomain = '';

    /** Competitor-mention guard: add-a-blocked-term input. */
    public string $newBlockedTerm = '';

    // Inline add-topic form (calendar)
    public bool $showAddTopic = false;

    public string $newTitle = '';

    public string $newKeyword = '';

    /** Article-structure toggles surfaced in the wizard (step 3). */
    public array $structureToggles = ['key_takeaways' => true, 'toc' => true, 'faq' => true, 'featured_image' => true];

    /** Image setup (onboarding step + settings). */
    public bool $imagesEnabled = true;

    public string $imageStyle = 'photographic';

    /** Publishing cadence — editable from the post-onboarding Settings view. */
    public int $articlesPerWeek = self::DEFAULT_PER_WEEK;

    public int $articleLength = self::DEFAULT_LENGTH;

    public bool $autoPublish = false;

    public int $reviewHours = 24;

    /** Publish window (auto-publish only fires inside it), in $publishTimezone. */
    public int $publishHourStart = 9;

    public int $publishHourEnd = 11;

    public string $publishTimezone = 'UTC';

    public function mount(string $mode = 'calendar'): void
    {
        $this->mode = in_array($mode, ['calendar', 'settings'], true) ? $mode : 'calendar';
        $this->websiteId = session('current_website_id');
        $this->month = now()->format('Y-m');
        // Default the publish timezone to the signed-in user's — bootWizard()
        // overrides it from an existing plan when one exists.
        $this->publishTimezone = auth()->user()?->timezoneForDisplay() ?? 'UTC';
        $this->bootWizard();
    }

    #[On('website-changed')]
    public function switchWebsite(string $websiteId): void
    {
        $this->websiteId = $websiteId;
        $this->reset('wizardStep', 'draftPlanId', 'businessDescription', 'sellItems', 'dontSellItems', 'brandName');
        $this->wizardStep = 1;
        $this->bootWizard();
    }

    // ── Wizard ──────────────────────────────────────────────────────────

    /**
     * Prepare wizard state: resume/edit an existing plan (any status) if one
     * exists, else arm the deferred site analysis (wire:init) for the fresh
     * flow. Only a true in-progress DRAFT auto-jumps past the offerings step
     * (background ideation is already running); an already-ACTIVE plan
     * opened via Settings starts at step 1 so the profile is reviewable from
     * the top, with every step unlocked immediately.
     */
    private function bootWizard(): void
    {
        $website = $this->website();
        $this->brandName = $this->brandName ?: $this->guessBrand($website);

        $existing = $this->plan();
        if ($existing !== null) {
            // Content planning is always on — no manual pause. Reactivate any plan
            // that was paused before the pause control was removed.
            if ($existing->status === ContentPlan::STATUS_PAUSED) {
                $existing->update(['status' => ContentPlan::STATUS_ACTIVE]);
            }
            $this->draftPlanId = $existing->id;
            $this->businessDescription = $this->businessDescription ?: (string) $existing->business_description;
            $offerings = (array) ($existing->offerings ?? []);
            $this->sellItems = $this->sellItems ?: array_values((array) ($offerings['sell'] ?? []));
            $this->dontSellItems = $this->dontSellItems ?: array_values((array) ($offerings['dont_sell'] ?? []));
            // Language is stored as a full name (dropdown value); legacy plans
            // hold a code — map en→English, ar→Arabic so the select preselects.
            $lang = (string) ($existing->language ?? '');
            $this->language = match (mb_strtolower(trim($lang))) {
                '', 'en' => 'English',
                'ar' => 'Arabic',
                default => $lang,
            };
            // Country is a KeywordFinderLocations key ('us', 'global', …).
            $this->country = (string) ($existing->country ?: 'global');
            $this->structureToggles = [
                'key_takeaways' => $existing->toggle('key_takeaways'),
                'toc' => $existing->toggle('toc'),
                'faq' => $existing->toggle('faq'),
                'featured_image' => $existing->toggle('featured_image'),
            ];
            $this->imagesEnabled = $existing->images_enabled === null ? true : (bool) $existing->images_enabled;
            $this->imageStyle = ContentImageStyles::isValid($existing->image_style)
                ? (string) $existing->image_style
                : ContentImageStyles::default();
            $this->articlesPerWeek = (int) ($existing->articles_per_week ?: self::DEFAULT_PER_WEEK);
            $this->articleLength = (int) ($existing->article_length ?: self::DEFAULT_LENGTH);
            $this->autoPublish = (bool) $existing->auto_publish;
            $this->reviewHours = (int) ($existing->review_hours ?? 24);
            $this->publishHourStart = (int) ($existing->publish_hour_start ?? 9);
            $this->publishHourEnd = (int) ($existing->publish_hour_end ?? 11);
            // Fall back to the user's own timezone rather than the UTC column
            // default, so the window reads in local time out of the box.
            $tz = (string) ($existing->timezone ?? '');
            $this->publishTimezone = in_array($tz, timezone_identifiers_list(), true)
                ? $tz : auth()->user()?->timezoneForDisplay() ?? 'UTC';
            // Auto-jump past the offerings step only for a DRAFT that is
            // genuinely in progress (business profile filled or ideation already
            // ran). A bare billing-coverage stub (just-activated site, wizard
            // not started) must begin at step 1.
            if ($existing->status === ContentPlan::STATUS_DRAFT
                && (filled($existing->business_description) || $existing->topics()->exists())) {
                $this->wizardStep = max($this->wizardStep, 3);
            }
            // A plan row no longer means "the wizard already ran": since billing,
            // coverWebsite() creates a covered STUB before the user has entered
            // anything. Auto-detect must still run for a stub, or the profile
            // step sits permanently empty with no way to regenerate it.
            $this->analyzing = blank($this->businessDescription);

            return;
        }

        $this->analyzing = $website !== null;
    }

    /** Auto-detect the business profile from crawl data (cached LLM call). */
    public function analyzeSite(): void
    {
        $this->analyzing = false;

        $website = $this->website();
        // Gate on the PROFILE, not on the existence of a plan row: a covered
        // stub (created by coverWebsite() at activation / trial start) has no
        // profile yet, and the old `plan() !== null` guard made auto-detect
        // dead on arrival for every billed site — the user saw the description
        // generate on their first visit, then an empty field ever after.
        if ($website === null || filled($this->businessDescription)
            || filled($this->plan()?->business_description)) {
            return;
        }

        $profile = app(SiteProfileExtractor::class)->extract($website);

        if ($this->businessDescription === '') {
            $this->businessDescription = (string) ($profile['description'] ?? '');
        }
        if ($this->sellItems === [] && ! empty($profile['sell'])) {
            $this->sellItems = array_values($profile['sell']);
        }
        if ($this->dontSellItems === [] && ! empty($profile['dont_sell'])) {
            $this->dontSellItems = array_values($profile['dont_sell']);
        }

        if ($this->businessDescription === '') {
            try {
                if ($website->crawl_site_id) {
                    $this->businessDescription = (string) (DB::table('website_pages')
                        ->where('crawl_site_id', $website->crawl_site_id)
                        ->whereNotNull('meta_description')->where('meta_description', '!=', '')
                        ->orderBy('url')->limit(1)->value('meta_description') ?? '');
                }
            } catch (\Throwable) {
            }
        }
    }

    public function goToStep(int $step): void
    {
        // Only allow jumping to steps already unlocked (never skip ahead).
        $max = $this->draftPlanId !== null ? 7 : 2;
        $this->wizardStep = max(1, min($step, $max));
    }

    /** Step 1 → 2 */
    public function toOfferings(): void
    {
        $this->validate([
            'businessDescription' => 'required|string|min:30|max:1000',
        ], [], ['businessDescription' => __('business description')]);

        $this->wizardStep = 2;
    }

    // Offerings list editing.
    public function addSell(): void
    {
        $v = trim($this->newSell);
        if ($v !== '') {
            $this->sellItems[] = mb_substr($v, 0, 120);
            $this->newSell = '';
        }
    }

    public function addDont(): void
    {
        $v = trim($this->newDont);
        if ($v !== '') {
            $this->dontSellItems[] = mb_substr($v, 0, 120);
            $this->newDont = '';
        }
    }

    public function removeSell(int $i): void
    {
        unset($this->sellItems[$i]);
        $this->sellItems = array_values($this->sellItems);
    }

    public function removeDont(int $i): void
    {
        unset($this->dontSellItems[$i]);
        $this->dontSellItems = array_values($this->dontSellItems);
    }

    /** Drag-and-drop reorder: move the item at $from to sit at $to. */
    public function reorderSell(int $from, int $to): void
    {
        if (! isset($this->sellItems[$from]) || $from === $to || $to < 0 || $to >= count($this->sellItems)) {
            return;
        }
        $item = $this->sellItems[$from];
        array_splice($this->sellItems, $from, 1);
        array_splice($this->sellItems, $to, 0, [$item]);
    }

    /**
     * Step 2 → 3: persist the DRAFT plan and kick off topic ideation in the
     * background so the calendar is ready by the "first articles" step.
     */
    public function toHowItWorks(): void
    {
        $website = $this->website();
        if ($website === null) {
            return;
        }
        if (! ($website->effectiveFeatureFlags()['content_autopilot'] ?? false)) {
            $this->addError('plan', __('Content Autopilot is not included in your plan.'));

            return;
        }

        $sell = array_values(array_filter(array_map('trim', $this->sellItems)));
        $dont = array_values(array_filter(array_map('trim', $this->dontSellItems)));

        // Opened via Settings on an already-active plan: preserve its status
        // and cadence fields. This step must never silently demote a live
        // plan back to draft or reset settings it doesn't manage.
        $existing = ContentPlan::query()->where('website_id', $website->id)->first();

        $plan = ContentPlan::query()->updateOrCreate(
            ['website_id' => $website->id],
            [
                'status' => $existing?->status ?? ContentPlan::STATUS_DRAFT,
                'articles_per_week' => $existing?->articles_per_week ?? self::DEFAULT_PER_WEEK,
                'article_length' => $existing?->article_length ?? self::DEFAULT_LENGTH,
                'auto_publish' => $existing?->auto_publish ?? false,
                'review_hours' => $existing?->review_hours ?? 24,
                'images_enabled' => $existing?->images_enabled ?? true,
                'image_style' => $existing?->image_style ?? ContentImageStyles::default(),
                // Merge the wizard's structure switches over the plan's stored
                // toggles (or first-run defaults), preserving toggles the
                // wizard doesn't surface (external_links, cta_enabled, author_box).
                'toggles' => array_merge(
                    $existing?->toggles ?? ['toc' => true, 'key_takeaways' => true, 'faq' => true,
                        'external_links' => true, 'cta_enabled' => false],
                    [
                        'key_takeaways' => (bool) ($this->structureToggles['key_takeaways'] ?? true),
                        'toc' => (bool) ($this->structureToggles['toc'] ?? true),
                        'faq' => (bool) ($this->structureToggles['faq'] ?? true),
                        'featured_image' => (bool) ($this->structureToggles['featured_image'] ?? true),
                    ]
                ),
                'business_description' => $this->businessDescription,
                'offerings' => ['sell' => array_slice($sell, 0, 12), 'dont_sell' => array_slice($dont, 0, 12)],
                'language' => $this->language ?: 'en',
                'country' => $this->country ?: null,
            ]
        );
        $this->draftPlanId = $plan->id;

        // Generate the calendar in the background (only if empty — resume-safe).
        if ($plan->topics()->count() === 0) {
            PlanContentTopicsJob::dispatch($plan->id);
        }
        // Keyword research runs in parallel (minutes-long, self-hosted server)
        // so step 5 has data by the time the user reads steps 3-4. Idempotent.
        PrepareContentKeywordInsightsJob::dispatch($plan->id);

        $this->wizardStep = 3;
    }

    public function toImages(): void
    {
        $this->wizardStep = 4;
    }

    public function toCompetitors(): void
    {
        $this->wizardStep = 5;
    }

    /** Persist the image enable/style choice to the draft plan immediately. */
    public function toggleImages(): void
    {
        $this->imagesEnabled = ! $this->imagesEnabled;
        $this->persistImageSettings();
    }

    public function selectImageStyle(string $key): void
    {
        if (ContentImageStyles::isValid($key)) {
            $this->imageStyle = $key;
            $this->imagesEnabled = true; // picking a style implies wanting images
            $this->persistImageSettings();
        }
    }

    private function persistImageSettings(): void
    {
        $this->plan()?->update([
            'images_enabled' => $this->imagesEnabled,
            'image_style' => ContentImageStyles::isValid($this->imageStyle)
                ? $this->imageStyle
                : ContentImageStyles::default(),
        ]);
    }

    /**
     * Flip an article-structure toggle (Key takeaways / In this article /
     * FAQ) and persist it to the draft plan right away — the plan already
     * exists by step 3, so the change survives without waiting for a later
     * step. Future articles pick it up; already-written ones are unchanged.
     */
    public function toggleStructure(string $key): void
    {
        if (! array_key_exists($key, $this->structureToggles)) {
            return;
        }
        $this->structureToggles[$key] = ! $this->structureToggles[$key];

        $plan = $this->plan();
        if ($plan !== null) {
            $toggles = (array) ($plan->toggles ?? []);
            $toggles[$key] = $this->structureToggles[$key];
            $plan->update(['toggles' => $toggles]);
        }
    }

    /**
     * Turn auto-publish ON from the calendar banner. Once enabled, new articles
     * publish automatically (to a connected destination); the banner then hides.
     */
    public function enableAutoPublish(): void
    {
        $plan = $this->plan();
        if ($plan === null) {
            return;
        }
        $plan->update(['auto_publish' => true]);
        $this->autoPublish = true;
        session()->flash('content-status', __('Auto-publish is on. New articles will publish automatically once your site is connected.'));
    }

    /**
     * Step 4 wire:init — ensure competitor authority data exists. If the site
     * has no usable report snapshot yet, kick off a one-time real generation
     * (spend-metered; sandbox on staging) and let the step poll until ready.
     */
    public function loadCompetitors(): void
    {
        $website = $this->website();
        if ($website === null) {
            return;
        }
        $insights = app(ContentSetupInsights::class);
        if ($insights->competitorAuthority($website) === null) {
            $insights->ensureGenerating($website);
        }
        // Mention-guard classification runs alongside — the step's guard card
        // polls (wire:poll on this step) and fills in when it lands.
        if (($plan = $this->plan()) !== null
            && ! app(CompetitorMentionGuard::class)->assessed($plan)) {
            AssessCompetitorGuardJob::dispatch($plan->id);
        }
    }

    /**
     * Poll target on step 4 while generation is in flight, and also the
     * manual "Refetch" button — both just clear the memoized insights so the
     * next render recomputes from the current report snapshot (+ fresh Moz
     * lookups for anything past its 30-day freshness window).
     */
    public function refreshCompetitors(): void
    {
        $website = $this->website();
        if ($website !== null) {
            app(ContentSetupInsights::class)->forget($website);
        }
    }

    /**
     * Undo manual competitor add/remove edits — restores the plain
     * auto-discovered list. The only way back once "remove" has cleared
     * every competitor from the table.
     */
    public function resetCompetitors(): void
    {
        $plan = $this->plan();
        if ($plan === null) {
            return;
        }
        $plan->update(['competitor_overrides' => null]);
        $this->reassessGuard($plan);
    }

    /** Add a manually-typed competitor domain to the step-4 table. */
    public function addCompetitor(): void
    {
        $this->resetErrorBag('newCompetitorDomain');
        $domain = $this->normalizeCompetitorDomain($this->newCompetitorDomain);
        $this->newCompetitorDomain = '';

        if ($domain === null) {
            $this->addError('newCompetitorDomain', __('Enter a valid competitor domain.'));

            return;
        }

        $plan = $this->plan();
        if ($plan === null) {
            return;
        }

        $overrides = (array) ($plan->competitor_overrides ?? []);
        $added = array_map('strtolower', (array) ($overrides['added'] ?? []));
        $removed = array_values(array_diff((array) ($overrides['removed'] ?? []), [$domain]));

        if (count($added) >= 8) {
            $this->addError('newCompetitorDomain', __('You can add up to 8 competitors.'));

            return;
        }
        if (! in_array($domain, $added, true)) {
            $added[] = $domain;
        }

        $plan->update(['competitor_overrides' => ['added' => array_values($added), 'removed' => $removed]]);
        $this->reassessGuard($plan);
    }

    /** Remove a competitor (auto-discovered or manually added) from the step-4 table. */
    public function removeCompetitor(string $domain): void
    {
        $domain = strtolower(trim($domain));
        $plan = $this->plan();
        if ($plan === null || $domain === '') {
            return;
        }

        $overrides = (array) ($plan->competitor_overrides ?? []);
        $added = array_values(array_diff((array) ($overrides['added'] ?? []), [$domain]));
        $removed = (array) ($overrides['removed'] ?? []);
        if (! in_array($domain, $removed, true)) {
            $removed[] = $domain;
        }

        $plan->update(['competitor_overrides' => ['added' => $added, 'removed' => array_values($removed)]]);
        $this->reassessGuard($plan);
    }

    /** Guard-card view state for the wizard's competitors step and Settings. */
    private function guardState(ContentPlan $plan): array
    {
        $guard = app(CompetitorMentionGuard::class);
        $g = (array) ($plan->competitor_guard ?? []);

        return [
            'assessed' => $guard->assessed($plan),
            'enabled' => $guard->enabled($plan),
            // "We turned this on for you" banner: shows until the client
            // clicks the toggle themselves (setEnabled clears the marker).
            'autoEnabled' => $guard->autoEnabled($plan),
            'reason' => (string) ($g['reason'] ?? ''),
            'terms' => $guard->terms($plan),
        ];
    }

    /** The competitor list changed — the guard's classification is stale. */
    private function reassessGuard(ContentPlan $plan): void
    {
        app(CompetitorMentionGuard::class)->invalidate($plan);
        AssessCompetitorGuardJob::dispatch($plan->id);
    }

    // ── Competitor-mention guard (wizard card + settings) ───────────────

    public function toggleCompetitorGuard(): void
    {
        $plan = $this->plan();
        if ($plan === null) {
            return;
        }
        $guard = app(CompetitorMentionGuard::class);
        // An explicit click is a human decision — recorded as such, so a later
        // re-assessment never flips it back.
        $guard->setEnabled($plan, ! $guard->enabled($plan));
    }

    public function addBlockedTerm(): void
    {
        $this->resetErrorBag('newBlockedTerm');
        $term = trim($this->newBlockedTerm);
        $this->newBlockedTerm = '';
        $plan = $this->plan();
        if ($plan === null || $term === '') {
            return;
        }
        if (mb_strlen($term) > 60) {
            $this->addError('newBlockedTerm', __('Keep blocked terms under 60 characters.'));

            return;
        }
        app(CompetitorMentionGuard::class)->addTerm($plan, $term);
    }

    public function removeBlockedTerm(string $term): void
    {
        $plan = $this->plan();
        if ($plan !== null) {
            app(CompetitorMentionGuard::class)->removeTerm($plan, $term);
        }
    }

    /** Bare lowercase host, or null if not a plausible domain / is the user's own site. */
    private function normalizeCompetitorDomain(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $host = parse_url(str_contains($raw, '://') ? $raw : 'https://'.$raw, PHP_URL_HOST) ?: $raw;
        $host = strtolower(preg_replace('/^www\./', '', $host));

        if (! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $host)) {
            return null;
        }

        $mine = $this->website()?->normalized_domain ?: $this->website()?->domain;
        if ($mine && strtolower(preg_replace('/^www\./', '', $mine)) === $host) {
            return null; // can't add yourself as a competitor
        }

        return $host;
    }

    public function toKeywordResearch(): void
    {
        $this->wizardStep = 6;
        // Belt-and-braces: a resumed old draft may predate the research job.
        if (($plan = $this->plan()) !== null) {
            PrepareContentKeywordInsightsJob::dispatch($plan->id);
            // Competitor list is final once the user leaves the step —
            // (re)classify it for the mention guard if needed.
            if (! app(CompetitorMentionGuard::class)->assessed($plan)) {
                AssessCompetitorGuardJob::dispatch($plan->id);
            }
        }
    }

    /** Poll target on step 5 — re-rendering re-reads the insights. */
    public function refreshKeywordInsights(): void
    {
        // render() re-evaluates get(); also re-attempt the keyword dispatch
        // (throttled) so a competitor request that wasn't ready at step-6 entry
        // still fires once competitors land.
        if (($plan = $this->plan()) !== null
            && Cache::add('content:kw-redispatch:'.$plan->id, 1, 20)) {
            PrepareContentKeywordInsightsJob::dispatch($plan->id);
        }
    }

    public function toFirstArticles(): void
    {
        $this->wizardStep = 7;
    }

    /**
     * Remove a topic from the "first articles" preview. Only touches topics
     * still in a pre-publish state — Settings can revisit this step on an
     * already-active plan, and must never let a stray click skip a
     * ready/scheduled/published article.
     */
    public function dropTopic(string $topicId): void
    {
        $this->plan()?->topics()->whereKey($topicId)
            ->whereIn('status', [ContentTopic::STATUS_SUGGESTED, ContentTopic::STATUS_APPROVED, ContentTopic::STATUS_FAILED])
            ->update(['status' => ContentTopic::STATUS_SKIPPED]);
    }

    /**
     * Finish the wizard: activate the plan (first time → article writing
     * begins; already active → just a settings save) and return to the
     * Calendar page.
     */
    public function launch(): void
    {
        $plan = $this->plan();
        if ($plan === null) {
            return;
        }
        $wasActive = $plan->isActive();
        $plan->update(['status' => ContentPlan::STATUS_ACTIVE]);
        session()->flash('content-status', $wasActive
            ? __('Your content settings have been saved.')
            : __('Your content calendar is live. Articles are being written and will appear for your review.'));
        $this->redirect(route('content.index'), navigate: true);
    }

    /**
     * Post-onboarding Settings save: persist ONLY the settings-relevant fields
     * (profile, offerings, structure, cadence) on an already-onboarded plan.
     * Never touches status and never re-triggers the onboarding jobs
     * (competitor discovery, keyword research, topic ideation) — those belong
     * to the first-run wizard, not to routine settings edits.
     */
    public function saveSettings(): void
    {
        $plan = $this->plan();
        if ($plan === null) {
            return;
        }
        $this->validate([
            'businessDescription' => 'required|string|min:30|max:1000',
        ]);

        $sell = array_values(array_filter(array_map('trim', $this->sellItems)));
        $dont = array_values(array_filter(array_map('trim', $this->dontSellItems)));
        $toggles = array_merge((array) ($plan->toggles ?? []), [
            'key_takeaways' => (bool) ($this->structureToggles['key_takeaways'] ?? true),
            'toc' => (bool) ($this->structureToggles['toc'] ?? true),
            'faq' => (bool) ($this->structureToggles['faq'] ?? true),
        ]);

        $plan->update([
            'business_description' => $this->businessDescription,
            'offerings' => ['sell' => array_slice($sell, 0, 12), 'dont_sell' => array_slice($dont, 0, 12)],
            'language' => $this->language ?: 'en',
            'country' => $this->country ?: null,
            'articles_per_week' => max(1, min(7, $this->articlesPerWeek)),
            'article_length' => max(800, min(4000, $this->articleLength)),
            'auto_publish' => $this->autoPublish,
            'review_hours' => max(0, min(168, $this->reviewHours)),
            'publish_hour_start' => max(0, min(23, $this->publishHourStart)),
            'publish_hour_end' => max(0, min(23, $this->publishHourEnd)),
            'timezone' => in_array($this->publishTimezone, timezone_identifiers_list(), true)
                ? $this->publishTimezone : 'UTC',
            'images_enabled' => $this->imagesEnabled,
            'image_style' => ContentImageStyles::isValid($this->imageStyle)
                ? $this->imageStyle
                : ContentImageStyles::default(),
            'toggles' => $toggles,
        ]);

        session()->flash('content-status', __('Your content settings have been saved.'));
        // Nudge the browser back to the top so the success banner is seen.
        $this->dispatch('content-settings-saved');
    }

    private function guessBrand(?Website $website): string
    {
        if ($website === null) {
            return '';
        }

        return Str::of((string) $website->domain)->before('.')->replace('-', ' ')->title()->value();
    }

    // ── Calendar actions ────────────────────────────────────────────────

    public function previousMonth(): void
    {
        $this->month = Carbon::createFromFormat('Y-m', $this->month)->subMonth()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = Carbon::createFromFormat('Y-m', $this->month)->addMonth()->format('Y-m');
    }

    public function approve(string $topicId): void
    {
        $this->topicOrFail($topicId)?->update(['status' => ContentTopic::STATUS_APPROVED]);
    }

    public function skip(string $topicId): void
    {
        $topic = $this->topicOrFail($topicId);
        if ($topic !== null && in_array($topic->status, [
            ContentTopic::STATUS_SUGGESTED, ContentTopic::STATUS_APPROVED, ContentTopic::STATUS_FAILED,
        ], true)) {
            $topic->update(['status' => ContentTopic::STATUS_SKIPPED]);
        }
    }

    public function retry(string $topicId): void
    {
        $topic = $this->topicOrFail($topicId);
        if ($topic === null || $topic->status !== ContentTopic::STATUS_FAILED) {
            return;
        }
        if (($reason = app(ContentEntitlements::class)->blockReason($topic)) !== null) {
            // Trial used up (or no active plan) → send them straight to the
            // purchase page with a clear prompt, not just a toast.
            if (in_array($reason, ['trial_limit', 'no_access', 'not_covered'], true)) {
                // 'error' is the key the Get started page renders — so the reason
                // (e.g. "You've used all 3 free trial articles") shows there.
                session()->flash('error', self::generationBlockMessage($reason));
                $this->redirect(route('content.get-started'), navigate: true);

                return;
            }
            session()->flash('content-error', self::generationBlockMessage($reason));

            return;
        }
        $topic->forceFill(['status' => ContentTopic::STATUS_APPROVED, 'last_error' => null, 'stage_started_at' => null])->save();
        ProduceContentArticleJob::dispatch($topic->id);
    }

    /** Client-safe copy for a blocked generation, with an upsell CTA where apt. */
    public static function generationBlockMessage(string $reason): string
    {
        return match ($reason) {
            'trial_limit' => __('You\'ve used all :n free trial articles. Choose a plan to keep generating.', ['n' => ContentAutopilotConfig::trialArticles()]),
            'monthly_limit' => __('You\'ve reached your plan\'s limit of :n articles this month for this website. It resets next month, or upgrade for more.', ['n' => ContentAutopilotConfig::monthlyArticlesPerWebsite()]),
            'not_covered' => __('This website is not on your content plan yet. Add it from Get started.'),
            default => __('Content Autopilot is not active for this website. Start it from Get started.'),
        };
    }

    /**
     * Client-triggered "write this now": dispatch the produce pipeline for a
     * not-yet-written topic and open the live progress panel. If it's already
     * generating, just open the panel.
     */
    public function writeNow(string $topicId): void
    {
        $topic = $this->topicOrFail($topicId);
        if ($topic === null) {
            return;
        }
        // Already generating: just open its detail page (progress lives there).
        if (in_array($topic->status, ContentTopic::IN_FLIGHT, true)) {
            $this->redirect(route('content.review', $topic->id), navigate: true);

            return;
        }
        if (! in_array($topic->status, [
            ContentTopic::STATUS_SUGGESTED, ContentTopic::STATUS_APPROVED, ContentTopic::STATUS_FAILED,
        ], true)) {
            return; // ready/scheduled/published — nothing to generate
        }
        // Entitlement/limit pre-check (same rule the job enforces) — never
        // dispatch a generation that would just be blocked; show why instead.
        if (($reason = app(ContentEntitlements::class)->blockReason($topic)) !== null) {
            // Trial used up (or no active plan) → send them straight to the
            // purchase page with a clear prompt, not just a toast.
            if (in_array($reason, ['trial_limit', 'no_access', 'not_covered'], true)) {
                // 'error' is the key the Get started page renders — so the reason
                // (e.g. "You've used all 3 free trial articles") shows there.
                session()->flash('error', self::generationBlockMessage($reason));
                $this->redirect(route('content.get-started'), navigate: true);

                return;
            }
            session()->flash('content-error', self::generationBlockMessage($reason));

            return;
        }
        $topic->forceFill([
            'status' => ContentTopic::STATUS_APPROVED,
            'last_error' => null,
            'stage_started_at' => now(),
        ])->save();
        // Record the overall start so the detail page can show elapsed/ETA
        // without a schema change (1h TTL comfortably covers a produce run).
        Cache::put('content:gen-start:'.$topic->id, now()->timestamp, now()->addHour());
        ProduceContentArticleJob::dispatch($topic->id);
        // Open the article detail page — the teaser + live progress render there.
        $this->redirect(route('content.review', $topic->id), navigate: true);
    }

    /**
     * A FAIR (deliberately conservative) monthly-visits estimate for a topic,
     * from data we already have (keyword_volume) — no extra API cost. This is
     * NOT the best case: it models a realistic mid-page-1 outcome, not a #1
     * ranking. Returns a {low, high} band, or null when we have no volume.
     *
     * @return array{low:int, high:int}|null
     */
    public static function fairMonthlyVisits(ContentTopic $topic): ?array
    {
        $volume = (int) ($topic->keyword_volume ?? 0);
        if ($volume <= 0) {
            return null;
        }
        // Conservative CTR band for a new article that settles mid-page-1 over
        // time: ~1.5% (pos ~10) to ~5% (pos ~5). Well below the ~28% a #1 gets,
        // so the number reads as achievable, not hype.
        $low = (int) round($volume * 0.015);
        $high = (int) round($volume * 0.05);

        return ['low' => max(1, $low), 'high' => max($low + 1, $high)];
    }

    public function reschedule(string $topicId, string $date): void
    {
        $topic = $this->topicOrFail($topicId);
        // Includes SCHEDULED — that status is shown to the client as "Approved"
        // (see statusPresentation) and is exactly the queued-to-publish item they
        // most want to move to a different day. Only published/in-flight are out.
        if ($topic === null || ! in_array($topic->status, [
            ContentTopic::STATUS_SUGGESTED, ContentTopic::STATUS_APPROVED,
            ContentTopic::STATUS_READY, ContentTopic::STATUS_SCHEDULED,
        ], true)) {
            return;
        }
        try {
            $day = Carbon::parse($date)->startOfDay();
        } catch (\Throwable) {
            session()->flash('content-error', __('That date could not be read — please pick a valid date.'));

            return;
        }
        // Allow TODAY (its start-of-day is technically "past"); reject only days
        // before today so a drop onto the current date works.
        if ($day->lt(now()->startOfDay())) {
            session()->flash('content-error', __('Pick today or a future date.'));

            return;
        }
        $from = $topic->scheduled_for?->translatedFormat('M j, Y') ?? __('unscheduled');
        if ($topic->scheduled_for !== null && $topic->scheduled_for->isSameDay($day)) {
            return; // no change
        }
        // The planner fills one per day, but the user may stack a second article
        // on a day manually — so no one-per-day guard here.
        $topic->update(['scheduled_for' => $day]);
        session()->flash('content-status', __('Moved “:title” from :from to :to.', [
            'title' => Str::limit((string) $topic->title, 40),
            'from' => $from,
            'to' => $day->translatedFormat('M j, Y'),
        ]));
    }

    /**
     * Publish a ready/scheduled article to the connected destination(s) RIGHT
     * NOW — bypasses the plan's publish window (that gate lives only in the
     * dispatcher). Requires a connected integration; otherwise we tell the
     * client to connect one instead of silently doing nothing.
     */
    public function publishNow(string $topicId): void
    {
        $topic = $this->topicOrFail($topicId);
        if (! self::publishableNow($topic)) {
            return; // gone, wrong status, or scheduled for a future day
        }
        if (! $this->hasPublishDestination()) {
            session()->flash('content-error', __('Connect a site in Settings → Integrations before publishing.'));

            return;
        }
        // Flip to PUBLISHING synchronously so this Livewire re-render already
        // shows the in-progress state (spinner) without a manual refresh — the
        // publish job accepts SCHEDULED or PUBLISHING.
        $topic->enterStage(ContentTopic::STATUS_PUBLISHING);
        PublishContentArticleJob::dispatch($topic->id);
        session()->flash('content-status', __('Publishing now — it can take a moment to appear on your site.'));
    }

    /**
     * Human label for a plan's auto-publish window, e.g. "9:00–11:00 (Karachi)".
     * When start==end it's a single hour. Timezone shown as its short city name.
     */
    public static function publishWindowLabel(?ContentPlan $plan): string
    {
        $start = (int) ($plan->publish_hour_start ?? 9);
        $end = (int) ($plan->publish_hour_end ?? 11);
        $tz = (string) ($plan->timezone ?: 'UTC');
        $city = str_replace('_', ' ', last(explode('/', $tz)) ?: $tz);
        $fmt = static fn (int $h) => sprintf('%d:00 %s', ($h % 12) ?: 12, $h < 12 ? 'AM' : 'PM');

        return $start === $end
            ? $fmt($start).' ('.$city.')'
            : $fmt($start).'–'.$fmt($end).' ('.$city.')';
    }

    /**
     * Whether a topic can be published RIGHT NOW: it has an article, is in a
     * publishable status, and its scheduled date is today or earlier (date only,
     * time ignored). Future-dated articles must wait for their day.
     */
    /**
     * A READY article whose images are still being generated — shown as
     * "Finalizing images…" so it doesn't read "Ready for review" prematurely.
     * The flag is set at image dispatch and cleared on every image-job exit.
     */
    public static function imagesPending(?ContentTopic $topic): bool
    {
        $article = $topic?->currentArticle;

        return $topic !== null
            && $topic->status === ContentTopic::STATUS_READY
            && $article !== null
            && Cache::has('content:images:pending:'.$article->id);
    }

    public static function publishableNow(?ContentTopic $topic): bool
    {
        if ($topic === null || $topic->currentArticle === null) {
            return false;
        }
        if (! in_array($topic->status, [ContentTopic::STATUS_READY, ContentTopic::STATUS_SCHEDULED], true)) {
            return false;
        }

        return $topic->scheduled_for === null
            || $topic->scheduled_for->toDateString() <= now()->toDateString();
    }

    /** Whether the active plan's website has a connected publishing destination. */
    private function hasPublishDestination(): bool
    {
        return (bool) $this->activePlan()?->website
            ?->contentIntegrations()
            ->where('status', ContentIntegration::STATUS_CONNECTED)
            ->exists();
    }

    public function addTopic(): void
    {
        $plan = $this->activePlan();
        if ($plan === null) {
            return;
        }
        $this->validate([
            'newTitle' => 'required|string|min:8|max:300',
            'newKeyword' => 'required|string|min:2|max:200',
        ], [], ['newTitle' => __('title'), 'newKeyword' => __('keyword')]);

        $plan->topics()->create([
            'website_id' => $this->websiteId,
            'title' => $this->newTitle,
            'target_keyword' => mb_strtolower(trim($this->newKeyword)),
            'source' => 'manual',
            'status' => ContentTopic::STATUS_APPROVED,
            'scheduled_for' => now()->addDays(2)->startOfDay(),
        ]);

        $this->reset('newTitle', 'newKeyword', 'showAddTopic');
    }

    /** Create a topic from the inline form AND start writing it immediately. */
    public function addAndWriteTopic(): void
    {
        $plan = $this->activePlan();
        if ($plan === null) {
            return;
        }
        $this->validate([
            'newTitle' => 'required|string|min:8|max:300',
            'newKeyword' => 'required|string|min:2|max:200',
        ], [], ['newTitle' => __('title'), 'newKeyword' => __('keyword')]);

        $topic = $plan->topics()->create([
            'website_id' => $this->websiteId,
            'title' => $this->newTitle,
            'target_keyword' => mb_strtolower(trim($this->newKeyword)),
            'source' => 'manual',
            'status' => ContentTopic::STATUS_APPROVED,
            'scheduled_for' => now()->startOfDay(),
        ]);

        $this->reset('newTitle', 'newKeyword', 'showAddTopic');
        $this->writeNow($topic->id);
    }

    // ── Presentation helpers ────────────────────────────────────────────

    /** @return array{label:string, color:string} */
    public static function statusPresentation(string $status): array
    {
        return match ($status) {
            ContentTopic::STATUS_SUGGESTED => ['label' => __('Suggested'), 'color' => 'slate'],
            ContentTopic::STATUS_APPROVED => ['label' => __('Planned'), 'color' => 'sky'],
            ContentTopic::STATUS_RESEARCHING,
            ContentTopic::STATUS_WRITING,
            ContentTopic::STATUS_SCORING,
            ContentTopic::STATUS_REVISING => ['label' => __('In progress'), 'color' => 'amber'],
            ContentTopic::STATUS_READY => ['label' => __('Ready for review'), 'color' => 'emerald'],
            ContentTopic::STATUS_SCHEDULED => ['label' => __('Approved'), 'color' => 'emerald'],
            ContentTopic::STATUS_PUBLISHING => ['label' => __('Publishing'), 'color' => 'amber'],
            ContentTopic::STATUS_PUBLISHED => ['label' => __('Published'), 'color' => 'emerald'],
            ContentTopic::STATUS_FAILED => ['label' => __('Needs attention'), 'color' => 'rose'],
            default => ['label' => __('Skipped'), 'color' => 'slate'],
        };
    }

    // ── Data access ─────────────────────────────────────────────────────

    private function website(): ?Website
    {
        if (! $this->websiteId) {
            return null;
        }

        return Auth::user()?->accessibleWebsitesQuery()->whereKey($this->websiteId)->first();
    }

    /** Any plan for this website (draft/active/paused). */
    private function plan(): ?ContentPlan
    {
        return $this->websiteId
            ? ContentPlan::query()->where('website_id', $this->websiteId)->first()
            : null;
    }

    private function activePlan(): ?ContentPlan
    {
        $plan = $this->plan();

        return ($plan !== null && $plan->status !== ContentPlan::STATUS_DRAFT) ? $plan : null;
    }

    private function topicOrFail(string $topicId): ?ContentTopic
    {
        return $this->activePlan()?->topics()->whereKey($topicId)->first();
    }

    // ── Insight builders (real topic/GSC data) ──────────────────────────

    private function overviewStats($topics): array
    {
        $inProgress = [ContentTopic::STATUS_RESEARCHING, ContentTopic::STATUS_WRITING,
            ContentTopic::STATUS_SCORING, ContentTopic::STATUS_REVISING, ContentTopic::STATUS_PUBLISHING];

        return [
            // "Planned" = everything queued but not yet writing/ready/published:
            // SUGGESTED + APPROVED + SCHEDULED (shown as "Approved") + FAILED
            // (awaiting retry). Without SCHEDULED/FAILED the cards under-count vs
            // the calendar total.
            'planned' => $topics->whereIn('status', [
                ContentTopic::STATUS_SUGGESTED, ContentTopic::STATUS_APPROVED,
                ContentTopic::STATUS_SCHEDULED, ContentTopic::STATUS_FAILED,
            ])->count(),
            'in_progress' => $topics->whereIn('status', $inProgress)->count(),
            'ready' => $topics->where('status', ContentTopic::STATUS_READY)->count(),
            'published' => $topics->where('status', ContentTopic::STATUS_PUBLISHED)->count(),
            'from_search' => $topics->where('source', 'gsc_gap')->count(),
            'monthly_searches' => (int) $topics->sum('keyword_volume'),
        ];
    }

    /** @return list<array{keyword:string, volume:?int}> */
    private function audienceSearches($topics): array
    {
        return $topics->sortByDesc(fn ($t) => (int) $t->keyword_volume)->take(8)
            ->map(fn ($t) => ['keyword' => (string) $t->target_keyword, 'volume' => $t->keyword_volume ? (int) $t->keyword_volume : null])
            ->values()->all();
    }

    /** @return list<array{theme:string, topics:list<array{id:string,title:string,status:string}>}> */
    private function strategyClusters($topics): array
    {
        if ($topics->isEmpty()) {
            return [];
        }

        $stop = ['the', 'a', 'an', 'for', 'to', 'of', 'and', 'in', 'on', 'your', 'how', 'what',
            'best', 'guide', 'with', 'vs', 'or', 'is', 'are'];
        $stem = static fn (string $t): string => (mb_strlen($t) > 4 && str_ends_with($t, 's') && ! str_ends_with($t, 'ss'))
            ? mb_substr($t, 0, -1) : $t;

        $rows = $topics->values();
        $total = $rows->count();
        $tokensPerRow = [];
        $freq = [];
        foreach ($rows as $i => $t) {
            $tokens = array_values(array_unique(array_map($stem, array_filter(array_diff(
                preg_split('/[^a-z0-9]+/', mb_strtolower((string) $t->target_keyword), -1, PREG_SPLIT_NO_EMPTY) ?: [],
                $stop
            ), fn ($tok) => mb_strlen($tok) >= 3))));
            $tokensPerRow[$i] = $tokens;
            foreach ($tokens as $token) {
                $freq[$token] = ($freq[$token] ?? 0) + 1;
            }
        }

        $ubiquitous = [];
        foreach ($freq as $token => $count) {
            if ($count > max(2, (int) floor($total * 0.45))) {
                $ubiquitous[$token] = true;
            }
        }

        $byToken = [];
        foreach ($tokensPerRow as $i => $tokens) {
            foreach ($tokens as $token) {
                if (! isset($ubiquitous[$token])) {
                    $byToken[$token][] = $i;
                }
            }
        }
        uasort($byToken, fn ($a, $b) => count($b) <=> count($a));

        $assigned = [];
        $clusters = [];
        foreach ($byToken as $token => $idxs) {
            $members = array_values(array_filter($idxs, fn ($i) => ! isset($assigned[$i])));
            if (count($members) < 2) {
                continue;
            }
            foreach ($members as $i) {
                $assigned[$i] = true;
            }
            $clusters[] = [
                'theme' => Str::title($token),
                'topics' => array_map(fn ($i) => [
                    'id' => (string) $rows[$i]->id, 'title' => (string) $rows[$i]->title, 'status' => (string) $rows[$i]->status,
                ], $members),
            ];
            if (count($clusters) >= 6) {
                break;
            }
        }

        $others = [];
        foreach ($rows as $i => $t) {
            if (! isset($assigned[$i])) {
                $others[] = ['id' => (string) $t->id, 'title' => (string) $t->title, 'status' => (string) $t->status];
            }
        }
        if ($others !== []) {
            $clusters[] = ['theme' => __('More topics'), 'topics' => array_slice($others, 0, 10)];
        }

        return $clusters;
    }

    /**
     * Topics generated so far for the "first articles" step. Scoped to the
     * pre-publish pipeline (never scheduled/publishing/published) — Settings
     * can revisit this step on an already-active plan, and a topic already
     * live on the Calendar shouldn't appear here with a removable checkmark.
     */
    private function draftTopics()
    {
        $plan = $this->plan();
        if ($plan === null) {
            return collect();
        }

        return $plan->topics()
            ->whereIn('status', [
                ContentTopic::STATUS_SUGGESTED, ContentTopic::STATUS_APPROVED,
                ContentTopic::STATUS_RESEARCHING, ContentTopic::STATUS_WRITING,
                ContentTopic::STATUS_SCORING, ContentTopic::STATUS_REVISING,
                ContentTopic::STATUS_READY, ContentTopic::STATUS_FAILED,
            ])
            ->orderBy('position')
            ->limit(12)
            ->get(['id', 'title', 'target_keyword', 'keyword_volume', 'source']);
    }

    public function render()
    {
        $plan = $this->activePlan();
        // Post-onboarding (plan is no longer a draft) the Settings page becomes
        // a real SETTINGS layout — profile/offerings/structure/cadence only —
        // skipping the onboarding-only steps (how-it-works, competitors,
        // keyword research, first articles). The full 6-step wizard is reserved
        // for first-run setup (draft or no plan yet).
        $settingsView = $this->mode === 'settings' && $plan !== null;
        $inWizard = $this->mode === 'settings' && ! $settingsView;
        $needsSetup = $this->mode === 'calendar' && $plan === null;

        if ($settingsView) {
            return view('livewire.content.content-calendar', [
                'inWizard' => false,
                'needsSetup' => false,
                'settingsView' => true,
                'wizard' => [],
                'plan' => $plan,
                'guard' => $this->guardState($plan),
            ] + $this->emptyCalendarBindings());
        }

        // ── Wizard data ──
        $wizard = [];
        if ($inWizard) {
            $insights = null;
            $generating = false;
            $needsReportGen = false;
            $hasOverrides = false;
            if ($this->wizardStep === 5 && ($w = $this->website()) !== null) {
                $svc = app(ContentSetupInsights::class);
                $rawInsights = $svc->competitorAuthority($w);
                $needsReportGen = $rawInsights === null;
                $plan4 = $this->plan();
                $insights = $plan4 !== null ? $svc->withOverrides($rawInsights, $plan4) : $rawInsights;
                $generating = $needsReportGen && $insights === null && $svc->isGenerating($w);
                $overrides = (array) ($plan4?->competitor_overrides ?? []);
                $hasOverrides = ! empty($overrides['added']) || ! empty($overrides['removed']);
            }
            $keywords = null;
            $keywordStatus = [];
            if ($this->wizardStep === 6 && ($plan5 = $this->plan()) !== null) {
                $kwSvc = app(ContentKeywordInsights::class);
                $keywords = $kwSvc->get($plan5);
                if ($keywords === null) {
                    $keywordStatus = $kwSvc->researchStatus($plan5);
                }
            }
            // Competitor-mention guard card state (competitors step).
            $guardState = $this->wizardStep === 5 && ($plan4g = $this->plan()) !== null
                ? $this->guardState($plan4g)
                : null;

            $wizard = [
                'guard' => $guardState,
                'draftTopics' => $this->wizardStep >= 7 ? $this->draftTopics() : collect(),
                'insights' => $insights,
                'generating' => $generating,
                'needsReportGen' => $needsReportGen,
                'hasOverrides' => $hasOverrides,
                'keywords' => $keywords,
                'keywordStatus' => $keywordStatus,
                'hasWebsite' => $this->website() !== null,
            ];

            return view('livewire.content.content-calendar', [
                'inWizard' => true,
                'needsSetup' => false,
                'wizard' => $wizard,
            ] + $this->emptyCalendarBindings());
        }

        if ($needsSetup) {
            return view('livewire.content.content-calendar', [
                'inWizard' => false,
                'needsSetup' => true,
                'wizard' => [],
            ] + $this->emptyCalendarBindings());
        }

        // ── Calendar data ──
        $monthStart = Carbon::createFromFormat('Y-m', $this->month)->startOfMonth();
        $topics = $plan->topics()
            ->whereNotIn('status', [ContentTopic::STATUS_SKIPPED]) // skipped ones aren't planned articles
            ->with('currentArticle:id,topic_id,seo_score,word_count,version')
            ->whereBetween('scheduled_for', [$monthStart->copy()->startOfWeek(), $monthStart->copy()->endOfMonth()->endOfWeek()])
            ->orderBy('scheduled_for')->orderBy('position')->get();

        $days = [];
        $cursor = $monthStart->copy()->startOfWeek();
        $end = $monthStart->copy()->endOfMonth()->endOfWeek();
        while ($cursor <= $end) {
            $days[] = $cursor->copy();
            $cursor->addDay();
        }

        $all = $plan->topics()->whereNotIn('status', [ContentTopic::STATUS_SKIPPED])
            ->get(['id', 'title', 'target_keyword', 'secondary_keywords', 'keyword_volume', 'source', 'status']);

        return view('livewire.content.content-calendar', [
            'inWizard' => false,
            'needsSetup' => false,
            'settingsView' => false,
            'wizard' => [],
            'plan' => $plan,
            'topics' => $topics,
            'topicsByDate' => $topics->groupBy(fn ($t) => $t->scheduled_for?->toDateString() ?? ''),
            'days' => $days,
            'monthStart' => $monthStart,
            'hasWebsite' => true,
            // Count the KPI cards from the SAME set the calendar renders ($topics,
            // the visible month) so the four cards always sum to the dots on the
            // grid — using $all (every month) made "Planned" not match the month.
            'stats' => $this->overviewStats($topics),
            'audience' => $this->audienceSearches($all),
            'clusters' => $this->strategyClusters($all),
            'publishConnected' => $this->hasPublishDestination(),
            'hasInFlight' => $topics->contains(fn ($t) => in_array($t->status, ContentTopic::IN_FLIGHT, true)),
            'hasImagesPending' => $topics->contains(fn ($t) => self::imagesPending($t)),
        ] + $this->capAndTrialBindings($topics, $monthStart));
    }

    /**
     * Monthly-cap (mark the cap-th article + beyond) and trial-limit view data.
     * The cap comes from plan/admin settings (default 30).
     */
    private function capAndTrialBindings($topics, Carbon $monthStart): array
    {
        $cap = ContentAutopilotConfig::monthlyArticlesPerWebsite();
        $monthKey = $monthStart->format('Y-m');
        $rank = 0;
        $overCapIds = [];
        foreach ($topics->sortBy('scheduled_for') as $t) {
            if ($t->scheduled_for?->format('Y-m') !== $monthKey) {
                continue;
            }
            $rank++;
            if ($rank >= $cap) { // the cap-th (your last) and anything beyond
                $overCapIds[] = $t->id;
            }
        }

        $user = Auth::user();
        $ent = app(ContentEntitlements::class);
        $trialActive = $user !== null && $ent->onContentTrial($user) && ! $ent->hasContentSubscription($user);

        return [
            'monthlyCap' => $cap,
            'overCapIds' => $overCapIds,
            'monthOverCap' => $rank > $cap,
            'trialActive' => $trialActive,
            'trialUsed' => $trialActive ? $ent->trialUsage($user) : 0,
            'trialCap' => ContentAutopilotConfig::trialArticles(),
        ];
    }

    /** Bindings the calendar branch needs so the wizard branch can omit them. */
    private function emptyCalendarBindings(): array
    {
        return [
            'settingsView' => false,
            'plan' => null,
            'topics' => collect(),
            'topicsByDate' => collect(),
            'days' => [],
            'monthStart' => Carbon::createFromFormat('Y-m', $this->month)->startOfMonth(),
            'hasWebsite' => $this->website() !== null,
            'stats' => [],
            'audience' => [],
            'clusters' => [],
            'publishConnected' => false,
            'hasInFlight' => false,
        ];
    }
}
