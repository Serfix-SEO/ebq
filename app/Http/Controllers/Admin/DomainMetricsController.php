<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\EnrichTopicalTrustJob;
use App\Models\DomainMetric;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin Domain Intelligence — browse/search/filter/sort the accumulating
 * domain_metrics asset, drill into any domain's full metrics + history +
 * link-graph presence, and run per-domain actions (reclassify topic, refresh
 * from free feeds). Read-heavy; the only writes are queued jobs.
 */
class DomainMetricsController extends Controller
{
    private const SORTABLE = [
        'domain', 'tier', 'times_seen', 'trust_score', 'citation_score', 'spam_score',
        'opr_score', 'cc_harmonic_rank', 'last_seen_at', 'topic',
    ];

    public function index(Request $request): View
    {
        // ── Filters ─────────────────────────────────────────────
        $q = trim((string) $request->query('q', ''));
        $tier = (string) $request->query('tier', '');
        $topic = (string) $request->query('topic', '');
        $has = (string) $request->query('has', ''); // scores | topic | seed | cc
        $sort = in_array($request->query('sort'), self::SORTABLE, true) ? $request->query('sort') : 'times_seen';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $query = DomainMetric::query()
            ->when($q !== '', fn ($b) => $b->where('domain', 'like', '%'.$q.'%'))
            ->when(in_array($tier, ['active', 'free'], true), fn ($b) => $b->where('tier', $tier))
            ->when($topic !== '', fn ($b) => $b->where('topic', $topic))
            ->when($has === 'scores', fn ($b) => $b->whereNotNull('trust_score'))
            ->when($has === 'topic', fn ($b) => $b->whereNotNull('topic'))
            ->when($has === 'seed', fn ($b) => $b->where('is_seed', true))
            ->when($has === 'cc', fn ($b) => $b->whereNotNull('cc_harmonic_rank'));

        // cc_harmonic_rank sorts asc-is-better; keep NULLs last either way.
        if ($sort === 'cc_harmonic_rank') {
            $query->orderByRaw('cc_harmonic_rank IS NULL')->orderBy('cc_harmonic_rank', $dir);
        } else {
            $query->orderByRaw("$sort IS NULL")->orderBy($sort, $dir);
        }

        $domains = $query->paginate(50)->withQueryString();

        // ── Stats header (whole asset) ──────────────────────────
        $stats = [
            'total' => DomainMetric::count(),
            'active' => DomainMetric::where('tier', 'active')->count(),
            'scored' => DomainMetric::whereNotNull('trust_score')->count(),
            'classified' => DomainMetric::whereNotNull('topic')->count(),
            'seeds' => DomainMetric::where('is_seed', true)->count(),
            'cc' => DomainMetric::whereNotNull('cc_harmonic_rank')->count(),
        ];
        $topics = DomainMetric::query()->whereNotNull('topic')
            ->selectRaw('topic, count(*) c')->groupBy('topic')->orderByDesc('c')->pluck('c', 'topic');

        return view('admin.domain-metrics.index', [
            'domains' => $domains,
            'stats' => $stats,
            'topics' => $topics,
            'filters' => compact('q', 'tier', 'topic', 'has', 'sort', 'dir'),
            'sortable' => self::SORTABLE,
        ]);
    }

    public function show(DomainMetric $domainMetric): View
    {
        // History series per source for sparklines.
        $history = $domainMetric->history()
            ->orderBy('captured_on')
            ->get(['source', 'value', 'captured_on'])
            ->groupBy('source')
            ->map(fn ($rows) => $rows->map(fn ($r) => [
                'date' => $r->captured_on->toDateString(),
                'value' => (float) $r->value,
            ])->values());

        // Link-graph presence (both directions).
        $node = DB::table('link_domains')->where('name', $domainMetric->domain)->first();
        $graph = ['inbound' => 0, 'outbound' => 0, 'top_inbound' => collect(), 'top_outbound' => collect()];
        if ($node) {
            $graph['inbound'] = DB::table('link_edges')->where('to_domain_id', $node->id)->count();
            $graph['outbound'] = DB::table('link_edges')->where('from_domain_id', $node->id)->count();
            $graph['top_inbound'] = DB::table('link_edges')
                ->join('link_domains as f', 'f.id', '=', 'link_edges.from_domain_id')
                ->where('link_edges.to_domain_id', $node->id)
                ->orderByDesc('link_edges.first_seen_at')
                ->limit(15)->get(['f.name', 'link_edges.dofollow', 'link_edges.source', 'link_edges.first_seen_at']);
            $graph['top_outbound'] = DB::table('link_edges')
                ->join('link_domains as t', 't.id', '=', 'link_edges.to_domain_id')
                ->where('link_edges.from_domain_id', $node->id)
                ->orderByDesc('link_edges.first_seen_at')
                ->limit(15)->get(['t.name', 'link_edges.dofollow', 'link_edges.source', 'link_edges.first_seen_at']);
        }

        // Does a full backlink report exist for this domain?
        $snapshot = \App\Models\WebsiteReportSnapshot::forDomain($domainMetric->domain);

        return view('admin.domain-metrics.show', [
            'm' => $domainMetric,
            'history' => $history,
            'graph' => $graph,
            'hasReport' => $snapshot !== null && $snapshot->status === 'ready',
        ]);
    }

    /** Re-run topic classification for one domain (clears cache → next report reclassifies). */
    public function reclassify(DomainMetric $domainMetric): RedirectResponse
    {
        $domainMetric->update(['topic' => null, 'topic_classified_at' => null]);
        // If a report exists, kick its topical enrichment so it refills.
        EnrichTopicalTrustJob::dispatch($domainMetric->domain);

        return back()->with('status', "Topic reset for {$domainMetric->domain} — will reclassify on next enrichment.");
    }

    /** Queue a free-feed refresh (OPR + CC) for this one domain. */
    public function refresh(DomainMetric $domainMetric): RedirectResponse
    {
        $domainMetric->update(['opr_refreshed_at' => null]); // makes it stale → next sweep picks it first
        \Illuminate\Support\Facades\Artisan::queue('ebq:refresh-domain-metrics', ['--limit' => 1, '--stale-days' => 0]);

        return back()->with('status', "Refresh queued for {$domainMetric->domain}.");
    }
}
