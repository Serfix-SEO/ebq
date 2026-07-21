<?php

namespace Tests\Feature\Content;

use App\Jobs\PlanContentTopicsJob;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentTopicPlanner;
use App\Services\Llm\LlmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The planner's ONLY defence against off-topic articles is the business
 * profile: the ideation prompt interpolates description / sell / don't-sell,
 * and filterRelevant() vets candidates against the same. Both degrade to
 * nothing when the profile is empty — the prompt still says "never write about
 * what they do not offer" while naming no exclusions, and the relevance gate
 * fails open. Prod 2026-07-20 produced 31 GSC-derived topics for a business the
 * system had no description of. These tests pin both guardrails.
 */
class ContentTopicPlannerGuardrailsTest extends TestCase
{
    use RefreshDatabase;

    private function planFor(?string $description): ContentPlan
    {
        $website = Website::factory()->for(User::factory())->create();

        return ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
            'billing_covered_at' => now(),
            'business_description' => $description,
        ]);
    }

    /**
     * The job resolves the planner via app(..., ['llm' => ...]); a container
     * `instance()` is bypassed whenever make() is given parameters, so the job's
     * own log line — not a spy — is the observable signal of which path ran.
     */
    public function test_no_ideation_without_a_business_profile(): void
    {
        Log::spy();
        $plan = $this->planFor(null);

        (new PlanContentTopicsJob($plan->id))->handle();

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($msg) => $msg === 'content_autopilot.topics_skipped_no_profile')
            ->once();
        Log::shouldNotHaveReceived('info', ['content_autopilot.topics_planned']);
        $this->assertSame(0, ContentTopic::query()->where('plan_id', $plan->id)->count());
    }

    public function test_ideation_proceeds_once_the_profile_is_filled(): void
    {
        Log::spy();
        $plan = $this->planFor('We restore vintage mechanical watches and sell serviced classics.');

        (new PlanContentTopicsJob($plan->id))->handle();

        Log::shouldNotHaveReceived('info', ['content_autopilot.topics_skipped_no_profile']);
        Log::shouldHaveReceived('info')
            ->withArgs(fn ($msg) => $msg === 'content_autopilot.topics_planned')
            ->once();
    }

    /** An LlmClient that records the user prompt it was handed. */
    private function recordingLlm(): LlmClient
    {
        return new class implements LlmClient
        {
            /** @var list<string> */
            public array $prompts = [];

            public function isAvailable(): bool
            {
                return true;
            }

            public function complete(array $messages, array $options = []): array
            {
                return ['ok' => true, 'content' => '', 'model' => 'fake',
                    'usage' => ['prompt' => 0, 'completion' => 0, 'total' => 0]];
            }

            public function completeJson(array $messages, array $options = []): ?array
            {
                $this->prompts[] = (string) ($messages[1]['content'] ?? '');

                return ['relevant' => ['watch servicing']];
            }

            public function completeWithTools(array $messages, array $tools, callable $dispatcher, array $options = []): array
            {
                return ['ok' => true, 'decoded' => null, 'content' => '', 'model' => 'fake',
                    'usage' => ['prompt' => 0, 'completion' => 0, 'total' => 0], 'tool_calls' => []];
            }
        };
    }

    /** Run the private relevance gate directly — it is the unit under test. */
    private function runRelevanceGate(ContentTopicPlanner $planner, ContentPlan $plan): void
    {
        $method = (new \ReflectionClass(ContentTopicPlanner::class))->getMethod('filterRelevant');
        $method->setAccessible(true);
        $method->invoke($planner, [
            ['target_keyword' => 'watch servicing'],
            ['target_keyword' => 'smartwatch repair'],
        ], $plan);
    }

    /**
     * The don't-sell list used to reach the ideation prompt only — the relevance
     * gate never saw it, so a candidate that drifted onto an explicit exclusion
     * had no second net. It must now be stated to the filter too.
     */
    public function test_relevance_gate_is_told_what_the_business_does_not_offer(): void
    {
        $llm = $this->recordingLlm();
        $plan = $this->planFor('We restore vintage mechanical watches.');
        $plan->offerings = ['sell' => ['Watch servicing'], 'dont_sell' => ['Smartwatch repair']];

        $this->runRelevanceGate(new ContentTopicPlanner($llm), $plan);

        $this->assertNotEmpty($llm->prompts, 'the relevance gate issued an LLM call');
        $this->assertStringContainsString(
            'Smartwatch repair',
            $llm->prompts[0],
            "the don't-sell list must reach the relevance gate, not just ideation"
        );
    }

    /** With nothing excluded, no hollow "does NOT offer:" line is sent. */
    public function test_no_hollow_exclusion_line_when_the_dont_sell_list_is_empty(): void
    {
        $llm = $this->recordingLlm();
        $plan = $this->planFor('We restore vintage mechanical watches.');
        $plan->offerings = ['sell' => ['Watch servicing'], 'dont_sell' => []];

        $this->runRelevanceGate(new ContentTopicPlanner($llm), $plan);

        $this->assertStringNotContainsString('does NOT offer', $llm->prompts[0]);
    }
}
