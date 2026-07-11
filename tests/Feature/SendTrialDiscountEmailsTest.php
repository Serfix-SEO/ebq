<?php

namespace Tests\Feature;

use App\Mail\TrialDiscountMail;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendTrialDiscountEmailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.stripe.winback_promo_code' => 'SAVE30',
            'services.stripe.winback_promo_percent' => 30,
        ]);
        Plan::create(['slug' => 'trial', 'name' => 'Trial', 'trial_days' => 14, 'is_active' => true, 'max_websites' => 1]);
    }

    private function user(int $ageHours, array $attrs = []): User
    {
        $u = User::factory()->create(array_merge(['email_verified_at' => now()], $attrs));
        $u->forceFill(['created_at' => now()->subHours($ageHours)])->saveQuietly();

        return $u->fresh();
    }

    public function test_sends_once_to_active_trial_users_only(): void
    {
        Mail::fake();

        $active = $this->user(3 * 24);                                        // day 4 — gets it
        $day1 = $this->user(6);                                               // too fresh — skipped
        $expired = $this->user(20 * 24);                                      // expired — countdown funnel
        $comped = $this->user(3 * 24, ['current_plan_slug' => 'pro']);        // comped — skipped
        $unverified = $this->user(3 * 24, ['email_verified_at' => null]);     // skipped

        $this->artisan('ebq:send-trial-discount-emails')->assertSuccessful();

        Mail::assertSentCount(1);
        Mail::assertSent(TrialDiscountMail::class, fn ($m) => $m->hasTo($active->email));
        $this->assertNotNull($active->fresh()->trial_discount_email_sent_at);
        $this->assertNull($day1->fresh()->trial_discount_email_sent_at);

        // Second run: no duplicate.
        $this->artisan('ebq:send-trial-discount-emails')->assertSuccessful();
        Mail::assertSentCount(1);
    }

    public function test_disabled_without_promo_code(): void
    {
        Mail::fake();
        config(['services.stripe.winback_promo_code' => '']);
        $this->user(3 * 24);

        $this->artisan('ebq:send-trial-discount-emails')->assertSuccessful();
        Mail::assertNothingSent();
    }
}
