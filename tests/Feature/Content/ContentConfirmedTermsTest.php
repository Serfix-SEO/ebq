<?php

namespace Tests\Feature\Content;

use App\Jobs\PlanContentTopicsJob;
use App\Livewire\Content\ContentCalendar;
use App\Models\ContentPlan;
use App\Models\ContentPlanKeyword;
use App\Models\ContentTopic;
use App\Models\KeywordApiRequest;
use App\Models\KeywordMetric;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentKeywordInsights;
use App\Services\Content\ContentTopicPlanner;
use App\Services\Llm\LlmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase C of the offer spine: the terms a client keeps on the wizard's
 * keyword step become articles 1:1, deterministically.
 */
class ContentConfirmedTermsTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPlan(array $planAttrs = []): array
    {
        $user = User::factory()->create([
            'content_trial_started_at' => now(), 'content_trial_ends_at' => now()->addDays(5),
        ]);
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create(array_merge([
            'website_id' => $website->id, 'status' => ContentPlan::STATUS_DRAFT,
            'business_description' => 'Luxury gourmand perfumes you can layer.',
            'offerings' => ['sell' => ['Vanilla eau de parfum'], 'dont_sell' => []],
        ], $planAttrs));

        return [$user, $website, $plan];
    }

    private function storeCompletedRequest(ContentPlan $plan, array $results): void
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
    }

    private function confirmTerm(ContentPlan $plan, string $keyword, ?int $volume = 300, string $intent = 'commercial'): ContentPlanKeyword
    {
        return ContentPlanKeyword::query()->create([
            'plan_id' => $plan->id,
            'keyword_hash' => KeywordMetric::hashKeyword($keyword),
            'keyword' => $keyword,
            'type' => ContentPlanKeyword::TYPE_CONFIRMED,
            'country' => 'global',
            'search_volume' => $volume,
            'search_intent' => $intent,
        ]);
    }

    /** A planner whose LLM is unavailable — confirmed terms must not need one. */
    private function plannerWithoutLlm(): ContentTopicPlanner
    {
        $llm = new class implements LlmClient
        {
            public function isAvailable(): bool
            {
                return false;
            }

            public function completeJson(array $messages, array $options = []): ?array
            {
                return null;
            }

            public function complete(array $messages, array $options = []): array
            {
                return [];
            }

            public function completeWithTools(array $messages, array $tools, callable $dispatcher, array $options = []): array
            {
                return [];
            }
        };

        return new ContentTopicPlanner($llm);
    }

    public function test_confirmed_terms_materialize_one_topic_each_without_an_llm(): void
    {
        [, , $plan] = $this->userWithPlan();
        $this->confirmTerm($plan, 'sweet vanilla eau de parfum', 400, 'commercial');
        $this->confirmTerm($plan, 'how to choose a signature scent', 250, 'informational');

        $created = $this->plannerWithoutLlm()->plan($plan->fresh());

        $this->assertCount(2, $created);
        $topics = $plan->topics()->orderBy('position')->get();
        $this->assertSame(['sweet vanilla eau de parfum', 'how to choose a signature scent'],
            $topics->pluck('target_keyword')->all());
        $this->assertSame(['confirmed', 'confirmed'], $topics->pluck('source')->all());
        $this->assertSame(400, (int) $topics[0]->keyword_volume);
        // Question-shaped terms title-case as-is; others get an intent suffix.
        $this->assertSame('How To Choose A Signature Scent', $topics[1]->title);
        $this->assertStringContainsString('Sweet Vanilla Eau De Parfum:', $topics[0]->title);
    }

    public function test_materialization_is_idempotent_across_planner_runs(): void
    {
        [, , $plan] = $this->userWithPlan();
        $this->confirmTerm($plan, 'sweet vanilla eau de parfum');

        $planner = $this->plannerWithoutLlm();
        $planner->plan($plan->fresh());
        // Simulate the topic having shipped — the term must NOT come back.
        $plan->topics()->update(['status' => ContentTopic::STATUS_PUBLISHED]);
        $again = $planner->plan($plan->fresh());

        $this->assertSame([], $again);
        $this->assertSame(1, $plan->topics()->count());
    }

    public function test_confirmed_topic_supersedes_the_farthest_llm_filler_when_the_pool_is_full(): void
    {
        [$user, $website, $plan] = $this->userWithPlan();
        \App\Models\Setting::set('content.limits.monthly_articles_per_website', 3);
        foreach ([1, 2, 3] as $i) {
            ContentTopic::factory()->for($plan, 'plan')->create([
                'website_id' => $website->id,
                'title' => "Filler article {$i}",
                'target_keyword' => "filler keyword {$i}",
                'source' => 'llm',
                'status' => ContentTopic::STATUS_APPROVED,
                'scheduled_for' => now()->addDays($i)->toDateString(),
                'position' => $i,
            ]);
        }
        $this->confirmTerm($plan, 'sweet vanilla eau de parfum');

        $created = $this->plannerWithoutLlm()->plan($plan->fresh());

        $this->assertCount(1, $created);
        // The farthest-out filler was bumped and its slot taken over.
        $bumped = $plan->topics()->where('target_keyword', 'filler keyword 3')->first();
        $this->assertSame(ContentTopic::STATUS_SKIPPED, $bumped->status);
        $this->assertSame($bumped->scheduled_for?->toDateString(), $created[0]->scheduled_for?->toDateString());
        // Pool size unchanged: 3 active (2 fillers + 1 confirmed).
        $this->assertSame(3, $plan->topics()
            ->whereNotIn('status', [ContentTopic::STATUS_PUBLISHED, ContentTopic::STATUS_SKIPPED])->count());
    }

    public function test_wizard_continue_persists_kept_terms_and_skips_crossed_out_ones(): void
    {
        Queue::fake();
        [$user, $website, $plan] = $this->userWithPlan(['site_type' => 'brand']);
        $this->storeCompletedRequest($plan, [
            ['keyword' => 'sweet vanilla parfum', 'avgMonthlySearches' => 400, 'competitionIndex' => 20],
            ['keyword' => 'warm gourmand fragrance', 'avgMonthlySearches' => 300, 'competitionIndex' => 15],
        ]);

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('wizardStep', 6)
            ->call('toggleTerm', 'warm gourmand fragrance')
            ->call('toFirstArticles')
            ->assertSet('wizardStep', 7);

        $stored = ContentPlanKeyword::query()->where('plan_id', $plan->id)
            ->where('type', ContentPlanKeyword::TYPE_CONFIRMED)->pluck('keyword')->all();
        // Server term kept + offer-spine candidates (also confirmable now);
        // the crossed-out term is the one thing that must NOT persist.
        $this->assertContains('sweet vanilla parfum', $stored);
        $this->assertNotContains('warm gourmand fragrance', $stored);
        Queue::assertPushed(PlanContentTopicsJob::class);
    }

    public function test_pending_research_never_blocks_the_step_transition(): void
    {
        Queue::fake();
        [$user, $website, $plan] = $this->userWithPlan();

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('wizardStep', 6)
            ->call('toFirstArticles')
            ->assertSet('wizardStep', 7);

        $this->assertSame(0, ContentPlanKeyword::query()->where('plan_id', $plan->id)->count());
    }

    public function test_confirm_terms_flips_a_gap_row_and_survives_reclassification_semantics(): void
    {
        [, , $plan] = $this->userWithPlan();
        // The same keyword already classified as gap — confirming must flip it
        // (unique plan+hash), not crash.
        ContentPlanKeyword::query()->create([
            'plan_id' => $plan->id,
            'keyword_hash' => KeywordMetric::hashKeyword('sweet vanilla parfum'),
            'keyword' => 'sweet vanilla parfum',
            'type' => ContentPlanKeyword::TYPE_GAP,
            'country' => 'global',
        ]);
        $this->storeCompletedRequest($plan, [
            ['keyword' => 'sweet vanilla parfum', 'avgMonthlySearches' => 400, 'competitionIndex' => 20],
        ]);

        $count = app(ContentKeywordInsights::class)->confirmTerms($plan, []);

        // ≥1: offer-spine candidates confirm alongside the flipped gap row.
        $this->assertGreaterThanOrEqual(1, $count);
        $row = ContentPlanKeyword::query()->where('plan_id', $plan->id)
            ->where('keyword', 'sweet vanilla parfum')->sole();
        $this->assertSame(ContentPlanKeyword::TYPE_CONFIRMED, $row->type);
    }
}
