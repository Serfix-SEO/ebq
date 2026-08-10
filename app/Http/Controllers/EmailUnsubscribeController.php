<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Signed unsubscribe link carried by lifecycle/marketing emails.
 *
 * GET renders a confirm page and must NOT mutate — mail scanners prefetch
 * links, and a prefetch that opts the user out would silently kill the
 * whole funnel for them. POST (the page button, or an RFC 8058 one-click
 * POST from the mail provider — CSRF-exempt in bootstrap/app.php) stamps
 * users.marketing_emails_opted_out_at idempotently.
 *
 * Scope: suppresses lifecycle/marketing mail only. Transactional mail
 * (verification, trial-deletion notices, published-article) is unaffected.
 */
class EmailUnsubscribeController extends Controller
{
    public function show(Request $request, User $user): View
    {
        return view('emails.unsubscribe-confirm', [
            'user' => $user,
            'done' => $user->marketing_emails_opted_out_at !== null,
        ]);
    }

    public function store(Request $request, User $user): View
    {
        if ($user->marketing_emails_opted_out_at === null) {
            $user->forceFill(['marketing_emails_opted_out_at' => now()])->save();
        }

        return view('emails.unsubscribe-confirm', [
            'user' => $user,
            'done' => true,
        ]);
    }
}
