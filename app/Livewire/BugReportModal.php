<?php

namespace App\Livewire;

use App\Mail\BugReportSubmitted;
use App\Models\BugReport;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * "Report a bug" modal (top-bar button, open-bug-report window event).
 * Screenshot arrives as a JPEG/PNG data URL produced client-side by the
 * snipping overlay (resources/js/bug-report-capture.js) — deliberately not
 * WithFileUploads, there is no <input type=file> in this flow.
 */
class BugReportModal extends Component
{
    /** Server-side caps for the optional screenshot payload. */
    private const MAX_DATA_URL_CHARS = 6_000_000;   // ~4.4MB decoded
    private const MAX_DECODED_BYTES = 4_194_304;    // 4MB

    public string $url = '';

    public string $description = '';

    public string $screenshotDataUrl = '';

    public string $viewport = '';

    public bool $submitted = false;

    public function open(?string $url): void
    {
        $this->reset(['description', 'screenshotDataUrl', 'viewport', 'submitted']);
        $this->resetErrorBag();
        $this->url = Str::limit(trim((string) $url), 2000, '');
    }

    public function removeScreenshot(): void
    {
        $this->screenshotDataUrl = '';
    }

    public function submit(): void
    {
        // Livewire actions don't pass through route throttle middleware —
        // limit here. 5 reports per user per hour is plenty for real use.
        if (! RateLimiter::attempt('bug-report:'.Auth::id(), 5, fn () => null, 3600)) {
            $this->addError('description', __('Too many reports. Please try again later.'));

            return;
        }

        $this->validate([
            'description' => ['required', 'string', 'max:5000'],
            'url' => ['required', 'string', 'max:2000'],
        ], [], ['description' => __('description')]);

        $screenshotPath = $this->storeScreenshot();

        $report = BugReport::create([
            'user_id' => Auth::id(),
            'website_id' => session('current_website_id') ?: null,
            'url' => $this->url,
            'description' => $this->description,
            'screenshot_path' => $screenshotPath,
            'user_agent' => Str::limit((string) request()->userAgent(), 500, ''),
            'viewport' => Str::limit($this->viewport, 50, ''),
            'status' => BugReport::STATUS_NEW,
        ]);

        // Mail trouble must never break the user's submit.
        try {
            $admins = User::query()->where('is_admin', true)->pluck('email')->filter()->values();
            if ($admins->isNotEmpty()) {
                Mail::to($admins->all())->send(new BugReportSubmitted($report));
            }
        } catch (\Throwable $e) {
            Log::warning("BugReportModal: admin mail failed for report {$report->id}: {$e->getMessage()}");
        }

        $this->submitted = true;
    }

    /** Validate + persist the data-URL screenshot; null when absent/invalid. */
    private function storeScreenshot(): ?string
    {
        $dataUrl = $this->screenshotDataUrl;
        if ($dataUrl === '') {
            return null;
        }

        if (strlen($dataUrl) > self::MAX_DATA_URL_CHARS
            || ! preg_match('#^data:image/(jpeg|png);base64,(.+)$#s', $dataUrl, $m)) {
            $this->addError('screenshotDataUrl', __('The screenshot could not be attached.'));
            $this->screenshotDataUrl = '';

            return null;
        }

        $binary = base64_decode($m[2], true);
        if ($binary === false
            || strlen($binary) > self::MAX_DECODED_BYTES
            || @getimagesizefromstring($binary) === false) {
            $this->addError('screenshotDataUrl', __('The screenshot could not be attached.'));
            $this->screenshotDataUrl = '';

            return null;
        }

        $path = 'bug-reports/'.strtolower((string) Str::ulid()).($m[1] === 'png' ? '.png' : '.jpg');
        Storage::disk('local')->put($path, $binary);

        return $path;
    }

    public function render()
    {
        return view('livewire.bug-report-modal');
    }
}
