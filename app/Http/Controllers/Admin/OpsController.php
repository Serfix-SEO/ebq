<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\CrawlWebsitePagesJob;
use App\Models\CrawlRun;
use App\Models\CrawlSite;
use App\Models\Website;
use App\Support\FailedJobAlertBuffer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\View\View;

/**
 * Admin "Ops" dashboard — queue/worker health at a glance. Built after the
 * 2026-07-06 incident where crawl jobs died silently on the worker box for
 * 3 days (db_nodes localhost + Redis-prefix split), visible only in
 * `failed_jobs`, which nobody watches. Complements the mailed digest
 * (`ebq:failed-jobs-alert`): the mail says "something broke", this page says
 * what, where, and offers retry/forget + start-crawl actions.
 *
 * Reads `failed_jobs` directly (persistent, full traces) rather than the
 * Redis alert buffer (that one is drained by the digest mail); the buffer's
 * current size is shown so an admin can see undelivered alerts.
 */
class OpsController extends Controller
{
    public function index(Request $request): View
    {
        // ─── Failed jobs (last 7 days), grouped by job class ────────────
        $window = now()->subDays(7);
        $failedRows = DB::table('failed_jobs')
            ->where('failed_at', '>=', $window)
            ->orderByDesc('failed_at')
            ->limit(500)
            ->get()
            ->map(function ($row): array {
                $payload = json_decode((string) $row->payload, true) ?: [];

                return [
                    'uuid' => $row->uuid,
                    'job' => $payload['displayName'] ?? 'unknown',
                    'queue' => $row->queue,
                    'connection' => $row->connection,
                    'failed_at' => \Illuminate\Support\Carbon::parse($row->failed_at),
                    'exception_head' => mb_substr(explode("\n", (string) $row->exception, 2)[0], 0, 300),
                ];
            });

        $failedGroups = $failedRows
            ->groupBy(fn (array $r) => $r['job'].'|'.$r['exception_head'])
            ->map(fn ($rows) => [
                'job' => $rows->first()['job'],
                'exception_head' => $rows->first()['exception_head'],
                'queue' => $rows->first()['queue'],
                'count' => $rows->count(),
                'latest_at' => $rows->max(fn (array $r) => $r['failed_at']),
                'uuids' => $rows->pluck('uuid')->all(),
            ])
            ->sortByDesc('latest_at')
            ->values();

        // ─── Crawl sites stuck pending with subscribers ─────────────────
        $stuckSites = CrawlSite::query()
            ->where('status', 'pending')
            ->where('subscriber_count', '>', 0)
            ->where('created_at', '<', now()->subDay())
            ->orderBy('created_at')
            ->get()
            ->map(function (CrawlSite $site): array {
                $website = Website::where('crawl_site_id', $site->id)->first();

                return [
                    'id' => $site->id,
                    'domain' => $site->normalized_domain,
                    'since' => $site->created_at,
                    'website' => $website,
                    'frozen' => $website?->isFrozen() ?? false,
                ];
            });

        // ─── Queue depths + alert-buffer backlog ────────────────────────
        $queues = [];
        foreach (['crawl', 'crawl-finalize', 'sync', 'interactive', 'default', 'fleet'] as $q) {
            try {
                $queues[$q] = [
                    'pending' => (int) Redis::connection()->llen('queues:'.$q),
                    'delayed' => (int) Redis::connection()->zcard('queues:'.$q.':delayed'),
                    'reserved' => (int) Redis::connection()->zcard('queues:'.$q.':reserved'),
                ];
            } catch (\Throwable) {
                $queues[$q] = ['pending' => null, 'delayed' => null, 'reserved' => null];
            }
        }

        return view('admin.ops.index', [
            'failedGroups' => $failedGroups,
            'failedTotal' => $failedRows->count(),
            'stuckSites' => $stuckSites,
            'queues' => $queues,
            'alertBufferSize' => count(FailedJobAlertBuffer::peek()),
        ]);
    }

    /** Retry every failed job in a group (by uuid list from the grouped view). */
    public function retry(Request $request): RedirectResponse
    {
        $uuids = array_slice((array) $request->input('uuids', []), 0, 500);
        $clean = array_values(array_filter($uuids, fn ($u) => is_string($u) && preg_match('/^[0-9a-f-]{36}$/', $u)));
        if ($clean === []) {
            return back()->with('error', 'No valid job UUIDs to retry.');
        }

        Artisan::call('queue:retry', ['id' => $clean]);

        return back()->with('status', 'Requeued '.count($clean).' job(s). They will re-run on their original queue.');
    }

    /** Forget (delete) every failed job in a group. */
    public function forget(Request $request): RedirectResponse
    {
        $uuids = array_slice((array) $request->input('uuids', []), 0, 500);
        $clean = array_values(array_filter($uuids, fn ($u) => is_string($u) && preg_match('/^[0-9a-f-]{36}$/', $u)));
        if ($clean === []) {
            return back()->with('error', 'No valid job UUIDs to forget.');
        }

        foreach ($clean as $uuid) {
            Artisan::call('queue:forget', ['id' => $uuid]);
        }

        return back()->with('status', 'Deleted '.count($clean).' failed job record(s).');
    }

    /** Kick the first crawl for a stuck-pending crawl site. */
    public function startCrawl(CrawlSite $crawlSite): RedirectResponse
    {
        $website = Website::where('crawl_site_id', $crawlSite->id)->first();
        if (! $website) {
            return back()->with('error', 'No website is linked to this crawl site.');
        }
        if ($website->isFrozen()) {
            return back()->with('error', $website->domain.' is frozen by the owner\'s plan limit — unfreeze (upgrade or deactivate other sites) before crawling.');
        }

        CrawlWebsitePagesJob::dispatch($website->id, CrawlRun::TRIGGER_BACKFILL);

        return back()->with('status', 'Crawl dispatched for '.$website->domain.'.');
    }
}
