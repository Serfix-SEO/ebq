<?php

namespace App\Livewire\Dashboard;

use App\Services\ReportDataService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Dashboard card: top 5 quick-wins (low-competition keywords with volume
 * where this site doesn't rank top-10 yet). Hidden when the list is empty
 * so new sites don't see a placeholder frame.
 */
#[Lazy]
class QuickWinsCard extends Component
{
    public int $websiteId = 0;

    public function mount(): void
    {
        $this->websiteId = (int) session('current_website_id', 0);
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="h-3 w-1/3 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
            <div class="mt-4 space-y-3">
                <div class="h-4 w-full animate-pulse rounded bg-slate-100 dark:bg-slate-800/60"></div>
                <div class="h-4 w-11/12 animate-pulse rounded bg-slate-100 dark:bg-slate-800/60"></div>
                <div class="h-4 w-10/12 animate-pulse rounded bg-slate-100 dark:bg-slate-800/60"></div>
            </div>
        </div>
        HTML;
    }

    #[On('website-changed')]
    public function switchWebsite(int $websiteId): void
    {
        $this->websiteId = $websiteId;
    }

    public function render()
    {
        $rows = [];

        if ($this->websiteId && Auth::user()?->canViewWebsiteId($this->websiteId)) {
            $rows = Cache::remember(
                'quick_wins_card:'.$this->websiteId,
                600,
                fn () => app(ReportDataService::class)->quickWins($this->websiteId, 5),
            );
        }

        return view('livewire.dashboard.quick-wins-card', [
            'rows' => $rows,
        ]);
    }
}
