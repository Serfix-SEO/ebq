<?php

namespace App\Http\Controllers;

use App\Services\ReportFreshnessGate;
use App\Services\WebsiteTabStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Dashboard "Backlinks" page (Pulse group) — the backlink slice of the Site
 * Explorer report for the CURRENTLY SELECTED website, rendered in the app's
 * own dashboard style (dark-mode aware) rather than the report's fixed light
 * "paper" theme. Data comes from the same shared per-domain snapshot the
 * report reads — `ReportViewController::resolve()` is reused verbatim so
 * pending/no-data/generation-dispatch behaviour can never drift between the
 * two pages, and opening this page never double-bills (freshness-gated).
 */
class BacklinksController extends Controller
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

        return view('backlinks', array_merge($data, [
            'website' => $website,
        ]));
    }
}
