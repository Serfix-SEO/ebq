<?php

namespace App\Support;

/**
 * Cheap, no-LLM detector for "scrap" keywords — the auth/navigation boilerplate
 * a brand-new or content-thin site ranks for (login, signup, create account,
 * dashboard, plus its own brand token) instead of real topical queries. Used by
 * the /keywords page to decide when to fall back to keyword-server suggestions
 * (the report's LLM junk-check is the deeper, paid version of this).
 */
final class KeywordJunkHeuristic
{
    /** Auth / nav / account phrases that carry no topical intent. */
    private const JUNK_PHRASES = [
        'login', 'log in', 'signin', 'sign in', 'signup', 'sign up',
        'register', 'registration', 'create account', 'create an account',
        'my account', 'account', 'password', 'forgot password', 'reset password',
        'log out', 'logout', 'sign out', 'dashboard', 'my profile', 'profile',
        'home', 'homepage', 'contact', 'contact us', 'about', 'about us',
        'terms', 'privacy', 'privacy policy', 'faq', 'support', 'help',
    ];

    public static function isJunk(string $keyword, string $domain = ''): bool
    {
        $k = trim(mb_strtolower($keyword));
        if ($k === '') {
            return true;
        }

        if (in_array($k, self::JUNK_PHRASES, true)) {
            return true;
        }

        // Pure brand navigation ("acme login", "acme", "www acme com") — the
        // brand token alone or brand + a junk phrase is not a topical query.
        $brand = self::brandToken($domain);
        if ($brand !== '' && mb_strlen($brand) >= 3) {
            $stripped = trim(str_replace($brand, '', $k));
            if ($stripped === '' || in_array($stripped, self::JUNK_PHRASES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when (nearly) every supplied query is junk — the signal that a site's
     * GSC data is all boilerplate and worth replacing with real suggestions.
     * Empty input is treated as junk (nothing useful to show).
     *
     * @param  list<string>  $queries
     */
    public static function mostlyJunk(array $queries, string $domain = '', float $threshold = 0.9): bool
    {
        $queries = array_values(array_filter(array_map('trim', $queries), static fn ($q) => $q !== ''));
        if ($queries === []) {
            return true;
        }

        $junk = 0;
        foreach ($queries as $q) {
            if (self::isJunk($q, $domain)) {
                $junk++;
            }
        }

        return ($junk / count($queries)) >= $threshold;
    }

    /** Registrable-name token, e.g. "acme" from "www.acme.co.uk". */
    private static function brandToken(string $domain): string
    {
        $host = mb_strtolower(trim($domain));
        $host = preg_replace('#^https?://#', '', $host) ?? $host;
        $host = preg_replace('#^www\.#', '', $host) ?? $host;
        $host = explode('/', $host)[0] ?? $host;
        $sld = explode('.', $host)[0] ?? $host;

        return preg_replace('/[^a-z0-9]/', '', $sld) ?? '';
    }
}
