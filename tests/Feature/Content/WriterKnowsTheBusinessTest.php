<?php

namespace Tests\Feature\Content;

use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentArticleProducer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The writer used to receive no business identity at all — `business_description`
 * reached the topic planner, the competitor guard and the image prompts, but
 * never the writer. A clinic client reported their clinic AND pharmacy names
 * missing from a 3,700-word article; the few mentions present had been guessed
 * off the CTA link.
 */
class WriterKnowsTheBusinessTest extends TestCase
{
    use RefreshDatabase;

    private function planWith(?string $description): array
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.org']);
        $plan = ContentPlan::query()->create([
            'website_id' => $website->id,
            'status' => 'active',
            'article_length' => 1500,
            'business_description' => $description,
        ]);
        $topic = ContentTopic::query()->create([
            'plan_id' => $plan->id,
            'website_id' => $website->id,
            'title' => 'Health packages explained',
            'target_keyword' => 'health packages',
            'status' => 'ready',
        ]);

        return [$plan, $topic];
    }

    /** templateInstructions() is private — it is the prompt contract we care about. */
    private function instructions(ContentPlan $plan, ContentTopic $topic): string
    {
        $m = new \ReflectionMethod(ContentArticleProducer::class, 'templateInstructions');
        $m->setAccessible(true);

        return (string) $m->invoke(app(ContentArticleProducer::class), $plan, $topic);
    }

    public function test_the_writer_is_told_who_the_article_is_for(): void
    {
        [$plan, $topic] = $this->planWith(
            'TMC General Clinic & Pharmacy is a trusted medical clinic and pharmacy in Deira, Dubai.'
        );

        $out = $this->instructions($plan, $topic);

        $this->assertStringContainsString('TMC General Clinic & Pharmacy', $out);
        $this->assertStringContainsString('2-4 times', $out, 'bounded mentions — their blog, not an advert');
        $this->assertStringContainsString('more than one name', $out, 'covers the clinic + pharmacy case');
        $this->assertStringContainsString('Never invent a different business name', $out);
    }

    public function test_a_plan_without_a_description_adds_no_naming_rule(): void
    {
        [$plan, $topic] = $this->planWith(null);

        $this->assertStringNotContainsString('THIS ARTICLE IS PUBLISHED BY THIS BUSINESS', $this->instructions($plan, $topic));
    }

    public function test_the_rule_travels_in_the_prompt_the_writer_actually_receives(): void
    {
        // templateInstructions() is spliced into AiWriterService's
        // `custom_prompt` (ContentArticleProducer:108) — that string IS the
        // writer's instruction channel. scorerContext() feeds the scorer, not
        // the writer, so putting the business there would have changed nothing.
        [$plan, $topic] = $this->planWith('Acme Dental is a family dentist in Leeds.');

        $this->assertStringContainsString('Acme Dental', $this->instructions($plan, $topic));
    }
}
