<?php

namespace App\Http\Controllers;

use App\Models\ReportShare;
use App\Models\WebsiteReportSnapshot;
use App\Services\Reports\ClientReportService;
use App\Services\Reports\ReportBrandingResolver;
use Illuminate\Contracts\View\View;

/**
 * Public, no-auth render of a shared report. Resolves a high-entropy share
 * token to a website's cached snapshot. Bad / revoked / expired tokens 404
 * (never 403) so tokens can't be enumerated.
 */
class PublicReportController extends Controller
{
    public function show(string $token): View
    {
        $share = ReportShare::active()->where('token', $token)->first();
        abort_if($share === null, 404);

        $website = $share->website;
        abort_if($website === null, 404);

        $branding = app(ReportBrandingResolver::class)->for($website->owner, $website);
        $snapshot = WebsiteReportSnapshot::forDomain($website->normalized_domain ?: $website->domain);

        if ($snapshot === null || empty($snapshot->payload)) {
            return view('reports.public', [
                'pending' => true,
                'branding' => $branding,
                'website' => $website,
            ]);
        }

        $payload = app(ClientReportService::class)->withTraffic($snapshot->payload, $website);

        return view('reports.public', [
            'pending' => false,
            'payload' => $payload,
            'branding' => $branding,
            'generatedAt' => optional($snapshot->fetched_at)->format('M j, Y'),
        ]);
    }
}
