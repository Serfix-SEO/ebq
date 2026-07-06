<?php

namespace App\Livewire\RankTracking;

use App\Jobs\TrackKeywordRankJob;
use App\Models\RankTrackingKeyword;
use App\Models\RankTrackingSnapshot;
use App\Models\SearchConsoleData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

class RankTrackingDetail extends Component
{
    use WithPagination;

    public ?string $keywordId = null;
    public ?string $selectedSnapshotId = null;

    public function mount(string $keywordId): void
    {
        $this->keywordId = $keywordId;
    }

    public function recheck(): void
    {
        $keyword = $this->keyword();
        if (! $keyword) {
            return;
        }

        TrackKeywordRankJob::dispatch($keyword->id, $keyword->website_id, true)->onQueue(\App\Support\Queues::INTERACTIVE);

        $keyword->forceFill([
            'last_status' => 'queued',
            'next_check_at' => Carbon::now(),
        ])->save();

        session()->flash('rank_tracking_status', 'Re-check queued.');
    }

    public function selectSnapshot(string $id): void
    {
        $snapshot = RankTrackingSnapshot::where('rank_tracking_keyword_id', $this->keywordId)
            ->find($id);
        $this->selectedSnapshotId = $snapshot?->id;
    }

    private function keyword(): ?RankTrackingKeyword
    {
        $keyword = RankTrackingKeyword::find($this->keywordId);
        if (! $keyword) {
            return null;
        }
        $user = Auth::user();
        if (! $user || ! $user->canViewWebsiteId((string) $keyword->website_id)) {
            return null;
        }

        return $keyword;
    }

    public function render()
    {
        $keyword = $this->keyword();

        if (! $keyword) {
            return view('livewire.rank-tracking.rank-tracking-detail', [
                'keyword' => null,
                'snapshots' => collect(),
                'selected' => null,
                'chartPoints' => [],
                'gsc' => ['matched' => false],
            ]);
        }

        $snapshots = RankTrackingSnapshot::query()
            ->where('rank_tracking_keyword_id', $keyword->id)
            ->orderByDesc('checked_at')
            ->paginate(20);

        $selected = null;
        if ($this->selectedSnapshotId) {
            $selected = RankTrackingSnapshot::where('rank_tracking_keyword_id', $keyword->id)
                ->find($this->selectedSnapshotId);
        }
        if (! $selected) {
            $selected = RankTrackingSnapshot::where('rank_tracking_keyword_id', $keyword->id)
                ->orderByDesc('checked_at')
                ->first();
            $this->selectedSnapshotId = $selected?->id;
        }

        $chartPoints = RankTrackingSnapshot::query()
            ->where('rank_tracking_keyword_id', $keyword->id)
            ->where('status', 'ok')
            ->orderBy('checked_at')
            ->limit(60)
            ->get(['checked_at', 'position'])
            ->map(fn ($s) => [
                'x' => $s->checked_at->toIso8601String(),
                'y' => $s->position,
            ])
            ->values()
            ->all();

        $gsc = $this->gscInsights($keyword);

        return view('livewire.rank-tracking.rank-tracking-detail', [
            'keyword' => $keyword,
            'snapshots' => $snapshots,
            'selected' => $selected,
            'chartPoints' => $chartPoints,
            'gsc' => $gsc,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function gscInsights(RankTrackingKeyword $keyword): array
    {
        $since = Carbon::now()->subDays(30)->toDateString();
        $since90 = Carbon::now()->subDays(90)->toDateString();

        $totals = $keyword->gscQuery()
            ->where('date', '>=', $since)
            ->selectRaw('SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as position, AVG(ctr) as ctr')
            ->first();

        $hasMatch = $totals && ($totals->clicks !== null || $totals->impressions !== null);

        if (! $hasMatch) {
            return ['matched' => false];
        }

        $byDevice = $keyword->gscQuery()
            ->where('date', '>=', $since)
            ->selectRaw('device, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as position')
            ->groupBy('device')
            ->orderByDesc('clicks')
            ->get()
            ->map(fn ($r) => [
                'device' => $r->device ?: '—',
                'clicks' => (int) $r->clicks,
                'impressions' => (int) $r->impressions,
                'position' => $r->position !== null ? round((float) $r->position, 1) : null,
            ])
            ->all();

        $topPages = $keyword->gscQuery()
            ->where('date', '>=', $since)
            ->selectRaw('page, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as position')
            ->groupBy('page')
            ->orderByDesc('clicks')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'page' => $r->page,
                'clicks' => (int) $r->clicks,
                'impressions' => (int) $r->impressions,
                'position' => $r->position !== null ? round((float) $r->position, 1) : null,
            ])
            ->all();

        $series = $keyword->gscQuery()
            ->where('date', '>=', $since90)
            ->selectRaw('date, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as position')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => [
                'date' => $r->date instanceof \Carbon\CarbonInterface ? $r->date->toDateString() : (string) $r->date,
                'clicks' => (int) $r->clicks,
                'impressions' => (int) $r->impressions,
                'position' => $r->position !== null ? round((float) $r->position, 1) : null,
            ])
            ->all();

        return [
            'matched' => true,
            'totals' => [
                'clicks' => (int) ($totals->clicks ?? 0),
                'impressions' => (int) ($totals->impressions ?? 0),
                'position' => $totals->position !== null ? round((float) $totals->position, 1) : null,
                'ctr' => $totals->ctr !== null ? round((float) $totals->ctr * 100, 2) : null,
            ],
            'by_device' => $byDevice,
            'top_pages' => $topPages,
            'series' => $series,
        ];
    }
}
