<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Admin-tunable knobs for the lifecycle (onboarding-funnel) emails, stored in
 * the `settings` table and flippable live from /admin/lifecycle — no deploy.
 *
 * The migration that creates lifecycle_email_sends seeds every row below, so
 * Setting::get never forever-caches a code default (the documented landmine).
 */
class LifecycleEmailConfig
{
    public const REPLY_TO_ADDRESS = 'fuaad@serfix.io';
    public const REPLY_TO_NAME = 'Fuaad from SERFIX';

    /**
     * Setting::get that fails safe to the default when the settings table is
     * unreachable (fresh deploy pre-migrate, DB-less unit tests) — same
     * philosophy as ContentAutopilotConfig::setting().
     */
    private static function setting(string $key, mixed $default = null): mixed
    {
        try {
            return Setting::get($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    /** Master kill switch. */
    public static function enabled(): bool
    {
        return (string) self::setting('lifecycle.enabled', '1') !== '0';
    }

    public static function segmentEnabled(int $segment): bool
    {
        return (string) self::setting('lifecycle.segment.'.$segment.'.enabled', '1') !== '0';
    }

    /**
     * Max successful sends (initials + follow-ups combined) per daily run.
     * Ramp control for the launch backlog; 0 pauses sending without
     * disabling conversion tracking.
     */
    public static function dailyCap(): int
    {
        return max(0, (int) self::setting('lifecycle.daily_cap', 50));
    }

    /**
     * Users younger than this never get lifecycle mail. Default 3 days so the
     * first touch never stacks onto the signup/verification emails or the
     * trial-discount promo (which lands on trial day 2).
     */
    public static function minAccountAgeDays(): int
    {
        return max(0, (int) self::setting('lifecycle.min_account_age_days', 3));
    }

    /**
     * Days after the initial before the follow-up may send. Segment 1's copy
     * says "about 3-4 days"; the rest say "2-3 days". We use the lower bound —
     * the daily schedule adds its own jitter.
     */
    public static function followupDelayDays(int $segment): int
    {
        return $segment === 1 ? 3 : 2;
    }
}
