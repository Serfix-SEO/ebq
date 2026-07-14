<?php

namespace App\Services;

use App\Models\AnalyticsData;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteReportSnapshot;
use Illuminate\Support\Facades\Auth;

/**
 * Shared "which of the 4 top-level website tabs is ready" logic — used by
 * WebsiteOverviewController (the /overview hub) AND the <x-website-tabs>
 * component embedded on dashboard/statistics/site-explorer, so every page
 * that shows this nav reads the exact same real, checked signals. Never
 * inferred from whether a cache happens to be warm.
 */
class WebsiteTabStatus
{
    public const TABS = ['explorer', 'health', 'statistics'];

    public function __construct(private ReportDataService $reports) {}

    /**
     * 'needs_action' vs 'processing' are deliberately distinct: the former
     * means the user must connect something, the latter means it's
     * connected and Serfix is still pulling data.
     *
     * @return array<string, array{state: string, label: string}>
     */
    public function forWebsite(Website $website): array
    {
        $out = [];

        // Site Explorer: real snapshot check (same source ReportViewController
        // reads) — was previously hardcoded to always show "Processing", even
        // on a cache hit or an already-built report. Admins read/generate
        // against the sandbox namespace, same as ReportViewController.
        $out['explorer'] = $this->explorerStatus($website);

        // Site Health: crawl-derived. isInitialCrawl() alone isn't enough —
        // it goes false as soon as a site has EVER completed a crawl, so a
        // RECRAWL of an established site (isCrawling() true, isInitialCrawl()
        // false — existing data still shows, by design) would otherwise read
        // as "Ready" while the crawler is actively running.
        $out['health'] = ($website->isCrawling() || $website->isInitialCrawl())
            ? ['state' => 'processing', 'label' => __('Crawl in progress')]
            : ['state' => 'ready', 'label' => __('Ready')];

        // Statistics = GA4 (traffic) + GSC (performance) merged onto one
        // screen, like /statistics — each source is independently gated
        // (its own connect prompt / processing state) inside that screen,
        // but the TAB pill itself needs one combined verdict:
        //   needs_action if EITHER source isn't connected (something to do);
        //   else processing if EITHER connected source is still importing;
        //   else ready.
        if (! $website->hasGa()) {
            $out['traffic'] = ['state' => 'needs_action', 'label' => __('Connect Google Analytics')];
        } elseif (! AnalyticsData::where('website_id', $website->id)->exists()) {
            $out['traffic'] = ['state' => 'processing', 'label' => __('Importing traffic data')];
        } else {
            $out['traffic'] = ['state' => 'ready', 'label' => __('Ready')];
        }

        // lastSafeReportDate() is the SAME "has at least one finalized day
        // landed" check SyncAndReportPanel already relies on, so this can't
        // disagree with the rest of the app either.
        if (! $website->hasGsc()) {
            $out['gsc'] = ['state' => 'needs_action', 'label' => __('Connect Search Console')];
        } elseif ($this->reports->lastSafeReportDate($website->id) === null) {
            $out['gsc'] = ['state' => 'processing', 'label' => __('Importing Search Console data')];
        } else {
            $out['gsc'] = ['state' => 'ready', 'label' => __('Ready')];
        }

        $out['statistics'] = $this->combine($out['traffic'], $out['gsc']);

        return $out;
    }

    /**
     * @param  array{state: string, label: string}  $a
     * @param  array{state: string, label: string}  $b
     * @return array{state: string, label: string}
     */
    private function combine(array $a, array $b): array
    {
        if ($a['state'] === 'needs_action' || $b['state'] === 'needs_action') {
            return ['state' => 'needs_action', 'label' => __('Needs action')];
        }
        if ($a['state'] === 'processing' || $b['state'] === 'processing') {
            return ['state' => 'processing', 'label' => __('Importing data')];
        }

        return ['state' => 'ready', 'label' => __('Ready')];
    }

    /**
     * @return array{state: string, label: string}
     */
    private function explorerStatus(Website $website): array
    {
        $domain = $website->normalized_domain;
        if ($domain === null || $domain === '') {
            return ['state' => 'ready', 'label' => __('Ready')];
        }

        $sandbox = (bool) Auth::user()?->is_admin;
        $snapshot = WebsiteReportSnapshot::forDomain($domain, $sandbox);

        // A snapshot exists with a real payload (cache hit or a completed
        // generation) — nothing left to wait for, including the "no
        // provider data for this domain" terminal state.
        if ($snapshot !== null && ($snapshot->status === 'no_data' || ! empty($snapshot->payload))) {
            return ['state' => 'ready', 'label' => __('Ready')];
        }

        return ['state' => 'processing', 'label' => __('Building your report')];
    }

    /**
     * Resolve the "current" website the same way across every page that
     * embeds the tabs: the session pin (set on website switch / onboarding),
     * validated against access, falling back to the account's oldest
     * accessible website. Returns null for a guest or a user with no sites.
     */
    public function currentWebsite(?User $user): ?Website
    {
        if ($user === null) {
            return null;
        }

        $sessionId = (string) session('current_website_id', '');
        if ($sessionId !== '' && $user->canViewWebsiteId($sessionId)) {
            $website = Website::find($sessionId);
            if ($website !== null) {
                return $website;
            }
        }

        return $user->accessibleWebsitesQuery()->orderBy('created_at')->first();
    }
}
