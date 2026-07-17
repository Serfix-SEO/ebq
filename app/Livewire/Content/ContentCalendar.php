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
    public string $businessDescription = '';
    public string $sellInput = '';
    public string $dontSellInput = '';
    public int $articlesPerWeek = 3;
    public int $articleLength = 2000;
    public bool $autoPublish = false;
    public bool $includeToc = true;
    public bool $includeTakeaways = true;
    public bool $includeFaq = true;
    public string $ctaUrl = '';

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

    /** Pre-fill the business description from data we already hold. */
    private function prefillWizard(): void
    {
        $website = $this->website();
        if ($website === null || $this->plan() !== null || $this->businessDescription !== '') {
            return;
        }

        try {
            if ($website->crawl_site_id) {
                $home = DB::table('website_pages')
                    ->where('crawl_site_id', $website->crawl_site_id)
                    ->whereNotNull('meta_description')
                    ->where('meta_description', '!=', '')
                    ->orderBy('url')
                    ->limit(1)
                    ->value('meta_description');
                $this->businessDescription = (string) ($home ?? '');
            }
        } catch (\Throwable) {
            // No crawl data yet — the client types it.
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
            'ctaUrl' => 'nullable|url|max:500',
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
                'cta_enabled' => $this->ctaUrl !== '',
            ],
            'cta_url' => $this->ctaUrl ?: null,
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

        return view('livewire.content.content-calendar', [
            'plan' => $plan,
            'topics' => $topics,
            'topicsByDate' => $topics->groupBy(fn ($t) => $t->scheduled_for?->toDateString() ?? ''),
            'days' => $days,
            'monthStart' => $monthStart,
            'hasWebsite' => $this->website() !== null,
        ]);
    }
}
