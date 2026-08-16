<?php

namespace App\Support\Crawler;

/**
 * One definition of "this link is dead", shared by every checker
 * (LinkChecker, SiteIssueDetector, PageAuditService) so a link can never be
 * broken in one report and fine in another.
 *
 * The distinction that matters: an HTTP error is not automatically a dead
 * link. A wall (401), a paywall (402), a WAF (403/418), a rate limit (429) or
 * a legal block (451) all mean "we were not allowed to see it" — the page is
 * there, and the site owner cannot fix it by editing their link. Reporting
 * those as broken links is how 48 of 239 open findings on prod became noise
 * (2026-08-16). Only "the server says this resource is not there" counts.
 */
final class LinkStatus
{
    /**
     * We were blocked/limited/redirected-to-auth, NOT told the page is gone.
     * Includes 999 (LinkedIn's non-standard bot block) and 418 (teapot, used
     * by several WAFs as a scraper trap).
     */
    public const NOT_DEAD = [401, 402, 403, 405, 406, 407, 408, 409, 412, 418, 421, 423, 425, 428, 429, 431, 451, 999];

    /** True only for a status that genuinely means the target is not there. */
    public static function isDead(?int $status): bool
    {
        if ($status === null || $status < 400) {
            return false;
        }

        // 5xx is the SERVER failing, not the URL being wrong — a site having a
        // bad five minutes must not turn every inbound link into a client's
        // "broken link" to fix.
        if ($status >= 500) {
            return false;
        }

        return ! in_array($status, self::NOT_DEAD, true);
    }

    /** Human-readable reason a non-dead error status was ignored (logging). */
    public static function blockedLabel(?int $status): string
    {
        return match ($status) {
            401, 407 => 'auth_required',
            402 => 'payment_required',
            403, 418 => 'bot_blocked',
            405 => 'method_not_allowed',
            406 => 'not_acceptable',
            408 => 'request_timeout',
            409 => 'conflict',
            412, 428 => 'precondition',
            421 => 'misdirected',
            423 => 'locked',
            425 => 'too_early',
            429 => 'rate_limited',
            431 => 'headers_too_large',
            451 => 'legal_block',
            999 => 'platform_block',
            default => 'blocked',
        };
    }
}
