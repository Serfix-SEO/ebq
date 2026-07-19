<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Laravel\Cashier\Checkout;
use Laravel\Cashier\Exceptions\IncompletePayment;

/**
 * Billing flow — Stripe Checkout + Customer Portal + in-app subscription
 * management via Cashier. Billing is per-USER (a single subscription
 * caps how many websites the user can manage; over-limit sites freeze
 * read-only via App\Models\User::frozenWebsiteIds()).
 *
 * Public surfaces:
 *
 *   GET  /billing                  show()                Subscription page
 *   POST /billing/swap             swap()                Switch plan (Cashier swap, prorated)
 *   POST /billing/cancel           cancelSubscription()  Cancel at period end
 *   POST /billing/resume           resume()              Undo a pending cancel
 *   GET  /billing/checkout         checkout()            Stripe Hosted Checkout
 *   GET  /billing/success          success()             Stripe redirected back, success
 *   GET  /billing/cancel-checkout  cancel()              Stripe redirected back, user backed out
 *   GET  /billing/portal           portal()              Stripe Customer Portal (cards / invoices)
 *
 * Tier sync runs in two places:
 *   1. Optimistic — `success()` writes `User->current_plan_slug` immediately
 *      so the user doesn't see a stale state during the brief window between
 *      the Stripe redirect and the webhook landing.
 *   2. Authoritative — `StripeWebhookController` handles every subscription
 *      lifecycle event (created / updated / deleted) and is the source of
 *      truth.
 */
class BillingController extends Controller
{
    /**
     * Mint a Stripe Hosted Checkout session for the chosen plan and
     * redirect. Trial days are read from the Plan row so the marketing
     * page, the WP plugin wizard, and the actual Stripe trial all stay in sync.
     */
    public function checkout(Request $request): RedirectResponse|Response|Checkout
    {
        $request->validate([
            'plan'     => 'required|string|max:32',
            'interval' => 'nullable|in:monthly,annual',
            'return_to' => 'nullable|string|max:2048',
            'promo'    => 'nullable|string|max:32',
        ]);

        $interval = $request->input('interval', 'annual');

        $plan = Plan::where('slug', $request->string('plan'))
            ->where('is_active', true)
            ->first();
        if (! $plan || ! $plan->isCheckoutReady($interval)) {
            return redirect()->route('pricing')
                ->with('error', 'That plan is not available for purchase right now.');
        }

        $user = $request->user();
        if (! $user) {
            return redirect()->route('login', ['redirect_to' => $request->fullUrl()]);
        }

        // If the user is already actively subscribed, route them through
        // the in-app swap flow rather than minting a second subscription
        // (Cashier would happily create one but Stripe would charge twice).
        if ($user->subscribed('default')) {
            return redirect()->route('billing.show')
                ->with('status', 'You already have an active subscription. Use "Switch plan" below to change tiers.');
        }

        // Build return URLs so the WP plugin wizard (or any other
        // referrer) gets sent back to the right surface.
        $returnTo = $this->safeReturnUrl($request->input('return_to'));
        $successUrl = route('billing.success', array_filter(['return_to' => $returnTo]));
        $cancelUrl = route('billing.cancel-checkout', array_filter(['return_to' => $returnTo]));

        $priceId = $plan->stripePriceIdFor($interval);

        // SELF-HEAL: a stripe_id minted under a previous Stripe account (the
        // business account was replaced 2026-07-06) 500s with "No such
        // customer" at checkout. Verify the stored customer still exists and
        // drop it if not — Cashier then creates a fresh one in the current
        // account. Verified once per user (only runs while stripe_id is set
        // and no local subscription exists, so steady-state cost is nil).
        if ($user->hasStripeId() && ! $user->subscribed('default')) {
            try {
                $user->asStripeCustomer();
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                if (str_contains($e->getMessage(), 'No such customer')) {
                    \Illuminate\Support\Facades\Log::warning(
                        "Billing: dropping stale Stripe customer {$user->stripe_id} for {$user->email} (old account)"
                    );
                    $user->forceFill(['stripe_id' => null, 'pm_type' => null, 'pm_last_four' => null])->save();
                } else {
                    throw $e;
                }
            }
        }

        try {
            $builder = $user->newSubscription('default', $priceId);
            $plan->trial_days > 0
                ? $builder->trialDays($plan->trial_days)
                : $builder->skipTrial();

            // Trial discount: every trial-tier user (active trial AND expired,
            // per TrialStatus::isWinbackEligible) gets the campaign
            // discount auto-applied (matches the banner on their billing page —
            // no code entry, no link needed). Otherwise honour ?promo= from the
            // expiry email (parked in the session by show() so it survives the
            // billing-page hop). Anything else falls back to Stripe's own code
            // field — Stripe forbids combining discounts with that field.
            $promo = \App\Support\TrialStatus::isWinbackEligible($user)
                ? (string) config('services.stripe.winback_promo_code')
                : $request->input('promo', $request->session()->get('billing_promo'));
            $promoId = $this->winbackPromotionCodeId($promo);
            $promoId !== null
                ? $builder->withPromotionCode($promoId)
                : $builder->allowPromotionCodes();

            try {
                return $builder->checkout([
                    'success_url' => $successUrl,
                    'cancel_url' => $cancelUrl,
                ]);
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                // A dead/expired promotion (e.g. its coupon was deleted) must
                // never block a purchase: drop the cached id and retry the
                // same checkout at full price with the manual code field.
                if ($promoId === null || ! str_contains(strtolower($e->getMessage()), 'coupon')) {
                    throw $e;
                }
                \Illuminate\Support\Facades\Log::warning("Billing: winback promo {$promoId} rejected by Stripe, checking out without it: {$e->getMessage()}");
                cache()->forget('stripe-winback-promo-id:'.(string) config('services.stripe.winback_promo_code'));

                $retry = $user->newSubscription('default', $priceId);
                $plan->trial_days > 0 ? $retry->trialDays($plan->trial_days) : $retry->skipTrial();

                return $retry->allowPromotionCodes()
                    ->checkout([
                        'success_url' => $successUrl,
                        'cancel_url' => $cancelUrl,
                    ]);
            }
        } catch (IncompletePayment $exception) {
            return redirect()->route(
                'cashier.payment',
                [$exception->payment->id, 'redirect' => $cancelUrl]
            );
        }
    }

    /**
     * Resolve the trial-winback promo (services.stripe.winback_promo_code) to
     * its Stripe promotion-code ID. Only that one campaign code is accepted —
     * anything else returns null (checkout then falls back to Stripe's own
     * promo-code field). Cached 1h; an inactive/deleted code resolves null.
     */
    private function winbackPromotionCodeId(?string $code): ?string
    {
        $campaign = (string) config('services.stripe.winback_promo_code');
        if ($campaign === '' || $code === null || strcasecmp(trim($code), $campaign) !== 0) {
            return null;
        }

        return cache()->remember('stripe-winback-promo-id:'.$campaign, 3600, function () use ($campaign): string {
            try {
                $found = \Laravel\Cashier\Cashier::stripe()->promotionCodes->all([
                    'code' => $campaign, 'active' => true, 'limit' => 1,
                ]);

                return $found->data[0]->id ?? '';
            } catch (\Throwable $e) {
                report($e);

                return '';
            }
        }) ?: null;
    }

    /**
     * Stripe redirected back after successful checkout. Optimistically
     * snapshot the plan slug; webhook will reconfirm.
     */
    public function success(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('dashboard');
        }

        // Webhook-race fallback: Stripe redirects the customer back to
        // `/billing/success` BEFORE the `customer.subscription.created`
        // webhook arrives at our endpoint. Without this sync, the local
        // `subscriptions` table is empty, $user->subscription('default')
        // returns null, the Billing page shows "no plan", AND every
        // website past index 0 is frozen (because websiteLimit()
        // falls back to the free-tier 1).
        //
        // We pull active subscriptions from Stripe directly and upsert
        // local rows. Idempotent — the webhook will overwrite with the
        // same data moments later.
        $this->syncSubscriptionsFromStripe($user);

        $subscription = $user->fresh()->subscription('default');
        if ($subscription && $subscription->valid()) {
            $plan = Plan::where('stripe_price_id_yearly', $subscription->stripe_price)
                ->orWhere('stripe_price_id_monthly', $subscription->stripe_price)
                ->first();
            if ($plan && $user->current_plan_slug !== $plan->slug) {
                $user->forceFill(['current_plan_slug' => $plan->slug])->save();
            }
        }

        $returnTo = $this->safeReturnUrl($request->input('return_to'));
        if ($returnTo) {
            return redirect()->away($returnTo.(str_contains($returnTo, '?') ? '&' : '?').'ebq_billing=success');
        }

        // Pay-first flow: a freshly-registered user paid before doing
        // onboarding. Bounce to onboarding so they can connect their
        // site — `current_website_id` is empty / no domain yet.
        if (! $user->hasAccessibleWebsites()) {
            return redirect()->route('onboarding')->with('status', 'Subscription active — let\'s connect your site.');
        }

        return view('billing.success', [
            'user' => $user,
        ]);
    }

    /**
     * User backed out of the Stripe Hosted Checkout. No state change.
     */
    public function cancel(Request $request): View|RedirectResponse
    {
        $returnTo = $this->safeReturnUrl($request->input('return_to'));
        if ($returnTo) {
            return redirect()->away($returnTo.(str_contains($returnTo, '?') ? '&' : '?').'ebq_billing=cancelled');
        }

        $user = $request->user();
        if ($user && ! $user->hasAccessibleWebsites()) {
            return redirect()->route('onboarding')->with('status', 'No subscription started — you can still use the free tier or upgrade later from Billing.');
        }

        return view('billing.cancel');
    }

    /**
     * Stripe Customer Portal — change card, view invoices, cancel.
     * Kept as the secondary action; primary plan-management lives on
     * the in-app subscription page.
     */
    public function portal(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user || ! $user->hasStripeId()) {
            return redirect()->route('billing.show');
        }
        return $user->redirectToBillingPortal(route('billing.show'));
    }

    /* ───── In-app subscription management (Workstream B) ───── */

    /**
     * Render the Subscription page — current plan + plan grid +
     * cancel / resume + recent invoices + frozen-sites banner.
     */
    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }

        // Winback-email landing: /billing?promo=<code> — remember it so the
        // discount survives the click through a plan card into checkout().
        if ($request->filled('promo')) {
            $request->session()->put('billing_promo', $request->string('promo')->toString());
        }

        // Webhook-race safety net: if the user has a Stripe customer
        // but no active local subscription, the webhook may not have
        // landed yet (or the queue worker is stopped). Pull from Stripe
        // directly so the page never lies about subscription state.
        // Cheap when there's nothing to sync — a single Stripe API call
        // with `limit=1` per render is acceptable on a low-traffic
        // billing page.
        if ($user->hasStripeId() && ! $user->subscribed('default')) {
            $this->syncSubscriptionsFromStripe($user);
        }

        return view('billing.subscription');
    }

    /**
     * Switch plan via Cashier `swap()` (immediate + Stripe-prorated).
     * Idempotent: swapping to the current plan is a no-op (Cashier
     * handles this).
     */
    public function swap(Request $request): RedirectResponse
    {
        $request->validate([
            'plan' => 'required|string|max:32',
        ]);

        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }

        $plan = Plan::where('slug', $request->string('plan'))
            ->where('is_active', true)
            ->first();

        $subscription = $user->subscription('default');
        if (! $subscription || ! $subscription->valid()) {
            // No active subscription — route into the standard checkout
            // flow instead of trying to swap a non-existent sub.
            if (! $plan || ! $plan->isCheckoutReady()) {
                return redirect()->route('billing.show')
                    ->with('error', 'That plan is not available right now.');
            }

            return redirect()->route('billing.checkout', ['plan' => $plan->slug]);
        }

        // Preserve the subscriber's current billing interval — swapping must not
        // silently move a monthly subscriber onto yearly billing for the new tier.
        // NULL interval = the current price isn't in `plans` (legacy/renamed) —
        // fail safe with the picker error instead of guessing an interval.
        $interval = Plan::intervalForStripePrice($subscription->stripe_price);
        if (! $plan || $interval === null || ! $plan->isCheckoutReady($interval)) {
            return redirect()->route('billing.show')
                ->with('error', 'That plan is not available right now.');
        }

        try {
            $subscription->swap($plan->stripePriceIdFor($interval));
        } catch (\Throwable $e) {
            return redirect()->route('billing.show')
                ->with('error', 'Could not switch plan: '.$e->getMessage());
        }

        // Snapshot the new plan slug right away. Webhook reconfirms.
        $user->forceFill(['current_plan_slug' => $plan->slug])->save();

        return redirect()->route('billing.show')
            ->with('status', 'Switched to '.$plan->name.'. Stripe applied a prorated charge or credit automatically.');
    }

    /**
     * Cancel at period end. Pro access continues until the current
     * billing window closes; on that date the webhook fires
     * `customer.subscription.deleted` and tier flips to free.
     */
    public function cancelSubscription(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }

        $subscription = $user->subscription('default');
        if (! $subscription || ! $subscription->valid()) {
            return redirect()->route('billing.show')
                ->with('error', 'No active subscription to cancel.');
        }

        try {
            $subscription->cancel();
        } catch (\Throwable $e) {
            return redirect()->route('billing.show')
                ->with('error', 'Could not cancel: '.$e->getMessage());
        }

        $endsAt = $subscription->fresh()?->ends_at;
        $endsAtLabel = $endsAt ? $endsAt->toFormattedDayDateString() : 'the end of the current period';

        return redirect()->route('billing.show')
            ->with('status', 'Subscription cancelled. You\'ll keep Pro access until '.$endsAtLabel.'.');
    }

    /**
     * Resume a cancelled-but-still-in-grace subscription. Cashier
     * `resume()` clears `ends_at` on the local subscription row and
     * tells Stripe to keep billing.
     */
    public function resume(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }

        $subscription = $user->subscription('default');
        if (! $subscription || ! $subscription->onGracePeriod()) {
            return redirect()->route('billing.show')
                ->with('error', 'No cancelled subscription to resume.');
        }

        try {
            $subscription->resume();
        } catch (\Throwable $e) {
            return redirect()->route('billing.show')
                ->with('error', 'Could not resume: '.$e->getMessage());
        }

        return redirect()->route('billing.show')
            ->with('status', 'Subscription resumed.');
    }

    /**
     * Pull active subscriptions from Stripe directly and upsert the
     * matching local rows. Used to bridge the webhook-race gap (Stripe
     * redirects to /billing/success before the subscription-created
     * webhook lands). Cashier's Billable trait already has the
     * relationship + columns; we just need to seed the row from a
     * stripe.com SDK call.
     *
     * Idempotent — the subsequent webhook will overwrite with the same
     * data. Safe to call multiple times. No-ops when the user has no
     * Stripe customer yet (fresh free-tier user, never paid).
     */
    private function syncSubscriptionsFromStripe(\App\Models\User $user): void
    {
        if (! $user->hasStripeId()) {
            return;
        }

        try {
            $stripeSubs = $user->stripe()->subscriptions->all([
                'customer' => $user->stripe_id,
                'status' => 'all',
                'limit' => 5,
                'expand' => ['data.default_payment_method'],
            ]);
        } catch (\Throwable $e) {
            // Best-effort sync. If Stripe is unreachable, stay silent
            // — the webhook will fix the gap when it lands. Logging at
            // info because this isn't a real failure for the user.
            \Illuminate\Support\Facades\Log::info('Stripe subscription sync failed: '.$e->getMessage());
            return;
        }

        foreach ($stripeSubs->data ?? [] as $stripeSub) {
            $statusActive = in_array(
                $stripeSub->status,
                ['active', 'trialing', 'past_due', 'unpaid'],
                true
            );

            // Existing row for this Stripe subscription (idempotent
            // upsert keyed by stripe_id, which is unique on the table).
            $existing = $user->subscriptions()
                ->where('stripe_id', $stripeSub->id)
                ->first();

            $price = $stripeSub->items->data[0] ?? null;
            $payload = [
                // Resolve the named subscription type the way Cashier's own
                // webhook does (metadata name/type set by SubscriptionBuilder).
                // Hardcoding 'default' would mis-type the content subscription
                // as a second 'default' row and corrupt plan-slug sync.
                'type' => $stripeSub->metadata['type']
                    ?? $stripeSub->metadata['name']
                    ?? 'default',
                'stripe_id' => $stripeSub->id,
                'stripe_status' => $stripeSub->status,
                'stripe_price' => $price?->price?->id,
                'quantity' => $price?->quantity,
                'trial_ends_at' => $stripeSub->trial_end
                    ? \Illuminate\Support\Carbon::createFromTimestamp((int) $stripeSub->trial_end)
                    : null,
                'ends_at' => $stripeSub->cancel_at
                    ? \Illuminate\Support\Carbon::createFromTimestamp((int) $stripeSub->cancel_at)
                    : ($stripeSub->canceled_at
                        ? \Illuminate\Support\Carbon::createFromTimestamp((int) $stripeSub->canceled_at)
                        : null),
            ];

            if ($existing) {
                $existing->forceFill($payload)->save();
            } elseif ($statusActive) {
                // Only seed brand-new local rows for live subs — old
                // canceled-and-gone subscriptions would clutter the table
                // with no upside.
                $user->subscriptions()->create($payload);
            }
        }

        // Mirror the user-side trial_ends_at off the active subscription
        // so the Billing page can read it directly.
        $active = $user->fresh()->subscription('default');
        if ($active && $active->trial_ends_at && $user->trial_ends_at?->ne($active->trial_ends_at)) {
            $user->forceFill(['trial_ends_at' => $active->trial_ends_at])->save();
        }
    }

    /**
     * Sanitise a `return_to` URL — only allow http(s) to prevent
     * open-redirect to javascript:/data: schemes.
     */
    private function safeReturnUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        $url = trim($url);
        if (! preg_match('/^https?:\/\//i', $url)) {
            return null;
        }
        return $url;
    }
}
