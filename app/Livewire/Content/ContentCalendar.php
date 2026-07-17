<?php

namespace App\Livewire\Content;

use App\Jobs\PlanContentTopicsJob;
use App\Jobs\ProduceContentArticleJob;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\Website;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Content Autopilot main page: the setup wizard (while the website has no
 * plan) and the monthly calendar (grid + list) once it does.
 *
 * Client copy invariant: pipeline internals (scores below floor, spend caps,
 * model names) NEVER surface here — statuses map to neutral labels via
 * statusLabel()/statusColor().
 */
class ContentCalendar extends Component
{
    public ?string $websiteId = null;

    // Calendar state
    public string $month = '';        // Y-m
    public string $view = 'grid';     // grid|list

    // Wizard state (shown while no plan exists)
    public int $wizardStep = 1;
    public bool $analyzing = false;
    public bool $analyzed = false;
    public string $businessDescription = '';
    public string $sellInput = '';
    public string $dontSellInput = '';
    public int $articlesPerWeek = 3;
    public int $articleLength = 2000;
    public bool $autoPublish = false;
    public bool $includeToc = true;
    public bool $includeTakeaways = true;
    public bool $includeFaq = true;

    // Inline add-topic form
    public bool $showAddTopic = false;
    public string $newTitle = '';
    public string $newKeyword = '';

    public function mount(): void
    {
        $this->websiteId = session('current_website_id');
        $this->month = now()->format('Y-m');
        $this->prefillWizard();
    }

    #[On('website-changed')]
    public function switchWebsite(string $websiteId): void
    {
        $this->websiteId = $websiteId;
        $this->wizardStep = 1;
        $this->prefillWizard();
    }

    // ── Wizard ──────────────────────────────────────────────────────────

    /**
     * Arm the deferred site analysis (wire:init) — the LLM extraction takes
     * seconds, so it must not block the first paint.
     */
    private function prefillWizard(): void
    {
        $this->analyzing = $this->website() !== null && $this->plan() === null;
        $this->analyzed = false;
    }

    /**
     * Auto-detect the business profile from crawl data (SiteProfileExtractor:
     * one cached LLM call over the site's own pages). Owner QA 2026-07-17:
     * offerings + description must be auto-detected, manual entry stays as
     * the override. Falls back to the homepage meta description.
     */
    public function analyzeSite(): void
    {
        $this->analyzing = false;
        $this->analyzed = true;

        $website = $this->website();
        if ($website === null || $this->plan() !== null) {
            return;
        }

        $profile = app(\App\Services\Content\SiteProfileExtractor::class)->extract($website);

        if ($this->businessDescription === '') {
            $this->businessDescription = (string) ($profile['description'] ?? '');
        }
        if ($this->sellInput === '' && $profile['sell'] !== []) {
            $this->sellInput = implode("\n", $profile['sell']);
        }
        if ($this->dontSellInput === '' && $profile['dont_sell'] !== []) {
            $this->dontSellInput = implode("\n", $profile['dont_sell']);
        }

        // Fallback: homepage meta description beats an empty box.
        if ($this->businessDescription === '') {
            try {
                if ($website->crawl_site_id) {
                    $this->businessDescription = (string) (DB::table('website_pages')
                        ->where('crawl_site_id', $website->crawl_site_id)
                        ->whereNotNull('meta_description')
                        ->where('meta_description', '!=', '')
                        ->orderBy('url')
                        ->limit(1)
                        ->value('meta_description') ?? '');
                }
            } catch (\Throwable) {
                // No crawl data yet — the client types it.
            }
        }
    }

    public function nextStep(): void
    {
        $this->validate([
            'businessDescription' => 'required|string|min:30|max:1000',
        ], [], ['businessDescription' => __('business description')]);

        $this->wizardStep = 2;
    }

    public function backStep(): void
    {
        $this->wizardStep = 1;
    }

    public function createPlan(): void
    {
        $website = $this->website();
        if ($website === null) {
            return;
        }
        if (! ($website->effectiveFeatureFlags()['content_autopilot'] ?? false)) {
            $this->addError('plan', __('Content Autopilot is not included in your plan.'));

            return;
        }

        $this->validate([
            'articlesPerWeek' => 'required|integer|min:1|max:7',
            'articleLength' => 'required|integer|in:1500,2000,2500,3000',
        ]);

        $split = fn (string $raw) => array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', $raw) ?: [])));

        ContentPlan::query()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
            'articles_per_week' => $this->articlesPerWeek,
            'article_length' => $this->articleLength,
            'auto_publish' => $this->autoPublish,
            'review_hours' => 24,
            'toggles' => [
                'toc' => $this->includeToc,
                'key_takeaways' => $this->includeTakeaways,
                'faq' => $this->includeFaq,
                'external_links' => true,
                'cta_enabled' => false,
            ],
            'cta_url' => null,
            'business_description' => $this->businessDescription,
            'offerings' => [
                'sell' => array_slice($split($this->sellInput), 0, 12),
                'dont_sell' => array_slice($split($this->dontSellInput), 0, 12),
            ],
            'language' => $website->owner_locale ?? 'en',
            'country' => null,
        ]);

        PlanContentTopicsJob::dispatch($this->plan()->id);

        session()->flash('content-status', __('Your content calendar is being prepared. First topics appear within a few minutes.'));
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
            $topic->forceFill([
                'status' => ContentTopic::STATUS_APPROVED,
                'last_error' => null,
                'stage_started_at' => null,
            ])->save();
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
        $plan = $this->plan();
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
        $plan = $this->plan();
        $plan?->update(['status' => $plan->isActive() ? ContentPlan::STATUS_PAUSED : ContentPlan::STATUS_ACTIVE]);
    }

    // ── Presentation helpers (used by the blade) ────────────────────────

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

    private function plan(): ?ContentPlan
    {
        return $this->websiteId
            ? ContentPlan::query()->where('website_id', $this->websiteId)->first()
            : null;
    }

    private function topicOrFail(string $topicId): ?ContentTopic
    {
        $plan = $this->plan();

        return $plan?->topics()->whereKey($topicId)->first();
    }

    public function render()
    {
        $plan = $this->plan();
        $monthStart = Carbon::createFromFormat('Y-m', $this->month)->startOfMonth();

        $topics = $plan === null ? collect() : $plan->topics()
            ->with('currentArticle:id,topic_id,seo_score,word_count,version')
            ->whereBetween('scheduled_for', [
                $monthStart->copy()->startOfWeek(),
                $monthStart->copy()->endOfMonth()->endOfWeek(),
            ])
            ->orderBy('scheduled_for')->orderBy('position')
            ->get();

        // Build the week grid (Mon-start) covering the month.
        $days = [];
        $cursor = $monthStart->copy()->startOfWeek();
        $end = $monthStart->copy()->endOfMonth()->endOfWeek();
        while ($cursor <= $end) {
            $days[] = $cursor->copy();
            $cursor->addDay();
        }

        // All topics (not just this month) power the overview + strategy map.
        $all = $plan === null ? collect() : $plan->topics()
            ->whereNotIn('status', [ContentTopic::STATUS_SKIPPED])
            ->get(['id', 'title', 'target_keyword', 'secondary_keywords', 'keyword_volume', 'source', 'status']);

        return view('livewire.content.content-calendar', [
            'plan' => $plan,
            'topics' => $topics,
            'topicsByDate' => $topics->groupBy(fn ($t) => $t->scheduled_for?->toDateString() ?? ''),
            'days' => $days,
            'monthStart' => $monthStart,
            'hasWebsite' => $this->website() !== null,
            'stats' => $this->overviewStats($all),
            'audience' => $this->audienceSearches($all),
            'clusters' => $this->strategyClusters($all),
        ]);
    }

    // ── Insight builders (real topic/GSC data) ──────────────────────────

    /** @return array<string,int> headline counts for the overview strip */
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

    /**
     * The "what your audience is searching" panel — real target keywords
     * ranked by monthly search volume (from GSC-gap ideation).
     *
     * @return list<array{keyword:string, volume:?int}>
     */
    private function audienceSearches($topics): array
    {
        return $topics
            ->sortByDesc(fn ($t) => (int) $t->keyword_volume)
            ->take(8)
            ->map(fn ($t) => ['keyword' => (string) $t->target_keyword, 'volume' => $t->keyword_volume ? (int) $t->keyword_volume : null])
            ->values()->all();
    }

    /**
     * Content-strategy clusters: group topics into themes by their most
     * common shared keyword token (greedy), so the strategy map shows how
     * the calendar's articles connect into content pillars.
     *
     * @return list<array{theme:string, topics:list<array{id:string,title:string,status:string}>}>
     */
    private function strategyClusters($topics): array
    {
        if ($topics->isEmpty()) {
            return [];
        }

        $stop = ['the', 'a', 'an', 'for', 'to', 'of', 'and', 'in', 'on', 'your', 'how', 'what',
            'best', 'guide', 'with', 'vs', 'or', 'is', 'are', 'name', 'names'];

        // token => [topic indices]
        $byToken = [];
        $rows = $topics->values();
        foreach ($rows as $i => $t) {
            $tokens = array_unique(array_diff(
                preg_split('/[^a-z0-9]+/', mb_strtolower((string) $t->target_keyword), -1, PREG_SPLIT_NO_EMPTY) ?: [],
                $stop
            ));
            foreach ($tokens as $token) {
                if (mb_strlen($token) >= 3) {
                    $byToken[$token][] = $i;
                }
            }
        }
        // Most-covering tokens first.
        uasort($byToken, fn ($a, $b) => count($b) <=> count($a));

        $assigned = [];
        $clusters = [];
        foreach ($byToken as $token => $idxs) {
            $members = array_values(array_filter($idxs, fn ($i) => ! isset($assigned[$i])));
            if (count($members) < 2) {
                continue; // a pillar needs at least 2 articles
            }
            foreach ($members as $i) {
                $assigned[$i] = true;
            }
            $clusters[] = [
                'theme' => Str::title($token),
                'topics' => array_map(fn ($i) => [
                    'id' => (string) $rows[$i]->id,
                    'title' => (string) $rows[$i]->title,
                    'status' => (string) $rows[$i]->status,
                ], $members),
            ];
            if (count($clusters) >= 6) {
                break;
            }
        }

        // Leftovers → an "Other" pillar so nothing is hidden.
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
}
