<?php

namespace App\Livewire\Dashboard;

use App\Services\ReportDataService;
use App\Support\Countries;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Top 10 countries by clicks (last 30d vs previous 30d), rendered as a
 * horizontal-bar list. Fed by ReportDataService::topCountriesTrend.
 * Cached 10 minutes per website.
 */
#[Lazy]
class TopCountriesCard extends Component
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
            <div class="mt-4 space-y-2.5">
                <div class="h-3 w-full animate-pulse rounded bg-slate-100 dark:bg-slate-800/60"></div>
                <div class="h-3 w-11/12 animate-pulse rounded bg-slate-100 dark:bg-slate-800/60"></div>
                <div class="h-3 w-10/12 animate-pulse rounded bg-slate-100 dark:bg-slate-800/60"></div>
                <div class="h-3 w-9/12 animate-pulse rounded bg-slate-100 dark:bg-slate-800/60"></div>
                <div class="h-3 w-8/12 animate-pulse rounded bg-slate-100 dark:bg-slate-800/60"></div>
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
        $max = 1;

        if ($this->websiteId && Auth::user()?->canViewWebsiteId($this->websiteId)) {
            $rows = Cache::remember(
                'top_countries:'.$this->websiteId,
                600,
                fn () => app(ReportDataService::class)->topCountriesTrend($this->websiteId, 10),
            );
            $max = max(1, collect($rows)->max('clicks') ?? 1);
        }

        $rows = array_map(fn ($r) => $r + [
            'name' => Countries::name((string) $r['country']),
            'flag' => Countries::flag((string) $r['country']),
            'width_pct' => max(2, (int) round(((int) $r['clicks'] / $max) * 100)),
        ], $rows);

        return view('livewire.dashboard.top-countries-card', [
            'rows' => $rows,
        ]);
    }
}
