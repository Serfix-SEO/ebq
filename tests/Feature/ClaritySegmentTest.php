<?php

namespace Tests\Feature;

use App\Models\ContentIntegration;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentEntitlements;
use App\Support\ClarityContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Microsoft Clarity session segmentation.
 *
 * The load-bearing case is STAFF traffic. Clarity has recorded admin sessions
 * since the tag went in, mixed indistinguishably into customer data — and an
 * IMPERSONATED session is the worst of them, because the authenticated user is
 * the client, so it looks exactly like real customer behaviour.
 */
class ClaritySegmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        Cache::flush(); // the count tags are cached per user
    }

    public function test_a_guest_session_is_tagged_guest(): void
    {
        $tags = ClarityContext::tags();

        $this->assertSame('guest', $tags['session_type']);
        $this->assertSame('no', $tags['staff_session']);
    }

    public function test_a_customer_session_is_not_staff(): void
    {
        $this->actingAs(User::factory()->create());

        $tags = ClarityContext::tags();

        $this->assertSame('customer', $tags['session_type']);
        $this->assertSame('no', $tags['staff_session']);
    }

    public function test_an_admin_session_is_tagged_staff(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $tags = ClarityContext::tags();

        $this->assertSame('admin', $tags['session_type']);
        $this->assertSame('yes', $tags['staff_session']);
    }

    /**
     * The one that matters: while impersonating, the authenticated user is the
     * CLIENT (is_admin false). Reading only the user would tag this
     * 'customer' and hide exactly the traffic we want excluded.
     */
    public function test_an_impersonated_session_is_tagged_staff_not_customer(): void
    {
        $client = User::factory()->create(['is_admin' => false]);
        $this->actingAs($client)->withSession([
            'impersonator_id' => User::factory()->create(['is_admin' => true])->id,
        ]);

        $html = $this->get(route('content.get-started'))->assertOk()->getContent();

        $this->assertStringContainsString("window.clarityTag('session_type', 'impersonating')", $html);
        $this->assertStringContainsString("window.clarityTag('staff_session', 'yes')", $html);
        $this->assertStringNotContainsString("window.clarityTag('session_type', 'customer')", $html);
    }

    public function test_plan_and_trial_day_describe_the_account(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();
        app(ContentEntitlements::class)->startTrial($user, $website);
        $this->actingAs($user->fresh());

        $tags = ClarityContext::tags();

        $this->assertSame('trial', $tags['plan']);
        $this->assertSame('1', $tags['trial_day'], 'a trial started today is day 1, not day 0');
    }

    public function test_paid_customers_are_tagged_paid(): void
    {
        $user = User::factory()->create();
        $user->subscriptions()->create([
            'type' => ContentEntitlements::SUBSCRIPTION, 'stripe_id' => 'sub_c',
            'stripe_status' => 'active', 'stripe_price' => 'price_c', 'quantity' => 1,
        ]);
        $this->actingAs($user);

        $this->assertSame('paid', ClarityContext::tags()['plan']);
    }

    /** Counts are bucketed — "articles = 37" is not a cohort, "20+" is. */
    public function test_counts_are_bucketed_and_publishing_state_is_tagged(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create(['website_id' => $website->id]);
        foreach (range(1, 3) as $i) {
            ContentTopic::factory()->create([
                'plan_id' => $plan->id,
                'website_id' => $website->id,
                'status' => ContentTopic::STATUS_PUBLISHED,
            ]);
        }
        $this->actingAs($user);

        $tags = ClarityContext::tags();
        $this->assertSame('1', $tags['websites']);
        $this->assertSame('1-4', $tags['articles_published']);
        $this->assertSame('none', $tags['publishing']);

        Cache::flush();
        ContentIntegration::query()->create([
            'website_id' => $website->id,
            'platform' => ContentIntegration::PLATFORM_WEBHOOK,
            'status' => ContentIntegration::STATUS_CONNECTED,
        ]);
        $this->assertSame('connected', ClarityContext::tags()['publishing']);
    }

    /** The tags have to actually reach the page, on public and app layouts. */
    public function test_the_tags_render_into_the_clarity_snippet(): void
    {
        $html = $this->get(route('landing'))->assertOk()->getContent();
        $this->assertStringContainsString('clarity.ms/tag/', $html);
        $this->assertStringContainsString("window.clarityTag('session_type', 'guest')", $html);

        $admin = User::factory()->create(['is_admin' => true]);
        $adminHtml = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString("window.clarityTag('staff_session', 'yes')", $adminHtml);
    }

    // ── identify(): links a person's sessions together ──────────────────

    public function test_a_signed_in_user_is_identified_by_ulid(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->assertSame((string) $user->id, ClarityContext::identity());

        $html = $this->get(route('content.get-started'))->assertOk()->getContent();
        $this->assertStringContainsString('clarity("identify", '."'".$user->id."'", $html);
    }

    public function test_guests_with_nothing_to_stitch_are_not_identified(): void
    {
        $this->assertNull(ClarityContext::identity());

        $html = $this->get(route('landing'))->assertOk()->getContent();
        $this->assertStringNotContainsString('clarity("identify"', $html);
    }

    /**
     * An anonymous wizard run still stitches — but via a ONE-WAY hash. The
     * onboarding token resumes someone else's onboarding, so it must never
     * leave our server, and it must not be recoverable from what does.
     */
    public function test_an_anonymous_onboarding_run_is_stitched_without_leaking_the_token(): void
    {
        $token = 'super-secret-resume-token-123';
        $this->withSession(['content_onboarding_token' => $token]);

        // Any rendered page carries the partial; the wizard url itself 302s
        // without a matching session row, and a redirect has no body.
        $html = $this->get(route('landing'))->assertOk()->getContent();

        $this->assertStringContainsString('clarity("identify", '."'".'anon-', $html, 'the run is stitched');
        $this->assertStringNotContainsString($token, $html, 'the resume token must never reach Clarity');
    }

    /** No PII may reach Clarity — tag values are not hashed. */
    public function test_no_identifying_values_are_tagged(): void
    {
        $user = User::factory()->create(['email' => 'someone@example.com', 'name' => 'Some One']);
        Website::factory()->for($user)->create(['domain' => 'private-domain.test']);
        $this->actingAs($user);

        $values = implode('|', ClarityContext::tags());

        $this->assertStringNotContainsString('someone@example.com', $values);
        $this->assertStringNotContainsString('Some One', $values);
        $this->assertStringNotContainsString('private-domain.test', $values);
    }
}
