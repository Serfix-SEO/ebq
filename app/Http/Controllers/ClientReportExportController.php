<?php

namespace App\Http\Controllers;

use App\Models\ReportBranding;
use App\Models\Website;
use App\Models\WebsiteReportSnapshot;
use App\Services\Reports\ClientReportPdfRenderer;
use App\Services\Reports\ClientReportService;
use App\Services\Reports\ReportBrandingResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * On-demand branded PDF of the backlink/authority report for the currently
 * selected website. Mirrors {@see SiteAuditExportController}: session website,
 * canViewWebsiteId gate, optional `?whitelabel=0`, immediate download.
 */
class ClientReportExportController extends Controller
{
    public function download(Request $request): Response
    {
        $user = Auth::user();
        $websiteId = session('current_website_id');
        abort_unless($websiteId !== null && $websiteId !== '' && $user?->canViewWebsiteId($websiteId), 403);

        $website = Website::find($websiteId);
        abort_unless($website !== null, 404);

        $snapshot = WebsiteReportSnapshot::forDomain($website->normalized_domain ?: $website->domain);
        abort_unless($snapshot !== null && ! empty($snapshot->payload), 404);

        $useWhitelabel = $request->boolean('whitelabel', true);
        $branding = $useWhitelabel
            ? app(ReportBrandingResolver::class)->for($website->owner ?? $user, $website)
            : ReportBranding::ebqDefault();

        $payload = app(ClientReportService::class)->withTraffic($snapshot->payload, $website);
        $generatedAt = optional($snapshot->fetched_at)->format('M j, Y');

        $renderer = app(ClientReportPdfRenderer::class);
        $bytes = $renderer->render($website, $branding, $payload, $generatedAt);
        $filename = $renderer->filenameFor($website, $branding);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
