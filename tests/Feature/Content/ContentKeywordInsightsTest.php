<?php

namespace Tests\Feature\Content;

use App\Jobs\PrepareContentKeywordInsightsJob;
use App\Livewire\Content\ContentCalendar;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\KeywordApiRequest;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentKeywordInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ContentKeywordInsightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
    }

    private function userWithPlan(array $planAttrs = []): array
    {
        $user = User::factory()->create([
            'content_trial_started_at' => now(), 'content_trial_ends_at' => now()->addDays(5),
        ]);
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create(array_merge([
            'website_id' => $website->id, 'status' => ContentPlan::STATUS_DRAFT,
        ], $planAttrs));

        return [$user, $website, $plan];
    }

    private function storeCompletedRequest(ContentPlan $plan, array $results): KeywordApiRequest
    {
        $request = KeywordApiRequest::query()->create([
            'request_id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => KeywordApiRequest::TYPE_IDEAS,
            'mode' => 'keywords',
            'payload' => ['seeds' => ['test']],
            'status' => KeywordApiRequest::STATUS_COMPLETED,
            'result' => ['results' => $results],
            'website_id' => $plan->website_id,
        ]);
        Cache::put('content:kw-insights:req:'.$plan->id, $request->id, now()->addHours(2));

        return $request;
    }

    public function test_step_two_dispatches_keyword_research_job(): void
    {
        Queue::fake();
        // A billing-covered DRAFT plan already exists (created when content was
        // activated); the wizard's step 2 fills it in and dispatches research.
        [$user, $website] = $this->userWithPlan();

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('businessDescription', 'We sell handmade wooden furniture for small apartments and offices.')
            ->call('toOfferings')
            ->set('sellItems', ['Wooden tables'])
            ->call('toHowItWorks')
            ->assertHasNoErrors();

        Queue::assertPushed(PrepareContentKeywordInsightsJob::class, 1);
    }

    public function test_completed_request_renders_rich_insights(): void
    {
        [$user, $website, $plan] = $this->userWithPlan();
        ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $website->id,
            'target_keyword' => 'pubg name generator',
            'status' => ContentTopic::STATUS_SUGGESTED,
        ]);
        $this->storeCompletedRequest($plan, [
            ['keyword' => 'pubg name generator', 'avgMonthlySearches' => 50000, 'competitionIndex' => 20],
            ['keyword' => 'how to change pubg name', 'avgMonthlySearches' => 8000, 'competitionIndex' => 10],
            ['keyword' => 'best pubg names', 'avgMonthlySearches' => 12000, 'competitionIndex' => 70],
            ['keyword' => 'stylish name maker', 'avgMonthlySearches' => 6000, 'competitionIndex' => 40],
        ]);

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('wizardStep', 6)
            ->assertSee(__('Keywords analyzed'))
            ->assertSee('pubg name generator')
            ->assertSee('50,000')
            ->assertSee(__('Top searches by demand'))
            ->assertSee(__('Questions your audience is asking'))
            ->assertSee('how to change pubg name');
    }

    public function test_digest_overlays_competitor_traffic_estimate(): void
    {
        [, $website, $plan] = $this->userWithPlan();

        // competitorAuthority() is read from its cache — inject one competitor.
        \Illuminate\Support\Facades\Cache::put('content:setup-insights:v1:'.$website->id, [
            'my_referring_domains' => 0, 'my_authority' => null,
            'competitors' => [[
                'domain' => 'rival.com', 'referring_domains' => null, 'backlinks' => null,
                'authority' => null, 'da' => null, 'pa' => null,
            ]],
            'median' => null, 'gap' => null, 'behind' => false,
        ], now()->addDay());

        // Batched DFS enrichment already stored the rival's organic ETV.
        \App\Models\DomainMetric::query()->create([
            'domain' => 'rival.com',
            'dfs_metrics' => ['metrics' => ['organic' => ['etv' => 4200.0, 'count' => 310]]],
            'dfs_metrics_refreshed_at' => now(),
        ]);

        // Seed + one completed competitor keyword request so the digest builds.
        $this->storeCompletedRequest($plan, [
            ['keyword' => 'name generator', 'avgMonthlySearches' => 1000, 'competitionIndex' => 10],
        ]);
        $compReq = KeywordApiRequest::query()->create([
            'request_id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => KeywordApiRequest::TYPE_IDEAS, 'mode' => 'keywords',
            'payload' => [], 'status' => KeywordApiRequest::STATUS_COMPLETED,
            'result' => ['results' => [['keyword' => 'best name maker', 'avgMonthlySearches' => 500, 'competitionIndex' => 20]]],
            'website_id' => $plan->website_id,
        ]);
        \Illuminate\Support\Facades\Cache::put('content:kw-insights:comp-req:'.$plan->id.':0', $compReq->id, now()->addHours(2));

        $insights = app(ContentKeywordInsights::class)->get($plan);

        $this->assertNotNull($insights);
        $this->assertSame(4200, $insights['traffic']['estimated']);
        $this->assertSame(1, $insights['traffic']['competitors']);

        // Per-competitor metrics row backs the "how your competitors stack up" table.
        $this->assertNotEmpty($insights['competitor_metrics']);
        $row = $insights['competitor_metrics'][0];
        $this->assertSame('rival.com', $row['domain']);
        $this->assertSame(4200, $row['traffic']);
        $this->assertSame(310, $row['keywords']);
    }

    public function test_gap_comes_from_the_single_competitor_keyword_request(): void
    {
        [, $website, $plan] = $this->userWithPlan();

        // One discovered competitor (MAX_COMPETITORS = 1).
        Cache::put('content:setup-insights:v1:'.$website->id, [
            'my_referring_domains' => 0, 'my_authority' => null,
            'competitors' => [['domain' => 'rival.com']],
            'median' => null, 'gap' => null, 'behind' => false,
        ], now()->addDay());

        // Client's own keywords.
        $this->storeCompletedRequest($plan, [
            ['keyword' => 'pubg name generator', 'avgMonthlySearches' => 5000, 'competitionIndex' => 20],
        ]);
        // Competitor's keywords: one the client already targets, one they don't.
        $comp = KeywordApiRequest::query()->create([
            'request_id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => KeywordApiRequest::TYPE_IDEAS, 'mode' => 'keywords',
            'payload' => [], 'status' => KeywordApiRequest::STATUS_COMPLETED,
            'result' => ['results' => [
                ['keyword' => 'pubg name generator', 'avgMonthlySearches' => 5000, 'competitionIndex' => 20],
                ['keyword' => 'best pubg names', 'avgMonthlySearches' => 12000, 'competitionIndex' => 30],
            ]],
            'website_id' => $plan->website_id,
        ]);
        Cache::put('content:kw-insights:comp-req:'.$plan->id.':0', $comp->id, now()->addHours(2));

        $insights = app(ContentKeywordInsights::class)->get($plan);

        $this->assertNotNull($insights);
        $keywords = array_column($insights['gap'], 'keyword');
        $this->assertContains('best pubg names', $keywords);        // competitor-only → gap
        $this->assertNotContains('pubg name generator', $keywords); // client already targets it
        $this->assertFalse($insights['competitors_pending']);
    }

    public function test_insights_classify_intent_questions_and_opportunities(): void
    {
        [, , $plan] = $this->userWithPlan();
        $this->storeCompletedRequest($plan, [
            ['keyword' => 'name generator tool', 'avgMonthlySearches' => 1000, 'competitionIndex' => 10],
            ['keyword' => 'how to pick a username', 'avgMonthlySearches' => 500, 'competitionIndex' => 5],
            ['keyword' => 'best username ideas', 'avgMonthlySearches' => 700, 'competitionIndex' => 90],
        ]);

        $insights = app(ContentKeywordInsights::class)->get($plan);

        $this->assertNotNull($insights);
        $this->assertFalse($insights['partial']);
        $this->assertSame(3, $insights['stats']['keywords']);
        $this->assertSame(2200, $insights['stats']['volume']);
        $this->assertSame(1, $insights['stats']['questions']);
        $this->assertArrayHasKey('transactional', $insights['intents']); // "tool"
        $this->assertArrayHasKey('informational', $insights['intents']); // "how to"
        $this->assertArrayHasKey('commercial', $insights['intents']); // "best"
        $this->assertSame('how to pick a username', $insights['questions'][0]['keyword']);
        // Low-competition 1000-vol beats high-competition 700-vol.
        $this->assertSame('name generator tool', $insights['opportunities'][0]['keyword']);
    }

    public function test_failed_request_falls_back_to_topic_derived_insights(): void
    {
        [$user, $website, $plan] = $this->userWithPlan();
        ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $website->id,
            'target_keyword' => 'how to change pubg name',
            'secondary_keywords' => ['pubg username ideas'],
            'keyword_volume' => 8000,
            'status' => ContentTopic::STATUS_SUGGESTED,
        ]);

        $request = $this->storeCompletedRequest($plan, []);
        $request->forceFill(['status' => KeywordApiRequest::STATUS_FAILED, 'result' => null])->save();

        $insights = app(ContentKeywordInsights::class)->get($plan);

        $this->assertNotNull($insights);
        $this->assertTrue($insights['partial']);
        $this->assertSame(2, $insights['stats']['keywords']);
        $this->assertSame(1, $insights['stats']['questions']);
    }

    public function test_fallback_fills_volumes_from_keywords_everywhere_when_configured(): void
    {
        config(['services.keywords_everywhere.key' => 'fake-ke-key']);
        \Illuminate\Support\Facades\Http::fake([
            'api.keywordseverywhere.com/*' => \Illuminate\Support\Facades\Http::response([
                'data' => [
                    ['keyword' => 'how to change pubg name', 'vol' => 8000, 'competition' => 0.2],
                ],
            ], 200),
        ]);

        [$user, $website, $plan] = $this->userWithPlan();
        ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $website->id,
            'target_keyword' => 'how to change pubg name',
            'keyword_volume' => null,
            'status' => ContentTopic::STATUS_SUGGESTED,
        ]);
        $request = $this->storeCompletedRequest($plan, []);
        $request->forceFill(['status' => KeywordApiRequest::STATUS_FAILED, 'result' => null])->save();

        $insights = app(ContentKeywordInsights::class)->get($plan);

        $this->assertTrue($insights['partial']);
        $this->assertSame(8000, $insights['stats']['volume']);
        $this->assertSame('low', $insights['opportunities'][0]['competition']);
    }

    public function test_pending_request_within_grace_returns_null(): void
    {
        [, , $plan] = $this->userWithPlan();
        $request = $this->storeCompletedRequest($plan, []);
        $request->forceFill(['status' => KeywordApiRequest::STATUS_RUNNING, 'result' => null])->save();

        $this->assertNull(app(ContentKeywordInsights::class)->get($plan));
    }

    public function test_completed_results_backfill_topic_volumes(): void
    {
        [, $website, $plan] = $this->userWithPlan();
        $topic = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $website->id,
            'target_keyword' => 'pubg name generator',
            'keyword_volume' => null,
            'status' => ContentTopic::STATUS_SUGGESTED,
        ]);
        $this->storeCompletedRequest($plan, [
            ['keyword' => 'PUBG Name Generator', 'avgMonthlySearches' => 50000, 'competitionIndex' => 20],
        ]);

        app(ContentKeywordInsights::class)->get($plan);

        $this->assertSame(50000, $topic->fresh()->keyword_volume);
    }

    public function test_step_five_shows_researching_state_while_pending(): void
    {
        [$user, $website, $plan] = $this->userWithPlan();
        $request = $this->storeCompletedRequest($plan, []);
        $request->forceFill(['status' => KeywordApiRequest::STATUS_QUEUED, 'result' => null])->save();

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('wizardStep', 6)
            ->assertSee(__('Researching live search data for your market…'));
    }

    public function test_research_is_geo_targeted_to_the_plan_country(): void
    {
        [, $website, $plan] = $this->userWithPlan([
            'country' => 'de',
            'language' => 'German',
            'business_description' => 'A Berlin bakery selling sourdough and pastries.',
            'offerings' => ['sell' => ['sourdough bread', 'croissants'], 'dont_sell' => []],
        ]);

        $captured = [];
        $mock = \Mockery::mock(\App\Services\KeywordFinder\KeywordFinderPool::class);
        // Multiple research angles now dispatch (offering seeds + the client's own
        // domain + top competitor); each must carry the plan's geo/language.
        $mock->shouldReceive('dispatchIdeas')
            ->atLeast()->once()
            ->andReturnUsing(function ($opts, $userId, $websiteId, $only = null, $countryKey = null, $meter = true) use (&$captured) {
                $captured = compact('opts', 'countryKey', 'meter');

                return KeywordApiRequest::query()->create([
                    'request_id' => (string) \Illuminate\Support\Str::uuid(),
                    'type' => KeywordApiRequest::TYPE_IDEAS, 'mode' => 'keywords',
                    'status' => KeywordApiRequest::STATUS_QUEUED, 'payload' => [], 'website_id' => $websiteId,
                ]);
            });

        $this->app->instance(\App\Services\KeywordFinder\KeywordFinderPool::class, $mock);
        app(ContentKeywordInsights::class)->ensureStarted($plan);

        $this->assertSame('de', $captured['countryKey'], 'research must geo-target the plan country');
        $this->assertSame('German', $captured['opts']['language']);
        $this->assertFalse($captured['meter'], 'platform prefill is unmetered');
    }
}
