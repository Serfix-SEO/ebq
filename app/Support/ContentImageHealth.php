<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Whether image generation is actually working, and if not, why.
 *
 * WHY THIS EXISTS. `IdeogramClient` never throws (see its failure philosophy)
 * and `GenerateContentImagesJob` creates image rows only on success, so a
 * broken image provider produces articles with silently zero images: no
 * exception, no failed job, no client-visible error, nothing in the ops
 * digest. That has now cost us two blackouts:
 *
 *  - 2026-08-17 — the monthly spend cap tripped at 00:14 and the next 91
 *    articles across 12 clients shipped imageless. One client signed up
 *    inside the blackout and never saw the feature work at all.
 *  - 2026-09-11 → 2026-09-16 — Ideogram started answering 401 "Access denied.
 *    Please verify your API Token is valid." Roughly 250 articles across 16
 *    clients shipped imageless over five days before anyone noticed, and the
 *    only trace was a Log::warning nobody reads.
 *
 * After the first one we built a backfill command but never an alarm, so the
 * second one ran just as blind. This is the alarm: the client records every
 * outcome here, and `ebq:failed-jobs-alert` turns it into a digest line.
 *
 * An AUTH failure is tracked separately from the rest because it is the one
 * kind that never self-heals — a rate limit or a timeout resolves itself, a
 * rejected token stays rejected until a human pastes a new one. The marker is
 * sticky (it keeps its original `since` across repeats, so the digest can say
 * how long we have been down) and is cleared by the first success.
 *
 * Cache-backed on purpose: this is a health signal, not a ledger. It lives in
 * the shared Redis every box already writes to, like FailedJobAlertBuffer.
 */
class ContentImageHealth
{
    /** HTTP statuses that mean "a human must fix a credential". */
    private const AUTH_STATUSES = [401, 403];

    private const AUTH_KEY = 'content:images:auth-blocked';

    private const LAST_FAILURE_KEY = 'content:images:last-failure';

    /** Long enough to survive a quiet weekend; refreshed by every new failure. */
    private const TTL_DAYS = 7;

    /**
     * One failed generate() call.
     *
     * @param  string  $error  the client's error code, e.g. "ideogram_http_401"
     * @param  int|null  $status  HTTP status, when the failure was an HTTP one
     */
    public static function recordFailure(string $error, ?int $status = null): void
    {
        $now = now();

        Cache::put(self::LAST_FAILURE_KEY, [
            'at' => $now->toDateTimeString(),
            'error' => $error,
            'status' => $status,
        ], $now->copy()->addDays(self::TTL_DAYS));

        if ($status === null || ! in_array($status, self::AUTH_STATUSES, true)) {
            return;
        }

        // Keep the ORIGINAL `since` so the digest can report the real length of
        // the outage rather than the age of the most recent retry.
        $existing = Cache::get(self::AUTH_KEY);
        Cache::put(self::AUTH_KEY, [
            'since' => is_array($existing) ? ($existing['since'] ?? $now->toDateTimeString()) : $now->toDateTimeString(),
            'last' => $now->toDateTimeString(),
            'status' => $status,
            'count' => (int) (is_array($existing) ? ($existing['count'] ?? 0) : 0) + 1,
        ], $now->copy()->addDays(self::TTL_DAYS));
    }

    /**
     * A successful generate(). Clears the auth marker: the moment a new token
     * works the alarm must go quiet on its own, with no admin action.
     */
    public static function recordSuccess(): void
    {
        Cache::forget(self::AUTH_KEY);
        Cache::forget(self::LAST_FAILURE_KEY);
    }

    /**
     * The provider is refusing our credentials.
     *
     * @return array{since: string, last: string, status: int, count: int}|null
     */
    public static function authBlocked(): ?array
    {
        $row = Cache::get(self::AUTH_KEY);

        return is_array($row) && isset($row['since']) ? $row : null;
    }

    /** @return array{at: string, error: string, status: ?int}|null */
    public static function lastFailure(): ?array
    {
        $row = Cache::get(self::LAST_FAILURE_KEY);

        return is_array($row) && isset($row['at']) ? $row : null;
    }

    /** Test/ops helper — forget everything this class remembers. */
    public static function reset(): void
    {
        self::recordSuccess();
    }
}
