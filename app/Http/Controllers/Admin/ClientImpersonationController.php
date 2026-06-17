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

        return redirect()->route('dashboard');
    }

    public function stop(Request $request, ClientActivityLogger $logger): RedirectResponse
    {
        $impersonatorId = session('impersonator_id');
        if (($impersonatorId === null || $impersonatorId === '')) {
            return redirect()->route('dashboard');
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
