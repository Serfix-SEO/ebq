<?php

namespace App\Livewire\Content;

use App\Models\ContentTrackedKeyword;
use App\Models\Website;
use App\Services\Content\ContentKeywordTracker;
use App\Services\Content\ContentPerformanceService;
use App\Services\Content\KeywordTrackerQuota;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Content Autopilot → Tracker. The client-facing view of the Keyword Tracker:
 * a capacity-limited list of the keywords their published articles target, each
 * with GSC search performance (position / clicks / impressions) and, per article,
 * GA traffic — the "is my content working?" page.
 *
 * Quota (KeywordTrackerQuota) is per-website; the row count IS the meter. When
 * full, the client deletes to add. Client-copy invariant: no internal datasource
 * names, no pipeline internals, no $ projections.
 */
class KeywordTracker extends Component
{
    public ?string $websiteId = null;

    /** Article (topic) whose full performance chart is expanded, if any. */
    public ?string $selectedTopicId = null;

    public function mount(): void
    {
        $this->websiteId = session('current_website_id');
        if (! $this->websiteId) {
            $this->websiteId = Auth::user()?->accessibleWebsitesQuery()->value('id');
        }
    }

    #[On('website-changed')]
    public function switchWebsite(string $websiteId): void
    {
        $this->websiteId = $websiteId;
        $this->selectedTopicId = null;
    }

    private function website(): ?Website
    {
        if (! $this->websiteId) {
            return null;
        }

        return Auth::user()?->accessibleWebsitesQuery()->whereKey($this->websiteId)->first();
    }

    public function untrack(string $id): void
    {
        $website = $this->website();
        if ($website === null) {
            return;
        }
        app(ContentKeywordTracker::class)->untrack($website, $id);
        if ($this->selectedTopicId !== null
            && ! ContentTrackedKeyword::query()->where('website_id', $website->id)->where('topic_id', $this->selectedTopicId)->exists()) {
            $this->selectedTopicId = null;
        }
        $this->dispatch('tracker-changed');
    }

    public function togglePerformance(string $topicId): void
    {
        $this->selectedTopicId = $this->selectedTopicId === $topicId ? null : $topicId;
    }

    public function render()
    {
        $website = $this->website();
        if ($website === null) {
            return view('livewire.content.keyword-tracker', ['hasWebsite' => false])
                ->layoutData(['title' => __('Tracker')]);
        }

        $quota = app(KeywordTrackerQuota::class);
        $perf = app(ContentPerformanceService::class);

        $tracked = ContentTrackedKeyword::query()
            ->where('website_id', $website->id)
            ->with('topic:id,title,target_keyword,status,published_at')
            ->orderByDesc('is_primary')
            ->orderBy('created_at')
            ->get();

        $summaries = $perf->keywordSummaries($website, $tracked->pluck('normalized_keyword')->all());

        // Group by source article (topic); manually tracked bare keywords fall
        // under a null-topic "Other keywords" group.
        $groups = [];
        foreach ($tracked as $row) {
            $key = $row->topic_id ?? '_manual';
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'topic' => $row->topic,
                    'topic_id' => $row->topic_id,
                    'page_url' => null,
                    'keywords' => [],
                ];
            }
            if ($groups[$key]['page_url'] === null && $row->page_url) {
                $groups[$key]['page_url'] = $row->page_url;
            }
            $groups[$key]['keywords'][] = $row;
        }

        // Selected article performance series (published articles only).
        $selectedSeries = null;
        $selectedGroup = null;
        if ($this->selectedTopicId !== null && isset($groups[$this->selectedTopicId])) {
            $selectedGroup = $groups[$this->selectedTopicId];
            if ($selectedGroup['page_url']) {
                $selectedSeries = $perf->pageSeries($website, $selectedGroup['page_url']);
            }
        }

        return view('livewire.content.keyword-tracker', [
            'hasWebsite' => true,
            'website' => $website,
            'hasGsc' => $website->hasGsc(),
            'hasGa' => $website->hasGa(),
            'used' => $quota->used($website),
            'limit' => $quota->limitFor($website),
            'remaining' => $quota->remaining($website),
            'nearCap' => $quota->nearCap($website),
            'exhausted' => $quota->exhausted($website),
            'groups' => array_values($groups),
            'summaries' => $summaries,
            'selectedSeries' => $selectedSeries,
            'selectedGroup' => $selectedGroup,
        ])->layoutData(['title' => __('Tracker')]);
    }
}
