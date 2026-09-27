<?php

namespace Tests\Feature\Aeo;

use App\Livewire\Content\AiVisibility;
use App\Models\ContentAeoAudit;
use App\Models\ContentAeoQuestion;
use App\Models\ContentAeoRun;
use App\Models\ContentAeoScore;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\Aeo\AeoVisibilityScorer;
use App\Services\Llm\LlmClient;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The single number, and the rule that governs it: a signal we cannot see is
 * dropped from the weighting, never scored as zero. Telling a client they are
 * failing at something we have no visibility of is the same dishonesty as
 * inventing the figure.
 */
class AeoVisibilityScoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function site(array $attrs = []): Website
    {
        $user = User::factory()->create(['content_comp_sites' => 1]);
        $website = Website::factory()->for($user)->create($attrs + [
            'domain' => 'bellavest.ae', 'normalized_domain' => 'bellavest.ae',
        ]);
        ContentPlan::factory()->create([
            'website_id' => $website->id, 'status' => ContentPlan::STATUS_ACTIVE,
            'billing_covered_at' => now(),
        ]);
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        return $website;
    }

    private function audit(Website $website, int $score = 80): void
    {
        ContentAeoAudit::create([
            'website_id' => $website->id, 'checked_at' => now(),
            'robots_ai' => ['gptbot' => 'allowed'], 'robots_fetched' => true,
            'llms_txt_present' => true, 'readiness_score' => $score, 'breakdown' => [],
        ]);
    }

    private function runs(Website $website, int $mentioned, int $missed, ?int $rank = 2): void
    {
        $i = 0;
        foreach (array_merge(array_fill(0, $mentioned, true), array_fill(0, $missed, false)) as $hit) {
            $q = ContentAeoQuestion::create([
                'website_id' => $website->id,
                'question' => 'Question '.$i,
                'normalized_question' => 'question '.$i++,
            ]);
            ContentAeoRun::create([
                'question_id' => $q->id, 'website_id' => $website->id,
                'ran_on' => now()->subDays(2)->toDateString(), 'engine' => ContentAeoRun::ENGINE_DEEPSEEK,
                'mentioned' => $hit, 'mention_rank' => $hit ? $rank : null,
                'competitors' => $hit ? [] : ['Ajmal', 'Rasasi'], 'ok' => true,
            ]);
        }
    }

    public function test_a_signal_we_cannot_see_is_dropped_not_scored_as_zero(): void
    {
        $website = $this->site(['ga_property_id' => '']);   // no analytics
        $this->audit($website, 100);
        $this->runs($website, mentioned: 4, missed: 0);

        $result = app(AeoVisibilityScorer::class)->score($website);

        $this->assertNotContains('ai_referrals', $result['signals_used']);
        $this->assertContains('ai_referrals', $result['missing']);
        // Perfect on both visible signals — a dropped signal must not drag it down.
        $this->assertSame(100, $result['score']);
    }

    public function test_being_named_near_the_top_counts_for_more_than_being_buried(): void
    {
        $top = $this->site();
        $this->audit($top);
        $this->runs($top, mentioned: 4, missed: 0, rank: 1);
        $topScore = app(AeoVisibilityScorer::class)->score($top)['components']['brand_recall']['value'];

        $buried = $this->site(['domain' => 'other.ae', 'normalized_domain' => 'other.ae']);
        $this->audit($buried);
        $this->runs($buried, mentioned: 4, missed: 0, rank: 7);
        $buriedScore = app(AeoVisibilityScorer::class)->score($buried)['components']['brand_recall']['value'];

        $this->assertGreaterThan($buriedScore, $topScore, 'eighth in a list is not a recommendation');
    }

    public function test_failed_probes_are_excluded_rather_than_counted_as_misses(): void
    {
        $website = $this->site();
        $this->audit($website);
        $this->runs($website, mentioned: 2, missed: 0);

        // Two probes that never got an answer.
        foreach ([1, 2] as $i) {
            $q = ContentAeoQuestion::create([
                'website_id' => $website->id, 'question' => 'Broken '.$i, 'normalized_question' => 'broken '.$i,
            ]);
            ContentAeoRun::create([
                'question_id' => $q->id, 'website_id' => $website->id,
                'ran_on' => now()->toDateString(), 'engine' => ContentAeoRun::ENGINE_DEEPSEEK,
                'mentioned' => false, 'ok' => false, 'error' => 'provider down',
            ]);
        }

        $recall = app(AeoVisibilityScorer::class)->score($website)['components']['brand_recall'];

        // 2 of 2 answered, not 2 of 4 — a provider outage is not a visibility drop.
        $this->assertSame(1.0, $recall['value']);
        $this->assertStringContainsString('2 of 2', $recall['detail']);
    }

    public function test_nothing_measurable_records_no_score_rather_than_a_zero(): void
    {
        $website = $this->site(['ga_property_id' => '']);

        $this->assertNull(app(AeoVisibilityScorer::class)->record($website));
        $this->assertSame(0, ContentAeoScore::count());
    }

    public function test_a_question_no_engine_answered_with_the_client_becomes_a_gap(): void
    {
        $website = $this->site();
        $this->runs($website, mentioned: 1, missed: 2);

        $gaps = app(AeoVisibilityScorer::class)->gaps($website);

        $this->assertCount(2, $gaps);
        $this->assertSame(['Ajmal', 'Rasasi'], $gaps[0]['competitors']);
    }

    public function test_a_gap_becomes_an_article_through_the_normal_composer(): void
    {
        $website = $this->site();
        $this->runs($website, mentioned: 0, missed: 1);

        // The composer's own LLM call, stubbed — the point is that the gap
        // goes through it rather than creating a topic by a second path.
        $this->app->instance(LlmClient::class, new class implements LlmClient
        {
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
                return ['topics' => [[
                    'title' => 'Which Oud Lasts Longest In Humidity',
                    'target_keyword' => 'oud humidity longevity',
                ]]];
            }
        });

        $questionId = ContentAeoQuestion::where('website_id', $website->id)->value('id');

        Livewire::test(AiVisibility::class)
            ->call('answerQuestion', $questionId)
            ->assertSee(__('Added ":title" to your calendar.', ['title' => 'Which Oud Lasts Longest In Humidity']));

        $topic = ContentTopic::where('website_id', $website->id)->first();
        $this->assertNotNull($topic);
        $this->assertSame('oud humidity longevity', $topic->target_keyword);
        // Linked back, so the page stops offering to write it twice.
        $this->assertSame($topic->id, ContentAeoQuestion::find($questionId)->topic_id);
    }

    public function test_a_free_signup_cannot_spend_our_money_on_answering_gaps(): void
    {
        $user = User::factory()->create([
            'content_trial_started_at' => now(), 'content_trial_ends_at' => now()->addDays(5),
        ]);
        $website = Website::factory()->for($user)->create();
        ContentPlan::factory()->create([
            'website_id' => $website->id, 'status' => ContentPlan::STATUS_ACTIVE, 'billing_covered_at' => now(),
        ]);
        $question = ContentAeoQuestion::create([
            'website_id' => $website->id, 'question' => 'Best oud', 'normalized_question' => 'best oud',
        ]);
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(AiVisibility::class)->call('answerQuestion', $question->id);

        $this->assertSame(0, ContentTopic::where('website_id', $website->id)->count());
    }

    public function test_the_page_shows_the_score_and_the_gaps(): void
    {
        $website = $this->site();
        $this->audit($website);
        $this->runs($website, mentioned: 1, missed: 1);
        ContentAeoScore::create([
            'website_id' => $website->id, 'scored_on' => now()->subWeek()->toDateString(),
            'score' => 40, 'components' => [], 'signals_used' => ['brand_recall'],
        ]);
        ContentAeoScore::create([
            'website_id' => $website->id, 'scored_on' => now()->toDateString(),
            'score' => 55, 'components' => [], 'signals_used' => ['brand_recall'],
        ]);

        Livewire::test(AiVisibility::class)
            ->assertSee(__('AI visibility'))
            ->assertSee(__('Questions where someone else gets recommended'))
            ->assertSee('Ajmal')
            ->assertSee(__('Write the answer'));
    }
}
