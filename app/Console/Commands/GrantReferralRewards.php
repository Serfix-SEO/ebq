<?php

namespace App\Console\Commands;

use App\Models\Referral;
use App\Services\ReferralProgram;
use Illuminate\Console\Command;

/**
 * Sweep referrals whose Stripe credit didn't land inline (qualified rows the
 * webhook couldn't finish, credit_failed rows from Stripe blips) and retry
 * the grant. Hourly — makes the webhook's inline grant best-effort.
 */
class GrantReferralRewards extends Command
{
    protected $signature = 'ebq:grant-referral-rewards';

    protected $description = 'Retry Stripe balance credits for qualified referral rewards';

    public function handle(ReferralProgram $program): int
    {
        $done = 0;
        Referral::query()
            ->whereIn('status', [Referral::STATUS_QUALIFIED, Referral::STATUS_CREDIT_FAILED])
            ->orderBy('qualified_at')
            ->get()
            ->each(function (Referral $referral) use ($program, &$done): void {
                if ($program->grant($referral)) {
                    $done++;
                }
            });

        $this->info("Credited {$done} referral reward(s).");

        return self::SUCCESS;
    }
}
