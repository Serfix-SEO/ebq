<?php

namespace App\Livewire\Content;

use App\Jobs\PlanContentTopicsJob;
use App\Jobs\ProduceContentArticleJob;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\Website;
use App\Services\Content\ContentSetupInsights;
use App\Services\Content\SiteProfileExtractor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
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
 * 3 how-it-works → 4 competitors & authority → 5 first articles. A DRAFT plan
 * is created at the end of step 2 (first time only) so topic ideation runs in
 * the BACKGROUND while the user reads steps 3-4; by step 5 real topics are
 * ready to show. Finishing the wizard activates the plan (article writing
 * begins) and returns to the Calendar page.
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

    // Inline add-topic form (calendar)
    public bool $showAddTopic = false;
    public string $newTitle = '';
    public string $newKeyword = '';

    public function mount(string $mode = 'calendar'): void
    {
        $this->mode = in_array($mode, ['calendar', 'settings'], true) ? $mode : 'calendar';
        $this->websiteId = session('current_website_id');
        $this->month = now()->format('Y-m');
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
            $this->draftPlanId = $existing->id;
            $this->businessDescription = $this->businessDescription ?: (string) $existing->business_description;
            $offerings = (array) ($existing->offerings ?? []);
            $this->sellItems = $this->sellItems ?: array_values((array) ($offerings['sell'] ?? []));
            $this->dontSellItems = $this->dontSellItems ?: array_values((array) ($offerings['dont_sell'] ?? []));
            $this->language = $existing->language ?: 'en';
            $this->country = (string) ($existing->country ?? '');
            if ($existing->status === ContentPlan::STATUS_DRAFT) {
                $this->wizardStep = max($this->wizardStep, 3);
            }
            $this->analyzing = false;

            return;
        }

        $this->analyzing = $website !== null;
    }

    /** Auto-detect the business profile from crawl data (cached LLM call). */
    public function analyzeSite(): void
    {
        $this->analyzing = false;

        $website = $this->website();
        if ($website === null || $this->plan() !== null) {
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
        $max = $this->draftPlanId !== null ? 5 : 2;
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

    public function moveSell(int $i, int $dir): void
    {
        $j = $i + $dir;
        if (isset($this->sellItems[$i], $this->sellItems[$j])) {
            [$this->sellItems[$i], $this->sellItems[$j]] = [$this->sellItems[$j], $this->sellItems[$i]];
        }
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
                'toggles' => $existing?->toggles ?? ['toc' => true, 'key_takeaways' => true, 'faq' => true,
                    'external_links' => true, 'cta_enabled' => false],
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

        $this->wizardStep = 3;
    }

    public function toCompetitors(): void
    {
        $this->wizardStep = 4;
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
    }

    /** Poll target on step 4 while generation is in flight. */
    public function refreshCompetitors(): void
    {
        // A no-op action: re-rendering re-reads the (now possibly ready) cache.
        // Clear the memoized null so a freshly-landed snapshot is picked up.
        $website = $this->website();
        if ($website !== null) {
            app(ContentSetupInsights::class)->forget($website);
        }
    }

    public function toFirstArticles(): void
    {
        $this->wizardStep = 5;
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
        if ($topic !== null && $topic->status === ContentTopic::STATUS_FAILED) {
            $topic->forceFill(['status' => ContentTopic::STATUS_APPROVED, 'last_error' => null, 'stage_started_at' => null])->save();
            ProduceContentArticleJob::dispatch($topic->id);
        }
    }

    public function reschedule(string $topicId, string $date): void
    {
        $topic = $this->topicOrFail($topicId);
        if ($topic === null || ! in_array($topic->status, [
            ContentTopic::STATUS_SUGGESTED, ContentTopic::STATUS_APPROVED, ContentTopic::STATUS_READY,
        ], true)) {
            return;
        }
        try {
            $day = Carbon::parse($date)->startOfDay();
        } catch (\Throwable) {
            return;
        }
        if ($day->isPast()) {
            return;
        }
        $topic->update(['scheduled_for' => $day]);
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

    public function pauseOrResume(): void
    {
        $plan = $this->activePlan();
        $plan?->update(['status' => $plan->isActive() ? ContentPlan::STATUS_PAUSED : ContentPlan::STATUS_ACTIVE]);
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
            'planned' => $topics->whereIn('status', [ContentTopic::STATUS_SUGGESTED, ContentTopic::STATUS_APPROVED])->count(),
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
        // Settings always shows the wizard. Calendar shows the wizard ONLY
        // implicitly never — a plan-less Calendar page gets a lightweight
        // "set it up in Settings" prompt instead (see $needsSetup below).
        $inWizard = $this->mode === 'settings';
        $needsSetup = $this->mode === 'calendar' && $plan === null;

        // ── Wizard data ──
        $wizard = [];
        if ($inWizard) {
            $insights = null;
            $generating = false;
            if ($this->wizardStep === 4 && ($w = $this->website()) !== null) {
                $svc = app(ContentSetupInsights::class);
                $insights = $svc->competitorAuthority($w);
                $generating = $insights === null && $svc->isGenerating($w);
            }
            $wizard = [
                'draftTopics' => $this->wizardStep >= 5 ? $this->draftTopics() : collect(),
                'insights' => $insights,
                'generating' => $generating,
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
            'wizard' => [],
            'plan' => $plan,
            'topics' => $topics,
            'topicsByDate' => $topics->groupBy(fn ($t) => $t->scheduled_for?->toDateString() ?? ''),
            'days' => $days,
            'monthStart' => $monthStart,
            'hasWebsite' => true,
            'stats' => $this->overviewStats($all),
            'audience' => $this->audienceSearches($all),
            'clusters' => $this->strategyClusters($all),
        ]);
    }

    /** Bindings the calendar branch needs so the wizard branch can omit them. */
    private function emptyCalendarBindings(): array
    {
        return [
            'plan' => null,
            'topics' => collect(),
            'topicsByDate' => collect(),
            'days' => [],
            'monthStart' => Carbon::createFromFormat('Y-m', $this->month)->startOfMonth(),
            'hasWebsite' => $this->website() !== null,
            'stats' => [],
            'audience' => [],
            'clusters' => [],
        ];
    }
}
