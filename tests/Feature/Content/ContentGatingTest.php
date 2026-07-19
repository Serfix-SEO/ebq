<?php

namespace Tests\Feature\Content;

use App\Jobs\ProduceContentArticleJob;
use App\Models\ContentArticle;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentArticleProducer;
use App\Services\Content\ContentEntitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentGatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_produce_job_is_blocked_without_content_access(): void
    {
        // Owner has NO content access; plan uncovered.
        $user = User::factory()->create();
        $site = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create(['website_id' => $site->id, 'billing_covered_at' => null]);
        $topic = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $site->id, 'status' => ContentTopic::STATUS_APPROVED,
        ]);

        (new ProduceContentArticleJob($topic->id))->handle(app(ContentArticleProducer::class));

        // Nothing generated, topic untouched.
        $this->assertSame(0, ContentArticle::query()->whereHas('topic', fn ($q) => $q->where('website_id', $site->id))->count());
        $this->assertSame(ContentTopic::STATUS_APPROVED, $topic->fresh()->status);
    }

    public function test_effective_feature_flag_follows_content_access(): void
    {
        $user = User::factory()->create();
        $site = Website::factory()->for($user)->create();

        $this->assertFalse($site->fresh()->effectiveFeatureFlags()['content_autopilot']);

        app(ContentEntitlements::class)->startTrial($user, $site); // trial + coverage
        $this->assertTrue($site->fresh()->effectiveFeatureFlags()['content_autopilot']);
    }
}
