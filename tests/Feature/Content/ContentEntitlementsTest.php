<?php

namespace Tests\Feature\Content;

use App\Models\ContentArticle;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentEntitlements;
use App\Support\TrialStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentEntitlementsTest extends TestCase
{
    use RefreshDatabase;

    private function ent(): ContentEntitlements
    {
        // Fresh instance each call so the per-request access/coverage memo
        // never masks a state change made mid-test.
        return new ContentEntitlements();
    }

    private function siteFor(User $user): Website
    {
        return Website::factory()->for($user)->create();
    }

    private function generate(ContentTopic $topic, int $versions = 1): void
    {
        for ($v = 1; $v <= $versions; $v++) {
            ContentArticle::storeVersion($topic, [
                'h1' => 'H', 'meta_title' => 'H', 'meta_description' => 'D', 'slug' => 's'.$v,
                'html' => '<p>x</p>', 'word_count' => 100, 'seo_score' => 90, 'seo_issues' => [],
            ]);
        }
    }

    public function test_start_trial_sets_window_and_coverage_once(): void
    {
        $user = User::factory()->create();
        $site = $this->siteFor($user);

        $this->ent()->startTrial($user, $site);
        $user->refresh();

        $this->assertNotNull($user->content_trial_started_at);
        $this->assertTrue($user->content_trial_ends_at->isFuture());
        $this->assertTrue($this->ent()->hasContentAccess($user));
        $this->assertTrue($this->ent()->hasContentAccessFor($user, $site));

        // One trial ever: a second start does not move the window.
        $firstEnd = $user->content_trial_ends_at->toDateTimeString();
        $this->travel(1)->days();
        $this->ent()->startTrial($user, $site);
        $this->assertSame($firstEnd, $user->fresh()->content_trial_ends_at->toDateTimeString());
    }

    public function test_no_access_without_trial_or_sub(): void
    {
        $user = User::factory()->create();
        $this->assertFalse($this->ent()->hasContentAccess($user));
    }

    public function test_access_requires_coverage_for_specific_website(): void
    {
        $user = User::factory()->create();
        $covered = $this->siteFor($user);
        $this->ent()->startTrial($user, $covered);
        $uncovered = $this->siteFor($user);

        $this->assertTrue($this->ent()->hasContentAccessFor($user, $covered));
        $this->assertFalse($this->ent()->hasContentAccessFor($user, $uncovered));
    }

    public function test_generation_counts_version_one_only(): void
    {
        $user = User::factory()->create();
        $site = $this->siteFor($user);
        $plan = ContentPlan::factory()->create(['website_id' => $site->id]);
        $topic = ContentTopic::factory()->for($plan, 'plan')->create(['website_id' => $site->id]);
        $this->generate($topic, versions: 3); // 1 generation, 2 revisions

        $this->assertSame(1, $this->ent()->usageForWebsite($site->id, now()->startOfMonth()));
    }

    public function test_block_reason_matrix(): void
    {
        $user = User::factory()->create();
        $site = $this->siteFor($user);
        // Uncovered on purpose — this test drives the not_covered → covered path.
        $plan = ContentPlan::factory()->create(['website_id' => $site->id, 'billing_covered_at' => null]);
        $topic = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $site->id, 'status' => ContentTopic::STATUS_APPROVED,
        ]);

        // No trial/sub → no_access.
        $this->assertSame('no_access', $this->ent()->blockReason($topic));

        // Trial started but this website not covered → not_covered.
        $other = $this->siteFor($user);
        $this->ent()->startTrial($user, $other);
        $this->assertSame('not_covered', $this->ent()->blockReason($topic->fresh()));

        // Cover it → allowed.
        $this->ent()->startTrial($user, $site);
        $this->assertNull($this->ent()->blockReason($topic->fresh()));

        // Hit the 3-article trial cap (topics on the covered sites).
        foreach ([$site, $other] as $s) {
            $p = ContentPlan::query()->firstOrCreate(['website_id' => $s->id]);
            for ($i = 0; $i < 2; $i++) {
                $t = ContentTopic::factory()->for($p, 'plan')->create(['website_id' => $s->id]);
                $this->generate($t);
            }
        }
        // 4 generations across covered sites ≥ 3 → trial_limit for a new topic.
        $this->assertSame('trial_limit', $this->ent()->blockReason($topic->fresh()));
    }

    public function test_is_content_only_when_dashboard_trial_expired(): void
    {
        // The dashboard trial length comes from the `trial` plan row.
        \App\Models\Plan::query()->create([
            'slug' => 'trial', 'name' => 'Trial', 'trial_days' => 14, 'is_active' => true,
        ]);
        \Illuminate\Support\Facades\Cache::forget('trial-status:days');

        $user = User::factory()->create(['created_at' => now()->subDays(30)]); // dashboard trial long expired
        $site = $this->siteFor($user);
        $this->assertTrue(TrialStatus::isExpired($user));

        $this->ent()->startTrial($user, $site);
        $this->assertTrue($user->fresh()->isContentOnly());
    }
}
