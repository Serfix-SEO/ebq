<?php

namespace App\Services\Content;

use App\Models\DomainMetric;
use App\Models\Website;

/**
 * Deterministic "can THIS site realistically rank for THIS keyword" math.
 * Calibrates keyword difficulty (DataForSEO 0-100, harvested into
 * keyword_metrics) against the site's own authority (Moz DA on the shared
 * domain_metrics asset). No I/O beyond one cached DA lookup; unit-testable.
 *
 * Philosophy (the offer-spine ranking flip): a DA-15 site gains nothing from
 * a difficulty-60 head term — winnability outranks volume, volume only breaks
 * ties. Unknown difficulty falls back to the keyword server's competition
 * tier, unknown authority assumes a small site (most of our clients).
 */
class KeywordWinnability
{
    /** Highest keyword difficulty a site of this authority can plausibly win. */
    public static function difficultyCeiling(?int $ownDa): int
    {
        return match (true) {
            $ownDa === null => 30,
            $ownDa >= 60 => 70,
            $ownDa >= 40 => 55,
            $ownDa >= 25 => 40,
            $ownDa >= 10 => 30,
            default => 20,
        };
    }

    /**
     * 0..1 — how winnable the keyword looks for a site with this authority.
     * Inside the ceiling: 1.0 (trivial) down to 0.7 (at the ceiling).
     * Past the ceiling: fades fast — never zero, so a tiny candidate pool
     * still ranks *something* rather than emptying the list.
     */
    public static function score(?int $difficulty, string $competitionTier, ?int $ownDa): float
    {
        if ($difficulty !== null) {
            $ceiling = self::difficultyCeiling($ownDa);
            if ($difficulty <= $ceiling) {
                return round(1.0 - 0.3 * ($difficulty / max(1, $ceiling)), 3);
            }

            return round(max(0.05, 0.5 - ($difficulty - $ceiling) / 100), 3);
        }

        return match ($competitionTier) {
            'low' => 0.9,
            'medium' => 0.6,
            'high' => 0.3,
            default => 0.5,
        };
    }

    /**
     * The site's own Moz DA from the shared domain_metrics asset (populated
     * by the wizard's competitor step for the client's own domain too). Null
     * when never fetched — score() then treats the site as small, which is
     * the safe default for keyword selection.
     */
    public static function ownAuthority(Website $website): ?int
    {
        try {
            $host = strtolower((string) ($website->normalized_domain ?: $website->domain));
            $host = preg_replace('/^www\./', '', preg_replace('#^https?://#', '', $host) ?? $host) ?? $host;
            if ($host === '') {
                return null;
            }
            $da = DomainMetric::query()->where('domain', $host)->value('moz_da');

            return is_numeric($da) ? (int) $da : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
