<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Website;
use App\Services\ReportFreshnessGate;
use App\Services\WebsiteTabStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Post-signup landing page — a single website-scoped hub with a top tab bar
 * (Site Explorer / Site Health / Traffic Statistics / GSC Performance).
 * `ConnectGoogle::finishOnboarding()` redirects here instead of `dashboard`
 * so a brand-new user's first view is their own site's backlink report,
 * with the other three data sources (crawl, GA4, GSC) visibly "processing"
 * until each one lands — never a bare, misleadingly-empty state.
 *
 * The SAME tab bar (<x-website-tabs>) is also embedded on dashboard,
 * statistics, and site-explorer — see WebsiteTabStatus — so wherever a
 * client enters the app once a website is identified, they can move through
 * the whole flow via one consistent nav.
 */
class WebsiteOverviewController extends Controller
{
    public function show(Request $request, ReportFreshnessGate $gate, WebsiteTabStatus $tabStatus): View
    {
        $user = Auth::user();
        $website = $tabStatus->currentWebsite($user);
        abort_if($website === null, 404);

        // Keep every crawl-derived Livewire component embedded below
        // (CrawlBanner, SiteHealthStats, PriorityActionQueue, etc.) reading
        // the same website this page is scoped to.
        session(['current_website_id' => $website->id]);

        $tab = (string) $request->query('tab', 'explorer');
        if (! in_array($tab, WebsiteTabStatus::TABS, true)) {
            $tab = 'explorer';
        }

        $reportData = null;
        if ($tab === 'explorer' && $website->normalized_domain !== null && $website->normalized_domain !== '') {
            $reportData = app(ReportViewController::class)->resolve($website->normalized_domain, $user, $gate);
        }

        return view('website-overview', [
            'website' => $website,
            'tab' => $tab,
            'status' => $tabStatus->forWebsite($website),
            'reportData' => $reportData,
            'justOnboarded' => (bool) session('just_onboarded'),
        ]);
    }
}
