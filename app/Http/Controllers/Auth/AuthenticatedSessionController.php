<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\ClientActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request, ClientActivityLogger $logger): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $user = Auth::user();
        $websiteId = session('current_website_id');
        if ($user && ($websiteId === null || $websiteId === '')) {
            $first = $user->accessibleWebsitesQuery()->select('id')->orderBy('domain')->first();
            if ($first) {
                $websiteId = (string) $first->id;
                session(['current_website_id' => $websiteId]);
            }
        }
        if ($user) {
            $logger->log('auth.login', userId: $user->id, meta: ['ip' => $request->ip()]);
        }

        // Public tool gate → return to the tool result (safe local path only).
        $redirect = (string) $request->input('redirect', '');
        if ($redirect !== '' && str_starts_with($redirect, '/') && ! str_starts_with($redirect, '//')) {
            return redirect()->to($redirect);
        }

        // Came from the homepage "Analyze website" funnel → back to that report.
        // Attach the domain only for accounts with NO websites yet (a signup
        // that never completed the funnel); users with existing sites are
        // likely researching a competitor and must not silently spend a plan
        // slot on it.
        $analyzeDomain = (string) $request->session()->pull('analyze_domain', '');
        if ($analyzeDomain !== '') {
            if ($user && ! $user->hasAccessibleWebsites()) {
                app(\App\Services\WebsiteAttachService::class)->attach($user, $analyzeDomain);
            }

            return redirect()->route('report.view', ['url' => $analyzeDomain]);
        }

        $fallback = $user ? $user->firstAccessibleRoute($websiteId) : 'dashboard';

        return redirect()->intended(route($fallback, absolute: false));
    }

    public function destroy(Request $request, ClientActivityLogger $logger): RedirectResponse
    {
        if ($request->user()) {
            $logger->log('auth.logout', userId: $request->user()->id, meta: ['ip' => $request->ip()]);
        }
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
