<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Referral attribution capture: any request carrying `?ref=CODE` stamps a
 * 60-day cookie which ReferralProgram::attributeFromRequest consumes at
 * signup (User::created choke point). Last touch wins. The cookie stays
 * encrypted (standard EncryptCookies) — we only read it server-side, and
 * encryption blocks trivial forgery.
 */
class CaptureReferralCode
{
    public const COOKIE = 'ebq_ref';

    public const TTL_MINUTES = 60 * 24 * 60; // 60 days

    public function handle(Request $request, Closure $next): Response
    {
        $code = strtolower(trim((string) $request->query('ref', '')));

        if ($code !== '' && preg_match('/^[a-z0-9]{4,16}$/', $code) === 1) {
            Cookie::queue(cookie(self::COOKIE, $code, self::TTL_MINUTES));
        }

        return $next($request);
    }
}
