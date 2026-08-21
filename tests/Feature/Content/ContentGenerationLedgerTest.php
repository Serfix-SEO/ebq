<?php

namespace Tests\Feature\Content;

use App\Models\ContentArticle;
use App\Models\ContentGeneration;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentEntitlements;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The delete-site + re-add loophole (2026-08-21): trial and monthly caps
 * were counted from rows that cascade-delete with the website, so cycling
 * websites reset the allowance ($1 first-month sub → unlimited articles).
 * The content_generations ledger has no FK to websites and survives.
 */
class ContentGenerationLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    /** @return array{0: User, 1: Website, 2: ContentPlan} */
    private function trialUser(): array
    {
        $user = User::factory()->create([
            'content_trial_started_at' => now()->subDay(),
            'content_trial_ends_at' => now()->addDays(4),
        ]);
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id, 'billing_covered_at' => now(),
        ]);

        return [$user, $website, $plan];
    }

    private function generate(ContentPlan $plan, Website $website, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $topic = ContentTopic::create([
                'plan_id' => $plan->id, 'website_id' => $website->id,
                'title' => 'T'.$i, 'target_keyword' => 'kw '.$i.' '.$website->id,
                'status' => ContentTopic::STATUS_READY,
            ]);
            ContentArticle::storeVersion($topic, [
                'h1' => 'H', 'meta_title' => 'H', 'meta_description' => 'D',
                'slug' => 'h'.$i, 'html' => '<p>x</p>', 'seo_score' => 90,
            ]);
        }
    }

    public function test_v1_articles_write_ledger_rows_and_revisions_do_not(): void
    {
        [$user, $website, $plan] = $this->trialUser();
        $this->generate($plan, $website, 1);
        $topic = $plan->topics()->first();
        ContentArticle::storeVersion($topic, [
            'h1' => 'H', 'meta_title' => 'H', 'meta_description' => 'D',
            'slug' => 'h', 'html' => '<p>revised</p>', 'seo_score' => 91,
        ]);

        $this->assertSame(1, ContentGeneration::where('user_id', $user->id)->count(),
            'one generation despite two versions');
    }

    public function test_trial_cap_survives_website_deletion(): void
    {
        [$user, $website, $plan] = $this->trialUser();
        $this->generate($plan, $website, 3); // trial cap

        $ent = app(ContentEntitlements::class);
        $this->assertSame(3, $ent->trialUsage($user));

        // The loophole: delete the site, add a fresh one.
        $website->delete();
        $newSite = Website::factory()->for($user)->create();
        $newPlan = ContentPlan::factory()->create([
            'website_id' => $newSite->id, 'billing_covered_at' => now(),
        ]);
        $topic = ContentTopic::create([
            'plan_id' => $newPlan->id, 'website_id' => $newSite->id,
            'title' => 'Fresh', 'target_keyword' => 'fresh keyword',
            'status' => ContentTopic::STATUS_APPROVED,
        ]);

        $this->assertSame(3, $ent->trialUsage($user->fresh()), 'usage must survive the deletion');
        $this->assertSame('trial_limit', $ent->blockReason($topic),
            'a fresh website must NOT reset the trial allowance');
    }

    public function test_user_monthly_cap_survives_website_deletion_for_subscribers(): void
    {
        [$user, $website, $plan] = $this->trialUser();
        // Simulate "paying subscriber": bypass the trial cap the way blockReason
        // does — via an admin comp grant (hasContentSubscription needs Stripe).
        $user->forceFill(['content_comp_sites' => 1])->save();

        config(['app.env' => config('app.env')]); // no-op; keep test explicit
        \App\Support\ContentAutopilotConfig::class;
        // Lower the cap so the test stays fast: 2 articles/site, 2 sites allowed
        // (1 trial + 1 comp) → user cap 4.
        \App\Models\Setting::set('content.limits.monthly_articles_per_website', 2);

        $this->generate($plan, $website, 4); // consume the full USER cap
        $website->delete();

        $newSite = Website::factory()->for($user)->create();
        $newPlan = ContentPlan::factory()->create([
            'website_id' => $newSite->id, 'billing_covered_at' => now(),
        ]);
        $topic = ContentTopic::create([
            'plan_id' => $newPlan->id, 'website_id' => $newSite->id,
            'title' => 'Fresh', 'target_keyword' => 'fresh keyword',
            'status' => ContentTopic::STATUS_APPROVED,
        ]);

        $this->assertSame('monthly_limit', app(ContentEntitlements::class)->blockReason($topic),
            'cycling websites must not refresh the monthly allowance');
    }

    public function test_under_cap_user_is_not_blocked(): void
    {
        [$user, $website, $plan] = $this->trialUser();
        $this->generate($plan, $website, 1);
        $topic = ContentTopic::create([
            'plan_id' => $plan->id, 'website_id' => $website->id,
            'title' => 'Next', 'target_keyword' => 'next keyword',
            'status' => ContentTopic::STATUS_APPROVED,
        ]);

        $this->assertNull(app(ContentEntitlements::class)->blockReason($topic));
    }
}
