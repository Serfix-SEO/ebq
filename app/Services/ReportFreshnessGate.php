<?php

namespace App\Services;

use App\Models\Website;
use App\Models\WebsiteReportSnapshot;
use Illuminate\Support\Carbon;

/**
 * Single source of truth for "should we (re)generate the shared report for this
 * domain right now?" — mirrors {@see BacklinkFreshnessGate} but for the
 * per-domain `website_report_snapshots` cache and the paid/free TTL tiers.
 *
 * Rule
 * ────
 * A domain's snapshot is fresh while it is younger than the TTL:
 *   - default `services.report.default_ttl_days` (90) for any domain,
 *   - the shorter `services.report.paid_ttl_days` (30) when the domain is a
 *     Website owned by a paid ({@see \App\Models\User::isPro()}) account, so
 *     paid-owned sites refresh monthly for as long as they stay paid, and
 *   - the shortest `services.report.partial_ttl_days` (10) when the snapshot
 *     is not a full report (status partial / no_data / enriching) — a young
 *     site that gains real backlink data auto-upgrades on the next view.
 *
 * A fresh snapshot MUST be served without calling DataForSEO / Moz.
 */
class ReportFreshnessGate
{
    public function defaultTtlDays(): int
    {
        return max(1, (int) config('services.report.default_ttl_days', 90));
    }

    public function paidTtlDays(): int
    {
        return max(1, (int) config('services.report.paid_ttl_days', 30));
    }

    public function partialTtlDays(): int
    {
        return max(1, (int) config('services.report.partial_ttl_days', 10));
    }

    /**
     * Effective TTL for a domain — the paid window if any paid account owns it,
     * otherwise the default window.
     */
    public function ttlDaysFor(string $domain): int
    {
        return $this->isPaidOwned($domain) ? $this->paidTtlDays() : $this->defaultTtlDays();
    }

    /**
     * True when a stored snapshot exists and is younger than the domain's TTL.
     * Callers MUST serve the cached snapshot (no provider calls) when true.
     */
    public function isFresh(string $domain, bool $sandbox = false): bool
    {
        $snapshot = WebsiteReportSnapshot::forDomain($domain, $sandbox);
        if ($snapshot === null || $snapshot->fetched_at === null) {
            return false;
        }

        $ttlDays = $this->ttlDaysFor($domain);
        if (in_array($snapshot->status, ['partial', 'no_data', 'enriching'], true)) {
            // Not a full report — expire sooner so growing sites upgrade.
            // ('enriching' rows normally finalize within minutes; the short
            // window here is purely a stuck-row self-heal.)
            $ttlDays = min($ttlDays, $this->partialTtlDays());
        }

        $cutoff = Carbon::now()->subDays($ttlDays);

        return $snapshot->fetched_at->greaterThanOrEqualTo($cutoff);
    }

    /**
     * Whether the domain is a Website in at least one paid account.
     */
    public function isPaidOwned(string $domain): bool
    {
        $normalized = WebsiteReportSnapshot::normalizeDomain($domain);
        if ($normalized === '') {
            return false;
        }

        return Website::query()
            ->where('normalized_domain', $normalized)
            ->get()
            ->contains(fn (Website $w) => $w->isPro());
    }
}
