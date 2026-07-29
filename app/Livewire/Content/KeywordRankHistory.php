<?php

namespace App\Livewire\Content;

use App\Jobs\CheckTrackedKeywordSerpJob;
use App\Models\ContentTrackedKeyword;
use App\Models\Website;
use App\Services\Content\ContentRankHistoryService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Content Tracker → one keyword's rank history. Reached from the chart icon on
 * every tracker row (route content.keyword-history).
 *
 * Authorization is by the keyword's WEBSITE (accessibleWebsitesQuery), not by
 * the session's "current website" — the page is deep-linkable and a stale
 * session pointer must not 403 a keyword the user can legitimately see.
 */
class KeywordRankHistory extends Component
{
    public string $keywordId;

    public int $range = 90;

    /** Set when the user asks for an out-of-cycle check this request. */
    public bool $refreshQueued = false;

    public function mount(string $keywordId): void
    {
        $this->keywordId = $keywordId;
        abort_if($this->keyword() === null, 404);
    }

    private function keyword(): ?ContentTrackedKeyword
    {
        $row = ContentTrackedKeyword::query()
            ->with(['topic:id,title,status,published_at', 'website:id,domain'])
            ->find($this->keywordId);
        if ($row === null) {
            return null;
        }

        $allowed = Auth::user()?->accessibleWebsitesQuery()->whereKey($row->website_id)->exists();

        return $allowed ? $row : null;
    }

    public function setRange(int $days): void
    {
        if (in_array($days, ContentRankHistoryService::RANGES, true)) {
            $this->range = $days;
        }
    }

    /**
     * Ask for a fresh live check. The job only queries keywords past the weekly
     * staleness window, so this is a cheap no-op when the data is already
     * current — the button reports "queued", never a guaranteed new number.
     */
    public function refreshRank(): void
    {
        $keyword = $this->keyword();
        if ($keyword === null) {
            return;
        }
        CheckTrackedKeywordSerpJob::dispatch($keyword->website_id);
        $this->refreshQueued = true;
    }

    public function render()
    {
        $keyword = $this->keyword();
        if ($keyword === null) {
            abort(404);
        }
        /** @var Website $website */
        $website = $keyword->website;

        $series = app(ContentRankHistoryService::class)->series($website, $keyword, $this->range);

        return view('livewire.content.keyword-rank-history', [
            'keyword' => $keyword,
            'website' => $website,
            'topic' => $keyword->topic,
            'series' => $series,
            'ranges' => ContentRankHistoryService::RANGES,
        ])->layoutData(['title' => __('Rank history')]);
    }
}
