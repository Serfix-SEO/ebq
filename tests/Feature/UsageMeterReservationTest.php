<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\ClientActivityLogger;
use App\Services\Usage\UsageMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for the non-atomic assertCanSpend() race (found + fixed
 * 2026-07-06, infra/billing/usage.md §Gotchas): units_consumed is only
 * logged to client_activities AFTER the external call completes, so two
 * concurrent calls could each read the same consumedInWindow() and both
 * pass, collectively overshooting the plan cap. Fixed with a Redis-backed
 * reservation: assertCanSpend() reserves atomically on success,
 * ClientActivityLogger::log() releases once the real row lands.
 */
class UsageMeterReservationTest extends TestCase
{
    use RefreshDatabase;

    private function userWithMistralLimit(int $limit): User
    {
        Plan::create([
            'slug' => 'trial',
            'name' => 'Trial',
            'is_active' => true,
            'api_limits' => ['mistral' => ['monthly_tokens' => $limit]],
        ]);

        return User::factory()->create();
    }

    public function test_concurrent_call_is_blocked_by_the_first_calls_reservation(): void
    {
        $user = $this->userWithMistralLimit(100);
        $meter = app(UsageMeter::class);

        // Call A passes and reserves 60 units (hasn't logged the real row yet
        // — that only happens after its external Mistral call returns).
        $meter->assertCanSpend($user, 'mistral', 60);
        $this->assertSame(60, $meter->pendingReserved($user->id, 'mistral'));

        // Call B arrives concurrently, before A logs. Pre-fix, B would read
        // consumedInWindow()=0 and wrongly pass (0+50<=100), collectively
        // overshooting to 110 once both logged. Post-fix, B sees A's
        // reservation and is correctly blocked.
        $this->expectException(\App\Exceptions\QuotaExceededException::class);
        $meter->assertCanSpend($user, 'mistral', 50);
    }

    public function test_release_clears_the_reservation_without_double_counting(): void
    {
        $user = $this->userWithMistralLimit(100);
        $meter = app(UsageMeter::class);
        $logger = app(ClientActivityLogger::class);

        $meter->assertCanSpend($user, 'mistral', 60);
        $this->assertSame(60, $meter->pendingReserved($user->id, 'mistral'));

        // Call A's external Mistral call completes — the real usage gets
        // logged, which must release the reservation (not leave it stacked
        // on top of the now-persisted row).
        $logger->log(type: 'ai_writer', userId: $user->id, provider: 'mistral', unitsConsumed: 60);

        $this->assertSame(0, $meter->pendingReserved($user->id, 'mistral'));
        $this->assertSame(60, $meter->consumedInWindow($user, 'mistral'));

        // A subsequent call for 30 more units (60 + 30 = 90 <= 100) must
        // succeed. If release() hadn't cleared the reservation, this would
        // wrongly see 60 (consumed) + 60 (stale reservation) + 30 = 150 and
        // throw even though real usage is only 60.
        $meter->assertCanSpend($user, 'mistral', 30);
        $this->assertTrue(true); // no exception thrown
    }
}
