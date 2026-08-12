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
