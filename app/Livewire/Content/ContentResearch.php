<?php

namespace App\Livewire\Content;

use App\Jobs\Content\RefineTopicSecondaryKeywordsJob;
use App\Jobs\PrepareContentKeywordInsightsJob;
use App\Jobs\ProduceContentArticleJob;
use App\Models\ContentPlan;
use App\Models\ContentPlanKeyword;
use App\Models\ContentTopic;
use App\Models\Website;
use App\Services\Content\ContentEntitlements;
use App\Services\Content\ContentKeywordInsights;
use App\Services\Content\ContentTopicPlanner;
use App\Services\Content\KeywordWinnability;
use App\Support\ContentAutopilotConfig;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Content Autopilot → Research. The client-facing keyword-ideas feed: every
 * keyword the always-on research has vetted for this site (competitor gap,
 * the site's own rankings, the client's wizard picks), each with volume, a
 * difficulty label calibrated to THIS site's authority (KeywordWinnability),
 * intent, and one-click Add-to-calendar / Write-now. Plus striking-distance
 * queries straight from the client's own Search Console and popular questions.
 *
 * Client-copy invariant: no internal datasource/vendor names, no pipeline
 * internals, no $ projections. It's all just "research".
 */
class ContentResearch extends Component
{
    private const PER_PAGE = 25;

    public ?string $websiteId = null;

    public string $search = '';

    /** all|informational|commercial|transactional|navigational */
    public string $intent = 'all';

    /** all|easy|moderate|hard */
    public string $difficulty = 'all';

    /** volume|newest|easiest */
    public string $sort = 'volume';

    public int $feedPage = 1;

    public function mount(): void
    {
        $this->websiteId = session('current_website_id');
        if (! $this->websiteId) {
            $this->websiteId = Auth::user()?->accessibleWebsitesQuery()->value('id');
        }
        // First visit on a plan that never researched: kick it off now (job is
        // idempotent + cache-guarded), so the page fills itself in minutes.
        $plan = $this->plan();
        if ($plan !== null && $plan->keywords_classified_at === null) {
            PrepareContentKeywordInsightsJob::dispatch($plan->id);
        }
    }

    #[On('website-changed')]
    public function switchWebsite(string $websiteId): void
    {
        $this->websiteId = $websiteId;
        $this->reset('search', 'intent', 'difficulty', 'sort', 'feedPage');
    }

    public function updated(string $prop): void
    {
        if (in_array($prop, ['search', 'intent', 'difficulty', 'sort'], true)) {
            $this->feedPage = 1;
        }
    }

    public function nextPage(): void
    {
        $this->feedPage++;
    }

    public function previousPage(): void
    {
        $this->feedPage = max(1, $this->feedPage - 1);
    }

    private function website(): ?Website
    {
        if (! $this->websiteId) {
            return null;
        }

        return Auth::user()?->accessibleWebsitesQuery()->whereKey($this->websiteId)->first();
    }

    private function plan(): ?ContentPlan
    {
        $website = $this->website();
        if ($website === null) {
            return null;
        }

        return ContentPlan::query()
            ->where('website_id', $website->id)
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Add a researched keyword to the calendar as an approved topic on the next
     * free publish day. Safe to call with any string — the plan is resolved
     * server-side and duplicates are refused.
     */
    public function addToCalendar(string $keyword, ?int $volume = null): void
    {
        $topic = $this->createTopicFor($keyword, $volume);
        if ($topic !== null) {
            // Async AI polish of the instant token-overlap secondaries — fails
            // open, never blocks the click. Skipped on the Write path (the
            // producer claims the topic immediately and does its own research).
            RefineTopicSecondaryKeywordsJob::dispatch($topic->id);
        }
    }

    /** Add to the calendar AND start writing immediately (entitlement-gated). */
    public function addAndWrite(string $keyword, ?int $volume = null): void
    {
        $topic = $this->createTopicFor($keyword, $volume);
        if ($topic === null) {
            return;
        }
        if (($reason = app(ContentEntitlements::class)->blockReason($topic)) !== null) {
            // Topic stays on the calendar; generation is what's blocked.
            session()->flash('content-error', ContentCalendar::generationBlockMessage($reason));

            return;
        }
        $topic->forceFill(['stage_started_at' => now()])->save();
        Cache::put('content:gen-start:'.$topic->id, now()->timestamp, now()->addHour());
        ProduceContentArticleJob::dispatch($topic->id);
        $this->redirect(route('content.review', $topic->id), navigate: true);
    }

    private function createTopicFor(string $keyword, ?int $volume): ?ContentTopic
    {
        $plan = $this->plan();
        $website = $this->website();
        $keyword = mb_strtolower(trim($keyword));
        if ($plan === null || $website === null || $keyword === '' || mb_strlen($keyword) > 200) {
            return null;
        }

        $existing = $plan->topics()
            ->whereRaw('LOWER(target_keyword) = ?', [$keyword])
            ->whereNot('status', ContentTopic::STATUS_SKIPPED)
            ->exists();
        if ($existing) {
            session()->flash('content-status', __('Already on your calendar.'));

            return null;
        }

        // Keep the planned pool bounded — same cap the planner honours.
        $pool = $plan->topics()
            ->whereNotIn('status', [ContentTopic::STATUS_PUBLISHED, ContentTopic::STATUS_SKIPPED])
            ->count();
        if ($pool >= ContentAutopilotConfig::monthlyArticlesPerWebsite()) {
            session()->flash('content-error', __('Your calendar is full for now — publish or skip an article to make room.'));

            return null;
        }

        if ($volume === null) {
            $volume = ContentPlanKeyword::query()
                ->where('plan_id', $plan->id)
                ->where('keyword', $keyword)
                ->value('search_volume');
        }

        $date = app(ContentTopicPlanner::class)->nextDates($plan, 1)[0] ?? now()->addDays(2)->startOfDay();

        $topic = $plan->topics()->create([
            'website_id' => $website->id,
            'title' => mb_substr(Str::title($keyword), 0, 300),
            'target_keyword' => $keyword,
            'keyword_volume' => $volume,
            'secondary_keywords' => $this->relatedKeywords($plan, $keyword),
            'source' => 'research',
            'status' => ContentTopic::STATUS_APPROVED,
            'scheduled_for' => $date,
        ]);

        session()->flash('content-status', __('Added to your calendar for :date.', [
            'date' => $date->translatedFormat('M j'),
        ]));

        return $topic;
    }

    /**
     * Secondary keywords for a research-added topic, mined from the plan's own
     * vetted library (same head start the planner's ideation gives its topics,
     * zero LLM cost): library keywords sharing a meaningful token with the
     * focus keyword, ranked by token overlap then volume, capped at 8 (the
     * planner's own cap).
     *
     * @return list<string>
     */
    private function relatedKeywords(ContentPlan $plan, string $keyword): array
    {
        $stop = ['the', 'and', 'for', 'with', 'what', 'how', 'why', 'best', 'top', 'your', 'you'];
        $tokens = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', $keyword) ?: [],
            fn ($t) => mb_strlen($t) >= 3 && ! in_array(mb_strtolower($t), $stop, true),
        ));
        if ($tokens === []) {
            return [];
        }

        $candidates = ContentPlanKeyword::query()
            ->where('plan_id', $plan->id)
            ->where('keyword', '!=', $keyword)
            ->where(function ($q) use ($tokens) {
                foreach (array_slice($tokens, 0, 4) as $t) {
                    $q->orWhere('keyword', 'like', '%'.mb_strtolower($t).'%');
                }
            })
            ->orderByDesc('search_volume')
            ->limit(30)
            ->pluck('keyword');

        return $candidates
            ->map(function (string $kw) use ($tokens) {
                $overlap = 0;
                foreach ($tokens as $t) {
                    if (str_contains($kw, mb_strtolower($t))) {
                        $overlap++;
                    }
                }

                return ['kw' => $kw, 'overlap' => $overlap];
            })
            ->sortByDesc('overlap')
            ->take(8)
            ->pluck('kw')
            ->values()
            ->all();
    }

    /**
     * Difficulty label for a keyword, calibrated to this site's authority.
     * MUST stay in lockstep with feedDifficultyFilter() — the SQL filter and
     * this chip must agree or filtered rows show the "wrong" chip.
     */
    private function difficultyLabel(?int $kd, ?float $competition, int $ceiling): string
    {
        if ($kd !== null) {
            if ($kd <= (int) ($ceiling * 0.6)) {
                return 'easy';
            }

            return $kd <= $ceiling ? 'moderate' : 'hard';
        }
        if ($competition === null) {
            return 'moderate';
        }
        if ($competition < 0.34) {
            return 'easy';
        }

        return $competition < 0.67 ? 'moderate' : 'hard';
    }

    /** Apply the difficulty filter in SQL, mirroring difficultyLabel(). */
    private function applyDifficultyFilter($query, string $level, int $ceiling): void
    {
        $easyKd = (int) ($ceiling * 0.6);
        match ($level) {
            'easy' => $query->where(function ($q) use ($easyKd) {
                $q->where(fn ($w) => $w->whereNotNull('keyword_difficulty')->where('keyword_difficulty', '<=', $easyKd))
                    ->orWhere(fn ($w) => $w->whereNull('keyword_difficulty')->whereNotNull('competition')->where('competition', '<', 0.34));
            }),
            'moderate' => $query->where(function ($q) use ($easyKd, $ceiling) {
                $q->where(fn ($w) => $w->whereNotNull('keyword_difficulty')->where('keyword_difficulty', '>', $easyKd)->where('keyword_difficulty', '<=', $ceiling))
                    ->orWhere(fn ($w) => $w->whereNull('keyword_difficulty')->where(
                        fn ($c) => $c->whereNull('competition')->orWhere(fn ($b) => $b->where('competition', '>=', 0.34)->where('competition', '<', 0.67))
                    ));
            }),
            'hard' => $query->where(function ($q) use ($ceiling) {
                $q->where(fn ($w) => $w->whereNotNull('keyword_difficulty')->where('keyword_difficulty', '>', $ceiling))
                    ->orWhere(fn ($w) => $w->whereNull('keyword_difficulty')->where('competition', '>=', 0.67));
            }),
            default => null,
        };
    }

    /**
     * Striking-distance queries from the client's own Search Console: the site
     * already appears (position 6-30) with real impressions — one strong
     * article can carry these to page 1. Same aggregate shape as the planner's
     * GSC signals; fails soft to [] (no GSC, no rows, any error).
     *
     * @return list<array{query:string, impressions:int, position:float, planned:bool}>
     */
    private function strikingDistance(Website $website, array $plannedSet): array
    {
        try {
            if (! $website->hasGsc()) {
                return [];
            }

            return DB::table('search_console_data')
                ->where('website_id', $website->id)
                ->where('date', '>=', now()->subDays(90)->toDateString())
                ->groupBy('query')
                ->havingRaw('SUM(impressions) >= 20')
                ->orderByRaw('SUM(impressions) DESC')
                ->selectRaw('query, SUM(impressions) as impressions, AVG(position) as position')
                ->limit(60)
                ->get()
                ->filter(fn ($r) => (float) $r->position >= 6.0 && (float) $r->position <= 30.0)
                ->take(10)
                ->map(fn ($r) => [
                    'query' => (string) $r->query,
                    'impressions' => (int) $r->impressions,
                    'position' => round((float) $r->position, 1),
                    'planned' => isset($plannedSet[mb_strtolower(trim((string) $r->query))]),
                ])
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function render()
    {
        $website = $this->website();
        if ($website === null) {
            return view('livewire.content.content-research', ['hasWebsite' => false])
                ->layoutData(['title' => __('Research')]);
        }

        $plan = $this->plan();
        if ($plan === null) {
            return view('livewire.content.content-research', ['hasWebsite' => true, 'hasPlan' => false])
                ->layoutData(['title' => __('Research')]);
        }

        $insights = app(ContentKeywordInsights::class);
        $researching = $plan->keywords_classified_at === null;

        // Keywords already on the calendar (any non-skipped status) → the row
        // shows "In your calendar" instead of an Add button.
        $plannedSet = $plan->topics()
            ->whereNot('status', ContentTopic::STATUS_SKIPPED)
            ->pluck('target_keyword')
            ->map(fn ($k) => mb_strtolower(trim((string) $k)))
            ->flip()
            ->all();

        $ownDa = KeywordWinnability::ownAuthority($website);
        $ceiling = KeywordWinnability::difficultyCeiling($ownDa);

        $base = ContentPlanKeyword::query()->where('plan_id', $plan->id);

        $totalLibrary = (clone $base)->count();
        $newThisWeek = (clone $base)->where('created_at', '>=', now()->subDays(7))->count();

        $feed = (clone $base);
        if (trim($this->search) !== '') {
            $feed->where('keyword', 'like', '%'.trim(mb_strtolower($this->search)).'%');
        }
        if (in_array($this->intent, ['informational', 'commercial', 'transactional', 'navigational'], true)) {
            $feed->where('search_intent', $this->intent);
        }
        if (in_array($this->difficulty, ['easy', 'moderate', 'hard'], true)) {
            $this->applyDifficultyFilter($feed, $this->difficulty, $ceiling);
        }
        match ($this->sort) {
            'newest' => $feed->orderByDesc('created_at')->orderByDesc('id'),
            'easiest' => $feed->orderByRaw('CASE WHEN keyword_difficulty IS NULL THEN COALESCE(competition, 0.5) * 100 ELSE keyword_difficulty END ASC')->orderByDesc('search_volume'),
            default => $feed->orderByDesc('search_volume')->orderBy('id'),
        };

        $filteredTotal = (clone $feed)->count();
        $lastPage = max(1, (int) ceil($filteredTotal / self::PER_PAGE));
        $this->feedPage = min($this->feedPage, $lastPage);

        $rows = $feed->forPage($this->feedPage, self::PER_PAGE)->get()
            ->map(function (ContentPlanKeyword $row) use ($plannedSet, $ceiling) {
                return [
                    'keyword' => $row->keyword,
                    'volume' => $row->search_volume,
                    'intent' => $row->search_intent,
                    'type' => $row->type,
                    'difficulty' => $this->difficultyLabel($row->keyword_difficulty, $row->competition, $ceiling),
                    'new' => $row->created_at !== null && $row->created_at->gte(now()->subDays(7)),
                    'planned' => isset($plannedSet[mb_strtolower(trim($row->keyword))]),
                ];
            })
            ->all();

        // Popular questions from the research digest (cached; null while pending).
        $questions = [];
        try {
            foreach (array_slice((array) ($insights->get($plan)['questions'] ?? []), 0, 12) as $q) {
                $kw = mb_strtolower(trim((string) ($q['keyword'] ?? '')));
                if ($kw !== '') {
                    $questions[] = [
                        'keyword' => $kw,
                        'volume' => $q['volume'] ?? null,
                        'planned' => isset($plannedSet[$kw]),
                    ];
                }
            }
        } catch (\Throwable) {
            // digest unavailable — section simply hides
        }

        return view('livewire.content.content-research', [
            'hasWebsite' => true,
            'hasPlan' => true,
            'website' => $website,
            'researching' => $researching,
            'researchStatus' => $researching ? $insights->researchStatus($plan) : [],
            'totalLibrary' => $totalLibrary,
            'newThisWeek' => $newThisWeek,
            'rows' => $rows,
            'filteredTotal' => $filteredTotal,
            'lastPage' => $lastPage,
            'striking' => $this->strikingDistance($website, $plannedSet),
            'questions' => $questions,
            'ownDa' => $ownDa,
        ])->layoutData(['title' => __('Research')]);
    }
}
