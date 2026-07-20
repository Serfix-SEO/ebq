<?php

namespace Tests\Feature\Content;

use App\Jobs\PrepareContentKeywordInsightsJob;
use App\Livewire\Content\ContentCalendar;
use App\Models\ContentPlan;
use App\Models\ContentPlanKeyword;
use App\Models\DomainKeywordRanking;
use App\Models\KeywordMetric;
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
            'website_id' => $website->id, 'status' => ContentPlan::STATUS_DRAFT, 'country' => 'US',
        ], $planAttrs));

        return [$user, $website, $plan];
    }

    private function competitor(Website $website, string $domain = 'rival.com'): void
    {
        Cache::put('content:setup-insights:v1:'.$website->id, [
            'my_referring_domains' => 10, 'my_authority' => null,
            'competitors' => [['domain' => $domain]],
            'median' => null, 'gap' => null, 'behind' => false,
        ], now()->addDay());
        // The competitor must have harvested rankings for the digest to be "final".
        $this->ranking($domain, 'competitor seed', 50);
    }

    private function ranking(string $domain, string $keyword, int $volume): void
    {
        DomainKeywordRanking::query()->create([
            'domain' => $domain, 'keyword' => $keyword, 'country' => 'us',
            'keyword_hash' => KeywordMetric::hashKeyword($keyword), 'search_volume' => $volume,
        ]);
    }

    private function keyword(ContentPlan $plan, string $kw, string $type, int $vol, float $comp): void
    {
        KeywordMetric::query()->create([
            'keyword' => $kw, 'keyword_hash' => KeywordMetric::hashKeyword($kw),
            'country' => 'us', 'data_source' => 'dfs_labs', 'search_volume' => $vol,
            'competition' => $comp, 'fetched_at' => now(), 'expires_at' => now()->addDays(30),
        ]);
        ContentPlanKeyword::query()->create([
            'plan_id' => $plan->id, 'keyword' => $kw, 'type' => $type,
            'keyword_hash' => KeywordMetric::hashKeyword($kw), 'country' => 'us',
            'search_volume' => $vol, 'competition' => $comp,
        ]);
    }

    public function test_step_two_dispatches_keyword_research_job(): void
    {
        Queue::fake();
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

    public function test_digest_builds_from_classified_dfs_keywords(): void
    {
        [$user, $website, $plan] = $this->userWithPlan();
        $this->competitor($website);
        $this->keyword($plan, 'stylish name generator', 'own', 5000, 0.2);
        $this->keyword($plan, 'best pubg names', 'gap', 12000, 0.7);
        $this->keyword($plan, 'how to change pubg name', 'gap', 3000, 0.1);
        $plan->forceFill(['keywords_classified_at' => now()])->save();

        $insights = app(ContentKeywordInsights::class)->get($plan);

        $this->assertNotNull($insights);
        $this->assertFalse($insights['partial']);
        $this->assertSame(3, $insights['stats']['keywords']);          // own + gap
        $this->assertSame(20000, $insights['stats']['volume']);        // 5000+12000+3000
        $keywords = array_column($insights['top_searches'], 'keyword');
        $this->assertContains('best pubg names', $keywords);           // highest volume
        $questions = array_column($insights['questions'], 'keyword');
        $this->assertContains('how to change pubg name', $questions);
    }

    public function test_get_pending_until_harvest_and_classified(): void
    {
        [$user, $website, $plan] = $this->userWithPlan();
        $this->competitor($website); // competitor harvested, but plan NOT classified

        $this->assertNull(app(ContentKeywordInsights::class)->get($plan));

        // Loader still lists per-source progress (own site, competitor, prioritizing).
        $status = app(ContentKeywordInsights::class)->researchStatus($plan);
        $this->assertNotEmpty($status);
        $this->assertContains('Prioritizing your keywords', array_column($status, 'label'));
    }

    public function test_gap_reads_classified_plan_keywords(): void
    {
        [$user, $website, $plan] = $this->userWithPlan();
        $this->competitor($website);
        $this->keyword($plan, 'pubg stylish name', 'gap', 9000, 0.2);
        $this->keyword($plan, 'bgmi name generator', 'gap', 4000, 0.5);
        $plan->forceFill(['keywords_classified_at' => now()])->save();

        $insights = app(ContentKeywordInsights::class)->get($plan);

        $this->assertNotNull($insights);
        $this->assertSame(2, $insights['gap_total']);
        $this->assertSame('pubg stylish name', $insights['gap'][0]['keyword']); // highest volume
        $this->assertSame('low', $insights['gap'][0]['competition']);           // 0.2 → low
        $this->assertFalse($insights['competitors_pending']);
    }

    public function test_digest_overlays_competitor_traffic_estimate(): void
    {
        [$user, $website, $plan] = $this->userWithPlan();
        $this->competitor($website, 'rival.com');
        \App\Models\DomainMetric::query()->create([
            'domain' => 'rival.com',
            'dfs_metrics' => ['metrics' => ['organic' => ['etv' => 4200.0, 'count' => 310]]],
            'dfs_metrics_refreshed_at' => now(),
        ]);
        $this->keyword($plan, 'best pubg names', 'gap', 12000, 0.7);
        $plan->forceFill(['keywords_classified_at' => now()])->save();

        $insights = app(ContentKeywordInsights::class)->get($plan);

        $this->assertNotNull($insights);
        $this->assertSame(4200, $insights['traffic']['estimated']);
        $this->assertNotEmpty($insights['competitor_metrics']);
        $this->assertSame('rival.com', $insights['competitor_metrics'][0]['domain']);
    }
}
