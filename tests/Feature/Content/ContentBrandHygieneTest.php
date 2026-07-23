<?php

namespace Tests\Feature\Content;

use App\Models\ContentPlan;
use App\Models\KeywordApiRequest;
use App\Models\KeywordMetric;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\CompetitorMentionGuard;
use App\Services\Content\ContentKeywordInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The kayali.com onboarding round (2026-07-23): blocked brands out of the
 * digest, the client's own brand never a competitor/blocked/opportunity,
 * offer-spine candidates surfacing in best terms, and affiliate-shaped
 * queries sinking for brand sites.
 */
class ContentBrandHygieneTest extends TestCase
{
    use RefreshDatabase;

    private function makePlan(array $attrs = []): ContentPlan
    {
        $website = Website::factory()->for(User::factory())->create([
            'domain' => 'kayali.com', 'normalized_domain' => 'kayali.com',
        ]);

        return ContentPlan::factory()->create(array_merge([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_DRAFT,
            'business_description' => 'Luxury gourmand perfumes you can layer.',
            'offerings' => ['sell' => ['Vanilla eau de parfum'], 'dont_sell' => []],
            'site_type' => 'brand',
        ], $attrs));
    }

    private function storeCompletedRequest(ContentPlan $plan, array $results): void
    {
        $request = KeywordApiRequest::query()->create([
            'request_id' => (string) Str::uuid(),
            'type' => KeywordApiRequest::TYPE_IDEAS,
            'mode' => 'keywords',
            'payload' => ['seeds' => ['test']],
            'status' => KeywordApiRequest::STATUS_COMPLETED,
            'result' => ['results' => $results],
            'website_id' => $plan->website_id,
        ]);
        Cache::put('content:kw-insights:req:'.$plan->id, $request->id, now()->addHours(2));
    }

    public function test_blocked_brand_keywords_are_purged_from_the_whole_digest(): void
    {
        $plan = $this->makePlan([
            'toggles' => [CompetitorMentionGuard::TOGGLE => true],
            'competitor_guard' => [
                'assessed_at' => now()->toIso8601String(),
                'auto' => [['brand' => 'sephora', 'domain' => 'sephora.com', 'reason' => 'rival']],
                'references' => [],
            ],
        ]);
        $this->storeCompletedRequest($plan, [
            ['keyword' => 'sephora birthday gift sets', 'avgMonthlySearches' => 9000, 'competitionIndex' => 10],
            ['keyword' => 'sweet vanilla parfum', 'avgMonthlySearches' => 400, 'competitionIndex' => 20],
        ]);

        $kw = app(ContentKeywordInsights::class)->get($plan);

        $all = json_encode($kw);
        $this->assertStringNotContainsString('sephora', $all,
            'a guard-blocked brand must not appear anywhere in the digest');
        $this->assertSame(1, $kw['stats']['keywords']);
    }

    public function test_own_brand_never_becomes_competitor_or_blocked_term(): void
    {
        $guard = app(CompetitorMentionGuard::class);
        $plan = $this->makePlan([
            'toggles' => [CompetitorMentionGuard::TOGGLE => true],
            // Stale assessment from before the own-brand filter existed.
            'competitor_guard' => [
                'assessed_at' => now()->toIso8601String(),
                'auto' => [
                    ['brand' => 'kayali official shop', 'domain' => 'kayaliofficial.shop', 'reason' => 'rival'],
                    ['brand' => 'sephora', 'domain' => 'sephora.com', 'reason' => 'rival'],
                ],
                'references' => [],
            ],
        ]);

        $this->assertSame(['sephora'], $guard->terms($plan),
            "the client's own brand must never be blocked");

        // And a fresh classification never even sees the own-brand domain.
        $plan->update(['competitor_overrides' => ['added' => ['kayaliofficial.shop', 'rival.com'], 'removed' => []]]);
        $guard->assess($plan->fresh()); // no LLM → fail-soft blocks the rest
        $terms = $guard->terms($plan->fresh());
        $this->assertContains('rival', $terms);
        $this->assertNotContains('kayaliofficial', $terms);
        $this->assertNotContains('kayali official shop', $terms);
    }

    public function test_candidates_surface_in_best_terms_with_dfs_priced_volume(): void
    {
        config(['services.mistral.key' => null]); // mechanical candidates
        $plan = $this->makePlan();
        // The DFS candidate enrichment priced this mechanical candidate.
        KeywordMetric::query()->create([
            'keyword' => 'how to choose vanilla eau de parfum',
            'keyword_hash' => KeywordMetric::hashKeyword('how to choose vanilla eau de parfum'),
            'country' => 'global',
            'data_source' => 'dfs_labs',
            'search_volume' => 320,
            'keyword_difficulty' => 12,
            'fetched_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);
        $this->storeCompletedRequest($plan, [
            ['keyword' => 'perfume', 'avgMonthlySearches' => 90000, 'competitionIndex' => 80],
        ]);

        $kw = app(ContentKeywordInsights::class)->get($plan);

        $first = $kw['opportunities'][0];
        $this->assertSame('how to choose vanilla eau de parfum', $first['keyword']);
        $this->assertSame(320, $first['volume']);
        $this->assertSame('Vanilla eau de parfum', $first['origin']);
        $this->assertSame('low', $first['competition']); // difficulty 12 → low
    }

    public function test_own_brand_keywords_stay_in_digest_but_not_in_opportunities(): void
    {
        $plan = $this->makePlan();
        $this->storeCompletedRequest($plan, [
            ['keyword' => 'kayali vanilla perfume', 'avgMonthlySearches' => 8000, 'competitionIndex' => 5],
            ['keyword' => 'sweet vanilla parfum', 'avgMonthlySearches' => 400, 'competitionIndex' => 20],
        ]);

        $kw = app(ContentKeywordInsights::class)->get($plan);

        $this->assertSame(2, $kw['stats']['keywords'], 'own-brand demand stays visible in the digest');
        $oppKeywords = array_column($kw['opportunities'], 'keyword');
        $this->assertNotContains('kayali vanilla perfume', $oppKeywords,
            'people searching the brand already found the site');
        $this->assertContains('sweet vanilla parfum', $oppKeywords);
    }

    public function test_dfs_suggestions_discover_demand_and_join_best_terms_with_lineage(): void
    {
        config([
            'services.mistral.key' => null,
            'services.dataforseo.login' => 'x',
            'services.dataforseo.password' => 'y',
        ]);
        $plan = $this->makePlan();

        \Illuminate\Support\Facades\Http::fake([
            'api.dataforseo.com/*' => \Illuminate\Support\Facades\Http::response([
                'tasks' => [[
                    'cost' => 0.0156,
                    'result' => [['items' => [
                        // Word-order variants of ONE query cluster + a real second term.
                        ['keyword' => 'best vanilla perfume', 'keyword_info' => ['search_volume' => 12100, 'competition' => 0.2], 'keyword_properties' => ['keyword_difficulty' => 0], 'search_intent_info' => ['main_intent' => 'commercial']],
                        ['keyword' => 'best perfume vanilla', 'keyword_info' => ['search_volume' => 12100, 'competition' => 0.2], 'keyword_properties' => ['keyword_difficulty' => 0], 'search_intent_info' => ['main_intent' => 'commercial']],
                        ['keyword' => 'vanilla perfume for women', 'keyword_info' => ['search_volume' => 2400, 'competition' => 0.3], 'keyword_properties' => ['keyword_difficulty' => 0], 'search_intent_info' => ['main_intent' => 'commercial']],
                        ['keyword' => 'obscure vanilla thing', 'keyword_info' => ['search_volume' => 10, 'competition' => 0.1], 'keyword_properties' => [], 'search_intent_info' => ['main_intent' => 'informational']],
                    ]]],
                ]],
            ], 200),
        ]);

        // The suggestions pass runs inside the queued research job's flow.
        app(ContentKeywordInsights::class)->ensureStarted($plan);
        $this->storeCompletedRequest($plan, [
            ['keyword' => 'perfume', 'avgMonthlySearches' => 90000, 'competitionIndex' => 80],
        ]);

        $kw = app(ContentKeywordInsights::class)->get($plan);

        $keywords = array_column($kw['opportunities'], 'keyword');
        $this->assertContains('best vanilla perfume', $keywords);
        // Word-order duplicate collapsed; sub-floor demand dropped.
        $this->assertNotContains('best perfume vanilla', $keywords);
        $this->assertNotContains('obscure vanilla thing', $keywords);

        $first = $kw['opportunities'][array_search('best vanilla perfume', $keywords, true)];
        $this->assertSame(12100, $first['volume']);
        $this->assertSame('Vanilla eau de parfum', $first['origin']);
        $this->assertSame('low', $first['competition']); // competition 0.2 tiers it (KD 0 → null)

        // Metrics persisted into the shared asset for future ranking rounds.
        $this->assertNotNull(KeywordMetric::query()
            ->where('keyword_hash', KeywordMetric::hashKeyword('best vanilla perfume'))->first());
    }

    public function test_affiliate_shaped_queries_sink_for_brand_sites(): void
    {
        $plan = $this->makePlan();
        $this->storeCompletedRequest($plan, [
            ['keyword' => 'perfume reviews', 'avgMonthlySearches' => 20000, 'competitionIndex' => 10],
            ['keyword' => 'sweet vanilla parfum', 'avgMonthlySearches' => 400, 'competitionIndex' => 20],
        ]);

        $kw = app(ContentKeywordInsights::class)->get($plan);

        $keywords = array_column($kw['opportunities'], 'keyword');
        $reviews = array_search('perfume reviews', $keywords, true);
        $vanilla = array_search('sweet vanilla parfum', $keywords, true);
        $this->assertNotFalse($vanilla);
        if ($reviews !== false) {
            $this->assertGreaterThan($vanilla, $reviews,
                'review-site queries must rank below offer-true terms for a brand');
        }
    }
}
