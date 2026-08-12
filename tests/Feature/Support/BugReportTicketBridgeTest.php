<?php

namespace Tests\Feature\Support;

use App\Livewire\BugReportModal;
use App\Models\BugReport;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/** Bug reports ARE support tickets — every report gets a client-visible thread. */
class BugReportTicketBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function report(User $user, array $attrs = []): BugReport
    {
        return BugReport::query()->create(array_merge([
            'user_id' => $user->id,
            'url' => 'https://serfix.io/content',
            'description' => 'The calendar shows no image for my scheduled article.',
            'status' => BugReport::STATUS_NEW,
        ], $attrs));
    }

    public function test_submitting_a_bug_report_creates_a_linked_ticket(): void
    {
        $user = User::factory()->create();
        Website::factory()->for($user)->create();
        RateLimiter::clear('bug-report:'.$user->id);

        Livewire::actingAs($user)
            ->test(BugReportModal::class)
            ->set('url', 'https://serfix.io/content')
            ->set('description', 'Something on the calendar looks broken for me.')
            ->call('submit');

        $report = BugReport::query()->firstOrFail();
        $this->assertNotNull($report->support_ticket_id);

        $ticket = SupportTicket::query()->findOrFail($report->support_ticket_id);
        $this->assertSame($user->id, $ticket->user_id);
        $this->assertStringStartsWith('Bug report:', $ticket->subject);
        $this->assertSame(1, $ticket->messages()->count());
        $this->assertStringContainsString('https://serfix.io/content', $ticket->messages()->first()->body);
    }

    public function test_backfill_converts_legacy_reports_and_is_idempotent(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $this->report($user);
        $this->report($user, [
            'description' => 'Old resolved problem.',
            'status' => BugReport::STATUS_RESOLVED,
            'resolution_note' => 'We fixed the image pipeline for your site.',
            'resolved_at' => now()->subDay(),
        ]);

        $this->artisan('ebq:backfill-bug-report-tickets')->assertSuccessful();

        $this->assertSame(2, SupportTicket::query()->count());
        $this->assertSame(0, BugReport::query()->whereNull('support_ticket_id')->count());

        $closed = SupportTicket::query()->where('status', SupportTicket::STATUS_CLOSED)->firstOrFail();
        $adminMsg = $closed->messages()->where('is_admin', true)->firstOrFail();
        $this->assertSame('We fixed the image pipeline for your site.', $adminMsg->body);
        $this->assertSame($admin->id, $adminMsg->user_id);

        // Second run creates nothing new.
        $this->artisan('ebq:backfill-bug-report-tickets')->assertSuccessful();
        $this->assertSame(2, SupportTicket::query()->count());
    }

    public function test_resolving_a_bug_report_replies_in_the_ticket_thread(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $report = $this->report($user);
        SupportTicket::createFromBugReport($report);

        $this->actingAs($admin)->post(route('admin.bug-reports.resolve', $report), [
            'resolution_note' => 'Fixed — the image now shows on your calendar.',
        ]);

        $ticket = SupportTicket::query()->findOrFail($report->refresh()->support_ticket_id);
        $this->assertSame(SupportTicket::STATUS_ANSWERED, $ticket->status);
        $this->assertSame('Fixed — the image now shows on your calendar.', $ticket->messages()->where('is_admin', true)->first()->body);
    }
}
