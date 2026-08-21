<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ClientActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ClientImpersonationController extends Controller
{
    public function start(Request $request, User $user, ClientActivityLogger $logger): RedirectResponse
    {
        $admin = $request->user();
        if (! $admin || ! $admin->is_admin) {
            abort(403);
        }
        if ($user->is_disabled) {
            return back()->withErrors(['impersonate' => 'Cannot impersonate a disabled user.']);
        }

        session([
            'impersonator_id' => $admin->id,
            'impersonator_return_url' => url()->previous(),
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        $logger->log('admin.impersonation_started', userId: $user->id, actorUserId: $admin->id);

        // Land where the CLIENT lands — 'dashboard' 301s to Site Health when
        // the SEO UI is off, which is not what they see after login. Pick
        // their first website like the login controller does (the regenerated
        // session has no current_website_id yet).
        $websiteId = $user->accessibleWebsitesQuery()->select('id')->orderBy('domain')->value('id');
        if ($websiteId !== null) {
            session(['current_website_id' => (string) $websiteId]);
        }

        return redirect()->route($user->firstAccessibleRoute($websiteId !== null ? (string) $websiteId : null));
    }

    public function stop(Request $request, ClientActivityLogger $logger): RedirectResponse
    {
        $impersonatorId = session('impersonator_id');
        if (($impersonatorId === null || $impersonatorId === '')) {
            $user = Auth::user();

            return redirect()->route($user ? $user->firstAccessibleRoute(session('current_website_id')) : 'login');
        }

        $impersonatedId = Auth::id();
        $returnUrl = (string) session('impersonator_return_url', route('admin.clients.index'));
        $request->session()->forget(['impersonator_id', 'impersonator_return_url']);

        Auth::loginUsingId($impersonatorId);
        $request->session()->regenerate();

        if ($impersonatedId) {
            $logger->log('admin.impersonation_ended', userId: $impersonatedId, actorUserId: $impersonatorId);
        }

        return redirect()->to($returnUrl);
    }
}
