<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\LinkCrawlBatchJob;
use App\Models\LinkCrawlFrontier;
use App\Services\LinkGraph\LinkCrawlBudget;
use App\Support\LinkCrawlToggle;
use App\Support\Queues;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\View\View;

/**
 * Master admin dashboard for the link-graph engine (Tier-1 passive + Tier-1.5
 * targeted crawler): live crawler status, daily budget, frontier breakdown,
 * new-backlink-discovery-per-day chart with filters, and the biggest recently
 * discovered domains. Read-only except the pause/resume + reseed actions.
 */
class LinkGraphController extends Controller
{
    private const SOURCES = ['own_crawl', 'enrichment', 'provider', 'cc_wat'];

    public function index(Request $request, LinkCrawlBudget $budget): View
    {
        // ── Filters ─────────────────────────────────────────────
        $days = (int) $request->integer('days', 30);
        $days = in_array($days, [7, 14, 30, 90], true) ? $days : 30;
        $source = (string) $request->query('source', 'all');
        $source = in_array($source, self::SOURCES, true) ? $source : 'all';
        $since = now()->subDays($days - 1)->startOfDay();

        // ── Live crawler status ─────────────────────────────────
        $enabled = LinkCrawlToggle::enabled();
        $envOn = (bool) config('crawler.link_crawl.enabled');
        $paused = LinkCrawlToggle::runtimePaused();
        // "Running" = batches in flight on the dedicated queue.
        $running = $this->queueSize(Queues::LINK_CRAWL) > 0;

        $frontier = LinkCrawlFrontier::query()
            ->selectRaw('status, count(*) c')
            ->groupBy('status')
            ->pluck('c', 'status');
        $frontierDue = LinkCrawlFrontier::query()
            ->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('next_at')->orWhere('next_at', '<=', now()))
            ->count();

        // Domains crawled today (frontier rows that transitioned to done today).
        $crawledToday = LinkCrawlFrontier::query()
            ->where('status', 'done')
            ->where('updated_at', '>=', now()->startOfDay())
            ->count();

        // ── New backlink discovery per day (filtered) ───────────
        $edgeQuery = DB::table('link_edges')->where('first_seen_at', '>=', $since);
        if ($source !== 'all') {
            $edgeQuery->where('source', $source);
        }
        $perDay = (clone $edgeQuery)
            ->selectRaw('DATE(first_seen_at) d, count(*) c')
            ->groupBy('d')->orderBy('d')->pluck('c', 'd');

        // Fill gaps so the chart has one bar per day.
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $key = now()->subDays($i)->toDateString();
            $series[] = ['date' => $key, 'count' => (int) ($perDay[$key] ?? 0)];
        }
        $discoveredInRange = array_sum(array_column($series, 'count'));

        // ── Source split (whole graph) ──────────────────────────
        $bySource = DB::table('link_edges')
            ->selectRaw('source, count(*) c')
            ->groupBy('source')->pluck('c', 'source');

        // ── Totals ──────────────────────────────────────────────
        $totals = [
            'edges' => DB::table('link_edges')->count(),
            'domains' => DB::table('link_domains')->count(),
            'urls' => DB::table('link_urls')->count(),
            'discovered_today' => (clone $edgeQuery)->where('first_seen_at', '>=', now()->startOfDay())->count(),
        ];

        // ── Most-linked-to domains discovered in range ──────────
        $topTargets = DB::table('link_edges')
            ->join('link_domains as t', 't.id', '=', 'link_edges.to_domain_id')
            ->where('link_edges.first_seen_at', '>=', $since)
            ->when($source !== 'all', fn ($q) => $q->where('link_edges.source', $source))
            ->selectRaw('t.name, count(*) c, sum(link_edges.dofollow) df')
            ->groupBy('t.name')->orderByDesc('c')->limit(20)->get();

        // ── Discovery feed (paginated) — honors the source + day filters,
        //    so an admin can page through everything the crawler/enrichment/
        //    provider pipelines have discovered, not just the latest 40.
        $recent = DB::table('link_edges')
            ->join('link_domains as f', 'f.id', '=', 'link_edges.from_domain_id')
            ->join('link_domains as t', 't.id', '=', 'link_edges.to_domain_id')
            ->leftJoin('link_urls as fu', 'fu.id', '=', 'link_edges.from_url_id')
            ->where('link_edges.first_seen_at', '>=', $since)
            ->when($source !== 'all', fn ($q) => $q->where('link_edges.source', $source))
            ->orderByDesc('link_edges.first_seen_at')
            ->select(['f.name as from_domain', 't.name as to_domain', 'fu.path as from_path',
                'link_edges.dofollow', 'link_edges.anchor_class', 'link_edges.source', 'link_edges.first_seen_at'])
            ->paginate(50, pageName: 'feed')
            ->withQueryString();

        return view('admin.link-graph.index', [
            'filters' => ['days' => $days, 'source' => $source],
            'sources' => self::SOURCES,
            'crawler' => [
                'enabled' => $enabled,
                'env_on' => $envOn,
                'paused' => $paused,
                'running' => $running,
                'crawled_today' => $crawledToday,
                'budget_spent' => $budget->spent(),
                'budget_limit' => $budget->limit(),
                'queue_depth' => $this->queueSize(Queues::LINK_CRAWL),
                'frontier' => $frontier,
                'frontier_due' => $frontierDue,
            ],
            'totals' => $totals,
            'series' => $series,
            'discovered_in_range' => $discoveredInRange,
            'by_source' => $bySource,
            'top_targets' => $topTargets,
            'recent' => $recent,
        ]);
    }

    /** Pause / resume the crawler without an SSH env edit (writes the runtime Setting). */
    public function toggle(Request $request): RedirectResponse
    {
        // Runtime pause/resume (honored by LinkCrawlToggle::enabled(), which
        // all jobs + the dispatcher guard on). The env flag stays the master
        // kill switch. On resume, seed the pool immediately (the every-minute
        // dispatcher would otherwise be the first refill).
        $on = $request->boolean('enabled');
        if ($on) {
            LinkCrawlToggle::resume();
            if (config('crawler.link_crawl.enabled')) {
                $target = max(1, (int) config('crawler.link_crawl.target_in_flight', 40));
                for ($i = 0; $i < $target; $i++) {
                    LinkCrawlBatchJob::dispatch();
                }
            }
        } else {
            LinkCrawlToggle::pause();
        }

        return back()->with('status', 'Link crawler '.($on ? 'resumed' : 'paused').'.');
    }

    public function reseed(Request $request): RedirectResponse
    {
        \Illuminate\Support\Facades\Artisan::queue('ebq:seed-link-crawl', ['--force' => true]);

        return back()->with('status', 'Reseed queued — frontier will refill shortly.');
    }

    private function queueSize(string $queue): ?int
    {
        try {
            return Queue::size($queue);
        } catch (\Throwable) {
            return null;
        }
    }
}
