<?php

namespace Tests\Feature\Content;

use App\Jobs\PlanContentTopicsJob;
use App\Jobs\ProduceContentArticleJob;
use App\Livewire\Content\ArticleReview;
use App\Livewire\Content\ContentCalendar;
use App\Models\ContentArticle;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ContentPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
    }

    private function userWithWebsite(): array
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();

        return [$user, $website];
    }

    public function test_content_page_requires_auth(): void
    {
        $this->get('/content')->assertRedirect();
    }

    public function test_content_page_shows_empty_state_without_plan(): void
    {
        [$user, $website] = $this->userWithWebsite();

        $this->actingAs($user)
            ->withSession(['current_website_id' => $website->id])
            ->get('/content')
            ->assertOk()
            ->assertSee(__('No content plan yet'))
            ->assertDontSee(__('Tell us about your business'));
    }

    public function test_settings_page_renders_wizard_without_plan(): void
    {
        [$user, $website] = $this->userWithWebsite();

        $this->actingAs($user)
            ->withSession(['current_website_id' => $website->id])
            ->get('/content/settings')
            ->assertOk()
            ->assertSee(__('Tell us about your business'));
    }

    public function test_settings_page_shows_settings_layout_for_onboarded_plan(): void
    {
        [$user, $website] = $this->userWithWebsite();
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
            'business_description' => 'We sell handmade wooden furniture for small apartments.',
            'articles_per_week' => 7,
        ]);

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        // Onboarded plan → settings layout, NOT the onboarding stepper.
        $this->get('/content/settings')
            ->assertOk()
            ->assertSee(__('Articles per week'))          // settings-only control
            ->assertSee(__('Auto-publish'))
            ->assertDontSee(__('Step 3 of 7'))            // no stepper
            ->assertDontSee(__('How this grows your traffic')) // onboarding-only step skipped
            ->assertDontSee(__('Your competitors and their authority'));

        // saveSettings persists settings fields without demoting the plan or
        // re-running onboarding jobs.
        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('businessDescription', 'We sell handmade wooden furniture for small apartments, updated.')
            ->set('articlesPerWeek', 3)
            ->set('autoPublish', true)
            ->set('language', 'German')
            ->set('country', 'de')
            ->set('publishHourStart', 14)
            ->set('publishHourEnd', 16)
            ->set('publishTimezone', 'Asia/Karachi')
            ->call('saveSettings')
            ->assertHasNoErrors();

        $fresh = $plan->fresh();
        $this->assertSame(ContentPlan::STATUS_ACTIVE, $fresh->status);
        $this->assertSame('We sell handmade wooden furniture for small apartments, updated.', $fresh->business_description);
        $this->assertSame(3, (int) $fresh->articles_per_week);
        $this->assertTrue((bool) $fresh->auto_publish);
        $this->assertSame('German', $fresh->language);
        $this->assertSame('de', $fresh->country);
        $this->assertSame(14, (int) $fresh->publish_hour_start);
        $this->assertSame(16, (int) $fresh->publish_hour_end);
        $this->assertSame('Asia/Karachi', $fresh->timezone);
    }

    public function test_settings_page_shows_wizard_for_draft_plan(): void
    {
        [$user, $website] = $this->userWithWebsite();
        ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_DRAFT,
            'business_description' => 'A not-yet-launched draft plan awaiting first setup.',
        ]);

        $this->actingAs($user)
            ->withSession(['current_website_id' => $website->id])
            ->get('/content/settings')
            ->assertOk()
            ->assertSee(__('How this grows your traffic')) // still the onboarding wizard (draft lands on step 3)
            ->assertDontSee(__('Articles per week'));       // settings-only control absent
    }

    public function test_structure_toggle_persists_to_plan(): void
    {
        Queue::fake();
        [$user, $website] = $this->userWithWebsite();
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
            'toggles' => ['toc' => true, 'key_takeaways' => true, 'faq' => true, 'external_links' => true, 'cta_enabled' => false],
        ]);

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->call('toggleStructure', 'key_takeaways')
            ->assertHasNoErrors();

        $toggles = $plan->fresh()->toggles;
        $this->assertFalse($toggles['key_takeaways'], 'toggle flips + persists');
        // Untouched toggles the wizard does not surface are preserved.
        $this->assertTrue($toggles['external_links']);
        $this->assertTrue($toggles['toc']);
    }

    public function test_image_step_persists_enable_and_style(): void
    {
        Queue::fake();
        [$user, $website] = $this->userWithWebsite();
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_DRAFT,
            'images_enabled' => true,
            'image_style' => 'photographic',
        ]);

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('wizardStep', 4)
            ->assertSee(__('Images for your articles'))
            ->assertSee(__('Anime / Manga'))
            ->call('selectImageStyle', 'anime')
            ->assertHasNoErrors();

        $this->assertSame('anime', $plan->fresh()->image_style);
        $this->assertTrue((bool) $plan->fresh()->images_enabled);

        // Turning images off persists too.
        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->call('toggleImages');
        $this->assertFalse((bool) $plan->fresh()->images_enabled);
    }

    public function test_invalid_image_style_is_ignored(): void
    {
        [$user, $website] = $this->userWithWebsite();
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id, 'status' => ContentPlan::STATUS_DRAFT, 'image_style' => 'anime',
        ]);
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->call('selectImageStyle', 'not-a-real-style');

        $this->assertSame('anime', $plan->fresh()->image_style); // unchanged
    }

    public function test_wizard_creates_draft_plan_and_dispatches_topic_planning(): void
    {
        Queue::fake();
        [$user, $website] = $this->userWithWebsite();

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class)
            ->set('businessDescription', 'We sell handmade wooden furniture for small apartments and offer design advice.')
            ->call('toOfferings')
            ->set('sellItems', ['Wooden tables', 'Chairs'])
            ->set('dontSellItems', ['Repairs'])
            ->call('toHowItWorks')
            ->assertHasNoErrors();

        $plan = ContentPlan::query()->where('website_id', $website->id)->first();
        $this->assertNotNull($plan);
        // Created as a DRAFT so topic ideation runs while the user finishes setup.
        $this->assertSame(ContentPlan::STATUS_DRAFT, $plan->status);
        $this->assertSame(7, (int) $plan->articles_per_week);
        $this->assertSame(['Wooden tables', 'Chairs'], $plan->offerings['sell']);
        Queue::assertPushed(PlanContentTopicsJob::class, 1);
    }

    public function test_launch_activates_the_draft_plan(): void
    {
        [$user, $website] = $this->userWithWebsite();
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id, 'status' => ContentPlan::STATUS_DRAFT,
        ]);

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class)->call('launch');

        $this->assertSame(ContentPlan::STATUS_ACTIVE, $plan->fresh()->status);
    }

    public function test_reorder_sell_moves_item_to_target_position(): void
    {
        [$user, $website] = $this->userWithWebsite();
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class)
            ->set('sellItems', ['A', 'B', 'C', 'D'])
            ->call('reorderSell', 3, 0)
            ->assertSet('sellItems', ['D', 'A', 'B', 'C']);

        Livewire::test(ContentCalendar::class)
            ->set('sellItems', ['A', 'B', 'C', 'D'])
            ->call('reorderSell', 0, 2)
            ->assertSet('sellItems', ['B', 'C', 'A', 'D']);
    }

    public function test_reorder_sell_ignores_out_of_range_indexes(): void
    {
        [$user, $website] = $this->userWithWebsite();
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class)
            ->set('sellItems', ['A', 'B'])
            ->call('reorderSell', 5, 0)
            ->assertSet('sellItems', ['A', 'B'])
            ->call('reorderSell', 0, 99)
            ->assertSet('sellItems', ['A', 'B']);
    }

    public function test_wizard_step_one_validates_description(): void
    {
        [$user, $website] = $this->userWithWebsite();

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class)
            ->set('businessDescription', 'too short')
            ->call('toOfferings')
            ->assertHasErrors(['businessDescription']);
    }

    public function test_calendar_renders_topics_with_neutral_status(): void
    {
        [$user, $website] = $this->userWithWebsite();
        $plan = ContentPlan::factory()->create(['website_id' => $website->id]);
        ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $website->id,
            'title' => 'How to choose a coffee table',
            'status' => ContentTopic::STATUS_WRITING,
            'scheduled_for' => now()->addDay(),
        ]);

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class)
            ->set('view', 'list')
            ->assertSee('How to choose a coffee table')
            ->assertSee(__('In progress'))
            // Never leak pipeline internals to clients.
            ->assertDontSee('scoring')
            ->assertDontSee('revising');
    }

    public function test_topic_actions_approve_skip_retry(): void
    {
        Queue::fake();
        [$user, $website] = $this->userWithWebsite();
        $plan = ContentPlan::factory()->create(['website_id' => $website->id]);

        $suggested = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $website->id, 'status' => ContentTopic::STATUS_SUGGESTED,
            'scheduled_for' => now()->addDay(),
        ]);
        $failed = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $website->id, 'status' => ContentTopic::STATUS_FAILED,
            'scheduled_for' => now()->addDays(2),
        ]);

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        $component = Livewire::test(ContentCalendar::class)
            ->call('approve', $suggested->id)
            ->call('retry', $failed->id);

        $this->assertSame(ContentTopic::STATUS_APPROVED, $suggested->fresh()->status);
        $this->assertSame(ContentTopic::STATUS_APPROVED, $failed->fresh()->status);
        Queue::assertPushed(ProduceContentArticleJob::class, 1);

        $component->call('skip', $suggested->id);
        $this->assertSame(ContentTopic::STATUS_SKIPPED, $suggested->fresh()->status);
    }

    public function test_reschedule_rejects_past_dates(): void
    {
        [$user, $website] = $this->userWithWebsite();
        $plan = ContentPlan::factory()->create(['website_id' => $website->id]);
        $topic = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $website->id,
            'status' => ContentTopic::STATUS_APPROVED,
            'scheduled_for' => now()->addDays(3),
        ]);

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class)
            ->call('reschedule', $topic->id, now()->subDay()->toDateString());

        $this->assertTrue($topic->fresh()->scheduled_for->isFuture());

        // Dropping onto TODAY (the current date) must work.
        Livewire::test(ContentCalendar::class)
            ->call('reschedule', $topic->id, now()->toDateString());

        $this->assertTrue($topic->fresh()->scheduled_for->isToday());

        // A SCHEDULED topic (shown to the client as "Approved") must also be
        // reschedulable — this was the status with no date control before.
        $scheduled = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $website->id,
            'status' => ContentTopic::STATUS_SCHEDULED,
            'scheduled_for' => now()->addDays(2),
        ]);
        Livewire::test(ContentCalendar::class)
            ->call('reschedule', $scheduled->id, now()->addDays(5)->toDateString());
        $this->assertSame(now()->addDays(5)->toDateString(), $scheduled->fresh()->scheduled_for->toDateString());
    }

    public function test_review_page_shows_article_and_approves(): void
    {
        [$user, $website] = $this->userWithWebsite();
        $plan = ContentPlan::factory()->create(['website_id' => $website->id]);
        $topic = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $website->id,
            'status' => ContentTopic::STATUS_READY,
        ]);
        ContentArticle::storeVersion($topic, [
            'h1' => 'A Great Article',
            'meta_title' => 'A Great Article',
            'meta_description' => 'Description here.',
            'slug' => 'a-great-article',
            'html' => '<h2>Section</h2><p>Body text.</p>',
            'word_count' => 500,
            'seo_score' => 90,
            'seo_issues' => [],
        ]);

        $this->actingAs($user)
            ->withSession(['current_website_id' => $website->id])
            ->get(route('content.review', $topic->id))
            ->assertOk()
            ->assertSee('A Great Article')
            ->assertSee(__('Content quality'));

        Livewire::actingAs($user)->test(ArticleReview::class, ['topicId' => $topic->id])
            ->call('approve');

        $this->assertSame(ContentTopic::STATUS_SCHEDULED, $topic->fresh()->status);
    }

    public function test_progress_overlay_persists_while_revising_with_a_draft(): void
    {
        [$user, $website] = $this->userWithWebsite();
        $plan = ContentPlan::factory()->create(['website_id' => $website->id]);
        // A draft already exists (the writer stored version 1) but the topic is
        // still REVISING — the user must keep seeing progress, not a half-baked
        // draft article view.
        $topic = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $website->id,
            'status' => ContentTopic::STATUS_REVISING,
        ]);
        ContentArticle::storeVersion($topic, [
            'h1' => 'Draft In Progress',
            'meta_title' => 'Draft In Progress',
            'meta_description' => 'Description here.',
            'slug' => 'draft-in-progress',
            'html' => '<h2>Section</h2><p>Body text.</p>',
            'word_count' => 500,
            'seo_score' => 70,
            'seo_issues' => [],
        ]);

        $this->actingAs($user)
            ->withSession(['current_website_id' => $website->id])
            ->get(route('content.review', $topic->id))
            ->assertOk()
            ->assertSee(__('Creating your article'))      // progress overlay is up
            ->assertSee(__('Optimizing for SEO & readability'))
            ->assertDontSee(__('Content quality'));         // NOT the finalized article panel
    }

    public function test_publish_now_dispatches_when_a_destination_is_connected(): void
    {
        Queue::fake();
        [$user, $website] = $this->userWithWebsite();
        $plan = ContentPlan::factory()->create(['website_id' => $website->id, 'status' => ContentPlan::STATUS_ACTIVE]);
        $topic = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $website->id, 'status' => ContentTopic::STATUS_SCHEDULED,
        ]);
        ContentArticle::storeVersion($topic, [
            'h1' => 'X', 'meta_title' => 'X', 'meta_description' => 'D', 'slug' => 'x',
            'html' => '<p>x</p>', 'word_count' => 100, 'seo_score' => 90, 'seo_issues' => [],
        ]);
        \App\Models\ContentIntegration::create([
            'website_id' => $website->id, 'platform' => 'webhook',
            'status' => \App\Models\ContentIntegration::STATUS_CONNECTED,
        ]);

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);
        Livewire::test(ContentCalendar::class)->call('publishNow', $topic->id);

        Queue::assertPushed(\App\Jobs\PublishContentArticleJob::class);
        // Flips to PUBLISHING immediately so the calendar shows in-progress.
        $this->assertSame(ContentTopic::STATUS_PUBLISHING, $topic->fresh()->status);
    }

    public function test_article_review_publish_now_dispatches_and_marks_publishing(): void
    {
        Queue::fake();
        [$user, $website] = $this->userWithWebsite();
        $plan = ContentPlan::factory()->create(['website_id' => $website->id, 'status' => ContentPlan::STATUS_ACTIVE]);
        $topic = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $website->id, 'status' => ContentTopic::STATUS_READY,
        ]);
        ContentArticle::storeVersion($topic, [
            'h1' => 'X', 'meta_title' => 'X', 'meta_description' => 'D', 'slug' => 'x',
            'html' => '<p>x</p>', 'word_count' => 100, 'seo_score' => 90, 'seo_issues' => [],
        ]);
        \App\Models\ContentIntegration::create([
            'website_id' => $website->id, 'platform' => 'webhook',
            'status' => \App\Models\ContentIntegration::STATUS_CONNECTED,
        ]);

        Livewire::actingAs($user)->test(ArticleReview::class, ['topicId' => $topic->id])
            ->call('publishNow');

        Queue::assertPushed(\App\Jobs\PublishContentArticleJob::class);
        $this->assertSame(ContentTopic::STATUS_PUBLISHING, $topic->fresh()->status);
    }

    public function test_publish_now_without_connection_flashes_error_and_does_not_dispatch(): void
    {
        Queue::fake();
        [$user, $website] = $this->userWithWebsite();
        $plan = ContentPlan::factory()->create(['website_id' => $website->id, 'status' => ContentPlan::STATUS_ACTIVE]);
        $topic = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $website->id, 'status' => ContentTopic::STATUS_SCHEDULED,
        ]);
        ContentArticle::storeVersion($topic, [
            'h1' => 'X', 'meta_title' => 'X', 'meta_description' => 'D', 'slug' => 'x',
            'html' => '<p>x</p>', 'word_count' => 100, 'seo_score' => 90, 'seo_issues' => [],
        ]);

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);
        Livewire::test(ContentCalendar::class)->call('publishNow', $topic->id);

        // No connected destination → must NOT publish (and the topic stays put).
        Queue::assertNotPushed(\App\Jobs\PublishContentArticleJob::class);
        $this->assertSame(ContentTopic::STATUS_SCHEDULED, $topic->fresh()->status);
    }

    public function test_review_page_is_tenant_scoped(): void
    {
        [$user] = $this->userWithWebsite();
        [$otherUser, $otherWebsite] = $this->userWithWebsite();
        $otherPlan = ContentPlan::factory()->create(['website_id' => $otherWebsite->id]);
        $otherTopic = ContentTopic::factory()->for($otherPlan, 'plan')->create([
            'website_id' => $otherWebsite->id,
        ]);

        $this->actingAs($user)
            ->get(route('content.review', $otherTopic->id))
            ->assertNotFound();
    }

    public function test_script_tags_are_stripped_from_preview(): void
    {
        [$user, $website] = $this->userWithWebsite();
        $plan = ContentPlan::factory()->create(['website_id' => $website->id]);
        $topic = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $website->id,
            'status' => ContentTopic::STATUS_READY,
        ]);
        ContentArticle::storeVersion($topic, [
            'h1' => 'Safe Article',
            'html' => '<p>Fine.</p><script>alert(1)</script><p onclick="evil()">Click</p>',
            'seo_issues' => [],
        ]);

        $this->actingAs($user)
            ->withSession(['current_website_id' => $website->id])
            ->get(route('content.review', $topic->id))
            ->assertOk()
            ->assertDontSee('alert(1)', false)
            ->assertDontSee('evil()', false);
    }
    public function test_write_now_dispatches_generation_and_opens_progress(): void
    {
        Queue::fake();
        [$user, $website] = $this->userWithWebsite();
        $plan = ContentPlan::factory()->create(['website_id' => $website->id, 'status' => ContentPlan::STATUS_ACTIVE]);
        $topic = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $website->id, 'status' => ContentTopic::STATUS_APPROVED, 'keyword_volume' => 4000,
        ]);
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class)
            ->call('writeNow', $topic->id)
            ->assertRedirect(route('content.review', $topic->id));

        Queue::assertPushed(\App\Jobs\ProduceContentArticleJob::class);
        $this->assertNotNull(\Illuminate\Support\Facades\Cache::get('content:gen-start:'.$topic->id));
    }

    public function test_fair_monthly_visits_is_a_conservative_band(): void
    {
        $t = new ContentTopic(['keyword_volume' => 10000]);
        $band = ContentCalendar::fairMonthlyVisits($t);
        $this->assertNotNull($band);
        // Well below the ~28% a #1 ranking gets — reads as achievable.
        $this->assertSame(150, $band['low']);   // 1.5%
        $this->assertSame(500, $band['high']);  // 5%
        $this->assertNull(ContentCalendar::fairMonthlyVisits(new ContentTopic(['keyword_volume' => 0])));
    }

}
