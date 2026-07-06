<?php

namespace App\Support;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Redis;

/**
 * Real-time capture of permanently-failed queue jobs into a shared-Redis
 * buffer, drained by `ebq:failed-jobs-alert` (web box, scheduled) into an
 * admin digest mail.
 *
 * Why this shape (incident 2026-07-06): crawl jobs died on the worker box for
 * 3 days, visible only in `failed_jobs`, which nobody watches. A poll of the
 * table would work but is delayed and needs high-water-mark bookkeeping; the
 * `Queue::failing()` event is immediate and fires on EVERY box (pinned worker,
 * ephemeral fleet, web). The capture deliberately writes only to the shared
 * Redis — the failing box may be exactly the one whose DB/mail connectivity is
 * broken (that WAS the incident), while Redis reachability is a given: no
 * Redis, no job to fail. Delivery happens from the web box where Postal lives.
 */
class FailedJobAlertBuffer
{
    public const KEY = 'alerts:failed-jobs';

    /** Keep the buffer bounded — a failure storm keeps the newest N. */
    private const MAX = 200;

    public static function record(JobFailed $event): void
    {
        try {
            $exception = explode("\n", (string) $event->exception, 2)[0];

            Redis::connection()->lpush(self::KEY, json_encode([
                'job' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'connection' => $event->connectionName,
                'exception' => mb_substr($exception, 0, 500),
                'box' => gethostname() ?: 'unknown',
                'failed_at' => now()->toIso8601String(),
            ]));
            Redis::connection()->ltrim(self::KEY, 0, self::MAX - 1);
        } catch (\Throwable) {
            // Never let alerting break the worker loop. Worst case the failure
            // still lands in failed_jobs as before.
        }
    }

    /**
     * Read without consuming (dry-run / dashboard use).
     *
     * @return list<array<string,mixed>>
     */
    public static function peek(): array
    {
        try {
            return collect(Redis::connection()->lrange(self::KEY, 0, self::MAX - 1))
                ->map(fn ($raw) => json_decode((string) $raw, true))
                ->filter(fn ($row) => is_array($row))
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Drain up to MAX entries (newest first). Consumed by the digest command.
     *
     * @return list<array<string,mixed>>
     */
    public static function drain(): array
    {
        $out = [];
        try {
            for ($i = 0; $i < self::MAX; $i++) {
                $raw = Redis::connection()->rpop(self::KEY);
                if ($raw === null || $raw === false) {
                    break;
                }
                $row = json_decode((string) $raw, true);
                if (is_array($row)) {
                    $out[] = $row;
                }
            }
        } catch (\Throwable) {
            // Redis briefly unreachable — leave remaining entries for next run.
        }

        return $out;
    }
}
