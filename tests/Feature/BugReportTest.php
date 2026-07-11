<?php

namespace Tests\Feature;

use App\Livewire\BugReportModal;
use App\Mail\BugReportSubmitted;
use App\Models\BugReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BugReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
    }

    private function user(array $attrs = []): User
    {
        $u = User::factory()->create(array_merge(['email_verified_at' => now()], $attrs));
        RateLimiter::clear('bug-report:'.$u->id);

        return $u;
    }

    /** Tiny valid JPEG as a data URL. */
    private function jpegDataUrl(): string
    {
        $im = imagecreatetruecolor(4, 4);
        ob_start();
        imagejpeg($im);

        return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
    }

    public function test_submit_creates_report_and_mails_admins_only(): void
    {
        $admin = $this->user(['is_admin' => true]);
        $user = $this->user();

        Livewire::actingAs($user)
            ->test(BugReportModal::class)
            ->call('open', 'https://serfix.io/dashboard')
            ->set('description', 'The chart is blank.')
            ->call('submit')
            ->assertSet('submitted', true)
            ->assertHasNoErrors();

        $report = BugReport::sole();
        $this->assertSame($user->id, $report->user_id);
        $this->assertSame('https://serfix.io/dashboard', $report->url);
        $this->assertNull($report->screenshot_path);
        Mail::assertSent(BugReportSubmitted::class, fn ($m) => $m->hasTo($admin->email));
        Mail::assertSentCount(1);
    }

    public function test_screenshot_data_url_is_decoded_and_stored(): void
    {
        $this->user(['is_admin' => true]);
        $user = $this->user();

        Livewire::actingAs($user)
            ->test(BugReportModal::class)
            ->call('open', 'https://serfix.io/reports')
            ->set('description', 'Broken layout, see screenshot.')
            ->set('screenshotDataUrl', $this->jpegDataUrl())
            ->set('viewport', '1440x900@2')
            ->call('submit')
            ->assertSet('submitted', true);

        $report = BugReport::sole();
        $this->assertNotNull($report->screenshot_path);
        $this->assertStringStartsWith('bug-reports/', $report->screenshot_path);
        Storage::disk('local')->assertExists($report->screenshot_path);
        $this->assertSame('1440x900@2', $report->viewport);
    }

    public function test_validation_rejects_empty_description_and_bad_screenshot(): void
    {
        $user = $this->user();

        Livewire::actingAs($user)
            ->test(BugReportModal::class)
            ->call('open', 'https://serfix.io/x')
            ->set('description', '')
            ->call('submit')
            ->assertHasErrors(['description']);

        // Bad screenshot: report still saves, screenshot dropped with an error.
        Livewire::actingAs($user)
            ->test(BugReportModal::class)
            ->call('open', 'https://serfix.io/x')
            ->set('description', 'desc')
            ->set('screenshotDataUrl', 'data:image/jpeg;base64,not-really-base64!!!')
            ->call('submit');

        $this->assertSame(1, BugReport::count());
        $this->assertNull(BugReport::sole()->screenshot_path);
    }

    public function test_rate_limited_after_five_reports(): void
    {
        $user = $this->user();

        for ($i = 1; $i <= 5; $i++) {
            Livewire::actingAs($user)
                ->test(BugReportModal::class)
                ->call('open', 'https://serfix.io/x')
                ->set('description', "report {$i}")
                ->call('submit')
                ->assertSet('submitted', true);
        }

        Livewire::actingAs($user)
            ->test(BugReportModal::class)
            ->call('open', 'https://serfix.io/x')
            ->set('description', 'report 6')
            ->call('submit')
            ->assertHasErrors(['description']);

        $this->assertSame(5, BugReport::count());
    }

    public function test_admin_index_and_screenshot_are_admin_only(): void
    {
        $admin = $this->user(['is_admin' => true]);
        $user = $this->user();

        // Guest first — Livewire::actingAs below leaves the session authenticated.
        $this->get(route('admin.bug-reports.index'))->assertRedirect();

        Livewire::actingAs($user)
            ->test(BugReportModal::class)
            ->call('open', 'https://serfix.io/keywords')
            ->set('description', 'bug!')
            ->set('screenshotDataUrl', $this->jpegDataUrl())
            ->call('submit');
        $report = BugReport::sole();
        $this->actingAs($user)->get(route('admin.bug-reports.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.bug-reports.index'))
            ->assertOk()
            ->assertSee('bug!')
            ->assertSee($user->email);

        $this->actingAs($user)->get(route('admin.bug-reports.screenshot', $report))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.bug-reports.screenshot', $report))->assertOk();
    }

    public function test_resolve_requires_note_and_emails_reporter(): void
    {
        $admin = $this->user(['is_admin' => true]);
        $user = $this->user();
        $report = BugReport::create([
            'user_id' => $user->id, 'url' => 'https://serfix.io/x', 'description' => 'd', 'status' => 'new',
        ]);

        // No note -> validation error, still new, no mail.
        $this->actingAs($admin)->post(route('admin.bug-reports.resolve', $report))
            ->assertSessionHasErrors('resolution_note');
        $this->assertSame('new', $report->fresh()->status);
        Mail::assertNothingSent();

        // With note -> resolved + reporter (and only the reporter) emailed.
        $this->actingAs($admin)->post(route('admin.bug-reports.resolve', $report), [
            'resolution_note' => 'We fixed the chart rendering on your dashboard.',
        ])->assertSessionHas('status');

        $fresh = $report->fresh();
        $this->assertSame('resolved', $fresh->status);
        $this->assertSame('We fixed the chart rendering on your dashboard.', $fresh->resolution_note);
        $this->assertNotNull($fresh->resolved_at);
        Mail::assertSent(\App\Mail\BugReportResolved::class, fn ($m) => $m->hasTo($user->email));
        Mail::assertSentCount(1);

        // Reopen: back to new, no extra mail, note kept for history.
        $this->actingAs($admin)->post(route('admin.bug-reports.resolve', $report));
        $reopened = $report->fresh();
        $this->assertSame('new', $reopened->status);
        $this->assertNull($reopened->resolved_at);
        $this->assertNotNull($reopened->resolution_note);
        Mail::assertSentCount(1);
    }
}
