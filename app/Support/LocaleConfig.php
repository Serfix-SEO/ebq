<?php

namespace App\Support;

use App\Http\Middleware\SetLocale;
use App\Models\Setting;

/**
 * Platform-wide language availability (admin-configurable kill switch).
 *
 * When multilingual is OFF, the whole app runs at config('app.locale')
 * (English): the first-visit language picker never shows, the EN/AR
 * switchers are hidden, /locale/{locale} only accepts the default, and
 * mailables render English regardless of a stored user/guest locale.
 * Stored `users.locale` / `ebq_locale` cookies are left intact so
 * re-enabling restores everyone's previous choice.
 */
class LocaleConfig
{
    public const SETTING_MULTILINGUAL = 'locale.multilingual_enabled';

    /** The raw stored flag — use this for the admin settings form only. */
    public static function multilingualEnabled(): bool
    {
        return (bool) Setting::get(self::SETTING_MULTILINGUAL, false);
    }

    /**
     * Whether multilingual is in effect for the CURRENT request: the stored
     * flag, force-overridden ON for a logged-in admin so they can preview
     * the Arabic experience while it stays off for everyone else. Queue
     * workers have no authenticated user, so mailables are never affected
     * by the override.
     */
    public static function active(): bool
    {
        return self::multilingualEnabled()
            || (bool) auth()->user()?->is_admin;
    }

    public static function setMultilingualEnabled(bool $enabled): void
    {
        Setting::set(self::SETTING_MULTILINGUAL, $enabled);
    }

    /** Locales selectable right now (switchers, /locale/{locale}). */
    public static function supported(): array
    {
        return self::active()
            ? SetLocale::SUPPORTED
            : [config('app.locale')];
    }

    /**
     * Clamp a stored locale (user column, guest-table snapshot) to what the
     * platform currently allows — mailables must call this, not use the raw
     * column, or a disabled language keeps leaking into queued mail.
     */
    public static function resolve(?string $locale): string
    {
        return in_array($locale, self::supported(), true)
            ? $locale
            : config('app.locale');
    }
}
