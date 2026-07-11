<?php

namespace App\Console\Commands;

use App\Mail\TrialDiscountMail;
use App\Models\User;
use App\Support\TrialStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * One-shot 30%-discount promo email to ACTIVE trial users (2026-07-10).
 *
 * Audience: verified, non-admin, trial-tier (no subscription, not comped),
 * NOT yet expired (expired users get the offer inside the TrialExpiryMail
 * countdown instead), at least 1 full day into their trial (so it never
 * lands on top of the signup/verification emails), and never emailed this
 * promo before (users.trial_discount_email_sent_at).
 *
 * Scheduled daily — future signups get their email on trial day 2+.
 * Disabled entirely when services.stripe.winback_promo_code is empty.
 */
class SendTrialDiscountEmails extends Command
{
    protected $signature = 'ebq:send-trial-discount-emails {--dry-run : List recipients without sending}';

    protected $description = 'Email the straight trial discount (winback promo) once to every active trial user.';

    public function handle(): int
    {
        if ((string) config('services.stripe.winback_promo_code') === '') {
            $this->info('Winback promo code not configured — nothing to send.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $candidates = User::query()
            ->where('is_admin', false)
            ->whereNotNull('email_verified_at')
            ->whereNull('trial_discount_email_sent_at')
            ->where('created_at', '<=', now()->subDay())
            ->where(fn ($q) => $q->whereNull('current_plan_slug')->orWhere('current_plan_slug', User::TIER_TRIAL))
            ->orderBy('created_at')
            ->get();

        $sent = 0;
        foreach ($candidates as $user) {
            // Live re-check (subscription state) + expired users belong to
            // the countdown funnel, not this promo.
            if (! TrialStatus::isWinbackEligible($user) || TrialStatus::isExpired($user)) {
                continue;
            }

            if ($dryRun) {
                $this->line("would send to {$user->email} (trial day ".((int) $user->created_at->diffInDays(now()) + 1).')');
                $sent++;

                continue;
            }

            try {
                Mail::to($user->email)->send(new TrialDiscountMail($user));
                $user->forceFill(['trial_discount_email_sent_at' => now()])->save();
                $sent++;
            } catch (\Throwable $e) {
                Log::warning("SendTrialDiscountEmails: mail to {$user->email} failed: {$e->getMessage()}");
            }
        }

        $this->info(($dryRun ? '[dry-run] ' : '')."trial-discount emails: {$sent}");

        return self::SUCCESS;
    }
}
