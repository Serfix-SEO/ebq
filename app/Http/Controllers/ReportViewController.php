<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateWebsiteReport;
use App\Models\ReportBranding;
use App\Models\Website;
use App\Models\WebsiteReportSnapshot;
use App\Services\DataForSeoBacklinkClient;
use App\Services\ReportFreshnessGate;
use App\Services\Reports\ClientReportService;
use App\Services\Reports\ReportBrandingResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Authed (but NOT email-verified-gated) report view — the landing spot after
 * the "Analyze website" funnel. Shows the shared per-domain report; dispatches
 * generation and shows a self-refreshing pending page while it is being built.
 */
class ReportViewController extends Controller
{
    public function show(Request $request, ReportFreshnessGate $gate): View|RedirectResponse
    {
        $raw = (string) ($request->query('url') ?: $request->session()->get('analyze_domain', ''));
        $domain = WebsiteReportSnapshot::normalizeDomain($raw);
        if ($domain === '') {
            return redirect()->route('landing');
        }

        $user = Auth::user();

        // Guest → blurred MOCK teaser + signup modal (marketing layout). No
        // provider call, no job.
        if ($user === null) {
            $request->session()->put('analyze_domain', $domain);

            return view('reports.teaser', [
                'domain' => $domain,
                'payload' => app(ClientReportService::class)->sampleTeaserPayload($domain),
                'branding' => ReportBranding::ebqDefault(),
            ]);
        }

        return view('reports.view', $this->resolve($domain, $user, $gate));
    }

    /**
     * Resolve the view data for an authed domain lookup — shared by the
     * standalone report page (`show()` above) and any other page that embeds
     * the same "backlink report for a domain" panel (e.g. the post-signup
     * website overview hub), so both read identical pending/ready/no-data
     * state instead of two copies of this logic drifting apart.
     *
     * @return array<string, mixed>
     */
    public function resolve(string $domain, \App\Models\User $user, ReportFreshnessGate $gate): array
    {
        // The user's own website for this domain (for private traffic + branding).
        $website = Website::query()
            ->where('normalized_domain', $domain)
            ->get()
            ->first(fn (Website $w) => $user->canViewWebsiteId($w->id));

        $branding = $website !== null
            ? app(ReportBrandingResolver::class)->for($website->owner ?? $user, $website)
            : ReportBranding::ebqDefault();

        // The domain being viewed is one of the user's OWN websites (not an
        // arbitrary/competitor lookup) — pin it as "current" so the shared
        // <x-website-tabs> nav can render here and Site Health/Statistics
        // tabs land on the RIGHT site's data, not whatever was last pinned.
        if ($website !== null) {
            session(['current_website_id' => $website->id]);
        }

        // Admins read/generate against the free sandbox namespace (mock data,
        // no billing) so their testing never touches production snapshots.
        $sandbox = (bool) $user->is_admin;
        $snapshot = WebsiteReportSnapshot::forDomain($domain, $sandbox);

        // A generation that found no provider data → stop the pending loop.
        if ($snapshot !== null && $snapshot->status === 'no_data' && empty($snapshot->payload)) {
            return [
                'noData' => true,
                'domain' => $domain,
                'branding' => $branding,
                'website' => $website,
            ];
        }

        if ($snapshot === null || empty($snapshot->payload)) {
            // No provider configured → generation can never complete; show a
            // clear message instead of an endless "building…" refresh loop.
            if (! app(DataForSeoBacklinkClient::class)->isConfigured()) {
                return [
                    'unavailable' => true,
                    'domain' => $domain,
                    'branding' => $branding,
                    'website' => $website,
                ];
            }

            if (! $gate->isFresh($domain, $sandbox)) {
                GenerateWebsiteReport::dispatch($domain, false, $sandbox);
            }

            return [
                'pending' => true,
                'domain' => $domain,
                'branding' => $branding,
                'website' => $website,
            ];
        }

        $payload = app(ClientReportService::class)->withTraffic($snapshot->payload, $website);

        return [
            'pending' => false,
            'domain' => $domain,
            'payload' => $payload,
            'branding' => $branding,
            'website' => $website,
            'generatedAt' => optional($snapshot->fetched_at)->format('M j, Y'),
        ];
    }
}
