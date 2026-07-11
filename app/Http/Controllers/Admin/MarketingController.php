<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\CrawlReportMail;
use App\Models\CrawlFinding;
use App\Models\CrawlReportSend;
use App\Models\CrawlRun;
use App\Models\Website;
use App\Services\ClientActivityLogger;
use App\Services\Crawler\CrawlReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Admin "Marketing" panel: surfaces client websites whose latest crawl is
 * finished and that still have open issues, and lets an admin email the client
 * a numbers + top-3-examples crawl summary. Every send is recorded in
 * crawl_report_sends so we keep a record of what was sent and to whom.
 */
class MarketingController extends Controller
{
    public function __construct(
        private readonly CrawlReportService $crawl,
    ) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $websites = Website::query()
            ->whereHas('crawlSite', fn ($cs) => $cs
                ->whereHas('crawlRuns', fn ($r) => $r->where('status', CrawlRun::STATUS_COMPLETED))
                ->whereHas('crawlFindings', fn ($f) => $f->where('status', CrawlFinding::STATUS_OPEN)))
            ->with(['user:id,name,email', 'crawlSite:id'])
            ->when($q !== '', fn ($query) => $query->where('domain', 'like', '%'.$q.'%'))
            ->orderBy('domain')
            ->paginate(25)
            ->withQueryString();

        $crawlSiteIds = $websites->getCollection()->pluck('crawlSite.id')->filter()->values()->all();

        // Batch: latest run + latest completed run per crawl_site (2 queries total).
        $latestRuns = CrawlRun::whereIn('crawl_site_id', $crawlSiteIds)
            ->orderByDesc('started_at')
            ->get(['id', 'crawl_site_id', 'status', 'health_score', 'started_at', 'finished_at', 'blocked_reason'])
            ->groupBy('crawl_site_id')
            ->map(fn ($runs) => [
                'latest'    => $runs->first(),
                'completed' => $runs->firstWhere('status', CrawlRun::STATUS_COMPLETED),
            ]);

        // Batch: open finding severity counts per crawl_site (1 query).
        $findingCounts = CrawlFinding::whereIn('crawl_site_id', $crawlSiteIds)
            ->where('status', CrawlFinding::STATUS_OPEN)
            ->select('crawl_site_id', 'severity', \Illuminate\Support\Facades\DB::raw('COUNT(*) as c'))
            ->groupBy('crawl_site_id', 'severity')
            ->get()
            ->groupBy('crawl_site_id')
            ->map(fn ($rows) => $rows->pluck('c', 'severity'));

        // Batch: crawled page counts per crawl_site (1 query).
        $pageCounts = \App\Models\WebsitePage::whereIn('crawl_site_id', $crawlSiteIds)
            ->whereNull('removed_at')
            ->whereNotNull('last_crawled_at')
            ->select('crawl_site_id', \Illuminate\Support\Facades\DB::raw('COUNT(*) as c'))
            ->groupBy('crawl_site_id')
            ->pluck('c', 'crawl_site_id');

        $rows = $websites->getCollection()->map(function (Website $w) use ($latestRuns, $findingCounts, $pageCounts): array {
            $csId = $w->crawlSite?->id;
            $runs = $latestRuns[$csId] ?? ['latest' => null, 'completed' => null];
            $display = $runs['completed'] ?? $runs['latest'];
            $sev = $findingCounts[$csId] ?? collect();

            return [
                'website'        => $w,
                'health'         => $display?->health_score,
                'counts'         => [
                    'critical' => (int) ($sev['critical'] ?? 0),
                    'high'     => (int) ($sev['high'] ?? 0),
                    'medium'   => (int) ($sev['medium'] ?? 0),
                    'low'      => (int) ($sev['low'] ?? 0),
                    'total'    => (int) $sev->sum(),
                ],
                'last_crawled_at' => $display?->finished_at ?? $display?->started_at,
                'run_status'      => $runs['latest']?->status,
                'pages_total'     => (int) ($pageCounts[$csId] ?? 0),
            ];
        });

        $recentSends = CrawlReportSend::with(['website:id,domain', 'sentBy:id,name'])
            ->latest('id')->limit(10)->get();

        return view('admin.marketing.index', [
            'websites'     => $websites,
            'rows'         => $rows,
            'recentSends'  => $recentSends,
            'q'            => $q,
        ]);
    }

    public function send(Request $request, Website $website, ClientActivityLogger $logger): RedirectResponse
    {
        $data = $request->validate([
            'to_email' => ['nullable', 'email'],
        ]);

        $owner = $website->user;
        $toEmail = ($data['to_email'] ?? null) ?: $owner?->email;

        if (! $toEmail) {
            return back()->with('status', "{$website->domain} has no owner email — type a recipient address to send.");
        }

        $report = $this->crawl->emailReportPayload($website);
        $subject = $this->subject($website, (int) ($report['counts']['total'] ?? 0));

        $status = 'sent';
        try {
            Mail::to($toEmail)->queue(new CrawlReportMail($website, $report, $owner?->name));
        } catch (\Throwable $e) {
            $status = 'failed';
            Log::warning("MarketingController: crawl report send failed for {$website->domain} -> {$toEmail}: {$e->getMessage()}");
        }

        CrawlReportSend::create([
            'website_id' => $website->id,
            'recipient_user_id' => $owner?->id,
            'sent_by_user_id' => $request->user()?->id,
            'to_email' => $toEmail,
            'subject' => $subject,
            'summary' => $report,
            'status' => $status,
        ]);

        $logger->log(
            'admin.crawl_report_sent',
            userId: $owner?->id,
            websiteId: $website->id,
            meta: ['to_email' => $toEmail, 'total' => (int) ($report['counts']['total'] ?? 0), 'status' => $status],
            actorUserId: $request->user()?->id,
        );

        return back()->with('status', $status === 'sent'
            ? "Crawl report sent to {$toEmail} for {$website->domain}."
            : "Could not send the report for {$website->domain} — logged as failed.");
    }

    /** Full paginated history of every report we've sent. */
    public function sends(Request $request): View
    {
        $sends = CrawlReportSend::with(['website:id,domain', 'recipient:id,name', 'sentBy:id,name'])
            ->latest('id')
            ->paginate(40)
            ->withQueryString();

        return view('admin.marketing.sends', ['sends' => $sends]);
    }

    private function subject(Website $website, int $count): string
    {
        return "Your {$website->domain} SEO crawl found {$count} ".Str::plural('issue', $count);
    }
}
