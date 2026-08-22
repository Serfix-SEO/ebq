<?php

namespace Tests\Feature\Billing;

use App\Http\Controllers\StripeWebhookController;
use App\Models\ContentRewriteCreditEvent as Event;
use App\Models\Setting;
use App\Models\User;
use App\Services\Content\RewriteCredits;
use App\Services\Content\StripeSessionReader;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RewriteCreditPurchaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function trialUser(): User
    {
        return User::factory()->create([
            'content_trial_started_at' => now(), 'content_trial_ends_at' => now()->addDays(5),
        ]);
    }

    private function fakeSession(User $user, string $id = 'cs_ok', string $paymentStatus = 'paid', int $credits = 10): void
    {
        $session = (object) [
            'payment_status' => $paymentStatus,
            'metadata' => (object) ['kind' => 'rewrite_credits', 'user_id' => (string) $user->id, 'credits' => (string) $credits],
        ];
        $this->app->instance(StripeSessionReader::class, new class($session) extends StripeSessionReader
        {
            public function __construct(private object $session) {}

            public function retrieve(User $user, string $sessionId): ?object
            {
                return $this->session;
            }
        });
    }

    public function test_checkout_gates_on_content_access_and_pack_index(): void
    {
        // No content relationship at all → 403.
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get(route('content.credits.checkout', ['pack' => 0]))->assertForbidden();

        // Trial user, bad pack index → 404 (trial CAN buy — owner decision).
        $trial = $this->trialUser();
        $this->actingAs($trial)->get(route('content.credits.checkout', ['pack' => 99]))->assertNotFound();
    }

    public function test_success_endpoint_verifies_grants_once_and_is_replayable(): void
    {
        $user = $this->trialUser();
        $this->fakeSession($user, credits: 10);

        $this->actingAs($user)->get(route('content.credits.success').'?session_id=cs_ok')->assertRedirect();
        $this->actingAs($user)->get(route('content.credits.success').'?session_id=cs_ok')->assertRedirect(); // replay

        $this->assertSame(10, app(RewriteCredits::class)->purchasedBalance($user));
        $this->assertSame(1, Event::query()->where('kind', Event::KIND_PURCHASE)->count());
    }

    public function test_success_endpoint_rejects_unpaid_or_foreign_sessions(): void
    {
        $user = $this->trialUser();
        $this->fakeSession($user, paymentStatus: 'unpaid');
        $this->actingAs($user)->get(route('content.credits.success').'?session_id=cs_ok')->assertRedirect();
        $this->assertSame(0, Event::query()->count());

        // Session belonging to another user id → no grant.
        $other = $this->trialUser();
        $this->fakeSession($other, credits: 10); // metadata.user_id = $other
        $this->actingAs($user)->get(route('content.credits.success').'?session_id=cs_ok')->assertRedirect();
        $this->assertSame(0, Event::query()->count());
    }

    public function test_webhook_grants_once_and_ignores_other_kinds_and_garbage(): void
    {
        $user = User::factory()->create(['stripe_id' => 'cus_wh']);
        $ctrl = new StripeWebhookController();
        $payload = ['data' => ['object' => [
            'id' => 'cs_wh_1', 'customer' => 'cus_wh', 'payment_status' => 'paid',
            'metadata' => ['kind' => 'rewrite_credits', 'user_id' => (string) $user->id, 'credits' => '25'],
        ]]];

        $ctrl->handleCheckoutSessionCompleted($payload)->isOk() ?: $this->fail('webhook must 200');
        $ctrl->handleCheckoutSessionCompleted($payload); // Stripe retry

        $this->assertSame(25, app(RewriteCredits::class)->purchasedBalance($user));
        $this->assertSame(1, Event::query()->count());

        // Other kinds ignored; garbage never 500s.
        $ctrl->handleCheckoutSessionCompleted(['data' => ['object' => ['id' => 'cs_x', 'metadata' => ['kind' => 'other']]]]);
        $ctrl->handleCheckoutSessionCompleted(['data' => 'garbage']);
        $this->assertSame(1, Event::query()->count());
    }

    public function test_admin_packs_settings_round_trip_and_validation(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $base = $this->validSettingsPayload();

        $this->actingAs($admin)->put(route('admin.settings.update'), $base + [
            'content_rewrite_monthly_free' => 7,
            'content_rewrite_packs' => "10:5\n25:20\n50:35",
        ])->assertRedirect();

        $this->assertSame(7, \App\Support\ContentAutopilotConfig::rewriteMonthlyFree());
        $this->assertSame([
            ['credits' => 10, 'usd' => 5],
            ['credits' => 25, 'usd' => 20],
            ['credits' => 50, 'usd' => 35],
        ], \App\Support\ContentAutopilotConfig::rewritePacks());

        $this->actingAs($admin)->put(route('admin.settings.update'), $base + [
            'content_rewrite_monthly_free' => 5,
            'content_rewrite_packs' => 'ten for five dollars',
        ])->assertSessionHasErrors('content_rewrite_packs');
    }

    /** Full valid payload for the admin settings PUT (LlmProviderSwitchTest pattern). */
    private function validSettingsPayload(): array
    {
        config(['services.mistral.key' => 'test-key']);

        return [
            'llm_provider' => 'mistral',
            'model' => 'mistral-small-latest',
            'deepseek_model' => 'deepseek-chat',
            'default_check_interval_hours' => 24,
            'keyword_volume_provider' => 'keywords_everywhere',
            'banner_type' => 'image',
            'autopilot_max_inline' => 2,
            'autopilot_rendering_speed' => 'TURBO',
            'autopilot_style_type' => 'AUTO',
            'autopilot_target_score' => 85, 'autopilot_max_revisions' => 3, 'autopilot_publish_floor' => 60,
            'content_monthly_usd' => 39, 'content_annual_usd' => 29, 'content_addon_monthly_usd' => 15,
            'content_addon_annual_usd' => 10, 'content_first_month_usd' => 1,
            'content_trial_days' => 5, 'content_trial_articles' => 3,
            'content_monthly_articles_per_website' => 30, 'content_only_crawl_pages' => 200,
            'content_tracker_keywords' => 500, 'content_trial_tracker_keywords' => 3,
        ];
    }
}
