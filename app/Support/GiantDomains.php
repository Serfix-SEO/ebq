<?php

namespace App\Support;

/**
 * Mega-platforms that appear in SERPs for almost any query — they share
 * keywords with everyone but are never a real competitor. Extracted from
 * CompetitorDiscoveryService so the report-enrichment competitor tally can
 * reuse the exact same filter.
 */
final class GiantDomains
{
    private const GIANT_DOMAINS = [
        'wikipedia.org', 'youtube.com', 'facebook.com', 'amazon.com', 'reddit.com',
        'linkedin.com', 'pinterest.com', 'quora.com', 'instagram.com', 'x.com',
        'twitter.com', 'google.com', 'tiktok.com', 'yelp.com', 'medium.com',
    ];

    public static function isGiant(string $domain): bool
    {
        foreach (self::GIANT_DOMAINS as $giant) {
            if ($domain === $giant || str_ends_with($domain, '.'.$giant)) {
                return true;
            }
        }

        return false;
    }
}
