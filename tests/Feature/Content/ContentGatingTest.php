<?php

namespace Tests\Feature\Content;

use App\Http\Middleware\EnsureContentAccess;
use App\Jobs\ProduceContentArticleJob;
use App\Models\ContentArticle;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentArticleProducer;
use App\Services\Content\ContentEntitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ContentGatingTest extends TestCase
{
    use RefreshDatabase;

    /** Run EnsureContentAccess for $user on $site; return true if it passed through. */
    private function passesAccess(User $user, Website $site): bool
    {
        $request = Request::create('/content');
        $request->setLaravelSession($this->app['session']->driver());
        $request->session()->put('current_website_id', $site->id);
        $request->setUserResolver(fn () => $user);

        $response = (new EnsureContentAccess)->handle($request, fn () => response('ok'));

        return ! $response->isRedirect(route('content.get-started'));
    }

    public function test_covered_site_passes_and_uncovered_new_site_is_blocked(): void
    {
        $user = User::factory()->create();
        $covered = Website::factory()->for($user)->create();
        app(ContentEntitlements::class)->startTrial($user, $covered); // trial covers this one

        // Second site: attached, ideation topics only, never paid → blocked.
        $unpaid = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create(['website_id' => $unpaid->id, 'billing_covered_at' => null]);
        ContentTopic::factory()->for($plan, 'plan')->create(['website_id' => $unpaid->id]);

        $this->assertTrue($this->passesAccess($user, $covered), 'existing covered site is NOT blocked');
        $this->assertFalse($this->passesAccess($user, $unpaid), 'new unpaid site (topics only) IS blocked');
    }

    public function test_lapsed_site_with_generated_articles_stays_reachable_to_publish(): void
    {
        $user = User::factory()->create();
        $site = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create(['website_id' => $site->id, 'billing_covered_at' => null]);
        $topic = ContentTopic::factory()->for($plan, 'plan')->create(['website_id' => $site->id]);
        ContentArticle::storeVersion($topic, []);

        // No coverage, but there's a generated article → publish-only access.
        $this->assertTrue($this->passesAccess($user, $site));
    }

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
