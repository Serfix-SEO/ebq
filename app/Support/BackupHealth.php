<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Is the nightly database backup actually happening?
 *
 * The dumps are written by `scripts/db/backup.sh` under a systemd timer, in
 * /var/backups/ebq — a path the app container cannot see. So the script drops a
 * heartbeat into storage/app/backup-status.json, which both sides share, and
 * this reads it.
 *
 * It exists because every silent failure in this codebase has followed the same
 * pattern: something stops, nothing throws, and nobody finds out until a person
 * happens to look. A backup is the worst possible thing to learn that about,
 * since you only discover it when you already need it. Three ways it can be
 * wrong, and the heartbeat catches all three:
 *
 *  - **missing** — no status file at all: the job has never run, or the path
 *    changed and nobody noticed.
 *  - **stale** — the last run is older than a day plus slack: the timer died,
 *    the box was down, the script broke before writing.
 *  - **suspiciously small** — the dump completed and exited 0 but is a fraction
 *    of the usual size. A truncated dump is worse than none, because it looks
 *    like a backup right up until you restore it.
 */
class BackupHealth
{
    /** Daily job + 2h slack. Later than this and something is wrong. */
    private const STALE_AFTER_HOURS = 26;

    /** Below this share of the median dump, treat the file as truncated. */
    private const MIN_SIZE_RATIO = 0.5;

    public const STATUS_FILE = 'backup-status.json';

    /**
     * @return array{state: string, detail: string, at: ?string, offsite: bool}
     *                                                                          state: ok | missing | stale | failed | truncated
     */
    public static function check(): array
    {
        // Not every box runs the backup job — a dev checkout and the test suite
        // never will. Reporting "never reported in" there would be noise, and
        // this is the last alarm that can afford to be noisy.
        if (! config('features.backups_expected', true)) {
            return ['state' => 'ok', 'detail' => 'backups are not expected on this box', 'at' => null, 'offsite' => false];
        }

        $raw = null;
        try {
            if (Storage::disk('local')->exists(self::STATUS_FILE)) {
                $raw = json_decode((string) Storage::disk('local')->get(self::STATUS_FILE), true);
            }
        } catch (\Throwable) {
            $raw = null;   // unreadable is the same as absent, for our purposes
        }

        if (! is_array($raw) || ! isset($raw['at'])) {
            return [
                'state' => 'missing',
                'detail' => 'no backup has ever reported in',
                'at' => null,
                'offsite' => false,
            ];
        }

        $at = Carbon::parse((string) $raw['at']);
        $offsite = (bool) ($raw['offsite'] ?? false);

        if (! ($raw['ok'] ?? false)) {
            return [
                'state' => 'failed',
                'detail' => trim((string) ($raw['error'] ?? '')) ?: 'the backup script reported a failure',
                'at' => $at->toDateTimeString(),
                'offsite' => $offsite,
            ];
        }

        if ($at->lt(now()->subHours(self::STALE_AFTER_HOURS))) {
            return [
                'state' => 'stale',
                'detail' => 'the last successful backup was '.$at->diffForHumans(),
                'at' => $at->toDateTimeString(),
                'offsite' => $offsite,
            ];
        }

        $bytes = (int) ($raw['bytes'] ?? 0);
        $median = (int) ($raw['median_bytes'] ?? 0);
        if ($bytes > 0 && $median > 0 && $bytes < $median * self::MIN_SIZE_RATIO) {
            return [
                'state' => 'truncated',
                'detail' => sprintf(
                    'the latest dump is %s, against a usual %s',
                    self::human($bytes),
                    self::human($median)
                ),
                'at' => $at->toDateTimeString(),
                'offsite' => $offsite,
            ];
        }

        return [
            'state' => 'ok',
            'detail' => sprintf('%s, %s', self::human($bytes), $at->diffForHumans()),
            'at' => $at->toDateTimeString(),
            'offsite' => $offsite,
        ];
    }

    private static function human(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 1).' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576).' MB';
        }

        return $bytes.' B';
    }
}
