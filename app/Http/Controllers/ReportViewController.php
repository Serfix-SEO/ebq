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
     * Lightweight polling endpoint for the pending/progress UIs — lets the
     * page poll a few bytes of JSON instead of reloading itself. `status` is
     * the snapshot state; `topical` reports the async topical-relevance
     * enrichment (none | pending | ready). READ-ONLY: never dispatches
     * generation (that stays with the full page request).
     */
    public function status(Request $request): \Illuminate\Http\JsonResponse
    {
        $domain = WebsiteReportSnapshot::normalizeDomain((string) $request->query('url', ''));
        if ($domain === '') {
            return response()->json(['status' => 'unknown', 'topical' => 'none']);
        }

        $snapshot = WebsiteReportSnapshot::forDomain($domain);
        $payload = $snapshot?->payload ?? [];
        $tt = $payload['topical_trust'] ?? null;

        $trust = $payload['scores']['trust'] ?? null;
        $relevant = $tt['relevant_pct'] ?? null;

        return response()->json([
            'status' => $snapshot->status ?? 'missing',
            'topical' => ! empty($tt['topics']) ? 'ready' : (! empty($tt['pending']) ? 'pending' : 'none'),
            // Batched classification: sample grows toward total as the job
            // chain works through all referring domains. The extra fields let
            // the topical card update ITSELF in place — no page reloads.
            'topical_done' => (int) ($tt['sample'] ?? 0),
            'topical_total' => (int) ($tt['total'] ?? ($tt['sample'] ?? 0)),
            'topical_relevant_pct' => is_numeric($relevant) ? (int) $relevant : null,
            'topical_target' => is_string($tt['target_topic'] ?? null) ? $tt['target_topic'] : null,
            'topical_topics' => array_slice(array_values(array_filter((array) ($tt['topics'] ?? []), 'is_array')), 0, 8),
            // Live TopicSignal (mirrors AuthorityScoreCalculator::withTopicalScore).
            'topical_score' => (is_numeric($trust) && is_numeric($relevant) && (int) ($tt['sample'] ?? 0) > 0)
                ? (int) round(min(100, max(0, $trust * (0.4 + 0.6 * $relevant / 100))))
                : null,
        ]);
    }

    /**
     * On-demand anchor drill-down: fetch the actual links carrying one
     * specific anchor when they aren't in the stored top-1000 sample (huge
     * profiles — spam anchors live far below the rank cap). One tiny
     * filtered DataForSEO call, cached 7 days per (domain, anchor); admins
     * hit the free sandbox host like the rest of their report flow.
     */
    public function anchorLinks(Request $request, \App\Services\DataForSeoBacklinkClient $dfs): \Illuminate\Http\JsonResponse
    {
        $domain = WebsiteReportSnapshot::normalizeDomain((string) $request->query('url', ''));
        $anchor = trim((string) $request->query('anchor', ''));
        abort_if($domain === '' || $anchor === '' || mb_strlen($anchor) > 300, 422);

        // Plan gate: each drill-down is a paid index call — plans can switch
        // it off (api_limits.report.allow_link_drilldown = 0; trial default).
        if ($request->user()?->effectivePlan()?->apiLimit('report.allow_link_drilldown') === 0) {
            return response()->json([
                'message' => __('Anchor drill-down is not included in your plan. Upgrade to fetch links from the live index.'),
            ], 403);
        }

        $sandbox = (bool) $request->user()?->is_admin;
        $snapshot = WebsiteReportSnapshot::forDomain($domain, $sandbox);
        abort_if($snapshot === null || $snapshot->status !== 'ready', 404);

        $rows = \Illuminate\Support\Facades\Cache::remember(
            'anchor-links:'.($sandbox ? 'sbx:' : '').md5($domain.'|'.$anchor),
            now()->addDays(7),
            function () use ($dfs, $domain, $anchor, $sandbox) {
                $dfs->useSandbox($sandbox);
                $items = $dfs->backlinksForAnchor($domain, $anchor);

                // Count the real billed cost toward the monthly circuit-breaker.
                // Drill-downs stay ALLOWED over cap (plan-gated, ~$0.03, only on
                // already-generated reports) but their spend must be visible.
                if (! $sandbox) {
                    app(\App\Services\Reports\DataForSeoSpendMeter::class)->add($dfs->totalCost());
                }

                return array_map(fn ($r) => [
                    'url_from' => (string) ($r['url_from'] ?? ''),
                    'url_to' => (string) ($r['url_to'] ?? ''),
                    'anchor' => (string) ($r['anchor'] ?? ''),
                    'dofollow' => (bool) ($r['dofollow'] ?? false),
                    'rank' => is_numeric($r['domain_from_rank'] ?? null) ? (int) $r['domain_from_rank'] : null,
                ], array_slice($items, 0, 100));
            },
        );

        // Persist into the snapshot's backlinks list so these rows become
        // first-class table rows (Trust/Citation pills + toxicity badges via
        // the read-time scorers) for every future viewer — the drill-down is
        // paid for once, then it's just data. Bounded: dedup by URL pair,
        // max +500 fetched rows per snapshot; lost on regeneration (fine —
        // the anchor stays clickable).
        $this->appendFetchedBacklinks($snapshot, $rows);

        // …and into the PERMANENT link graph, which regenerations never touch.
        if (! $sandbox) {
            app(\App\Services\LinkGraph\EdgeRecorder::class)->recordInbound($domain, $rows);
        }

        return response()->json(['anchor' => $anchor, 'rows' => $rows]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function appendFetchedBacklinks(WebsiteReportSnapshot $snapshot, array $rows): void
    {
        $snapshot = $snapshot->fresh();
        if ($snapshot === null || $snapshot->status !== 'ready' || empty($snapshot->payload) || $rows === []) {
            return;
        }
        $payload = $snapshot->payload;
        $existing = [];
        $fetched = 0;
        foreach ((array) ($payload['backlinks'] ?? []) as $b) {
            $existing[($b['url_from'] ?? '').'|'.($b['url_to'] ?? '')] = true;
            $fetched += ! empty($b['via']) ? 1 : 0;
        }

        $added = 0;
        foreach ($rows as $r) {
            $key = $r['url_from'].'|'.$r['url_to'];
            if ($r['url_from'] === '' || isset($existing[$key]) || $fetched + $added >= 500) {
                continue;
            }
            $payload['backlinks'][] = [
                'url_from' => $r['url_from'],
                'url_to' => $r['url_to'],
                'anchor' => $r['anchor'],
                'dofollow' => $r['dofollow'],
                'rank' => $r['rank'],
                'opr_score' => null,
                'via' => 'anchor_fetch',
            ];
            $existing[$key] = true;
            $added++;
        }
        if ($added > 0) {
            // Drop the stamped scores so the next read fully recomputes —
            // otherwise the version gate would skip the new rows and they'd
            // never get their Trust/Citation pills or toxicity badges.
            unset($payload['scores']);
            WebsiteReportSnapshot::query()
                ->where('id', $snapshot->id)
                ->where('status', 'ready')
                ->update(['payload' => json_encode($payload)]);
        }
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

        // Enrichment in flight (young site, no backlink-index data yet) —
        // keep the self-refreshing pending page up with adjusted copy; the
        // partial report lands within minutes.
        if ($snapshot !== null && $snapshot->status === 'enriching' && empty($snapshot->payload)) {
            return [
                'pending' => true,
                'enriching' => true,
                'domain' => $domain,
                'branding' => $branding,
                'website' => $website,
            ];
        }

        // A generation that found no provider data AND wasn't eligible for
        // enrichment (sandbox / kill switch) → stop the pending loop.
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

        $service = app(ClientReportService::class);

        // Self-heal stale-schema snapshots: a report generated before a schema
        // upgrade is served AS-IS here (fresh snapshots aren't re-fetched), so
        // it would never gain new sections until its TTL lapsed. Kick ONE
        // background regeneration (job dedups + rewrites the current schema, so
        // it bills at most once per domain) and keep rendering the current
        // payload — the new sections land on the next view.
        if (! $service->isPayloadCurrent($snapshot->payload)
            && app(DataForSeoBacklinkClient::class)->isConfigured()) {
            GenerateWebsiteReport::dispatch($domain, true, $sandbox);
        }

        $payload = $service->withTraffic($snapshot->payload, $website);

        // Monthly backlink-row quota: decides how many rows this viewer sees
        // and charges them once per (user, domain, window). Public shares
        // never pass through resolve(), so they stay uncapped.
        $payload = app(\App\Services\Reports\BacklinkRowQuota::class)->apply($user, $payload);

        return [
            'pending' => false,
            'partial' => (bool) ($payload['meta']['partial'] ?? false),
            'domain' => $domain,
            'payload' => $payload,
            'branding' => $branding,
            'website' => $website,
            'generatedAt' => optional($snapshot->fetched_at)->format('M j, Y'),
        ];
    }
}
