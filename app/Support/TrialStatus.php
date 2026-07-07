<?php

namespace App\Support;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Single source of truth for "is this user's free trial expired?" — shared by
 * the hourly cleanup command (emails + data deletion) and the billing-lockout
 * middleware, so the two can never disagree.
 *
 * Trial length comes from the Trial plan's `trial_days` (admin-editable at
 * /admin/plans; 0 disables the whole trial-expiry system). Buffer between
 * expiry and data deletion is BUFFER_DAYS.
 *
 * A user is trial-expired when ALL hold:
 *   - not an admin
 *   - no active Stripe subscription (Cashier `subscribed('default')`)
 *   - not comped (current_plan_slug empty or 'trial' — admin force-applied
 *     paid plans must keep full access)
 *   - account older than the trial length
 *
 * `trial_data_deleted_at` intentionally does NOT reset expiry: a returning
 * expired user keeps their login but never gets a fresh trial.
 */
class TrialStatus
{
    public const BUFFER_DAYS = 3;

    /** Trial length in days; 0 = trial expiry disabled. Cached 10 min. */
    public static function trialDays(): int
    {
        return (int) Cache::remember(
            'trial-status:days',
            600,
            fn () => (int) (Plan::query()->where('slug', 'trial')->value('trial_days') ?? 0),
        );
    }

    public static function isExpired(User $user): bool
    {
        $days = self::trialDays();
        if ($days <= 0 || $user->is_admin) {
            return false;
        }
        if (! empty($user->current_plan_slug) && $user->current_plan_slug !== User::TIER_TRIAL) {
            return false; // comped / snapshot of a paid plan
        }
        if ($user->created_at === null || $user->created_at->gt(Carbon::now()->subDays($days))) {
            return false; // still inside the trial window
        }

        return ! $user->subscribed('default');
    }

    public static function expiryAt(User $user): ?Carbon
    {
        $days = self::trialDays();

        return ($days > 0 && $user->created_at) ? $user->created_at->copy()->addDays($days) : null;
    }

    public static function deletionAt(User $user): ?Carbon
    {
        return self::expiryAt($user)?->addDays(self::BUFFER_DAYS);
    }
}
