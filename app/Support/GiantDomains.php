<?php

namespace App\Support;

/**
 * Mega-platforms that appear in SERPs for almost any query — they share
 * keywords with everyone but are (virtually) never a real product
 * competitor for a Serfix client. Extracted from CompetitorDiscoveryService
 * so the report-enrichment competitor tally, the Content Autopilot
 * competitors step, and any future SERP tally reuse the exact same filter.
 *
 * Expanded 2026-07-22 (owner request): the original 15 entries let amazon,
 * netflix-class giants through into the wizard's competitor list. This list
 * is EXTENDABLE, not exhaustive — add any globally-generic platform that
 * pollutes SERPs; genuinely niche-scale sites (even large ones like a
 * national directory) stay OUT, because for some client they ARE the
 * logical competitor.
 *
 * Matching is exact-or-subdomain (`isGiant`), so `music.amazon.com` is
 * covered by `amazon.com` — but ccTLD siblings are separate entries.
 */
final class GiantDomains
{
    private const GIANT_DOMAINS = [
        // Search / reference
        'google.com', 'google.co.uk', 'google.ae', 'google.de', 'google.fr',
        'bing.com', 'yahoo.com', 'duckduckgo.com', 'baidu.com', 'yandex.com',
        'wikipedia.org', 'wikimedia.org', 'wikihow.com', 'britannica.com',
        'dictionary.com', 'merriam-webster.com', 'archive.org', 'fandom.com',

        // Social / community / UGC
        'facebook.com', 'instagram.com', 'x.com', 'twitter.com', 'tiktok.com',
        'linkedin.com', 'pinterest.com', 'reddit.com', 'quora.com',
        'snapchat.com', 'whatsapp.com', 'telegram.org', 'discord.com',
        'threads.net', 'tumblr.com', 'medium.com', 'stackexchange.com',
        'stackoverflow.com', 'github.com',

        // Video / streaming / music
        'youtube.com', 'netflix.com', 'hulu.com', 'disneyplus.com',
        'primevideo.com', 'twitch.tv', 'vimeo.com', 'dailymotion.com',
        'spotify.com', 'soundcloud.com',

        // Marketplaces / retail giants
        'amazon.com', 'amazon.co.uk', 'amazon.de', 'amazon.fr', 'amazon.it',
        'amazon.es', 'amazon.ca', 'amazon.ae', 'amazon.in',
        'ebay.com', 'ebay.co.uk', 'ebay.de', 'etsy.com', 'aliexpress.com',
        'alibaba.com', 'walmart.com', 'target.com', 'bestbuy.com', 'temu.com',
        'craigslist.org', 'ikea.com',

        // Big tech / OS / app stores
        'apple.com', 'microsoft.com', 'play.google.com', 'adobe.com',
        'mozilla.org', 'samsung.com',

        // Mega review / listing / booking platforms
        'yelp.com', 'tripadvisor.com', 'trustpilot.com', 'glassdoor.com',
        'indeed.com', 'crunchbase.com', 'booking.com', 'airbnb.com',
        'expedia.com', 'zillow.com', 'realtor.com',

        // News / media giants
        'nytimes.com', 'bbc.com', 'bbc.co.uk', 'cnn.com', 'forbes.com',
        'theguardian.com', 'reuters.com', 'bloomberg.com',
        'businessinsider.com', 'washingtonpost.com', 'huffpost.com',
        'usatoday.com', 'dailymail.co.uk',
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

    /**
     * SIGNAL-based giant detection (2026-07-23) — the static list's recall
     * net. A domain not on the list can still be giant-class by scale
     * (sephora/kohls for kayali.com were exactly this). Uses metrics already
     * stored on domain_metrics — zero API cost. Deliberately conservative:
     * every branch requires strong absolute authority, so a merely-larger
     * niche rival never demotes.
     *
     * Gated at call sites by `content.giant_signals.enabled` (live-flippable
     * Setting — the whole feature reverts with one flag, no deploy).
     */
    public static function isScaleGiant(
        ?int $domainReferring,
        ?int $domainDa,
        ?int $organicKeywordCount,
        ?int $clientReferring,
    ): bool {
        // Ranks for a platform-scale keyword set → giant behavior by definition.
        if ($organicKeywordCount !== null && $organicKeywordCount > 500_000) {
            return true;
        }
        // Massive authority AND dwarfs the client (≥20×) → not a comparable rival.
        if ($domainDa !== null && $domainDa >= 70
            && $clientReferring !== null && $clientReferring > 0
            && $domainReferring !== null && $domainReferring > 20 * $clientReferring) {
            return true;
        }
        // Client size unknown: only the unambiguous mega-profile qualifies.
        if ($domainDa !== null && $domainDa >= 75
            && $domainReferring !== null && $domainReferring > 100_000) {
            return true;
        }

        return false;
    }
}
