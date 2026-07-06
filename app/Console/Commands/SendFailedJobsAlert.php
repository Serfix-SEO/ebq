<?php

namespace App\Console\Commands;

use App\Models\CrawlSite;
use App\Models\User;
use App\Support\FailedJobAlertBuffer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Drains the shared-Redis failed-job buffer (fed in real time by
 * `Queue::failing()` on every box — see FailedJobAlertBuffer) and mails a
 * digest to platform admins. Also flags crawl_sites that have subscribers but
 * have sat `pending` (never crawled) for over a day — the other blind spot
 * from the 2026-07-06 incident: a job that dies BEFORE creating a CrawlRun is
 * invisible to the crawl supervisor.
 *
 * Runs on the WEB box only (scheduler lives there), so mail goes out through
 * the local Postal relay regardless of which box the failures happened on.
 * No cooldown needed: the drain empties the buffer, so a repeat mail only
 * happens when NEW failures land.
 */
class SendFailedJobsAlert extends Command
{
    protected $signature = 'ebq:failed-jobs-alert {--dry-run : Print the digest instead of mailing}';

    protected $description = 'Mail admins a digest of recently failed queue jobs + never-crawled stuck sites.';

    public function handle(): int
    {
        // Dry-run must not consume the buffer — a peeked entry still gets
        // mailed by the next real run.
        $failures = $this->option('dry-run')
            ? FailedJobAlertBuffer::peek()
            : FailedJobAlertBuffer::drain();

        $stuckPending = CrawlSite::query()
            ->where('status', 'pending')
            ->where('subscriber_count', '>', 0)
            ->where('created_at', '<', now()->subDay())
            ->get(['id', 'normalized_domain', 'created_at']);

        if ($failures === [] && $stuckPending->isEmpty()) {
            $this->info('Nothing to report.');

            return self::SUCCESS;
        }

        $lines = [];
        if ($failures !== []) {
            $lines[] = count($failures).' queue job(s) failed permanently since the last digest:';
            $byJob = collect($failures)->groupBy('job');
            foreach ($byJob as $job => $rows) {
                $first = $rows->first();
                $lines[] = sprintf(
                    '  %s ×%d  [queue=%s box=%s]',
                    $job, $rows->count(), $first['queue'] ?? '?', $first['box'] ?? '?'
                );
                $lines[] = '    latest: '.($rows->first()['exception'] ?? '');
            }
            $lines[] = '';
            $lines[] = 'Full stack traces: /horizon (Failed) or the failed_jobs table.';
        }

        if ($stuckPending->isNotEmpty()) {
            $lines[] = '';
            $lines[] = $stuckPending->count().' crawl site(s) with subscribers have NEVER been crawled (pending >24h):';
            foreach ($stuckPending as $site) {
                $lines[] = '  '.$site->normalized_domain.' (since '.$site->created_at->toDateString().')';
            }
            $lines[] = 'These never created a CrawlRun, so the crawl supervisor cannot see them.';
        }

        $body = implode("\n", $lines);

        if ($this->option('dry-run')) {
            $this->line($body);

            return self::SUCCESS;
        }

        $admins = User::query()->where('is_admin', true)->pluck('email')->filter()->values();
        if ($admins->isEmpty()) {
            $this->warn('No admin users to notify.');

            return self::SUCCESS;
        }

        Mail::to($admins->all())->send(new \App\Mail\FailedJobsDigestMail(
            $body,
            count($failures),
            $stuckPending->count(),
        ));

        $this->info('Digest sent to '.$admins->implode(', '));

        return self::SUCCESS;
    }
}
