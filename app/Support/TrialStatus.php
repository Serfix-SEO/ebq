<?php

namespace App\Support;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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

    /**
     * Winback/launch discount eligibility (2026-07-10): the :percent%-off
     * promo shows for EVERY trial-tier user — active trial included, not just
     * expired — anywhere the discount surfaces (billing panel, pricing page,
     * landing pricing section, checkout auto-apply). Subscribers and comped
     * (force-applied paid) plans never see it; admins are internal accounts.
     * Independent of trialDays() — the promo lives or dies by the
     * services.stripe.winback_promo_code config knob.
     */
    public static function isWinbackEligible(User $user): bool
    {
        if ($user->is_admin) {
            return false;
        }
        if (! empty($user->current_plan_slug) && $user->current_plan_slug !== User::TIER_TRIAL) {
            return false; // comped / snapshot of a paid plan
        }

        return ! $user->subscribed('default');
    }

    /**
     * Team member on at least one OTHER user's website (website_user pivot).
     * Such users work under the owner's plan, so trial expiry must not lock
     * them out of the app — only their OWN websites are on the deletion track.
     */
    public static function isTeamMember(User $user): bool
    {
        return DB::table('website_user')->where('user_id', $user->id)->exists();
    }

    /**
     * Lockout rule for the billing-confinement middleware: expired AND not a
     * team member anywhere. Data deletion (TrialCleanup) intentionally uses
     * the broader isExpired() — an expired team member keeps app access but
     * still loses their own trial websites after the buffer.
     */
    public static function isLockedOut(User $user): bool
    {
        return self::isExpired($user) && ! self::isTeamMember($user);
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
