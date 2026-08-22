<?php

namespace Tests\Feature\Content;

use App\Models\ContentPlan;
use App\Models\ContentRewriteCreditEvent as Event;
use App\Models\ContentRewriteRequest;
use App\Models\ContentTopic;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentEntitlements;
use App\Services\Content\Exceptions\InsufficientRewriteCreditsException;
use App\Services\Content\RewriteCredits;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class RewriteCreditsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function credits(): RewriteCredits
    {
        return app(RewriteCredits::class);
    }

    /** @return array{0: User, 1: ContentTopic} */
    private function subscriber(bool $withSub = true): array
    {
        $user = User::factory()->create(['stripe_id' => 'cus_'.uniqid()]);
        if ($withSub) {
            $sub = $user->subscriptions()->create([
                'id' => (string) Str::ulid(), 'type' => ContentEntitlements::SUBSCRIPTION,
                'stripe_id' => 'sub_'.uniqid(), 'stripe_status' => 'active',
                'stripe_price' => 'price_base_m', 'quantity' => 1,
            ]);
            $sub->items()->create([
                'id' => (string) Str::ulid(), 'stripe_id' => 'si_'.uniqid(),
                'stripe_product' => 'prod', 'stripe_price' => 'price_base_m', 'quantity' => 1,
            ]);
        }
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create(['website_id' => $website->id, 'status' => ContentPlan::STATUS_ACTIVE]);
        $topic = ContentTopic::create([
            'plan_id' => $plan->id, 'website_id' => $website->id,
            'title' => 'T', 'target_keyword' => 'kw', 'status' => ContentTopic::STATUS_READY,
        ]);

        return [$user, $topic];
    }

    private function request(User $user, ContentTopic $topic): ContentRewriteRequest
    {
        return ContentRewriteRequest::create([
            'topic_id' => $topic->id, 'user_id' => $user->id, 'website_id' => $topic->website_id,
            'status' => ContentRewriteRequest::STATUS_QUEUED, 'prior_status' => ContentTopic::STATUS_READY,
        ]);
    }

    public function test_subscriber_allowance_default_and_override(): void
    {
        [$user] = $this->subscriber();
        $this->assertSame(5, $this->credits()->summary($user)['free_remaining']);

        Setting::set('content.rewrite.monthly_free', 8);
        $this->assertSame(8, $this->credits()->summary($user)['free_remaining']);
    }

    public function test_trial_user_gets_no_free_but_can_spend_purchased(): void
    {
        [$user, $topic] = $this->subscriber(withSub: false);
        $user->forceFill(['content_trial_started_at' => now(), 'content_trial_ends_at' => now()->addDays(5)])->save();

        $this->assertSame(0, $this->credits()->summary($user)['free_remaining']);
        $this->credits()->grantAdmin($user, 2);
        $this->assertSame(2, $this->credits()->summary($user)['total']);

        $event = $this->credits()->spend($user, $topic, $this->request($user, $topic)->id);
        $this->assertSame(Event::SOURCE_PURCHASED, $event->source);
        $this->assertSame(1, $this->credits()->summary($user)['total']);
    }

    public function test_spend_is_free_first_then_purchased(): void
    {
        Setting::set('content.rewrite.monthly_free', 1);
        [$user, $topic] = $this->subscriber();
        $this->credits()->grantAdmin($user, 1);

        $first = $this->credits()->spend($user, $topic, $this->request($user, $topic)->id);
        $second = $this->credits()->spend($user, $topic, $this->request($user, $topic)->id);

        $this->assertSame(Event::SOURCE_FREE, $first->source);
        $this->assertSame(Event::SOURCE_PURCHASED, $second->source);

        $this->expectException(InsufficientRewriteCreditsException::class);
        $this->credits()->spend($user, $topic, $this->request($user, $topic)->id);
    }

    public function test_free_allowance_resets_monthly_without_rollover(): void
    {
        Setting::set('content.rewrite.monthly_free', 2);
        [$user, $topic] = $this->subscriber();

        $this->credits()->spend($user, $topic, $this->request($user, $topic)->id);
        $this->assertSame(1, $this->credits()->summary($user)['free_remaining']);

        Carbon::setTestNow(now()->addMonthNoOverflow()->startOfMonth()->addDay());
        // New month: full allowance again (reset), NOT 1 + 2 (no rollover).
        $this->assertSame(2, $this->credits()->summary($user)['free_remaining']);
    }

    public function test_refund_is_idempotent_and_restores_the_original_source(): void
    {
        Setting::set('content.rewrite.monthly_free', 1);
        [$user, $topic] = $this->subscriber();
        $request = $this->request($user, $topic);
        $this->credits()->spend($user, $topic, $request->id);
        $this->assertSame(0, $this->credits()->summary($user)['free_remaining']);

        $this->credits()->refund($request);
        $this->credits()->refund($request); // second call no-ops

        $this->assertSame(1, $this->credits()->summary($user)['free_remaining']);
        $this->assertSame(1, Event::query()->where('kind', Event::KIND_REFUND)->count());
        $this->assertSame(Event::SOURCE_FREE, Event::query()->where('kind', Event::KIND_REFUND)->value('source'));
    }

    public function test_purchase_grant_is_idempotent_by_session_id(): void
    {
        [$user] = $this->subscriber(withSub: false);

        $this->assertTrue($this->credits()->grantForPurchase($user, 'cs_test_1', 10));
        $this->assertFalse($this->credits()->grantForPurchase($user, 'cs_test_1', 10));

        $this->assertSame(10, $this->credits()->purchasedBalance($user));
    }
}
