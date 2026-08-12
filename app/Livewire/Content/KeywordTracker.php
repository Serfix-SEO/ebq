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

    /** Country the live SERP checks run from — saved on the content plan. */
    public string $serpCountry = 'global';

    public function mount(): void
    {
        $this->websiteId = session('current_website_id');
        if (! $this->websiteId) {
            $this->websiteId = Auth::user()?->accessibleWebsitesQuery()->value('id');
        }
        $this->loadSerpCountry();
    }

    #[On('website-changed')]
    public function switchWebsite(string $websiteId): void
    {
        $this->websiteId = $websiteId;
        $this->selectedTopicId = null;
        $this->loadSerpCountry();
    }

    private function loadSerpCountry(): void
    {
        $plan = $this->websiteId
            ? \App\Models\ContentPlan::query()->where('website_id', $this->websiteId)->first()
            : null;
        $this->serpCountry = (string) ($plan?->serp_country ?: $plan?->country ?: 'global');
    }

    /**
     * Persist the SERP-check country for this website and re-check every
     * tracked keyword against the new market right away (positions differ per
     * country, so yesterday's numbers no longer apply).
     */
    public function saveSerpCountry(): void
    {
        $website = $this->website();
        if ($website === null) {
            return;
        }
        if (! array_key_exists($this->serpCountry, \App\Support\KeywordFinderLocations::countryOptions())) {
            $this->loadSerpCountry();

            return;
        }

        $plan = \App\Models\ContentPlan::query()->where('website_id', $website->id)->first();
        if ($plan === null) {
            return;
        }
        if ((string) ($plan->serp_country ?: $plan->country ?: 'global') === $this->serpCountry) {
            return; // unchanged
        }

        $plan->forceFill(['serp_country' => $this->serpCountry])->save();

        // Positions are per-market: mark everything unchecked so the re-check
        // isn't skipped by the weekly staleness window, then run it now.
        ContentTrackedKeyword::query()
            ->where('website_id', $website->id)
            ->update(['serp_checked_at' => null]);
        \App\Jobs\CheckTrackedKeywordSerpJob::dispatch($website->id);

        session()->flash('tracker-status', __('SERP country saved — rechecking all keyword positions for the new market now.'));
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

    /**
     * Track one of the article's real search phrases (from the "where the
     * impressions come from" list). Attached to the article's topic so it
     * groups under the article; quota-checked; the new keyword gets its live
     * SERP position immediately (track() dispatches the check).
     */
    public function trackQuery(string $keyword): void
    {
        $website = $this->website();
        $keyword = trim($keyword);
        if ($website === null || $keyword === '' || mb_strlen($keyword) > 200) {
            return;
        }

        $topic = null;
        if ($this->selectedTopicId !== null && $this->selectedTopicId !== '_manual') {
            $topic = \App\Models\ContentTopic::query()
                ->where('website_id', $website->id)
                ->whereKey($this->selectedTopicId)
                ->first();
        }

        // Inherit the article's page URL so the new keyword joins the same
        // article group with a working performance link.
        $pageUrl = $topic !== null
            ? ContentTrackedKeyword::query()->where('topic_id', $topic->id)->whereNotNull('page_url')->value('page_url')
            : null;

        $result = app(ContentKeywordTracker::class)->track(
            $website,
            [$keyword],
            topic: $topic,
            source: ContentTrackedKeyword::SOURCE_MANUAL,
            user: Auth::user(),
            primaryKeyword: '', // never steal the primary flag from the article's target
            pageUrl: $pageUrl,
        );

        if ($result['capped']) {
            session()->flash('tracker-status', __('You\'ve reached your tracking limit. Remove a keyword to make room, then try again.'));
        } elseif ($result['added'] > 0) {
            session()->flash('tracker-status', __(':keyword is now tracked — checking its live position now.', ['keyword' => $keyword]));
        }
        $this->dispatch('tracker-changed');
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
        $selectedQueries = [];
        if ($this->selectedTopicId !== null && isset($groups[$this->selectedTopicId])) {
            $selectedGroup = $groups[$this->selectedTopicId];
            if ($selectedGroup['page_url']) {
                $selectedSeries = $perf->pageSeries($website, $selectedGroup['page_url']);
                // The real phrases this page appears for, flagged with
                // whether each is already in the tracker.
                $trackedSet = $tracked->pluck('normalized_keyword')->flip();
                foreach ($perf->pageQueries($website, $selectedGroup['page_url']) as $row) {
                    $row['tracked'] = $trackedSet->has(ContentTrackedKeyword::normalize($row['query']));
                    $selectedQueries[] = $row;
                }
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
            'selectedQueries' => $selectedQueries,
            'countryOptions' => \App\Support\KeywordFinderLocations::countryOptions(),
        ])->layoutData(['title' => __('Tracker')]);
    }
}
