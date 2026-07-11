<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request locale (en/ar) and shares whether the language
 * picker should render. Admin (`admin*`) is deliberately excluded — it
 * shares the customer dashboard's root layout (no separate admin layout
 * exists), so the cleanest exclusion point is here rather than forking
 * the layout: admin always renders at the config default (en).
 *
 * Resolution order: authenticated user's `locale` column > `ebq_locale`
 * cookie > Accept-Language sniff (ar prefix) > config('app.locale').
 */
class SetLocale
{
    public const SUPPORTED = ['en', 'ar'];

    public const COOKIE = 'ebq_locale';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('admin*')) {
            return $next($request);
        }

        // Admin kill switch (Settings → Languages): force the default locale
        // and never show the picker. Stored user/cookie choices survive
        // untouched for when it's re-enabled. active() overrides ON for a
        // logged-in admin so they can preview Arabic while it's off.
        if (! \App\Support\LocaleConfig::active()) {
            $locale = config('app.locale');
            App::setLocale($locale);
            \Illuminate\Support\Carbon::setLocale($locale);
            View::share('showLocalePicker', false);

            return $next($request);
        }

        $user = $request->user();
        $cookie = $request->cookie(self::COOKIE);

        $locale = $user?->locale
            ?: (in_array($cookie, self::SUPPORTED, true) ? $cookie : null)
            ?: $this->sniff($request)
            ?: config('app.locale');

        if (! in_array($locale, self::SUPPORTED, true)) {
            $locale = config('app.locale');
        }

        App::setLocale($locale);
        // Carbon's locale is a SEPARATE global from Laravel's app locale —
        // ->translatedFormat() (month/day names, AM/PM) silently stays
        // English forever unless this is set too (found 2026-07-07: every
        // format_user_date()/format_user_datetime() call site was rendering
        // English month names under the Arabic locale).
        \Illuminate\Support\Carbon::setLocale($locale);

        // Picker shows only when NOBODY has ever made an explicit choice —
        // not the user column, not the cookie. A guest who already picked
        // (cookie set) but later logs in in a fresh browser still gets
        // asked once (no cookie there), which is correct: it's a new
        // browser's first visit.
        View::share('showLocalePicker', $user?->locale === null && $cookie === null);

        return $next($request);
    }

    private function sniff(Request $request): ?string
    {
        $header = (string) $request->server('HTTP_ACCEPT_LANGUAGE', '');

        return str_starts_with(strtolower($header), 'ar') ? 'ar' : null;
    }
}
