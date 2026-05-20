<?php

namespace App\Livewire\Reports;

use App\Models\Website;
use App\Services\ReportDataService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

class ReportGenerator extends Component
{
    public int $websiteId = 0;

    public string $reportType = 'weekly';

    public string $startDate = '';

    public string $endDate = '';

    public bool $showPreview = false;

    public array $report = [];

    public ?string $sendSuccess = null;

    public ?string $sendError = null;

    #[Url(as: 'country', history: true)]
    public string $country = '';

    public function mount(): void
    {
        $this->websiteId = (int) session('current_website_id', 0);
        $this->applyPreset();
    }

    #[On('website-changed')]
    public function switchWebsite(int $websiteId): void
    {
        $this->websiteId = $websiteId;
        $this->country = '';
        $this->showPreview = false;
        $this->report = [];
        $this->resetMessages();
    }

    #[On('country-changed')]
    public function onCountryChanged(string $country): void
    {
        $this->country = $country;
        $this->showPreview = false;
        $this->report = [];
        $this->resetMessages();
    }

    public function updatedReportType(): void
    {
        $this->applyPreset();
        $this->showPreview = false;
        $this->report = [];
        $this->resetMessages();
    }

    public function generatePreview(): void
    {
        $this->resetMessages();
        $this->showPreview = false;
        $this->report = [];

        if (! $this->validateInputs()) {
            return;
        }

        $this->report = app(ReportDataService::class)->generate(
            $this->websiteId,
            $this->startDate,
            $this->endDate,
            $this->country !== '' ? strtoupper($this->country) : null,
        );

        $this->showPreview = true;
    }

    public function sendReport(): void
    {
        $this->resetMessages();

        if (! $this->validateInputs()) {
            return;
        }

        $user = Auth::user();
        $key = 'send-report:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->sendError = 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.';

            return;
        }

        $website = Website::find($this->websiteId);
        $recipients = $website->getReportRecipientUsers();

        try {
            $emails = [];

            foreach ($recipients as $recipient) {
                app(\App\Services\Reports\ReportMailDispatcher::class)
                    ->send($recipient, $website, $this->startDate, $this->endDate, $this->reportType);
                $emails[] = $recipient->email;
            }

            RateLimiter::hit($key, 3600);
            $this->sendSuccess = ucfirst($this->reportType).' report for '.$website->domain.' sent to '.implode(', ', $emails).'.';
        } catch (Throwable $e) {
            $this->sendError = 'Could not send the report. Check your mail configuration.';
            report($e);
        }
    }

    public function render()
    {
        $website = null;
        $user = Auth::user();

        if ($this->websiteId && $user?->canViewWebsiteId($this->websiteId)) {
            $website = Website::find($this->websiteId);
        }

        return view('livewire.reports.report-generator', [
            'website' => $website,
        ]);
    }

    private function applyPreset(): void
    {
        $tz = config('app.timezone');

        $this->endDate = match ($this->reportType) {
            'daily' => Carbon::yesterday($tz)->toDateString(),
            default => Carbon::yesterday($tz)->toDateString(),
        };

        $this->startDate = match ($this->reportType) {
            'daily' => $this->endDate,
            'weekly' => Carbon::parse($this->endDate)->subDays(6)->toDateString(),
            'monthly' => Carbon::parse($this->endDate)->subDays(29)->toDateString(),
            'custom' => Carbon::parse($this->endDate)->subDays(6)->toDateString(),
            default => $this->endDate,
        };
    }

    private function validateInputs(): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        if (! $this->websiteId || ! $user->canViewWebsiteId($this->websiteId)) {
            $this->sendError = 'Please select a website first.';

            return false;
        }

        if (! $this->startDate || ! $this->endDate) {
            $this->sendError = 'Please select a valid date range.';

            return false;
        }

        if (Carbon::parse($this->startDate)->gt(Carbon::parse($this->endDate))) {
            $this->sendError = 'Start date must be before or equal to end date.';

            return false;
        }

        return true;
    }

    private function resetMessages(): void
    {
        $this->sendSuccess = null;
        $this->sendError = null;
    }
}
