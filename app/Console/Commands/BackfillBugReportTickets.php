<?php

namespace App\Console\Commands;

use App\Models\BugReport;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * One-time backfill: turn every pre-existing bug report into a support
 * ticket thread (bug reports ARE support tickets since 2026-08-12).
 * Idempotent — reports already carrying a support_ticket_id are skipped,
 * so re-running is always safe.
 */
class BackfillBugReportTickets extends Command
{
    protected $signature = 'ebq:backfill-bug-report-tickets {--dry-run : Count only, write nothing}';

    protected $description = 'Create support tickets for bug reports that predate the tickets feature';

    public function handle(): int
    {
        $pending = BugReport::query()->whereNull('support_ticket_id')->orderBy('created_at');

        if ($this->option('dry-run')) {
            $this->info($pending->count().' bug reports would get a ticket.');

            return self::SUCCESS;
        }

        // Resolution notes are posted as the team's reply; they need an
        // author row — any admin account works.
        $adminId = User::query()->where('is_admin', true)->orderBy('created_at')->value('id');

        $made = 0;
        foreach ($pending->get() as $report) {
            $ticket = SupportTicket::createFromBugReport($report);

            if ($report->status === BugReport::STATUS_RESOLVED) {
                if ($adminId !== null && trim((string) $report->resolution_note) !== '') {
                    $ticket->messages()->create([
                        'user_id' => $adminId,
                        'is_admin' => true,
                        'body' => trim((string) $report->resolution_note),
                        'created_at' => $report->resolved_at ?? $report->updated_at,
                    ]);
                }
                $ticket->forceFill([
                    'status' => SupportTicket::STATUS_CLOSED,
                    'last_reply_at' => $report->resolved_at ?? $report->updated_at,
                ])->save();
            }

            $made++;
        }

        $this->info("Created $made tickets from bug reports.");

        return self::SUCCESS;
    }
}
