<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * "Is this a big brand's website rather than the visitor's own?"
 *
 * The third class of bad sign-up domain, after malformed input
 * ({@see DomainName}) and mega-platforms ({@see GiantDomains}): a real,
 * well-formed, famous domain the person entering it does not own. Shape
 * validation cannot see it — `du.ae` is a perfectly legal hostname — and it
 * slipped straight through to a live plan on 2026-08-16: 36 topics and 4
 * finished articles about paying a UAE telecom's bills, on a free account
 * with no publish target and a homepage that had timed out on crawl.
 *
 * NOT merged into GiantDomains on purpose. That list filters competitor
 * discovery, where a national telecom is a legitimate competitor for another
 * telecom; blocking brands there would quietly degrade research for real
 * clients. Two lists, two jobs.
 *
 * Small and mid-size businesses must keep sailing through — they are the
 * customer. Every rule here is deliberately biased toward letting a domain
 * pass.
 */
final class MajorBrands
{
    /** Setting keys — admins change these live, no deploy. */
    public const SETTING_BLOCK = 'signup.blocked_brand_domains';

    public const SETTING_ALLOW = 'signup.allowed_brand_domains';

    /**
     * True when $domain (already normalized) belongs to a brand/institution
     * the visitor could not plausibly own and publish to.
     */
    public static function isProtected(string $domain): bool
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return false;
        }

        // Allow-list wins over everything: the escape hatch for the real
        // employee of a listed brand, or for a false positive on the
        // authority signal. Support flips it without a deploy.
        if (self::matches($domain, self::settingList(self::SETTING_ALLOW))) {
            return false;
        }

        if (self::isPublicSector($domain)) {
            return true;
        }

        if (self::matches($domain, (array) config('brands.protected', []))) {
            return true;
        }

        if (self::matches($domain, self::settingList(self::SETTING_BLOCK))) {
            return true;
        }

        return self::isAuthorityBrand($domain);
    }

    /** Which rule fired — for logging and for admin-facing explanations. */
    public static function reason(string $domain): ?string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || self::matches($domain, self::settingList(self::SETTING_ALLOW))) {
            return null;
        }
        if (self::isPublicSector($domain)) {
            return 'public_sector';
        }
        if (self::matches($domain, (array) config('brands.protected', []))) {
            return 'curated_list';
        }
        if (self::matches($domain, self::settingList(self::SETTING_BLOCK))) {
            return 'admin_list';
        }
        if (self::isAuthorityBrand($domain)) {
            return 'authority_signal';
        }

        return null;
    }

    private static function isPublicSector(string $domain): bool
    {
        foreach ((array) config('brands.public_sector_suffixes', []) as $suffix) {
            $suffix = ltrim((string) $suffix, '.');
            if ($domain === $suffix || str_ends_with($domain, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recall net for brands nobody thought to list. Reads ONLY what the shared
     * `domain_metrics` asset already holds — never triggers a paid fetch, so a
     * cold domain (every small business) simply isn't checked. Both thresholds
     * must be met and both are set at global-brand scale.
     */
    private static function isAuthorityBrand(string $domain): bool
    {
        if (! config('brands.authority_signal.enabled', true)) {
            return false;
        }

        $row = DB::table('domain_metrics')
            ->where('domain', $domain)
            ->first(['moz_da', 'dfs_referring_domains']);

        if ($row === null || $row->moz_da === null || $row->dfs_referring_domains === null) {
            return false;
        }

        return (int) $row->moz_da >= (int) config('brands.authority_signal.min_moz_da', 80)
            && (int) $row->dfs_referring_domains >= (int) config('brands.authority_signal.min_referring_domains', 50_000);
    }

    /** Exact-or-subdomain match against a list of domains. */
    private static function matches(string $domain, array $list): bool
    {
        foreach ($list as $entry) {
            $entry = strtolower(trim((string) $entry));
            if ($entry === '') {
                continue;
            }
            if ($domain === $entry || str_ends_with($domain, '.'.$entry)) {
                return true;
            }
        }

        return false;
    }

    /** Admin-managed list: newline-, comma- or space-separated. */
    private static function settingList(string $key): array
    {
        $raw = (string) Setting::get($key, '');
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $raw) ?: [])));
    }
}
