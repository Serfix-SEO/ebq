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

    public function test_settings_page_reopens_wizard_for_active_plan_without_demoting_it(): void
    {
        [$user, $website] = $this->userWithWebsite();
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
            'business_description' => 'We sell handmade wooden furniture for small apartments.',
        ]);

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        $this->get('/content/settings')
            ->assertOk()
            ->assertSee(__('Tell us about your business'));

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('businessDescription', 'We sell handmade wooden furniture for small apartments, updated.')
            ->call('toOfferings')
            ->call('toHowItWorks')
            ->assertHasNoErrors();

        $this->assertSame(ContentPlan::STATUS_ACTIVE, $plan->fresh()->status);
        $this->assertSame(
            'We sell handmade wooden furniture for small apartments, updated.',
            $plan->fresh()->business_description
        );
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
}
