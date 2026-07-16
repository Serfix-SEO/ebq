<?php

namespace Tests\Feature;

use App\Livewire\Keywords\KeywordIdeaFinder;
use App\Models\GoogleAccount;
use App\Models\RankTrackingKeyword;
use App\Models\SearchConsoleData;
use App\Models\User;
use App\Models\Website;
use App\Services\KeywordResearch\AiKeywordClusterService;
use App\Services\KeywordResearch\KeywordIntentClassifier;
use App\Services\KeywordResearch\KeywordTermGrouper;
use App\Services\Llm\LlmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class KeywordResearchUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_intent_classifier(): void
    {
        $this->assertSame('informational', KeywordIntentClassifier::classify('how to fix cls'));
        $this->assertSame('commercial', KeywordIntentClassifier::classify('best crm software'));
        $this->assertSame('transactional', KeywordIntentClassifier::classify('buy running shoes near me'));
        $this->assertSame('navigational', KeywordIntentClassifier::classify('hubspot login'));
        $this->assertSame('other', KeywordIntentClassifier::classify('running shoes'));
        // "best price" → transactional wins over commercial.
        $this->assertSame('transactional', KeywordIntentClassifier::classify('best price for iphone'));

        // Tool / do-intent NOUNS → transactional (use-a-tool intent).
        $this->assertSame('transactional', KeywordIntentClassifier::classify('name generator'));
        $this->assertSame('transactional', KeywordIntentClassifier::classify('logo maker'));
        $this->assertSame('transactional', KeywordIntentClassifier::classify('mortgage calculator'));
        $this->assertSame('transactional', KeywordIntentClassifier::classify('grammar checker'));
        $this->assertSame('transactional', KeywordIntentClassifier::classify('name randomizer'));
        // Bare verbs are NOT triggers, so how-to queries stay informational.
        $this->assertSame('informational', KeywordIntentClassifier::classify('how to make money'));
        // A bare browse noun-phrase with no signal stays 'other' (un-guessable).
        $this->assertSame('other', KeywordIntentClassifier::classify('spanish names'));

        $this->assertTrue(KeywordIntentClassifier::isQuestion('how to run faster'));
        $this->assertFalse(KeywordIntentClassifier::isQuestion('running shoes'));
    }

    public function test_term_grouper_groups_by_shared_terms(): void
    {
        $rows = [
            ['keyword' => 'running shoes for men', 'volume' => 1000],
            ['keyword' => 'running shoes for women', 'volume' => 900],
            ['keyword' => 'trail running shoes', 'volume' => 500],
            ['keyword' => 'best marathon training plan', 'volume' => 200],
        ];

        $groups = KeywordTermGrouper::groups($rows);
        $byTerm = collect($groups)->keyBy('term');

        $this->assertTrue($byTerm->has('running shoes'));
        $this->assertSame(3, $byTerm['running shoes']['count']);
        $this->assertSame(2400, $byTerm['running shoes']['volume']);
        // Single-keyword terms are noise and excluded.
        $this->assertFalse($byTerm->has('marathon'));
    }

    public function test_ai_cluster_service_maps_and_caches(): void
    {
        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('isAvailable')->andReturn(true);
        $llm->shouldReceive('completeJson')->once()->andReturn([
            'clusters' => [
                ['label' => 'Running shoes', 'keywords' => ['running shoes for men', 'trail running shoes']],
                ['label' => 'Training', 'keywords' => ['marathon training plan', 'half marathon plan', 'invented keyword']],
            ],
        ]);

        $service = new AiKeywordClusterService($llm);
        $rows = [
            ['keyword' => 'running shoes for men', 'volume' => 1000],
            ['keyword' => 'trail running shoes', 'volume' => 500],
            ['keyword' => 'marathon training plan', 'volume' => 200],
            ['keyword' => 'half marathon plan', 'volume' => 150],
            ['keyword' => 'running socks', 'volume' => 100],
            ['keyword' => 'running watch', 'volume' => 90],
        ];

        $map = $service->cluster($rows, 'test-key');

        $this->assertSame('Running shoes', $map['running shoes for men']);
        $this->assertSame('Training', $map['marathon training plan']);
        // Invented keyword rejected; unassigned input lands in "Other".
        $this->assertArrayNotHasKey('invented keyword', $map);
        $this->assertSame('Other', $map['running socks']);

        // Second call: served from cache — the once() expectation would fail otherwise.
        $again = $service->cluster($rows, 'test-key');
        $this->assertSame($map, $again);
    }

    public function test_ai_cluster_service_declines_small_result_sets(): void
    {
        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('isAvailable')->andReturn(true);
        // completeJson must NEVER be called — below MIN_KEYWORDS, decline before any LLM spend.
        $llm->shouldNotReceive('completeJson');

        $service = new AiKeywordClusterService($llm);
        $rows = array_map(fn ($i) => ['keyword' => "keyword {$i}", 'volume' => 100], range(1, 5));

        $this->assertNull($service->cluster($rows, 'small-set'));
    }

    public function test_ai_cluster_service_merges_singleton_clusters_into_other(): void
    {
        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('isAvailable')->andReturn(true);
        $llm->shouldReceive('completeJson')->andReturn([
            'clusters' => [
                ['label' => 'Real cluster', 'keywords' => ['kw a', 'kw b', 'kw c']],
                // Singleton — model ignored the "min 2 per cluster" instruction.
                ['label' => 'Lonely cluster', 'keywords' => ['kw d']],
            ],
        ]);

        $service = new AiKeywordClusterService($llm);
        $rows = array_map(fn ($k) => ['keyword' => $k, 'volume' => 100], ['kw a', 'kw b', 'kw c', 'kw d', 'kw e', 'kw f']);

        $map = $service->cluster($rows, 'singleton-test');

        // The singleton "Lonely cluster" never reaches the caller — folded into Other.
        $this->assertSame('Other', $map['kw d']);
        $this->assertSame('Real cluster', $map['kw a']);
    }

    public function test_ai_cluster_service_force_bypasses_cache(): void
    {
        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('isAvailable')->andReturn(true);
        $llm->shouldReceive('completeJson')->twice()->andReturn([
            'clusters' => [
                ['label' => 'Cluster one', 'keywords' => ['kw a', 'kw b']],
                ['label' => 'Cluster two', 'keywords' => ['kw c', 'kw d', 'kw e', 'kw f']],
            ],
        ]);

        $service = new AiKeywordClusterService($llm);
        $rows = array_map(fn ($k) => ['keyword' => $k, 'volume' => 100], ['kw a', 'kw b', 'kw c', 'kw d', 'kw e', 'kw f']);

        $first = $service->cluster($rows, 'force-test');
        // Without force this would hit cache and the twice() expectation would fail.
        $second = $service->cluster($rows, 'force-test', force: true);

        $this->assertIsArray($first);
        $this->assertIsArray($second);
    }

    public function test_component_filters_intent_questions_exclude_and_groups(): void
    {
        $user = User::factory()->create();
        $results = [
            ['keyword' => 'how to choose running shoes', 'avgMonthlySearches' => 300],
            ['keyword' => 'buy running shoes', 'avgMonthlySearches' => 900],
            ['keyword' => 'best running shoes', 'avgMonthlySearches' => 700],
            ['keyword' => 'yoga mat', 'avgMonthlySearches' => 500],
        ];

        $test = Livewire::actingAs($user)->test(KeywordIdeaFinder::class)
            ->set('results', $results)
            ->set('hasRun', true);

        // Exclude filter
        $test->set('excludeText', 'yoga');
        $this->assertCount(3, $test->viewData('rows'));

        // Questions only
        $test->set('questionsOnly', true);
        $rows = $test->viewData('rows');
        $this->assertCount(1, $rows);
        $this->assertSame('how to choose running shoes', $rows[0]['keyword']);
        $test->set('questionsOnly', false);

        // Intent filter
        $test->set('intent', 'transactional');
        $rows = $test->viewData('rows');
        $this->assertCount(1, $rows);
        $this->assertSame('buy running shoes', $rows[0]['keyword']);
        $test->set('intent', 'all');

        // Term-group filter ("running shoes" group excludes yoga even without excludeText)
        $test->set('excludeText', '')->call('setGroup', 'running shoes');
        $this->assertCount(3, $test->viewData('rows'));

        // Groups rail contains the shared term
        $terms = collect($test->viewData('termGroups'))->pluck('term');
        $this->assertTrue($terms->contains('running shoes'));
    }

    public function test_component_bulk_track_selected(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);

        $results = [
            ['keyword' => 'alpha keyword', 'avgMonthlySearches' => 100],
            ['keyword' => 'beta keyword', 'avgMonthlySearches' => 90],
        ];

        $this->withSession(['current_website_id' => $website->id]);

        Livewire::actingAs($user)
            ->test(KeywordIdeaFinder::class)
            ->set('results', $results)
            ->set('hasRun', true)
            ->call('toggleSelectPage')
            ->call('trackSelected')
            ->assertSet('selected', []);

        $this->assertSame(2, RankTrackingKeyword::where('website_id', $website->id)->count());
    }

    public function test_component_cluster_with_ai_switches_view(): void
    {
        $user = User::factory()->create();
        $results = [];
        foreach (['run fast', 'run slow', 'jump high', 'jump far', 'swim laps', 'swim gear'] as $i => $kw) {
            $results[] = ['keyword' => $kw, 'avgMonthlySearches' => 100 - $i];
        }

        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('isAvailable')->andReturn(true);
        $llm->shouldReceive('completeJson')->andReturn([
            'clusters' => [
                ['label' => 'Running', 'keywords' => ['run fast', 'run slow']],
                ['label' => 'Jumping', 'keywords' => ['jump high', 'jump far']],
                ['label' => 'Swimming', 'keywords' => ['swim laps', 'swim gear']],
            ],
        ]);
        $this->app->instance(AiKeywordClusterService::class, new AiKeywordClusterService($llm));

        $test = Livewire::actingAs($user)->test(KeywordIdeaFinder::class)
            ->set('results', $results)
            ->set('hasRun', true)
            ->call('clusterWithAi')
            ->assertSet('viewMode', 'clusters');

        $clusters = $test->viewData('clusters');
        $this->assertCount(3, $clusters);
        $this->assertSame('Running', $clusters[0]['label']);
    }

    public function test_component_recluster_forces_a_fresh_llm_call(): void
    {
        $user = User::factory()->create();
        $results = [];
        foreach (['run fast', 'run slow', 'jump high', 'jump far', 'swim laps', 'swim gear'] as $i => $kw) {
            $results[] = ['keyword' => $kw, 'avgMonthlySearches' => 100 - $i];
        }

        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('isAvailable')->andReturn(true);
        // Two distinct payloads prove the second call is a genuine re-run, not a cache hit.
        $llm->shouldReceive('completeJson')->twice()->andReturn(
            ['clusters' => [
                ['label' => 'Running', 'keywords' => ['run fast', 'run slow']],
                ['label' => 'Jumping', 'keywords' => ['jump high', 'jump far']],
                ['label' => 'Swimming', 'keywords' => ['swim laps', 'swim gear']],
            ]],
            ['clusters' => [
                ['label' => 'Cardio', 'keywords' => ['run fast', 'run slow', 'jump high', 'jump far']],
                ['label' => 'Water sports', 'keywords' => ['swim laps', 'swim gear']],
            ]],
        );
        $this->app->instance(AiKeywordClusterService::class, new AiKeywordClusterService($llm));

        $test = Livewire::actingAs($user)->test(KeywordIdeaFinder::class)
            ->set('results', $results)
            ->set('hasRun', true)
            ->call('clusterWithAi')
            ->assertSet('clusterMap.run fast', 'Running')
            ->call('clusterWithAi', true)
            ->assertSet('clusterMap.run fast', 'Cardio');
    }

    public function test_component_shows_gsc_metrics_instead_of_row_actions(): void
    {
        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);
        $website = Website::factory()->create([
            'user_id' => $user->id,
            'domain' => 'example.com',
            'gsc_site_url' => 'sc-domain:example.com',
            'gsc_google_account_id' => $account->id,
        ]);

        SearchConsoleData::create([
            'website_id' => $website->id,
            'date' => now()->subDays(5)->toDateString(),
            'query' => 'running shoes for men',
            'page' => 'https://example.com/shoes',
            'clicks' => 42,
            'impressions' => 900,
            'position' => 4.2,
            'ctr' => 0.047,
            'country' => 'usa',
            'device' => 'DESKTOP',
        ]);

        $results = [
            ['keyword' => 'running shoes for men', 'avgMonthlySearches' => 500],
            ['keyword' => 'brand new topic idea', 'avgMonthlySearches' => 300],
        ];

        $this->withSession(['current_website_id' => $website->id]);
        $test = Livewire::actingAs($user)
            ->test(KeywordIdeaFinder::class)
            ->set('results', $results)
            ->set('hasRun', true);

        $test->assertSet('hasRun', true);
        $this->assertTrue($test->viewData('hasGsc'));
        $metrics = $test->viewData('gscMetrics');
        $this->assertSame(42, $metrics['running shoes for men']['clicks']);
        $this->assertSame(900, $metrics['running shoes for men']['impressions']);
        $this->assertSame(4.2, $metrics['running shoes for men']['position']);
        // The never-before-seen keyword has no GSC row — correctly absent (renders as "New").
        $this->assertArrayNotHasKey('brand new topic idea', $metrics);

        // No GSC connected on the website → hasGsc is false and no metrics are queried.
        $noGscWebsite = Website::factory()->create(['user_id' => $user->id, 'domain' => 'other.test']);
        $this->withSession(['current_website_id' => $noGscWebsite->id]);
        $test2 = Livewire::actingAs($user)
            ->test(KeywordIdeaFinder::class)
            ->set('results', $results)
            ->set('hasRun', true);
        $this->assertFalse($test2->viewData('hasGsc'));
        $this->assertSame([], $test2->viewData('gscMetrics'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
