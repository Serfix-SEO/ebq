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
