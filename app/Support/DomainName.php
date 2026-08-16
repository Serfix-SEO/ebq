<?php

namespace App\Support;

/**
 * "Is this string actually a website address?" — the shape gate that
 * `CrawlSite::normalizeDomain()` deliberately does not perform.
 *
 * Normalization only strips (scheme, path, www, userinfo, port); it happily
 * returns whatever is left, so before 2026-08-16 ANY string without a slash
 * became a crawlable "domain". A visitor who typed their email address into
 * the funnel's website field got a real website row, a real crawl_site and a
 * 190-page crawl of a mailbox — the row `santoshvarma.water@gmail.com` on
 * prod. Nothing downstream ever questioned it.
 *
 * The rules below are deliberately conservative: they reject what is clearly
 * not a hostname, and stay quiet about anything that might be. IDN is allowed
 * unencoded (we lowercase but never punycode), so unicode labels pass.
 */
final class DomainName
{
    /** Longest legal FQDN, minus the root dot. */
    private const MAX_LENGTH = 253;

    private const MAX_LABEL = 63;

    /**
     * True when $normalized (output of CrawlSite::normalizeDomain) is a
     * plausible registrable hostname we could crawl.
     */
    public static function isValid(string $normalized): bool
    {
        if ($normalized === '' || mb_strlen($normalized) > self::MAX_LENGTH) {
            return false;
        }

        // Anything still carrying URL/mail syntax after normalization is not a
        // host: '@' (userinfo/email), ':' (port/scheme), '/?#' (path/query),
        // whitespace, or a wildcard.
        if (preg_match('#[@:/?\#\s*\\\\]#u', $normalized) === 1) {
            return false;
        }

        // A bare label ("localhost", "intranet") is never a public website.
        $labels = explode('.', $normalized);
        if (count($labels) < 2) {
            return false;
        }

        foreach ($labels as $label) {
            if ($label === '' || mb_strlen($label) > self::MAX_LABEL) {
                return false;
            }
            if (str_starts_with($label, '-') || str_ends_with($label, '-')) {
                return false;
            }
            // Letters (incl. IDN), digits and hyphens only. Underscores are
            // legal in DNS but never in a website hostname.
            if (preg_match('/^[\p{L}\p{N}-]+$/u', $label) !== 1) {
                return false;
            }
        }

        // The TLD carries the real signal: it must be alphabetic (or an IDN
        // A-label). This is what rejects bare IPv4 ("203.0.113.9" → tld "9")
        // and typo'd input like "site.123".
        $tld = end($labels);

        return mb_strlen($tld) >= 2
            && (preg_match('/^\p{L}+$/u', $tld) === 1 || str_starts_with($tld, 'xn--'));
    }

    /**
     * Does $url belong to the crawl site for $siteDomain — the site itself or
     * one of its subdomains?
     *
     * The crawler's own notion of "internal" used to be per-PAGE (same host as
     * the document being parsed), which is right for a one-off page audit and
     * catastrophically wrong for a site crawl: one off-domain redirect
     * (serfix.io/auth/google/sso → accounts.google.com) re-anchored the parser
     * to the foreign host, so every link on Google's page counted as internal
     * and got a page row under OUR crawl site. 194 of serfix.io's 234 pages
     * were policies.google.com by the time it was caught (2026-08-16).
     */
    public static function urlBelongsToSite(string $url, string $siteDomain): bool
    {
        if ($siteDomain === '') {
            return false;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        $siteDomain = preg_replace('/^www\./', '', strtolower($siteDomain)) ?? $siteDomain;

        return $host === $siteDomain || str_ends_with($host, '.'.$siteDomain);
    }

    /**
     * True when the RAW input the user typed looks like an email address, so
     * the UI can say "that's an email" instead of a generic "invalid domain".
     * Checked on the raw value because normalization strips the local part.
     */
    public static function looksLikeEmail(string $raw): bool
    {
        $raw = trim($raw);
        $raw = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $raw) ?? $raw;

        // '#' delimiters: the character class contains a literal '/'.
        return str_contains($raw, '@') && preg_match('#^[^@\s/]+@[^@\s/]+\.[^@\s/]+$#u', $raw) === 1;
    }
}
