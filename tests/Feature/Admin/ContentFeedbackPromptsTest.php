<?php

namespace Tests\Feature\Admin;

use App\Models\ContentArticleFeedback;
use App\Models\ContentPlan;
use App\Models\ContentRewriteRequest;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin must see the instruction a client actually sent, not just their
 * verdict (owner 2026-09-13: "we can see what the customer did but we can't
 * see the prompt he typed for rewrite").
 *
 * Two ways the feedback comment alone lies about what was asked:
 *  - the client accepts the enhancer's sharpened wording, so the prompt that
 *    reached the writer is NOT the note they typed;
 *  - a second rewrite overwrites the first note (feedback is one row per
 *    topic+user), while every prompt survives on the rewrite requests.
 */
class ContentFeedbackPromptsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: ContentTopic, 2: User} [client, topic, admin] */
    private function scenario(): array
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create();
        $website = Website::factory()->for($client)->create();
        $plan = ContentPlan::factory()->create(['website_id' => $website->id]);
        $topic = ContentTopic::factory()->create([
            'plan_id' => $plan->id,
            'website_id' => $website->id,
            'title' => 'A Topic The Client Disliked',
        ]);

        return [$client, $topic, $admin];
    }

    private function feedback(User $client, ContentTopic $topic, ?string $comment): ContentArticleFeedback
    {
        return ContentArticleFeedback::create([
            'topic_id' => $topic->id,
            'user_id' => $client->id,
            'website_id' => $topic->website_id,
            'rating' => ContentArticleFeedback::RATING_REWRITES,
            'comment' => $comment,
        ]);
    }

    private function rewrite(User $client, ContentTopic $topic, ?string $prompt, string $status = 'done'): ContentRewriteRequest
    {
        return ContentRewriteRequest::create([
            'topic_id' => $topic->id,
            'user_id' => $client->id,
            'website_id' => $topic->website_id,
            'prompt' => $prompt,
            'status' => $status,
            'prior_status' => 'ready',
        ]);
    }

    public function test_the_feedback_page_shows_the_prompt_that_actually_ran(): void
    {
        [$client, $topic, $admin] = $this->scenario();
        $this->feedback($client, $topic, 'make it about our own product pls');
        $this->rewrite($client, $topic, 'Rewrite the article to focus on the benefits of Content Autopilot.');

        $this->actingAs($admin)
            ->get(route('admin.content-feedback.index'))
            ->assertOk()
            ->assertSee('make it about our own product pls')                                   // their note
            ->assertSee('Rewrite the article to focus on the benefits of Content Autopilot.')   // what ran
            ->assertSee('sharpened');   // flagged, because the two differ
    }

    public function test_every_rewrite_is_listed_not_only_the_latest_note(): void
    {
        [$client, $topic, $admin] = $this->scenario();
        // The note keeps only the latest wording; both prompts must survive.
        $this->feedback($client, $topic, 'and at least 100 of them');
        $this->rewrite($client, $topic, 'Add name examples in fancy text.');
        $this->rewrite($client, $topic, 'Add at least 100 fancy-text name examples.');

        $this->actingAs($admin)
            ->get(route('admin.content-feedback.index'))
            ->assertOk()
            ->assertSee('Add name examples in fancy text.')
            ->assertSee('Add at least 100 fancy-text name examples.')
            ->assertSee('Rewrite #1')
            ->assertSee('Rewrite #2');
    }

    public function test_an_unchanged_prompt_is_not_flagged_as_sharpened(): void
    {
        [$client, $topic, $admin] = $this->scenario();
        $this->feedback($client, $topic, 'Add more examples.');
        $this->rewrite($client, $topic, 'Add more examples.');

        $this->actingAs($admin)
            ->get(route('admin.content-feedback.index'))
            ->assertOk()
            ->assertDontSee('sharpened');
    }

    public function test_a_rewrite_with_no_instruction_reads_as_a_quality_pass(): void
    {
        [$client, $topic, $admin] = $this->scenario();
        $this->feedback($client, $topic, null);
        $this->rewrite($client, $topic, null);

        $this->actingAs($admin)
            ->get(route('admin.content-feedback.index'))
            ->assertOk()
            ->assertSee('No instruction — a general quality pass.');
    }

    public function test_a_failed_rewrite_shows_its_status(): void
    {
        [$client, $topic, $admin] = $this->scenario();
        $this->feedback($client, $topic, 'fix the intro');
        $this->rewrite($client, $topic, 'Fix the introduction.', 'failed');

        $this->actingAs($admin)
            ->get(route('admin.content-feedback.index'))
            ->assertOk()
            ->assertSee('failed');
    }

    public function test_the_dashboard_queue_shows_the_prompt_too(): void
    {
        [$client, $topic, $admin] = $this->scenario();
        $this->feedback($client, $topic, 'too generic');
        $this->rewrite($client, $topic, 'Ground the piece in our own case study.');

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Rewrite asked:')
            ->assertSee('Ground the piece in our own case study.');
    }

    public function test_one_clients_prompt_never_leaks_onto_another_clients_verdict(): void
    {
        [$clientA, $topicA, $admin] = $this->scenario();
        $this->feedback($clientA, $topicA, 'client A note');

        $clientB = User::factory()->create();
        $websiteB = Website::factory()->for($clientB)->create();
        $planB = ContentPlan::factory()->create(['website_id' => $websiteB->id]);
        $topicB = ContentTopic::factory()->create(['plan_id' => $planB->id, 'website_id' => $websiteB->id]);
        $this->feedback($clientB, $topicB, 'client B note');
        $this->rewrite($clientB, $topicB, 'Only client B asked for this.');

        $html = $this->actingAs($admin)->get(route('admin.content-feedback.index'))->assertOk()->getContent();

        // The pair lookup is topic+user; a shared topic id or user id alone
        // must not pull another client's instruction into this row.
        $this->assertSame(1, substr_count($html, 'Only client B asked for this.'));
    }
}
