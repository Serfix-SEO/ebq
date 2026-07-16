<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientActivity;
use App\Models\User;
use App\Models\WebsiteReportSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Admin "Site Explorer usage" page — who is querying which domains, when,
 * and whether each query hit the shared cache or triggered a fresh (billed)
 * DataForSEO/Moz generation. Sourced from `client_activities` rows of two
 * types, both written by app code, never guessed:
 *   - `site_explorer.query`      — every authed Analyze (WebsiteAnalyzeController)
 *   - `site_explorer.generation` — every ACTUAL DataForSEO generation
 *     (GenerateWebsiteReport), carrying the REAL cost DataForSEO's own
 *     response reported (`tasks[0].cost`) — not an estimate.
 *
 * This is a separate page from admin.usage (KE/Serper/LLM credits) because
 * Site Explorer isn't metered per-unit — cost is per-generation (bounded
 * top-N pulls keep it roughly flat regardless of site size), not a
 * per-row/per-token rate.
 */
class SiteExplorerUsageController extends Controller
{
    public function index(Request $request): View
    {
        $preset = $request->query('range', '30');
        if (in_array($preset, ['7', '30', '90'], true)) {
            $endDate = Carbon::now();
            $startDate = $endDate->copy()->subDays((int) $preset)->startOfDay();
        } else {
            $startDate = $this->parseDate($request->query('from')) ?? Carbon::now()->subDays(30)->startOfDay();
            $endDate = $this->parseDate($request->query('to')) ?? Carbon::now();
            $preset = 'custom';
        }
        if ($endDate->lt($startDate)) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $userFilter = (string) $request->query('user_id', '');
        $domainFilter = trim((string) $request->query('domain', ''));
        $cacheFilter = (string) $request->query('cache', ''); // '', 'fresh', 'cached'

        $base = ClientActivity::query()
            ->where('type', 'site_explorer.query')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->when($userFilter !== '', fn ($q) => $q->where('user_id', $userFilter))
            ->when($domainFilter !== '', fn ($q) => $q->where('meta->domain', 'like', '%'.$domainFilter.'%'))
            ->when($cacheFilter === 'fresh', fn ($q) => $q->where('meta->cache_hit', false))
            ->when($cacheFilter === 'cached', fn ($q) => $q->where('meta->cache_hit', true));

        // Pull the filtered period's rows once (site-explorer volume is a
        // small fraction of KE/SERP call volume) and derive both the
        // per-client rollup and the summary cards from it in PHP — avoids
        // JSON-path GROUP BY, which isn't portable across MySQL/sqlite
        // (tests run on sqlite :memory:, see CLAUDE.md).
        $rows = (clone $base)->select(['id', 'user_id', 'meta', 'created_at'])->get();

        // Real-cost ledger — one row per ACTUAL DataForSEO generation
        // (GenerateWebsiteReport), independent of the date-range/user/cache
        // filters above (a generation isn't user-attributed — see below —
        // so it's not meaningful to filter it by user_id). This can differ
        // slightly from the query-level "fresh" count: job dedup
        // (ShouldBeUnique) collapses near-simultaneous fresh lookups of the
        // same domain into ONE real generation.
        $generationRows = ClientActivity::query()
            ->where('type', 'site_explorer.generation')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->when($domainFilter !== '', fn ($q) => $q->where('meta->domain', 'like', '%'.$domainFilter.'%'))
            ->get(['meta']);
        $realCost = $generationRows->sum(fn ($r) => (float) ($r->meta['cost_usd'] ?? 0));

        $summary = [
            'total' => $rows->count(),
            'unique_domains' => $rows->pluck('meta.domain')->filter()->unique()->count(),
            'unique_clients' => $rows->pluck('user_id')->filter()->unique()->count(),
            'fresh' => $rows->filter(fn ($r) => empty($r->meta['cache_hit']))->count(),
            'real_generations' => $generationRows->count(),
            'real_cost' => $realCost,
        ];
        $summary['cached'] = $summary['total'] - $summary['fresh'];

        // Per-client rollup's cost column: the REAL cost of each client's
        // distinct fresh-triggering domains, from that domain's snapshot
        // (its latest generation — see WebsiteReportSnapshot::$dataforseo_cost_usd).
        // An approximation when a domain regenerated more than once in the
        // period (the snapshot only holds the LATEST cost), but always a
        // REAL captured number, never a guess.
        $byClient = [];
        foreach ($rows as $row) {
            $uid = (string) $row->user_id;
            if ($uid === '') {
                continue;
            }
            $byClient[$uid] ??= ['user_id' => $uid, 'total' => 0, 'fresh' => 0, 'domains' => [], 'last_at' => null];
            $byClient[$uid]['total']++;
            $isFresh = empty($row->meta['cache_hit']);
            if ($isFresh) {
                $byClient[$uid]['fresh']++;
            }
            $domain = (string) ($row->meta['domain'] ?? '');
            if ($domain !== '') {
                $byClient[$uid]['domains'][$domain] = true;
            }
            if ($byClient[$uid]['last_at'] === null || $row->created_at->gt($byClient[$uid]['last_at'])) {
                $byClient[$uid]['last_at'] = $row->created_at;
            }
        }
        $costByDomain = $this->realCostByDomain($rows->pluck('meta.domain')->filter()->unique()->all());
        foreach ($byClient as &$c) {
            $c['unique_domains'] = count($c['domains']);
            $c['real_cost'] = array_sum(array_map(fn ($d) => $costByDomain[$d] ?? 0.0, array_keys($c['domains'])));
            unset($c['domains']);
        }
        unset($c);
        usort($byClient, fn ($a, $b) => $b['total'] <=> $a['total']);
        $byClient = array_slice($byClient, 0, 50);

        $clientIds = array_column($byClient, 'user_id');
        $clients = $clientIds
            ? User::query()->whereIn('id', $clientIds)->get(['id', 'name', 'email'])->keyBy('id')
            : collect();

        // Paginated raw query log — the actual "query details" list.
        $queries = (clone $base)
            ->with('user:id,name,email')
            ->latest('id')
            ->paginate(30)
            ->withQueryString();
        $queryCostByDomain = $this->realCostByDomain(
            collect($queries->items())->pluck('meta.domain')->filter()->unique()->all()
        );

        return view('admin.site-explorer-usage.index', [
            'preset' => $preset,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'summary' => $summary,
            'byClient' => $byClient,
            'clients' => $clients,
            'queries' => $queries,
            'costByDomain' => $queryCostByDomain,
            'users' => User::query()->select('id', 'name', 'email')->orderBy('name')->limit(200)->get(),
            'filters' => [
                'user_id' => $userFilter,
                'domain' => $domainFilter,
                'cache' => $cacheFilter,
                'from' => $startDate->toDateString(),
                'to' => $endDate->toDateString(),
            ],
        ]);
    }

    /**
     * Admin action: wipe a domain's cached report so the next lookup runs a
     * fresh generation (and, for empty domains, the full enrichment pipeline).
     * Deletes BOTH the production and sandbox snapshot rows plus the shared
     * monthly keyword-ideas cache entry the enrichment would otherwise reuse.
     * Deliberately does NOT touch client_activities — the usage/cost ledger
     * must stay historically accurate.
     */
    public function clearCache(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate(['domain' => ['required', 'string', 'max:255']]);

        $normalized = WebsiteReportSnapshot::normalizeDomain($validated['domain']);
        if ($normalized === '') {
            return back()->with('cache_clear_error', 'Enter a valid domain.');
        }

        $deleted = WebsiteReportSnapshot::query()
            ->whereIn('normalized_domain', [$normalized, 'sbx:'.$normalized])
            ->delete();

        // Shared calendar-month keyword-ideas cache (site scope, the exact key
        // ReportEnrichmentService computes) — cleared so re-testing a new
        // domain exercises the full pipeline, not a cached keyword list.
        [$mode, $payload] = app(\App\Services\KeywordFinder\KeywordFinderPool::class)
            ->buildIdeasPayload(['url' => 'https://'.$normalized, 'scope' => 'site'], 'us');
        \Illuminate\Support\Facades\Cache::forget(
            \App\Services\KeywordFinder\KeywordIdeasMonthlyCache::key($mode, $payload)
        );

        app(\App\Services\ClientActivityLogger::class)->log('site_explorer.cache_cleared', userId: $request->user()?->id, meta: [
            'domain' => $normalized,
            'snapshots_deleted' => $deleted,
        ]);

        return back()->with('cache_cleared', $deleted > 0
            ? "Report cache for {$normalized} removed ({$deleted} snapshot".($deleted === 1 ? '' : 's').") — next lookup regenerates fresh."
            : "No cached report existed for {$normalized} — nothing to remove.");
    }

    /**
     * Real DataForSEO cost of each domain's LATEST generation, from its
     * shared snapshot — sandbox rows never carry a cost (never billed).
     *
     * @param  array<int, string>  $domains
     * @return array<string, float>
     */
    private function realCostByDomain(array $domains): array
    {
        if ($domains === []) {
            return [];
        }

        return WebsiteReportSnapshot::query()
            ->whereIn('normalized_domain', $domains) // production (non-sandbox) keys equal the bare domain
            ->whereNotNull('dataforseo_cost_usd')
            ->pluck('dataforseo_cost_usd', 'normalized_domain')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    private function parseDate(?string $s): ?Carbon
    {
        if (! $s || trim($s) === '') {
            return null;
        }
        try {
            return Carbon::parse($s);
        } catch (\Throwable) {
            return null;
        }
    }
}
