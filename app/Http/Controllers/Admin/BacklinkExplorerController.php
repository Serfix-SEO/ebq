<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\OpenPageRankClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin Backlink Explorer — search every backlink we've EVER stored for a
 * domain, purely from the permanent link_edges graph. That store already
 * unifies both sources:
 *   - crawler-discovered links (source own_crawl / enrichment), and
 *   - previously-processed DataForSEO backlink rows (source provider).
 * So this page makes ZERO new provider calls — it's all local reads.
 */
class BacklinkExplorerController extends Controller
{
    private const SOURCES = ['own_crawl', 'enrichment', 'provider', 'cc_wat'];

    public function index(Request $request): View
    {
        [$filters, $base, $node] = $this->resolve($request);

        $stats = null;
        $rows = null;
        if ($node !== null) {
            $stats = [
                'edges' => (clone $base)->count(),
                'domains' => (clone $base)->distinct($filters['direction'] === 'inbound' ? 'from_domain_id' : 'to_domain_id')
                    ->count($filters['direction'] === 'inbound' ? 'from_domain_id' : 'to_domain_id'),
                'dofollow' => (clone $base)->where('dofollow', true)->count(),
                'by_source' => (clone $base)->selectRaw('source, count(*) c')->groupBy('source')->pluck('c', 'source'),
            ];
            $rows = $this->select($base, $filters)->paginate(60)->withQueryString();
        }

        return view('admin.backlink-explorer.index', [
            'filters' => $filters,
            'sources' => self::SOURCES,
            'node' => $node,
            'stats' => $stats,
            'rows' => $rows,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        [$filters, $base, $node] = $this->resolve($request);
        abort_if($node === null, 404);

        $direction = $filters['direction'];
        $filename = 'backlinks-'.$filters['domain'].'-'.$direction.'-'.now()->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($base, $filters, $direction) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [$direction === 'inbound' ? 'from_domain' : 'to_domain', 'from_url', 'dofollow', 'anchor_class', 'source', 'first_seen', 'last_seen']);
            $this->select($base, $filters)->chunk(2000, function ($chunk) use ($out) {
                foreach ($chunk as $r) {
                    fputcsv($out, [$r->other_domain, $r->from_path ?? '', $r->dofollow ? 'dofollow' : 'nofollow',
                        $r->anchor_class, $r->source, $r->first_seen_at, $r->last_seen_at]);
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Resolve filters + the base filtered query + the target link_domains node
     * (null when the domain isn't in the graph). Shared by index + export.
     *
     * @return array{0: array<string,mixed>, 1: \Illuminate\Database\Query\Builder, 2: ?object}
     */
    private function resolve(Request $request): array
    {
        $raw = trim((string) $request->query('domain', ''));
        $domain = $raw === '' ? '' : OpenPageRankClient::registrable(strtolower($raw));
        $direction = $request->query('direction') === 'outbound' ? 'outbound' : 'inbound';
        $source = in_array($request->query('source'), self::SOURCES, true) ? $request->query('source') : 'all';
        $dofollow = $request->query('dofollow') === '1';
        $anchor = in_array($request->query('anchor'), ['naked', 'generic', 'text', 'empty'], true) ? $request->query('anchor') : '';

        $filters = compact('domain', 'direction', 'source', 'dofollow', 'anchor') + ['domain_raw' => $raw];

        $node = $domain === '' ? null : DB::table('link_domains')->where('name', $domain)->first();

        // Base filtered edge query (no select yet — reused by count + list + export).
        $base = DB::table('link_edges');
        if ($node !== null) {
            $base->where($direction === 'inbound' ? 'to_domain_id' : 'from_domain_id', $node->id);
        } else {
            $base->whereRaw('1 = 0'); // no domain → empty
        }
        if ($source !== 'all') {
            $base->where('source', $source);
        }
        if ($dofollow) {
            $base->where('dofollow', true);
        }
        if ($anchor !== '') {
            $base->where('anchor_class', $anchor);
        }

        return [$filters, $base, $node];
    }

    /** Add joins + select + ordering to a base edge query. */
    private function select(\Illuminate\Database\Query\Builder $base, array $filters): \Illuminate\Database\Query\Builder
    {
        // "other" = the domain at the far end of the edge from the searched one.
        $otherCol = $filters['direction'] === 'inbound' ? 'from_domain_id' : 'to_domain_id';

        return $base
            ->join('link_domains as o', 'o.id', '=', 'link_edges.'.$otherCol)
            ->leftJoin('link_urls as fu', 'fu.id', '=', 'link_edges.from_url_id')
            ->orderByDesc('link_edges.first_seen_at')
            ->select([
                'o.name as other_domain',
                'fu.path as from_path',
                'link_edges.dofollow',
                'link_edges.anchor_class',
                'link_edges.source',
                'link_edges.first_seen_at',
                'link_edges.last_seen_at',
            ]);
    }
}
