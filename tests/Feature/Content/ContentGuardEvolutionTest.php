<?php

namespace Tests\Feature\Content;

use App\Jobs\Content\ScanPublishedForBlockedTermsJob;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\CompetitorMentionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase E: guard policy modes, alias terms, the value counter and the
 * retroactive published-article scan.
 */
class ContentGuardEvolutionTest extends TestCase
{
    use RefreshDatabase;

    private function makePlan(array $attrs = []): ContentPlan
    {
        $website = Website::factory()->for(User::factory())->create();

        return ContentPlan::factory()->create(array_merge([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
            'business_description' => 'Luxury gourmand perfumes.',
            'offerings' => ['sell' => ['Vanilla eau de parfum'], 'dont_sell' => []],
        ], $attrs));
    }

    private function topicFor(ContentPlan $plan, array $attrs = []): ContentTopic
    {
        return ContentTopic::factory()->for($plan, 'plan')->create(array_merge([
            'website_id' => $plan->website_id,
            'target_keyword' => 'how to layer perfume',
            'status' => ContentTopic::STATUS_PUBLISHED,
        ], $attrs));
    }

    public function test_mode_defaults_follow_the_site_type(): void
    {
        $guard = app(CompetitorMentionGuard::class);

        $this->assertSame('protect', $guard->mode($this->makePlan(['site_type' => 'brand'])));
        $this->assertSame('brands_required', $guard->mode($this->makePlan(['site_type' => 'affiliate'])));
        $this->assertSame('stocked_only', $guard->mode($this->makePlan(['site_type' => 'ecommerce_reseller'])));
        $this->assertSame('off', $guard->mode($this->makePlan(['site_type' => 'blog'])));
        // Free tools protect: traffic is the product, a rival-tool mention steers it away.
        $this->assertSame('protect', $guard->mode($this->makePlan(['site_type' => 'tool'])));
        // 2026-07-23 additions: all sell something → protect.
        $this->assertSame('protect', $guard->mode($this->makePlan(['site_type' => 'creator'])));
        $this->assertSame('protect', $guard->mode($this->makePlan(['site_type' => 'marketplace'])));
        $this->assertSame('protect', $guard->mode($this->makePlan(['site_type' => 'education'])));
        // Null site type = protect = exactly the pre-mode behavior.
        $this->assertSame('protect', $guard->mode($this->makePlan()));
    }

    public function test_affiliate_mode_never_blocks_and_never_auto_enables(): void
    {
        $guard = app(CompetitorMentionGuard::class);
        $plan = $this->makePlan([
            'site_type' => 'affiliate',
            'toggles' => [CompetitorMentionGuard::TOGGLE => true], // even toggled on
            'competitor_guard' => [
                'assessed_at' => now()->toIso8601String(),
                'auto' => [['brand' => 'semrush', 'domain' => 'semrush.com', 'reason' => 'rival']],
                'references' => [],
            ],
        ]);
        $topic = $this->topicFor($plan, ['status' => ContentTopic::STATUS_APPROVED]);

        // brands_required: rival brands ARE the content — nothing is blocked.
        $this->assertSame([], $guard->termsForTopic($plan, $topic));

        // And a fresh assessment must not flip the toggle on for an affiliate.
        $fresh = $this->makePlan(['site_type' => 'affiliate']);
        $fresh->update(['competitor_overrides' => ['added' => ['rival-reviews.test'], 'removed' => []]]);
        $guard->assess($fresh); // no LLM configured → fail-soft blocks everything
        $fresh->refresh();
        $this->assertFalse($guard->enabled($fresh));
        $this->assertFalse($guard->autoEnabled($fresh));
    }

    public function test_stocked_only_mode_never_blocks_brands_the_shop_carries(): void
    {
        $guard = app(CompetitorMentionGuard::class);
        $plan = $this->makePlan([
            'site_type' => 'ecommerce_reseller',
            'offerings' => ['sell' => ['Kayali perfumes', 'Lattafa fragrances'], 'dont_sell' => []],
            'toggles' => [CompetitorMentionGuard::TOGGLE => true],
            'competitor_guard' => [
                'assessed_at' => now()->toIso8601String(),
                'auto' => [
                    ['brand' => 'kayali', 'domain' => 'kayali.com', 'reason' => 'brand the shop stocks'],
                    ['brand' => 'rivalshop', 'domain' => 'rivalshop.test', 'reason' => 'competing retailer'],
                ],
                'references' => [],
            ],
        ]);
        $topic = $this->topicFor($plan, ['status' => ContentTopic::STATUS_APPROVED]);

        $terms = $guard->termsForTopic($plan, $topic);
        $this->assertContains('rivalshop', $terms);
        $this->assertNotContains('kayali', $terms, 'a stocked brand must never be blocked for a reseller');
    }

    public function test_aliases_are_blocked_with_their_brand_and_removed_with_it(): void
    {
        $guard = app(CompetitorMentionGuard::class);
        $plan = $this->makePlan([
            'toggles' => [CompetitorMentionGuard::TOGGLE => true],
            'competitor_guard' => [
                'assessed_at' => now()->toIso8601String(),
                'auto' => [[
                    'brand' => 'urban company', 'domain' => 'urbancompany.com',
                    'reason' => 'rival', 'aliases' => ['uc', 'urbanclap'],
                ]],
                'references' => [],
            ],
        ]);

        $this->assertEqualsCanonicalizing(['urban company', 'uc', 'urbanclap'], $guard->terms($plan));

        // Removing the parent brand takes its aliases with it.
        $guard->removeTerm($plan, 'urban company');
        $this->assertSame([], $guard->terms($plan->fresh()));
    }

    public function test_value_counter_increments_and_surfaces_in_state(): void
    {
        $guard = app(CompetitorMentionGuard::class);
        $plan = $this->makePlan();

        $guard->recordArticleChecked($plan);
        $guard->recordArticleChecked($plan->fresh());
        $guard->recordMentionRemoved($plan->fresh());

        $state = $guard->stateFor($plan->fresh());
        $this->assertSame(2, $state['stats']['articles_checked']);
        $this->assertSame(1, $state['stats']['mentions_removed']);
    }

    public function test_adding_a_term_dispatches_the_retroactive_scan(): void
    {
        Queue::fake();
        $guard = app(CompetitorMentionGuard::class);
        $plan = $this->makePlan();

        $guard->addTerm($plan, 'justlife');

        Queue::assertPushed(ScanPublishedForBlockedTermsJob::class);
    }

    public function test_retro_scan_flags_published_articles_mentioning_a_blocked_brand(): void
    {
        $plan = $this->makePlan([
            'toggles' => [CompetitorMentionGuard::TOGGLE => true],
            'competitor_guard' => [
                'assessed_at' => now()->toIso8601String(),
                'auto' => [['brand' => 'justlife', 'domain' => 'justlife.com', 'reason' => 'rival']],
                'references' => [],
            ],
        ]);
        $hit = $this->topicFor($plan);
        $hit->articles()->create([
            'is_current' => true, 'version' => 1,
            'html' => '<p>For a quicker clean, we recommend Justlife for weekly visits.</p>',
        ]);
        $clean = $this->topicFor($plan, ['target_keyword' => 'spring cleaning checklist']);
        $clean->articles()->create([
            'is_current' => true, 'version' => 1,
            'html' => '<p>A tidy home starts with a plan.</p>',
        ]);

        (new ScanPublishedForBlockedTermsJob($plan->id))->handle(
            app(CompetitorMentionGuard::class), app(\App\Services\Content\HumanizerService::class)
        );

        $this->assertNotEmpty(((array) $hit->fresh()->meta)['brand_safety'] ?? null);
        $this->assertArrayNotHasKey('brand_safety', (array) $clean->fresh()->meta);
    }
}
