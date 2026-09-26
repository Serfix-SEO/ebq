<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\ContentCalendar;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\TopicComposer;
use App\Services\Llm\LlmClient;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The calendar side of client-authored topics: typing an idea, picking one of
 * the suggestions, and swapping it in for a planned article.
 */
class ClientTopicComposerFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        Queue::fake();
        $this->stubLlm();
    }

    /** @return array{User, Website, ContentPlan} */
    private function fixture(): array
    {
        $user = User::factory()->create([
            'content_trial_started_at' => now(),
            'content_trial_ends_at' => now()->addDays(5),
        ]);
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
            'articles_per_week' => 7,
            'keywords_classified_at' => now(),
        ]);
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        return [$user, $website, $plan];
    }

    private function stubLlm(array $topics = [['title' => 'Choosing a Summer Perfume', 'target_keyword' => 'summer perfume guide']]): void
    {
        $this->app->instance(LlmClient::class, new class($topics) implements LlmClient
        {
            public function __construct(private array $topics) {}

            public function isAvailable(): bool
            {
                return true;
            }

            public function complete(array $messages, array $options = []): array
            {
                return ['ok' => true, 'content' => '', 'model' => 'stub', 'usage' => ['prompt' => 0, 'completion' => 0, 'total' => 0]];
            }

            public function completeWithTools(array $messages, array $tools, callable $dispatcher, array $options = []): array
            {
                return $this->complete($messages, $options);
            }

            public function completeJson(array $messages, array $options = []): ?array
            {
                return ['topics' => $this->topics];
            }
        });
    }

    public function test_a_client_adds_their_own_topic_from_an_idea(): void
    {
        [, , $plan] = $this->fixture();

        Livewire::test(ContentCalendar::class, ['mode' => 'calendar'])
            ->call('openComposer')
            ->assertSet('composerOpen', true)
            ->set('composerIdea', 'perfume for hot weather')
            ->call('suggestTopics')
            ->assertCount('composerSuggestions', 1)
            ->call('useSuggestion', 0)
            ->assertSet('composerOpen', false);

        $topic = $plan->topics()->first();
        $this->assertNotNull($topic);
        $this->assertSame('summer perfume guide', $topic->target_keyword);
        $this->assertSame(TopicComposer::SOURCE, $topic->source);
        $this->assertNotNull($topic->scheduled_for);
    }

    public function test_swapping_a_planned_article_keeps_its_publish_date(): void
    {
        [, $website, $plan] = $this->fixture();
        $planned = ContentTopic::create([
            'plan_id' => $plan->id, 'website_id' => $website->id,
            'title' => 'A Topic They Do Not Want', 'target_keyword' => 'unwanted',
            'status' => ContentTopic::STATUS_APPROVED,
            'scheduled_for' => now()->addDays(3)->toDateString(),
        ]);

        Livewire::test(ContentCalendar::class, ['mode' => 'calendar'])
            ->call('openComposer', $planned->id)
            ->assertSet('composerReplacing', $planned->id)
            ->set('composerIdea', 'perfume for hot weather')
            ->call('suggestTopics')
            ->call('useSuggestion', 0)
            ->assertSet('composerOpen', false);

        $new = $plan->topics()->where('target_keyword', 'summer perfume guide')->first();
        $this->assertNotNull($new);
        $this->assertSame($planned->scheduled_for->toDateString(), $new->scheduled_for->toDateString());
        $this->assertSame(ContentTopic::STATUS_SKIPPED, $planned->fresh()->status);
    }

    public function test_an_article_that_is_already_written_cannot_be_swapped(): void
    {
        [, $website, $plan] = $this->fixture();
        $ready = ContentTopic::create([
            'plan_id' => $plan->id, 'website_id' => $website->id,
            'title' => 'Already Written', 'target_keyword' => 'already written',
            'status' => ContentTopic::STATUS_READY,
            'scheduled_for' => now()->addDay()->toDateString(),
        ]);

        Livewire::test(ContentCalendar::class, ['mode' => 'calendar'])
            ->call('openComposer', $ready->id)
            ->assertSet('composerOpen', false);

        $this->assertSame(ContentTopic::STATUS_READY, $ready->fresh()->status);
    }

    public function test_another_tenants_topic_cannot_be_replaced(): void
    {
        [, , $plan] = $this->fixture();
        $otherWebsite = Website::factory()->for(User::factory())->create();
        $otherPlan = ContentPlan::factory()->create([
            'website_id' => $otherWebsite->id, 'status' => ContentPlan::STATUS_ACTIVE,
        ]);
        $theirs = ContentTopic::create([
            'plan_id' => $otherPlan->id, 'website_id' => $otherWebsite->id,
            'title' => 'Their Article', 'target_keyword' => 'their keyword',
            'status' => ContentTopic::STATUS_APPROVED,
            'scheduled_for' => now()->addDay()->toDateString(),
        ]);

        Livewire::test(ContentCalendar::class, ['mode' => 'calendar'])
            ->call('openComposer', $theirs->id)
            ->assertSet('composerOpen', false);

        $this->assertSame(ContentTopic::STATUS_APPROVED, $theirs->fresh()->status);
        $this->assertSame(0, $plan->topics()->count());
    }

    public function test_a_refusal_is_explained_in_plain_words(): void
    {
        $this->fixture();

        Livewire::test(ContentCalendar::class, ['mode' => 'calendar'])
            ->call('openComposer')
            ->set('composerIdea', 'perfume')
            ->call('suggestTopics')
            ->call('$refresh');

        // Internal reason codes must never reach the client.
        $notice = ContentCalendar::composerNoticeFor('not_in_catalog');
        $this->assertStringNotContainsString('catalog_', $notice);
        $this->assertStringNotContainsString('_', $notice);
    }

    public function test_the_calendar_offers_the_composer(): void
    {
        [, $website, $plan] = $this->fixture();
        ContentTopic::create([
            'plan_id' => $plan->id, 'website_id' => $website->id,
            'title' => 'Planned One', 'target_keyword' => 'planned one',
            'status' => ContentTopic::STATUS_APPROVED,
            'scheduled_for' => now()->toDateString(),
        ]);

        Livewire::test(ContentCalendar::class, ['mode' => 'calendar'])
            ->assertSee(__('Add your own topic'))
            ->set('view', 'list')
            ->assertSee(__('Write something else'));
    }
}
