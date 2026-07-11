<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    /**
     * Set the visitor's language (popup choice or the persistent switcher).
     * Cookie for everyone (1 year); the `users.locale` column too when
     * authenticated, so the choice follows them to a new browser/device.
     */
    public function set(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, \App\Support\LocaleConfig::supported(), true), 404);

        if ($request->user()) {
            $request->user()->forceFill(['locale' => $locale])->save();
        }

        return redirect(url()->previous() !== url()->current() ? url()->previous() : '/')
            ->withCookie(cookie(SetLocale::COOKIE, $locale, 60 * 24 * 365));
    }
}
