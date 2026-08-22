<?php

namespace Tests\Feature\Content;

use App\Models\ContentArticle;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentArticleProducer;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * HARD RULE (mirrors SiteDirectivesCoverageTest): any LLM stage reachable
 * from reviseCurrentArticle must thread $clientInstruction and have a
 * capture test here. Covered: revise + scrubBlockedTerms. deAiCleanup is
 * NOT reachable from reviseCurrentArticle (produce() only) — no threading.
 */
class ClientRewriteCoverageTest extends TestCase
{
    use RefreshDatabase;

    private const SENTINEL = 'Make the intro friendlier to beginners';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        config(['services.mistral.key' => 'test-key', 'services.deepseek.key' => 'test-key']);
    }

    /** @return array{0: ContentPlan, 1: ContentTopic} */
    private function fixture(): array
    {
        $user = User::factory()->create([
            'content_trial_started_at' => now(), 'content_trial_ends_at' => now()->addDays(5),
        ]);
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id, 'billing_covered_at' => now(),
            'business_description' => 'A shop selling fruit-themed phone cases.',
            'offerings' => ['sell' => ['phone cases'], 'dont_sell' => []],
        ]);
        $topic = ContentTopic::create([
            'plan_id' => $plan->id, 'website_id' => $website->id,
            'title' => 'How to pick a case', 'target_keyword' => 'pick a phone case',
            'status' => ContentTopic::STATUS_READY,
        ]);

        return [$plan, $topic];
    }

    private function articleFor(ContentTopic $topic): ContentArticle
    {
        return ContentArticle::create([
            'topic_id' => $topic->id, 'version' => 1, 'is_current' => true,
            'h1' => 'H', 'meta_title' => 'H', 'meta_description' => 'D',
            'slug' => 'h', 'html' => '<p>Body text for the article under test.</p>',
            'seo_score' => 99, // healthy — the forced-pass test depends on this
        ]);
    }

    private function capture(string $method, array $args): string
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' =>
            json_encode(['html' => '<p>x</p>', 'meta_title' => 't', 'meta_description' => 'd', 'h1' => 'h'])]]]])]);

        $m = new \ReflectionMethod(ContentArticleProducer::class, $method);
        $m->setAccessible(true);
        $m->invoke(app(ContentArticleProducer::class), ...$args);

        $bodies = '';
        foreach (Http::recorded() as [$request]) {
            $bodies .= json_encode($request->data());
        }

        return $bodies;
    }

    public function test_client_instruction_reaches_the_revise_prompt(): void
    {
        [$plan, $topic] = $this->fixture();
        $article = $this->articleFor($topic);

        $body = $this->capture('revise', [$article, $topic, $plan, self::SENTINEL]);

        $this->assertStringContainsString('CLIENT REWRITE REQUEST', $body);
        $this->assertStringContainsString(self::SENTINEL, $body);
        // Regression (pubg 2026-08-23): the instruction as a system-tail
        // footnote produced near-identical HTML. It must be a PRIMARY task in
        // the USER message with the failure-on-no-change framing.
        $this->assertStringContainsString('YOUR PRIMARY TASK', $body);
        $this->assertStringContainsString('is a FAILURE', $body);
    }

    public function test_client_instruction_reaches_the_brand_scrub_prompt(): void
    {
        [$plan, $topic] = $this->fixture();
        $plan->update(['competitor_guard' => [
            'assessed_at' => now()->toIso8601String(), 'harmful' => true,
            'auto' => [['brand' => 'rivalbrand', 'domain' => 'rival.com', 'reason' => 'x']],
            'manual' => [], 'removed' => [],
        ], 'toggles' => [\App\Services\Content\CompetitorMentionGuard::TOGGLE => true]]);
        $article = $this->articleFor($topic);

        $body = $this->capture('scrubBlockedTerms', [$article, $topic, $plan->fresh(), false, self::SENTINEL]);

        $this->assertStringContainsString(self::SENTINEL, $body);
    }

    public function test_healthy_article_still_gets_one_forced_pass(): void
    {
        [, $topic] = $this->fixture();
        $this->articleFor($topic); // score 99 ≥ target, no style issues

        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' =>
            json_encode(['html' => '<p>rewritten</p>', 'meta_title' => 't', 'meta_description' => 'd', 'h1' => 'h'])]]]])]);
        app(ContentArticleProducer::class)->reviseCurrentArticle($topic, clientInstruction: self::SENTINEL);

        $sentinelSeen = false;
        foreach (Http::recorded() as [$request]) {
            $sentinelSeen = $sentinelSeen || str_contains(json_encode($request->data()), self::SENTINEL);
        }
        $this->assertTrue($sentinelSeen, 'without the forced pass a healthy article makes the rewrite a silent no-op');
        // A short faked article can re-score below target and earn a second
        // pass — any client_rewrite_N stage proves the forced path ran.
        $this->assertStringStartsWith('client_rewrite_',
            (string) ($topic->articles()->where('is_current', true)->value('generation_meta')['stage'] ?? ''));
    }

    public function test_instruction_is_one_shot_and_never_persisted(): void
    {
        [$plan, $topic] = $this->fixture();
        $article = $this->articleFor($topic);
        $before = $plan->fresh()->toArray();

        $this->capture('revise', [$article, $topic, $plan, self::SENTINEL]);
        // A later run WITHOUT the instruction carries no marker…
        $body = $this->capture('revise', [$article, $topic, $plan]);
        $this->assertStringNotContainsString('CLIENT REWRITE REQUEST', $body);
        // …and nothing was written to the plan.
        $this->assertSame($before, $plan->fresh()->toArray());
    }

    public function test_null_instruction_leaves_prompts_byte_identical(): void
    {
        [$plan, $topic] = $this->fixture();
        $article = $this->articleFor($topic);

        $body = $this->capture('revise', [$article, $topic, $plan, null]);

        $this->assertStringNotContainsString('CLIENT REWRITE REQUEST', $body);
        $this->assertStringNotContainsString('Also apply the CLIENT REWRITE REQUEST', $body);
    }
}
