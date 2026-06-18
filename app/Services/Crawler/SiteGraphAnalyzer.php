<?php

namespace App\Services\Crawler;

use App\Models\CrawlSite;
use App\Models\WebsitePage;
use App\Support\Crawler\CrawlValueRank;
use Illuminate\Support\Facades\DB;

/**
 * Derives the internal-link graph metrics for a shared crawl_site from the
 * discovered edges: inbound-link counts (→ orphan detection), click-depth from
 * the homepage via in-memory BFS (O(V+E), no SQL recursion), and the persisted
 * value_rank used for per-user cap windows.
 *
 * Scale note (2026-06-18): a single finalize must handle sites with ~1.5M edges
 * over ~168k pages (xplate). To stay inside the finalize budget the graph is built
 * ONCE into an INTEGER-INDEXED adjacency list — ULID strings (26 bytes each) as
 * array keys/values blew memory and slowed every hash — the BFS uses a pointer
 * queue (array_shift is O(n²)), and every write goes out in bounded id-keyset
 * chunks (a table-wide UPDATE trips innodb_lock_wait_timeout / 1205 while the crawl
 * is still writing the same rows). See infra/crawler/known-issues.md.
 */
class SiteGraphAnalyzer
{
    public function analyze(CrawlSite $crawlSite): void
    {
        $crawlSiteId = $crawlSite->id;

        // One pass over the edges → inbound counts + integer-indexed adjacency.
        $graph = $this->buildGraph($crawlSiteId);

        $this->writeInboundCounts($crawlSiteId, $graph);
        $this->writeClickDepth($crawlSite, $graph);
        // Persist the value ordering so reads can window pages by value_rank <= cap.
        CrawlValueRank::assign($crawlSiteId);
    }

    /**
     * Build an integer-indexed view of the link graph in a single streamed pass.
     * Indices (not ULIDs) keep the adjacency list small enough to fit in RAM on
     * large sites and make the BFS cache-friendly.
     *
     * @return array{ids:list<string>, inbound:list<int>, adj:array<int,list<int>>, index:array<string,int>}
     */
    private function buildGraph(string $crawlSiteId): array
    {
        // page id (ULID) ↔ dense integer index over the site's LIVE pages.
        $ids = [];
        $index = [];
        foreach (
            DB::table('website_pages')
                ->where('crawl_site_id', $crawlSiteId)
                ->whereNull('removed_at')
                ->select('id')
                ->orderBy('id')
                ->lazyById(5000, 'id') as $p
        ) {
            $index[$p->id] = count($ids);
            $ids[] = $p->id;
        }

        $inbound = array_fill(0, count($ids), 0);
        $adj = [];
        foreach (
            DB::table('website_internal_links')
                ->where('crawl_site_id', $crawlSiteId)
                ->where('status', 'discovered')
                ->select('id', 'from_page_id', 'to_page_id')
                ->lazyById(5000, 'id') as $e
        ) {
            $to = $index[$e->to_page_id] ?? null;
            if ($to === null) {
                continue; // edge points at a removed/unknown page — ignore
            }
            $inbound[$to]++;
            $from = $index[$e->from_page_id] ?? null;
            if ($from !== null) {
                $adj[$from][] = $to;
            }
        }

        return ['ids' => $ids, 'inbound' => $inbound, 'adj' => $adj, 'index' => $index];
    }

    /**
     * @param  array{ids:list<string>, inbound:list<int>, adj:array<int,list<int>>, index:array<string,int>}  $graph
     */
    private function writeInboundCounts(string $crawlSiteId, array $graph): void
    {
        $byPage = [];
        foreach ($graph['inbound'] as $i => $count) {
            if ($count > 0) {
                $byPage[$graph['ids'][$i]] = $count;
            }
        }
        // Reset every page to 0, then write the non-zero counts grouped by value.
        $this->resetColumnChunked($crawlSiteId, 'inbound_link_count', 0);
        $this->writeGroupedChunked($crawlSiteId, 'inbound_link_count', $byPage);
    }

    /**
     * Click-depth = shortest hop count from the homepage via BFS over the in-memory
     * adjacency. Unreachable pages stay null. Depth 0 (homepage) is a real value.
     *
     * @param  array{ids:list<string>, inbound:list<int>, adj:array<int,list<int>>, index:array<string,int>}  $graph
     */
    private function writeClickDepth(CrawlSite $crawlSite, array $graph): void
    {
        $depthByPage = [];

        $startId = $this->homepageId($crawlSite);
        $start = $startId !== null ? ($graph['index'][$startId] ?? null) : null;
        if ($start !== null) {
            $depth = [$start => 0];
            // Pointer-based queue: array_shift would make the BFS O(n²) on 168k nodes.
            $queue = [$start];
            for ($head = 0; $head < count($queue); $head++) {
                $node = $queue[$head];
                $d = $depth[$node] + 1;
                foreach ($graph['adj'][$node] ?? [] as $next) {
                    if (! isset($depth[$next])) { // values are ints ≥ 0, never null → isset is safe
                        $depth[$next] = $d;
                        $queue[] = $next;
                    }
                }
            }
            foreach ($depth as $i => $d) {
                $depthByPage[$graph['ids'][$i]] = $d;
            }
        }

        $this->resetColumnChunked($crawlSite->id, 'click_depth', null);
        $this->writeGroupedChunked($crawlSite->id, 'click_depth', $depthByPage);
    }

    /**
     * Set $column = $value for every page of the site in bounded id-keyset chunks so
     * each UPDATE locks at most ~2000 rows (a table-wide UPDATE trips 1205 mid-crawl).
     */
    private function resetColumnChunked(string $crawlSiteId, string $column, mixed $value): void
    {
        DB::table('website_pages')
            ->where('crawl_site_id', $crawlSiteId)
            ->select('id')
            ->orderBy('id')
            ->chunkById(2000, function ($rows) use ($column, $value): void {
                $ids = array_map(static fn ($r) => $r->id, $rows->all());
                if ($ids !== []) {
                    DB::table('website_pages')->whereIn('id', $ids)->update([$column => $value]);
                }
            }, 'id');
    }

    /**
     * Write $column from a [pageId => value] map, grouped by value and chunked to
     * 1000 ids per UPDATE (bounded lock scope). Pages absent from the map are left as
     * the caller reset them.
     *
     * @param  array<string,int>  $valueByPage
     */
    private function writeGroupedChunked(string $crawlSiteId, string $column, array $valueByPage): void
    {
        $idsByValue = [];
        foreach ($valueByPage as $id => $v) {
            $idsByValue[$v][] = $id;
        }
        foreach ($idsByValue as $v => $ids) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                WebsitePage::where('crawl_site_id', $crawlSiteId)
                    ->whereIn('id', $chunk)
                    ->update([$column => $v]);
            }
        }
    }

    private function homepageId(CrawlSite $crawlSite): ?string
    {
        $rootHash = WebsitePage::hashUrl($crawlSite->homepageUrl());
        $id = WebsitePage::where('crawl_site_id', $crawlSite->id)->where('url_hash', $rootHash)->value('id');
        if ($id) {
            return $id;
        }

        // Fallback: the indexable page with the shortest URL (closest to root).
        $page = WebsitePage::where('crawl_site_id', $crawlSite->id)
            ->whereNull('removed_at')
            ->orderByRaw('LENGTH(url) asc')
            ->orderBy('id')
            ->first();

        return $page?->id;
    }
}
