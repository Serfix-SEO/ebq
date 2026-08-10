<?php

namespace Tests\Feature\Lifecycle;

use App\Models\ContentArticle;
use App\Models\ContentIntegration;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Lifecycle\LifecycleSegmentResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LifecycleSegmentResolverTest extends TestCase
{
    use RefreshDatabase;

    private LifecycleSegmentResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(LifecycleSegmentResolver::class);
    }

    private function agedUser(array $attributes = []): User
    {
        return User::factory()->create($attributes + [
            'created_at' => now()->subDays(30),
            // Factory safeEmail() yields @example.* — which the eligibility
            // query rightly excludes as undeliverable demo data.
            'email' => fake()->unique()->userName().'@lifecycle-test.dev',
        ]);
    }

    private function siteFor(User $user): Website
    {
        return Website::factory()->create(['user_id' => $user->id]);
    }

    public function test_user_without_websites_is_segment_2(): void
    {
        $user = $this->agedUser();

        $this->assertSame(2, $this->resolver->resolve($user)['segment']);
    }

    public function test_pivot_only_team_member_is_no_segment(): void
    {
        $owner = $this->agedUser();
        $site = $this->siteFor($owner);

        $member = $this->agedUser();
        $member->sharedWebsites()->attach($site->id, ['role' => 'member']);

        $this->assertNull($this->resolver->resolve($member));
    }

    public function test_website_without_plan_is_segment_3(): void
    {
        $user = $this->agedUser();
        $this->siteFor($user);

        $resolved = $this->resolver->resolve($user);
        $this->assertSame(3, $resolved['segment']);
        $this->assertNotNull($resolved['website']);
    }

    public function test_draft_plan_is_segment_3_even_with_progress(): void
    {
        $user = $this->agedUser();
        $site = $this->siteFor($user);
        ContentPlan::factory()->create([
            'website_id' => $site->id,
            'status' => ContentPlan::STATUS_DRAFT,
            'business_description' => 'A real business, wizard mid-flight',
        ]);

        $this->assertSame(3, $this->resolver->resolve($user)['segment']);
    }

    public function test_active_unconnected_plan_is_segment_4(): void
    {
        $user = $this->agedUser();
        $site = $this->siteFor($user);
        ContentPlan::factory()->create(['website_id' => $site->id, 'status' => ContentPlan::STATUS_ACTIVE]);

        $resolved = $this->resolver->resolve($user);
        $this->assertSame(4, $resolved['segment']);
        $this->assertTrue($resolved['website']->is($site));
    }

    public function test_pending_integration_still_counts_as_not_connected(): void
    {
        $user = $this->agedUser();
        $site = $this->siteFor($user);
        ContentPlan::factory()->create(['website_id' => $site->id, 'status' => ContentPlan::STATUS_ACTIVE]);
        ContentIntegration::create([
            'website_id' => $site->id,
            'platform' => ContentIntegration::PLATFORM_WEBHOOK,
            'credentials' => ['endpoint_url' => 'https://client.test/hook', 'secret' => str_repeat('a', 48)],
            'status' => ContentIntegration::STATUS_PENDING,
        ]);

        $this->assertSame(4, $this->resolver->resolve($user)['segment']);
    }

    public function test_connected_site_without_articles_is_no_segment(): void
    {
        $user = $this->agedUser();
        $site = $this->connectedActiveSite($user);

        $this->assertNull($this->resolver->resolve($user));
    }

    public function test_connected_site_with_articles_is_segment_1(): void
    {
        $user = $this->agedUser();
        $site = $this->connectedActiveSite($user);
        $plan = ContentPlan::query()->where('website_id', $site->id)->first();
        $topic = ContentTopic::factory()->create(['plan_id' => $plan->id, 'website_id' => $site->id]);
        ContentArticle::storeVersion($topic, ['html' => '<p>hi</p>']);

        $resolved = $this->resolver->resolve($user);
        $this->assertSame(1, $resolved['segment']);
        $this->assertNull($resolved['website']);
    }

    public function test_precedence_mixed_portfolio_prefers_most_blocked(): void
    {
        // One connected+articles site AND one active-unconnected site → 4 wins.
        $user = $this->agedUser();
        $flowing = $this->connectedActiveSite($user);
        $plan = ContentPlan::query()->where('website_id', $flowing->id)->first();
        $topic = ContentTopic::factory()->create(['plan_id' => $plan->id, 'website_id' => $flowing->id]);
        ContentArticle::storeVersion($topic, ['html' => '<p>hi</p>']);

        $stuck = $this->siteFor($user);
        ContentPlan::factory()->create(['website_id' => $stuck->id, 'status' => ContentPlan::STATUS_ACTIVE]);

        $resolved = $this->resolver->resolve($user);
        $this->assertSame(4, $resolved['segment']);
        $this->assertTrue($resolved['website']->is($stuck));
    }

    public function test_one_active_site_defeats_all_draft_segment_3(): void
    {
        // Draft site + active-connected site → not segment 3 (not ALL unfinished).
        $user = $this->agedUser();
        $draftSite = $this->siteFor($user);
        ContentPlan::factory()->create(['website_id' => $draftSite->id, 'status' => ContentPlan::STATUS_DRAFT]);
        $this->connectedActiveSite($user);

        $resolved = $this->resolver->resolve($user);
        $this->assertNotEquals(3, $resolved['segment'] ?? null);
    }

    public function test_cta_website_prefers_covered_site(): void
    {
        $user = $this->agedUser();
        $uncovered = $this->siteFor($user);
        ContentPlan::factory()->create([
            'website_id' => $uncovered->id,
            'status' => ContentPlan::STATUS_DRAFT,
            'billing_covered_at' => null,
        ]);
        $covered = $this->siteFor($user);
        ContentPlan::factory()->create([
            'website_id' => $covered->id,
            'status' => ContentPlan::STATUS_DRAFT,
            'billing_covered_at' => now(),
        ]);

        $resolved = $this->resolver->resolve($user);
        $this->assertSame(3, $resolved['segment']);
        $this->assertTrue($resolved['website']->is($covered));
    }

    public function test_eligibility_exclusions(): void
    {
        $eligible = $this->agedUser();
        $this->agedUser(['is_system' => true]);
        $this->agedUser(['is_admin' => true]);
        $this->agedUser(['is_disabled' => true]);
        User::factory()->unverified()->create(['created_at' => now()->subDays(30)]);
        $this->agedUser(['marketing_emails_opted_out_at' => now()]);
        $this->agedUser(['email' => 'demo@example.org']); // faker demo row
        User::factory()->create(); // brand-new: under min account age

        $ids = $this->resolver->eligibleUsersQuery()->pluck('id')->all();

        $this->assertSame([$eligible->id], $ids);
    }

    private function connectedActiveSite(User $user): Website
    {
        $site = $this->siteFor($user);
        ContentPlan::factory()->create(['website_id' => $site->id, 'status' => ContentPlan::STATUS_ACTIVE]);
        ContentIntegration::create([
            'website_id' => $site->id,
            'platform' => ContentIntegration::PLATFORM_WEBHOOK,
            'credentials' => ['endpoint_url' => 'https://client.test/hook', 'secret' => str_repeat('a', 48)],
            'status' => ContentIntegration::STATUS_CONNECTED,
        ]);

        return $site;
    }
}
