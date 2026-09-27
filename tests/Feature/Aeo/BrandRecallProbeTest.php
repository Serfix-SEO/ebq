<?php

namespace Tests\Feature\Aeo;

use App\Models\ContentAeoQuestion;
use App\Models\ContentAeoRun;
use App\Models\ContentPlan;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\Aeo\BrandRecallProbe;
use App\Services\Llm\LlmClient;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Does the model name you when a buyer asks?"
 *
 * The rule under test throughout: the MODEL never decides whether the client
 * was mentioned. It returns a list of businesses; deterministic PHP matches it.
 * Asking an LLM "were you mentioned?" invites an agreeable answer, and an
 * agreeable answer here flatters the client and the product at once.
 */
class BrandRecallProbeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    /** @return array{Website, ContentAeoQuestion} */
    private function fixture(array $planAttrs = []): array
    {
        $website = Website::factory()->for(User::factory())->create([
            'domain' => 'bellavest.ae', 'normalized_domain' => 'bellavest.ae',
        ]);
        ContentPlan::factory()->create(['website_id' => $website->id, 'country' => 'ae'] + $planAttrs);
        $question = ContentAeoQuestion::create([
            'website_id' => $website->id,
            'question' => 'Best oud perfume in Dubai',
            'normalized_question' => ContentAeoQuestion::normalize('Best oud perfume in Dubai'),
        ]);

        return [$website, $question];
    }

    private function stubLlm(?array $payload): void
    {
        $this->app->instance(LlmClient::class, new class($payload) implements LlmClient
        {
            public function __construct(private ?array $payload) {}

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
                return $this->payload;
            }
        });
    }

    public function test_being_named_is_recorded_with_its_position(): void
    {
        [$website, $question] = $this->fixture();
        $this->stubLlm(['brands' => ['Ajmal', 'Bellavest', 'Rasasi'], 'summary' => 'Three good options.']);

        $run = app(BrandRecallProbe::class)->run($question, $website);

        $this->assertTrue($run->ok);
        $this->assertTrue($run->mentioned);
        $this->assertSame(2, $run->mention_rank);
        // Everyone else, in order — the part the client acts on.
        $this->assertSame(['Ajmal', 'Rasasi'], $run->competitors);
        $this->assertSame('Three good options.', $run->excerpt);
    }

    public function test_not_being_named_is_recorded_as_a_miss_not_a_failure(): void
    {
        [$website, $question] = $this->fixture();
        $this->stubLlm(['brands' => ['Ajmal', 'Swiss Arabian'], 'summary' => 'Try these.']);

        $run = app(BrandRecallProbe::class)->run($question, $website);

        $this->assertTrue($run->ok, 'the probe worked — the client simply was not named');
        $this->assertFalse($run->mentioned);
        $this->assertNull($run->mention_rank);
    }

    public function test_a_provider_failure_is_recorded_as_a_failure_not_a_miss(): void
    {
        [$website, $question] = $this->fixture();
        $this->stubLlm(null);   // the model returned nothing usable

        $run = app(BrandRecallProbe::class)->run($question, $website);

        // Telling a client "you were not mentioned" when we never got an answer
        // would be a lie the scorer then averages into their history.
        $this->assertFalse($run->ok);
        $this->assertFalse($run->mentioned);
        $this->assertNotEmpty($run->error);
    }

    public function test_the_brand_is_matched_through_the_ways_a_model_writes_it(): void
    {
        [$website, $question] = $this->fixture(['org_legal_name' => 'Bellavest Trading LLC']);

        foreach ([
            'Bellavest',
            'bellavest.ae',
            'Bellavest Trading L.L.C.',
            'BELLAVEST',
        ] as $spelling) {
            $this->stubLlm(['brands' => [$spelling], 'summary' => 'x']);
            ContentAeoRun::query()->delete();

            $run = app(BrandRecallProbe::class)->run($question, $website);
            $this->assertTrue($run->mentioned, "failed to match \"{$spelling}\"");
        }
    }

    public function test_a_similarly_named_stranger_is_not_counted_as_the_client(): void
    {
        [$website, $question] = $this->fixture();
        $this->stubLlm(['brands' => ['Vest Perfumes', 'Bella Donna'], 'summary' => 'x']);

        $run = app(BrandRecallProbe::class)->run($question, $website);

        $this->assertFalse($run->mentioned, 'a loose match would inflate every client\'s score');
    }

    public function test_a_rerun_on_the_same_day_updates_rather_than_duplicating(): void
    {
        [$website, $question] = $this->fixture();
        $this->stubLlm(['brands' => ['Ajmal'], 'summary' => 'x']);
        app(BrandRecallProbe::class)->run($question, $website);

        $this->stubLlm(['brands' => ['Bellavest'], 'summary' => 'y']);
        app(BrandRecallProbe::class)->run($question, $website);

        $this->assertSame(1, ContentAeoRun::count());
        $this->assertTrue(ContentAeoRun::first()->mentioned);
    }

    public function test_the_question_records_when_it_was_last_asked(): void
    {
        [$website, $question] = $this->fixture();
        $this->stubLlm(['brands' => [], 'summary' => '']);

        app(BrandRecallProbe::class)->run($question, $website);

        $this->assertTrue($question->fresh()->last_run_at->isToday());
    }
}
