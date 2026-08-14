<?php

namespace App\Services\Admin;

use App\Models\ClientActivity;
use App\Models\ContentArticleFeedback;
use App\Models\ContentIntegration;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\ContentTrackedKeyword;
use App\Models\LifecycleEmailSend;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Content\ContentEntitlements;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Everything the admin client-detail screen (`/admin/clients/{user}`) shows,
 * assembled in one place so the Blade stays dumb and the query cost is
 * visible and bounded.
 *
 * Cost rules, learned from the research-page incident (see
 * `infra/content-autopilot/README.md` § Performance):
 *  - Per-website aggregates run as grouped queries over the client's OWN
 *    website ids — never per-row in the view.
 *  - The only large table touched is `search_console_data` (~2M rows); that
 *    block is cached for 30 minutes per client, so a reload or an F5 storm
 *    on the page can't turn into repeated 0.5s+ GROUP BYs.
 *  - Everything else is small (content_*, client_activities, subscriptions).
 */
class ClientProfileService
{
    /** GSC/GA look-back for the per-website performance columns. */
    private const PERF_DAYS = 28;

    private const GSC_CACHE_TTL = 1800;

    public function __construct(private ContentEntitlements $entitlements) {}

    /**
     * @return array<string, mixed>
     */
    public function profile(User $client): array
    {
        $websites = $client->websites()
            ->orderBy('created_at')
            ->get(['id', 'user_id', 'domain', 'created_at', 'feature_flags', 'gsc_site_url', 'ga_property_id', 'last_search_console_sync_at', 'last_analytics_sync_at']);

        $ids = $websites->pluck('id')->all();

        $plans = $ids === [] ? collect() : ContentPlan::query()->whereIn('website_id', $ids)->get()->keyBy('website_id');
        $integrations = $ids === [] ? collect() : ContentIntegration::query()->whereIn('website_id', $ids)->get()->groupBy('website_id');

        $topicCounts = $this->topicCountsByWebsite($ids);
        $trackedCounts = $this->trackedCountsByWebsite($ids);
        $gsc = $this->gscTotals($client, $ids);
        $ga = $this->gaTotals($ids);
        $lastPublished = $this->lastPublishedByWebsite($ids);
        $covered = $this->entitlements->coveredWebsites($client)->pluck('id')->all();

        $rows = $websites->map(fn ($w) => [
            'id' => $w->id,
            'domain' => $w->domain,
            'created_at' => $w->created_at,
            'covered' => in_array($w->id, $covered, true),
            'plan' => $plans->get($w->id),
            'integrations' => $integrations->get($w->id, collect()),
            'topics' => $topicCounts[$w->id] ?? [],
            'tracked' => $trackedCounts[$w->id] ?? 0,
            'gsc' => $gsc[$w->id] ?? null,
            'ga' => $ga[$w->id] ?? null,
            'gsc_connected' => (bool) $w->gsc_site_url,
            'ga_connected' => (bool) $w->ga_property_id,
            'last_published_at' => $lastPublished[$w->id] ?? null,
        ])->all();

        return [
            'websites' => $rows,
            'content' => $this->contentSummary($ids),
            'keywords' => $this->keywordSummary($ids),
            'spend' => $this->spend($client),
            'billing' => $this->billing($client),
            'support' => $this->support($client),
            'lifecycle' => LifecycleEmailSend::query()
                ->where('user_id', $client->id)
                ->latest('created_at')
                ->limit(10)
                ->get(),
            'activity' => ClientActivity::query()
                ->where('user_id', $client->id)
                ->latest('created_at')
                ->limit(15)
                ->get(),
            'perf_days' => self::PERF_DAYS,
            'totals' => [
                'websites' => count($rows),
                'covered' => count($covered),
                'sites_allowed' => $this->entitlements->sitesAllowed($client),
                'has_content_access' => $this->entitlements->hasContentAccess($client),
            ],
        ];
    }

    /** @return array<string, array<string, int>> website_id => [status => count] */
    private function topicCountsByWebsite(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];
        ContentTopic::query()
            ->whereIn('website_id', $ids)
            ->selectRaw('website_id, status, COUNT(*) AS c')
            ->groupBy('website_id', 'status')
            ->get()
            ->each(function ($row) use (&$out) {
                $out[$row->website_id][$row->status] = (int) $row->c;
            });

        return $out;
    }

    /** @return array<string, int> */
    private function trackedCountsByWebsite(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return ContentTrackedKeyword::query()
            ->whereIn('website_id', $ids)
            ->selectRaw('website_id, COUNT(*) AS c')
            ->groupBy('website_id')
            ->pluck('c', 'website_id')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /** @return array<string, Carbon> */
    private function lastPublishedByWebsite(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return ContentTopic::query()
            ->whereIn('website_id', $ids)
            ->whereNotNull('published_at')
            ->selectRaw('website_id, MAX(published_at) AS last_at')
            ->groupBy('website_id')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->website_id => Carbon::parse($r->last_at)])
            ->all();
    }

    /**
     * Search Console clicks/impressions over the look-back window.
     * `search_console_data` is the one big table on this page — cached.
     *
     * @return array<string, array{clicks:int, impressions:int}>
     */
    private function gscTotals(User $client, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Cache::remember(
            'admin:client-profile:gsc:v1:'.$client->id.':'.Carbon::now()->toDateString(),
            self::GSC_CACHE_TTL,
            fn () => DB::table('search_console_data')
                ->whereIn('website_id', $ids)
                ->where('date', '>=', Carbon::now()->subDays(self::PERF_DAYS)->toDateString())
                ->selectRaw('website_id, SUM(clicks) AS clicks, SUM(impressions) AS impressions')
                ->groupBy('website_id')
                ->get()
                ->mapWithKeys(fn ($r) => [$r->website_id => [
                    'clicks' => (int) $r->clicks,
                    'impressions' => (int) $r->impressions,
                ]])
                ->all()
        );
    }

    /** @return array<string, array{pageviews:int, sessions:int}> */
    private function gaTotals(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('content_page_analytics')
            ->whereIn('website_id', $ids)
            ->where('date', '>=', Carbon::now()->subDays(self::PERF_DAYS)->toDateString())
            ->selectRaw('website_id, SUM(pageviews) AS pageviews, SUM(sessions) AS sessions')
            ->groupBy('website_id')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->website_id => [
                'pageviews' => (int) $r->pageviews,
                'sessions' => (int) $r->sessions,
            ]])
            ->all();
    }

    /** @return array<string, mixed> */
    private function contentSummary(array $ids): array
    {
        if ($ids === []) {
            return ['by_status' => [], 'total' => 0, 'published_30d' => 0, 'published_90d' => 0,
                'avg_score' => null, 'words' => 0, 'feedback' => []];
        }

        $byStatus = ContentTopic::query()
            ->whereIn('website_id', $ids)
            ->selectRaw('status, COUNT(*) AS c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->map(fn ($c) => (int) $c)
            ->all();

        // Article-level stats read the CURRENT version only — storeVersion()
        // moves is_current on save, so older drafts must not skew the average
        // (see the is_current trap in infra/content-autopilot/README.md).
        $articleStats = DB::table('content_articles')
            ->join('content_topics', 'content_topics.id', '=', 'content_articles.topic_id')
            ->whereIn('content_topics.website_id', $ids)
            ->where('content_articles.is_current', true)
            ->selectRaw('AVG(content_articles.seo_score) AS avg_score, SUM(content_articles.word_count) AS words, COUNT(*) AS c')
            ->first();

        $published = fn (int $days) => ContentTopic::query()
            ->whereIn('website_id', $ids)
            ->whereNotNull('published_at')
            ->where('published_at', '>=', Carbon::now()->subDays($days))
            ->count();

        return [
            'by_status' => $byStatus,
            'total' => array_sum($byStatus),
            'articles' => (int) ($articleStats->c ?? 0),
            'published_30d' => $published(30),
            'published_90d' => $published(90),
            'avg_score' => $articleStats?->avg_score !== null ? round((float) $articleStats->avg_score) : null,
            'words' => (int) ($articleStats->words ?? 0),
            'feedback' => ContentArticleFeedback::query()
                ->whereIn('website_id', $ids)
                ->selectRaw('rating, COUNT(*) AS c')
                ->groupBy('rating')
                ->pluck('c', 'rating')
                ->map(fn ($c) => (int) $c)
                ->all(),
            'feedback_recent' => ContentArticleFeedback::query()
                ->whereIn('website_id', $ids)
                ->whereNotNull('comment')
                ->where('comment', '!=', '')
                ->latest('created_at')
                ->limit(5)
                ->get(),
        ];
    }

    /** @return array<string, mixed> */
    private function keywordSummary(array $ids): array
    {
        if ($ids === []) {
            return ['total' => 0, 'buckets' => [], 'best' => collect()];
        }

        $rows = ContentTrackedKeyword::query()
            ->whereIn('website_id', $ids)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN serp_position BETWEEN 1 AND 3 THEN 1 ELSE 0 END) AS top3')
            ->selectRaw('SUM(CASE WHEN serp_position BETWEEN 4 AND 10 THEN 1 ELSE 0 END) AS top10')
            ->selectRaw('SUM(CASE WHEN serp_position BETWEEN 11 AND 20 THEN 1 ELSE 0 END) AS top20')
            ->selectRaw('SUM(CASE WHEN serp_position > 20 THEN 1 ELSE 0 END) AS rest')
            ->selectRaw('SUM(CASE WHEN serp_position IS NULL THEN 1 ELSE 0 END) AS unranked')
            ->first();

        return [
            'total' => (int) ($rows->total ?? 0),
            'buckets' => [
                'Top 3' => (int) ($rows->top3 ?? 0),
                '4–10' => (int) ($rows->top10 ?? 0),
                '11–20' => (int) ($rows->top20 ?? 0),
                '21+' => (int) ($rows->rest ?? 0),
                'Not ranked' => (int) ($rows->unranked ?? 0),
            ],
            'best' => ContentTrackedKeyword::query()
                ->whereIn('website_id', $ids)
                ->whereNotNull('serp_position')
                ->orderBy('serp_position')
                ->limit(10)
                ->get(['id', 'website_id', 'keyword', 'serp_position', 'serp_checked_at', 'page_url']),
        ];
    }

    /**
     * API spend. Units are provider-specific; the USD rates live in
     * config/services.php and are the same ones the Clients list uses.
     *
     * @return array<string, mixed>
     */
    private function spend(User $client): array
    {
        $rates = [
            'keywords_everywhere' => (float) config('services.keywords_everywhere.cost_per_keyword_usd', 0.0001),
            'serp_api' => (float) config('services.serper.cost_per_call_usd', 0.0003),
        ];

        $byProvider = fn (?Carbon $since) => ClientActivity::query()
            ->where('user_id', $client->id)
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->selectRaw('provider, SUM(units_consumed) AS units, COUNT(*) AS calls')
            ->groupBy('provider')
            ->get()
            ->map(fn ($r) => [
                'provider' => (string) $r->provider,
                'units' => (int) $r->units,
                'calls' => (int) $r->calls,
                'usd' => round((int) $r->units * ($rates[(string) $r->provider] ?? 0.0), 4),
            ])
            ->sortByDesc('usd')
            ->values();

        // 6-month spend trend, oldest first, zero-filled so the bar chart has
        // a bar for every month rather than a ragged axis.
        $since = Carbon::now()->startOfMonth()->subMonths(5);
        // Month bucketing is driver-specific: MySQL runs prod, sqlite runs the
        // test suite, and DATE_FORMAT() doesn't exist there.
        $ym = ClientActivity::query()->getConnection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "DATE_FORMAT(created_at, '%Y-%m')";
        $raw = ClientActivity::query()
            ->where('user_id', $client->id)
            ->where('created_at', '>=', $since)
            ->selectRaw("{$ym} AS ym, provider, SUM(units_consumed) AS units")
            ->groupBy('ym', 'provider')
            ->get();

        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $key = Carbon::now()->startOfMonth()->subMonths($i)->format('Y-m');
            $months[$key] = 0.0;
        }
        foreach ($raw as $r) {
            if (array_key_exists($r->ym, $months)) {
                $months[$r->ym] += (int) $r->units * ($rates[(string) $r->provider] ?? 0.0);
            }
        }

        return [
            'rates' => $rates,
            'mtd' => $byProvider(Carbon::now()->startOfMonth()),
            'lifetime' => $byProvider(null),
            'months' => $months,
        ];
    }

    /** @return array<string, mixed> */
    private function billing(User $client): array
    {
        return [
            'subscriptions' => $client->subscriptions()->latest('created_at')->get(),
            'has_card' => (bool) $client->pm_type,
            'card' => $client->pm_type ? strtoupper((string) $client->pm_type).' •••• '.$client->pm_last_four : null,
            'stripe_id' => $client->stripe_id,
            'content_access' => $this->entitlements->hasContentAccess($client),
            'content_subscription' => $this->entitlements->hasContentSubscription($client),
            'content_trial' => $this->entitlements->onContentTrial($client),
            'comp_sites' => (int) ($client->content_comp_sites ?? 0),
            'comp_until' => $client->content_comp_until,
            'addon_quantity' => $this->entitlements->addonQuantity($client),
        ];
    }

    /** @return array<string, mixed> */
    private function support(User $client): array
    {
        $tickets = SupportTicket::query()
            ->where('user_id', $client->id)
            ->withCount('messages')
            ->latest('last_reply_at')
            ->limit(10)
            ->get();

        return [
            'tickets' => $tickets,
            'open' => SupportTicket::query()
                ->where('user_id', $client->id)
                ->whereIn('status', [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_ANSWERED])
                ->count(),
            'total' => SupportTicket::query()->where('user_id', $client->id)->count(),
        ];
    }
}
