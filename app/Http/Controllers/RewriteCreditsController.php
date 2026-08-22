<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Content\ContentEntitlements;
use App\Services\Content\RewriteCredits;
use App\Services\Content\StripeSessionReader;
use App\Support\ContentAutopilotConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * One-time purchases of article-rewrite credit packs (Cashier payment-mode
 * Checkout with an ad-hoc price). Fulfillment is idempotent by the Stripe
 * session id (unique column) and happens on BOTH the success return and the
 * checkout.session.completed webhook — whichever lands first wins, the other
 * no-ops.
 */
class RewriteCreditsController extends Controller
{
    public function checkout(Request $request, ContentEntitlements $entitlements)
    {
        $user = $request->user();
        // Trial users may buy packs (owner decision); anyone with no content
        // relationship at all may not.
        abort_unless($entitlements->hasContentSubscription($user) || $entitlements->onContentTrial($user), 403);

        $packs = ContentAutopilotConfig::rewritePacks();
        $index = (int) $request->query('pack', -1);
        abort_unless(isset($packs[$index]), 404);
        [$credits, $usd] = [$packs[$index]['credits'], $packs[$index]['usd']];

        $topicId = trim((string) $request->query('topic', ''));

        // success_url by STRING CONCAT: route() params would percent-encode
        // the {CHECKOUT_SESSION_ID} placeholder braces (Arr::query RFC3986).
        $successUrl = route('content.credits.success').'?session_id={CHECKOUT_SESSION_ID}'
            .($topicId !== '' ? '&topic='.urlencode($topicId) : '');
        $cancelUrl = $topicId !== '' ? route('content.review', $topicId) : route('billing.show');

        return $user->checkoutCharge($usd * 100, $credits.' article rewrite credits', 1, [
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'kind' => 'rewrite_credits',
                'user_id' => (string) $user->id,
                'credits' => (string) $credits,
                'pack' => (string) $index,
            ],
        ]);
    }

    public function success(Request $request, StripeSessionReader $sessions, RewriteCredits $credits): RedirectResponse
    {
        $user = $request->user();
        $sessionId = trim((string) $request->query('session_id', ''));
        $topicId = trim((string) $request->query('topic', ''));
        $back = $topicId !== ''
            ? redirect()->route('content.review', $topicId)
            : redirect()->route('billing.show');

        if ($sessionId === '') {
            return $back;
        }

        $session = $sessions->retrieve($user, $sessionId);
        $meta = $session !== null ? (array) ($session->metadata ?? []) : [];
        if ($session === null
            || ($session->payment_status ?? '') !== 'paid'
            || ($meta['kind'] ?? '') !== 'rewrite_credits'
            || ($meta['user_id'] ?? '') !== (string) $user->id) {
            Log::warning('rewrite_credits.success_verification_failed', ['session' => $sessionId, 'user' => $user->id]);

            return $back->with('review-status', __('We couldn\'t confirm this payment yet — your credits will appear shortly if it went through.'));
        }

        $granted = $credits->grantForPurchase($user, $sessionId, (int) ($meta['credits'] ?? 0));

        return $back->with('review-status', $granted
            ? __(':n rewrite credits added.', ['n' => (int) $meta['credits']])
            : __('Your rewrite credits are already in your account.'));
    }
}
