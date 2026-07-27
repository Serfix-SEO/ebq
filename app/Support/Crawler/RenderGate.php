<?php

namespace App\Support\Crawler;

/**
 * Decides whether an HTTP fetch result is a bot-challenge / render-required shell
 * (as opposed to real content or a plain 404/500). Kept deliberately narrow —
 * Cloudflare "Just a moment" / Managed Challenge and equivalents — so the render
 * fallback (Firecrawl) only fires where a plain HTTP GET genuinely can't win.
 */
class RenderGate
{
    /**
     * @param  array<string,string>  $headers
     */
    public static function isChallenge(int $status, array $headers, string $body): bool
    {
        $h = array_change_key_case($headers, CASE_LOWER);

        // Cloudflare's own signal — present on managed/JS challenges.
        if (str_contains(strtolower((string) ($h['cf-mitigated'] ?? '')), 'challenge')) {
            return true;
        }

        $b = strtolower($body);

        // Challenge-platform markers can appear even on a 200 interstitial.
        foreach (['/cdn-cgi/challenge-platform', 'window._cf_chl', '__cf_chl_', 'turnstile'] as $n) {
            if (str_contains($b, $n)) {
                return true;
            }
        }

        // Classic interstitial phrases, only on block-ish statuses / Cloudflare origin.
        $onGuard = in_array($status, [401, 403, 429, 503], true)
            || str_contains(strtolower((string) ($h['server'] ?? '')), 'cloudflare');
        if ($onGuard) {
            foreach (['just a moment', 'checking your browser', 'enable javascript and cookies to continue', 'attention required'] as $n) {
                if (str_contains($b, $n)) {
                    return true;
                }
            }
        }

        return false;
    }
}
