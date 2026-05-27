<?php

namespace App\Livewire\Dashboard;

use App\Models\Website;
use App\Services\ReportDataService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

#[Lazy]
class SyncAndReportPanel extends Component
{
    public int $websiteId = 0;

    public ?string $sendError = null;

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="h-3 w-1/4 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
            <div class="mt-4 h-10 w-full animate-pulse rounded bg-slate-100 dark:bg-slate-800/60"></div>
            <div class="mt-2 h-10 w-full animate-pulse rounded bg-slate-100 dark:bg-slate-800/60"></div>
        </div>
        HTML;
    }

    public ?string $sendSuccess = null;

    public function mount(): void
    {
        $this->websiteId = (int) session('current_website_id', 0);
    }

    #[On('website-changed')]
    public function switchWebsite(int $websiteId): void
    {
        $this->websiteId = $websiteId;
        $this->sendError = null;
        $this->sendSuccess = null;
    }

    public function sendReport(): void
    {
        $this->sendError = null;
        $this->sendSuccess = null;

        $user = Auth::user();
        if (! $user) {
            return;
        }

        if (! $this->websiteId || ! $user->canViewWebsiteId($this->websiteId)) {
            $this->sendError = 'Select a website to send a report.';

            return;
        }

        $website = Website::query()->find($this->websiteId);
        if (! $website) {
            $this->sendError = 'Website not found.';

            return;
        }

        $key = 'send-growth-report:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->sendError = 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.';

            return;
        }

        // Snap to the most recent fully-synced GSC day. When no
        // safe day exists yet (sync still catching up, or a brand
        // new site), surface a clear toast instead of mailing a
        // misleading report.
        $safe = app(ReportDataService::class)->lastSafeReportDate($website->id);
        if (! $safe) {
            $this->sendError = 'Search Console data is still syncing for this site. Try again tomorrow once Google has finalised the latest day.';

            return;
        }

        try {
            $date = $safe->toDateString();
            $recipients = $website->getReportRecipientUsers();

            if ($recipients->isEmpty()) {
                $this->sendError = 'No report recipients are configured for this website. Add team members or set a report email first.';

                return;
            }

            $emails = [];

            foreach ($recipients as $recipient) {
                app(\App\Services\Reports\ReportMailDispatcher::class)
                    ->send($recipient, $website, $date, $date, 'daily');
                $emails[] = $recipient->email;
            }

            RateLimiter::hit($key, 3600);
            $this->sendSuccess = 'Report for '.$website->domain.' (data through '.$date.') sent to '.implode(', ', $emails).'.';
        } catch (Throwable $e) {
            $this->sendError = 'Could not send the report. Check your mail configuration.';
            report($e);
        }
    }

    public function render()
    {
        $user = Auth::user()?->fresh();
        $website = null;

        if ($this->websiteId && $user?->canViewWebsiteId($this->websiteId)) {
            $website = Website::query()->find($this->websiteId);
        }

        return view('livewire.dashboard.sync-and-report-panel', [
            'website' => $website,
            'user' => $user,
        ]);
    }
}
