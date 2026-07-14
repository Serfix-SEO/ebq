<?php

namespace App\Http\Controllers;

use App\Services\ReportFreshnessGate;
use App\Services\WebsiteTabStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Dashboard "Competitors" page (Pulse group) — the organic-competitor slice
 * of the Site Explorer report for the CURRENTLY SELECTED website (domains
 * ranking for the same keywords, from DataForSEO Labs). Identical data-flow
 * to {@see BacklinksController}: reuses `ReportViewController::resolve()`
 * so pending/no-data/auto-generation behaviour and the freshness gate can
 * never drift from the report page, and opening it never double-bills.
 */
class CompetitorsController extends Controller
{
    public function show(Request $request, ReportFreshnessGate $gate, WebsiteTabStatus $tabStatus): View
    {
        $user = Auth::user();
        $website = $tabStatus->currentWebsite($user);
        abort_if($website === null, 404);

        session(['current_website_id' => $website->id]);

        $domain = (string) $website->normalized_domain;
        $data = $domain !== ''
            ? app(ReportViewController::class)->resolve($domain, $user, $gate)
            : ['noData' => true, 'domain' => $website->domain];

        return view('competitors', array_merge($data, [
            'website' => $website,
        ]));
    }
}
