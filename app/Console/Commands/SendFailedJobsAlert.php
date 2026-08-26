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

        // DataForSEO spend circuit-breaker warning (80% / 100% of the monthly
        // cap). Runs every 15 min, so a cache flag limits each threshold to
        // ONE digest line per day. Admin-only — clients never see spend state.
        $spendLine = null;
        $meter = app(\App\Services\Reports\DataForSeoSpendMeter::class);
        if ($meter->nearCap()) {
            $threshold = $meter->exhausted() ? '100' : '80';
            $flag = 'dfs-spend-warned:'.now()->utc()->format('Y-m-d').':'.$threshold;
            if (\Illuminate\Support\Facades\Cache::add($flag, true, now()->addDay())) {
                $spendLine = sprintf(
                    'DataForSEO spend: $%.2f of the $%.2f monthly cap%s',
                    $meter->spent(), $meter->cap(),
                    $meter->exhausted()
                        ? ' — CAP REACHED: lookups now serve free-signal partials, TTL refreshes paused, own-site first reports still generate. Raise DATAFORSEO_MONTHLY_CAP_USD to resume.'
                        : ' (80%+ warning).'
                );
            }
        }

        // Collapse repeats (2026-08-26: ONE broken Hindi article re-failed on
        // every 15-min dispatcher tick → six identical digest emails). Each
        // job+exception fingerprint that already triggered a mail stays muted
        // for REALERT_HOURS while its count accumulates in cache; it re-mails
        // with the cumulative total once the window lapses. Fresh fingerprints
        // always mail. A digest containing ONLY muted repeats sends nothing.
        $freshGroups = [];
        $mutedGroups = [];
        foreach (collect($failures)->groupBy(fn ($f) => $this->fingerprint($f)) as $fp => $rows) {
            $cached = \Illuminate\Support\Facades\Cache::get('failed-digest:fp:'.$fp);
            $muted = is_array($cached)
                && (now()->timestamp - (int) ($cached['mailed_at'] ?? 0)) < self::REALERT_HOURS * 3600;
            $group = [
                'fp' => (string) $fp,
                'rows' => $rows,
                'prior' => (int) ($cached['count'] ?? 0),
            ];
            $muted ? $mutedGroups[] = $group : $freshGroups[] = $group;
        }

        if ($freshGroups === [] && $stuckPending->isEmpty() && $spendLine === null) {
            $this->rememberGroups($freshGroups, $mutedGroups);
            $this->info($mutedGroups === []
                ? 'Nothing to report.'
                : 'Only already-reported failures repeating — digest suppressed.');

            return self::SUCCESS;
        }

        $lines = [];
        if ($spendLine !== null) {
            $lines[] = $spendLine;
            $lines[] = '';
        }
        if ($freshGroups !== []) {
            $lines[] = collect($freshGroups)->sum(fn ($g) => $g['rows']->count()).' queue job(s) failed permanently since the last digest:';
            foreach ($freshGroups as $g) {
                $first = $g['rows']->first();
                $lines[] = sprintf(
                    '  %s ×%d%s  [queue=%s box=%s]',
                    $first['job'] ?? '?',
                    $g['rows']->count(),
                    $g['prior'] > 0 ? ' (+'.$g['prior'].' reported earlier)' : '',
                    $first['queue'] ?? '?', $first['box'] ?? '?'
                );
                $lines[] = '    latest: '.($first['exception'] ?? '');
            }
        }
        if ($mutedGroups !== []) {
            $lines[] = '';
            $lines[] = 'Still repeating (same exception already reported — muted for '.self::REALERT_HOURS.'h):';
            foreach ($mutedGroups as $g) {
                $first = $g['rows']->first();
                $lines[] = sprintf('  %s ×%d total  [queue=%s]',
                    $first['job'] ?? '?', $g['prior'] + $g['rows']->count(), $first['queue'] ?? '?');
            }
        }
        if ($freshGroups !== [] || $mutedGroups !== []) {
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

        $this->rememberGroups($freshGroups, $mutedGroups);

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

    /** Hours a mailed fingerprint stays muted before it may re-alert. */
    private const REALERT_HOURS = 6;

    /**
     * Same-failure identity: job class + exception first line with ids and
     * numbers neutralized, so per-run ULIDs/timestamps don't defeat the
     * collapse.
     */
    private function fingerprint(array $failure): string
    {
        $normalized = preg_replace('/[0-9a-hjkmnp-tv-z]{20,}|\d+/i', '#', (string) ($failure['exception'] ?? ''));

        return md5(($failure['job'] ?? '?').'|'.$normalized);
    }

    /**
     * Persist the mute state: fresh groups start a new mute window from now;
     * muted groups keep their original window but accumulate the count.
     * Called on every non-dry-run path — including the suppressed-mail one —
     * so counts stay truthful.
     */
    private function rememberGroups(array $freshGroups, array $mutedGroups): void
    {
        foreach ($freshGroups as $g) {
            \Illuminate\Support\Facades\Cache::put('failed-digest:fp:'.$g['fp'], [
                'mailed_at' => now()->timestamp,
                'count' => $g['prior'] + $g['rows']->count(),
            ], now()->addDay());
        }
        foreach ($mutedGroups as $g) {
            $cached = \Illuminate\Support\Facades\Cache::get('failed-digest:fp:'.$g['fp']) ?? ['mailed_at' => now()->timestamp];
            \Illuminate\Support\Facades\Cache::put('failed-digest:fp:'.$g['fp'], [
                'mailed_at' => (int) ($cached['mailed_at'] ?? now()->timestamp),
                'count' => $g['prior'] + $g['rows']->count(),
            ], now()->addDay());
        }
    }
}
