<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\ArticleReview;
use App\Livewire\Content\KeywordTracker;
use App\Models\ContentArticle;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\ContentTrackedKeyword;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentKeywordTracker;
use App\Services\Content\KeywordTrackerQuota;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContentTrackerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function trialUser(): User
    {
        return User::factory()->create(['content_trial_ends_at' => now()->addDays(3)]);
    }

    private function siteFor(User $user): Website
    {
        return Website::factory()->for($user)->create();
    }

    private function topicFor(Website $website, string $target = 'seo dubai', array $secondary = []): ContentTopic
    {
        $plan = ContentPlan::factory()->create(['website_id' => $website->id, 'status' => ContentPlan::STATUS_ACTIVE]);
        $topic = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $website->id,
            'target_keyword' => $target,
            'secondary_keywords' => $secondary,
            'status' => ContentTopic::STATUS_READY,
        ]);
        ContentArticle::storeVersion($topic, ['h1' => 'X', 'html' => '<p>Body.</p>', 'word_count' => 2, 'seo_score' => 80, 'seo_issues' => []]);

        return $topic;
    }

    public function test_tracking_a_keyword_dispatches_an_immediate_serp_check(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $user = $this->trialUser();
        $website = $this->siteFor($user);

        app(\App\Services\Content\ContentKeywordTracker::class)->track($website, ['digital marketing']);

        \Illuminate\Support\Facades\Queue::assertPushed(
            \App\Jobs\CheckTrackedKeywordSerpJob::class,
            fn ($job) => $job->websiteId === $website->id,
        );
    }

    public function test_tracking_only_duplicates_dispatches_no_check(): void
    {
        $user = $this->trialUser();
        $website = $this->siteFor($user);
        app(\App\Services\Content\ContentKeywordTracker::class)->track($website, ['digital marketing']);

        \Illuminate\Support\Facades\Queue::fake();
        app(\App\Services\Content\ContentKeywordTracker::class)->track($website, ['digital marketing']);

        \Illuminate\Support\Facades\Queue::assertNothingPushed();
    }

    public function test_serp_country_saves_and_forces_a_full_recheck(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $user = $this->trialUser();
        $website = $this->siteFor($user);
        ContentPlan::factory()->create(['website_id' => $website->id, 'status' => ContentPlan::STATUS_ACTIVE, 'country' => 'us']);
        $kw = \App\Models\ContentTrackedKeyword::create([
            'website_id' => $website->id, 'keyword' => 'digital marketing',
            'normalized_keyword' => 'digital marketing', 'source' => 'manual',
            'serp_checked_at' => now(), 'serp_position' => 5,
        ]);
        session(['current_website_id' => $website->id]);

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Content\KeywordTracker::class)
            ->assertSet('serpCountry', 'us')
            ->set('serpCountry', 'ae')
            ->call('saveSerpCountry');

        $this->assertSame('ae', ContentPlan::query()->where('website_id', $website->id)->value('serp_country'));
        $this->assertNull($kw->refresh()->serp_checked_at); // staleness window bypassed
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\CheckTrackedKeywordSerpJob::class);
    }

    public function test_serp_check_uses_the_saved_country_override(): void
    {
        config(['services.serper.key' => 'test-key']);
        \Illuminate\Support\Facades\Http::fake(['google.serper.dev/*' => \Illuminate\Support\Facades\Http::response(['organic' => []], 200)]);
        $user = $this->trialUser();
        $website = $this->siteFor($user);
        ContentPlan::factory()->create(['website_id' => $website->id, 'status' => ContentPlan::STATUS_ACTIVE, 'country' => 'us', 'serp_country' => 'ae']);
        $kw = \App\Models\ContentTrackedKeyword::create([
            'website_id' => $website->id, 'keyword' => 'digital marketing',
            'normalized_keyword' => 'digital marketing', 'source' => 'manual',
        ]);

        app(\App\Services\Content\ContentSerpChecker::class)->check($kw);

        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => ($r->data()['gl'] ?? null) === 'ae');
    }

    public function test_page_queries_aggregates_real_search_phrases_by_impressions(): void
    {
        $user = $this->trialUser();
        $website = $this->siteFor($user);
        $page = 'https://example.com/blog/post';
        foreach ([
            ['q' => 'brand development plans dubai', 'i' => 16, 'c' => 2, 'p' => 8.0, 'd' => 3],
            ['q' => 'brand development plans dubai', 'i' => 4, 'c' => 0, 'p' => 10.0, 'd' => 4],
            ['q' => 'luxury property branding', 'i' => 1, 'c' => 0, 'p' => 40.0, 'd' => 3],
        ] as $row) {
            \App\Models\SearchConsoleData::create([
                'website_id' => $website->id, 'date' => now()->subDays($row['d'])->toDateString(),
                'query' => $row['q'], 'page' => $page,
                'clicks' => $row['c'], 'impressions' => $row['i'], 'position' => $row['p'],
            ]);
        }

        $out = app(\App\Services\Content\ContentPerformanceService::class)->pageQueries($website, $page);

        $this->assertSame('brand development plans dubai', $out[0]['query']);
        $this->assertSame(20, $out[0]['impressions']);
        $this->assertSame(2, $out[0]['clicks']);
        $this->assertEqualsWithDelta(8.4, $out[0]['position'], 0.1); // impression-weighted
        $this->assertSame('luxury property branding', $out[1]['query']);
    }

    public function test_track_query_adds_the_phrase_under_the_article_group(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $user = $this->trialUser();
        $website = $this->siteFor($user);
        $topic = $this->topicFor($website, 'luxury branding dubai');
        \App\Models\ContentTrackedKeyword::create([
            'website_id' => $website->id, 'topic_id' => $topic->id,
            'keyword' => 'luxury branding dubai', 'normalized_keyword' => 'luxury branding dubai',
            'source' => 'auto', 'is_primary' => true, 'page_url' => 'https://example.com/blog/post',
        ]);
        session(['current_website_id' => $website->id]);

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Content\KeywordTracker::class)
            ->call('togglePerformance', $topic->id)
            ->call('trackQuery', 'brand development plans dubai');

        $row = \App\Models\ContentTrackedKeyword::query()
            ->where('normalized_keyword', 'brand development plans dubai')->firstOrFail();
        $this->assertSame($topic->id, $row->topic_id);
        $this->assertSame('https://example.com/blog/post', $row->page_url);
        $this->assertFalse((bool) $row->is_primary);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\CheckTrackedKeywordSerpJob::class);
    }

    public function test_tracker_shows_article_value_totals_and_discovered_phrases_inline(): void
    {
        $user = $this->trialUser();
        $website = $this->siteFor($user);
        $topic = $this->topicFor($website, 'luxury branding dubai');
        $page = 'https://example.com/blog/post';
        \App\Models\ContentTrackedKeyword::create([
            'website_id' => $website->id, 'topic_id' => $topic->id,
            'keyword' => 'luxury branding dubai', 'normalized_keyword' => 'luxury branding dubai',
            'source' => 'auto', 'is_primary' => true, 'page_url' => $page,
        ]);
        \App\Models\SearchConsoleData::create([
            'website_id' => $website->id, 'date' => now()->subDays(3)->toDateString(),
            'query' => 'brand development plans dubai', 'page' => $page,
            'clicks' => 4, 'impressions' => 16, 'position' => 8.0,
        ]);
        session(['current_website_id' => $website->id]);

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Content\KeywordTracker::class)
            // Site-total hero
            ->assertSee(__('What your articles brought you'))
            ->assertSee(__('Visits from Google'))
            // Discovered phrase inline in the keyword list, without opening performance
            ->assertSee(__('More searches this article shows up for'))
            ->assertSee('brand development plans dubai')
            ->assertSee(__('Track'));
    }

    public function test_quota_is_3_on_trial_and_500_when_comped_per_website(): void
    {
        $quota = app(KeywordTrackerQuota::class);

        $trial = $this->siteFor($this->trialUser());
        $this->assertSame(3, $quota->limitFor($trial));

        $comped = $this->siteFor(User::factory()->create(['content_comp_sites' => 1]));
        $this->assertSame(500, $quota->limitFor($comped));

        $noAccess = $this->siteFor(User::factory()->create());
        $this->assertSame(0, $quota->limitFor($noAccess));
    }

    public function test_track_dedupes_and_respects_cap(): void
    {
        $website = $this->siteFor($this->trialUser()); // cap 3
        $tracker = app(ContentKeywordTracker::class);

        // Case-variant duplicates collapse to one.
        $r1 = $tracker->track($website, ['SEO Dubai', 'seo dubai']);
        $this->assertSame(1, $r1['added']);
        $this->assertFalse($r1['capped']);

        // Five more distinct — only two slots remain, so capped.
        $r2 = $tracker->track($website, ['a', 'b', 'c', 'd', 'e']);
        $this->assertSame(2, $r2['added']);
        $this->assertTrue($r2['capped']);

        $this->assertSame(3, ContentTrackedKeyword::where('website_id', $website->id)->count());
        $this->assertTrue(app(KeywordTrackerQuota::class)->exhausted($website));
    }

    public function test_track_from_topic_flags_primary(): void
    {
        $website = $this->siteFor(User::factory()->create(['content_comp_sites' => 1]));
        $topic = $this->topicFor($website, 'main kw', ['sec one', 'sec two']);
        $tracker = app(ContentKeywordTracker::class);

        $r = $tracker->track($website, $tracker->keywordsFor($topic), $topic, ContentTrackedKeyword::SOURCE_AUTO);
        $this->assertSame(3, $r['added']);

        $primary = ContentTrackedKeyword::where('website_id', $website->id)->where('is_primary', true)->first();
        $this->assertNotNull($primary);
        $this->assertSame('main kw', $primary->normalized_keyword);
        $this->assertSame('auto', $primary->source);
    }

    public function test_untrack_removes_row(): void
    {
        $website = $this->siteFor($this->trialUser());
        $tracker = app(ContentKeywordTracker::class);
        $tracker->track($website, ['keep me']);
        $row = ContentTrackedKeyword::where('website_id', $website->id)->first();

        $this->assertTrue($tracker->untrack($website, $row->id));
        $this->assertSame(0, ContentTrackedKeyword::where('website_id', $website->id)->count());
    }

    public function test_article_page_track_cta_adds_keywords(): void
    {
        $user = $this->trialUser();
        $website = $this->siteFor($user);
        $topic = $this->topicFor($website, 'dubai seo', ['seo agency']);
        $this->actingAs($user);

        Livewire::test(ArticleReview::class, ['topicId' => $topic->id])
            ->assertSet('isTracked', false)
            ->call('trackKeywords')
            ->assertSet('isTracked', true);

        $this->assertDatabaseHas('content_tracked_keywords', [
            'website_id' => $website->id,
            'topic_id' => $topic->id,
            'normalized_keyword' => 'dubai seo',
            'source' => 'manual',
        ]);
    }

    public function test_tracker_page_lists_and_deletes(): void
    {
        $user = $this->trialUser();
        $website = $this->siteFor($user);
        $topic = $this->topicFor($website, 'coffee beans');
        app(ContentKeywordTracker::class)->track($website, ['coffee beans'], $topic, ContentTrackedKeyword::SOURCE_AUTO);
        $row = ContentTrackedKeyword::where('website_id', $website->id)->first();
        $this->actingAs($user);

        Livewire::test(KeywordTracker::class)
            ->assertSee('coffee beans')
            ->assertSee(__('Tracked keywords'))
            ->call('untrack', $row->id);

        $this->assertSame(0, ContentTrackedKeyword::where('website_id', $website->id)->count());
    }
}
