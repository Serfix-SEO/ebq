<?php

namespace App\Jobs;

use App\Mail\CrawlReportMail;
use App\Models\CrawlReportSend;
use App\Models\CrawlSite;
use App\Services\ClientActivityLogger;
use App\Services\Crawler\CrawlReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Auto-sends the crawl-issues summary email (same CrawlReportMail the admin
 * Marketing panel sends manually — see MarketingController::send) to every
 * subscriber website's owner after a crawl finishes cleanly. Dispatched from
 * AnalyzeSiteJob's success path only (not blocked/aborted/enrichment-failed
 * runs). Skips a website if it has no open findings — a "0 issues" email adds
 * no value, matching the Marketing panel's own listing filter.
 */
class SendCrawlReportEmailsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $crawlSiteId, public string $crawlRunId)
    {
        $this->onQueue(\App\Support\Queues::DEFAULT);
    }

    public function handle(CrawlReportService $crawl, ClientActivityLogger $logger): void
    {
        $crawlSite = CrawlSite::find($this->crawlSiteId);
        if (! $crawlSite) {
            return;
        }

        foreach ($crawlSite->websites()->with('user:id,name,email,locale')->get() as $website) {
            $owner = $website->user;
            if (! $owner?->email) {
                continue;
            }

            $report = $crawl->emailReportPayload($website);
            $total = (int) ($report['counts']['total'] ?? 0);
            if ($total === 0) {
                continue;
            }

            $status = 'sent';
            try {
                Mail::to($owner->email)->queue(new CrawlReportMail($website, $report, $owner->name));
            } catch (\Throwable $e) {
                $status = 'failed';
                Log::warning("SendCrawlReportEmailsJob: send failed for {$website->domain} -> {$owner->email}: {$e->getMessage()}");
            }

            CrawlReportSend::create([
                'website_id' => $website->id,
                'recipient_user_id' => $owner->id,
                'sent_by_user_id' => null,
                'to_email' => $owner->email,
                'subject' => "Your {$website->domain} SEO crawl found {$total} ".Str::plural('issue', $total),
                'summary' => $report,
                'status' => $status,
            ]);

            $logger->log(
                'crawl.report_auto_sent',
                userId: $owner->id,
                websiteId: $website->id,
                meta: ['to_email' => $owner->email, 'total' => $total, 'status' => $status, 'crawl_run_id' => $this->crawlRunId],
            );
        }
    }
}
