<?php

namespace App\Livewire\Billing;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Subscription page Livewire component.
 *
 * Read-only by design: every mutation (swap / cancel / resume) is a
 * standard form POST to BillingController so the public API is the
 * same whether triggered by this UI, a future CLI tool, or a webhook
 * replay. The component is a thin presenter — gather everything the
 * view needs in `render()`, then render.
 *
 * Two pieces of state worth tracking client-side:
 *  - `confirmingCancel` (bool)  — drives the cancel-confirmation modal.
 *  - `confirmingSwap` (?string) — the slug of the plan being swapped to;
 *    null when no swap modal is open.
 */
class SubscriptionPanel extends Component
{
    public bool $confirmingCancel = false;

    public ?string $confirmingSwap = null;

    public function openCancelConfirm(): void
    {
        $this->confirmingCancel = true;
    }

    public function dismissCancelConfirm(): void
    {
        $this->confirmingCancel = false;
    }

    public function openSwapConfirm(string $slug): void
    {
        // Only expose modal state — actual swap is the form POST inside
        // the modal so we don't reach into Cashier from Livewire.
        $this->confirmingSwap = trim($slug) ?: null;
    }

    public function dismissSwapConfirm(): void
    {
        $this->confirmingSwap = null;
    }

    public function render()
    {
        /** @var User $user */
        $user = Auth::user();
        $subscription = $user->subscription('default');

        $plans = Plan::ordered()->get();

        $currentPlan = $user->effectivePlan();
        $isOnTrial = $subscription && $subscription->onTrial();
        // Cashier 16 dropped the British `cancelled()` alias — use the
        // American `canceled()` spelling. Variable name keeps the
        // British spelling because the rest of the view reads it.
        $isCancelled = $subscription && $subscription->canceled() && $subscription->onGracePeriod();
        $isPastDue = $subscription && $subscription->stripe_status === 'past_due';

        $endsAt = $subscription?->ends_at;          // set when cancelled at period end
        $trialEndsAt = $subscription?->trial_ends_at;
        // Cashier doesn't expose period-end on the local model; pull
        // straight from the Stripe object as a one-off when needed.
        $nextChargeAt = null;
        try {
            if ($subscription && $subscription->valid() && ! $isCancelled) {
                $stripeSub = $subscription->asStripeSubscription();
                if (isset($stripeSub->current_period_end)) {
                    $nextChargeAt = \Illuminate\Support\Carbon::createFromTimestamp((int) $stripeSub->current_period_end);
                }
            }
        } catch (\Throwable $_) {
            // Stripe API unreachable — fall through with null. UI shows
            // "—" rather than failing the whole page render.
        }

        // Recent invoices (best-effort). Cashier returns Stripe Invoice
        // objects; we only need date / amount / pdf-url.
        //
        // During a trial Stripe creates a $0 "trial-start" invoice that
        // is noise to the user (no actual charge). Filter it out so the
        // list only shows real-money invoices. Once the trial converts,
        // the first real-charge invoice appears here.
        $invoices = [];
        try {
            if ($user->hasStripeId()) {
                $invoices = collect($user->invoices())
                    ->filter(fn ($inv) => (int) ($inv->total ?? 0) > 0)
                    ->take(3);
            }
        } catch (\Throwable $_) {
            $invoices = [];
        }

        $totalWebsites = $user->websites()->count();
        $websiteLimit = $user->websiteLimit();
        $frozenIds = $user->frozenWebsiteIds();
        $frozenSites = empty($frozenIds)
            ? collect()
            : $user->websites()->whereIn('id', $frozenIds)->orderBy('created_at')->get(['id','domain']);

        // Free-for-limited-time promo mode. When APP_FREE=true (mirrors
        // the /pricing marketing page's behaviour), the plan grid +
        // cancel/danger zone are hidden and replaced with a single
        // "Pro features unlocked free during the launch period" panel.
        // Same env flag, same UX pattern, single source of truth via
        // config('app.free').
        $isFreePromo = (bool) config('app.free');

        // Winback/trial discount: loud banner + the checkout() auto-apply
        // (BillingController). Since 2026-07-10 this shows for every
        // trial-tier user (active trial too), not only expired ones —
        // same config knobs as the h24 expiry email.
        $winbackCode = (string) config('services.stripe.winback_promo_code');
        $showWinback = $winbackCode !== '' && \App\Support\TrialStatus::isWinbackEligible($user);

        // This month's metered usage — used/limit per capped provider, so
        // the billing page shows exactly where the plan's quotas stand.
        $meter = app(\App\Services\Usage\UsageMeter::class);
        $usage = [];
        foreach ([
            'serp_api' => __('Live ranking checks'),
            'keyword_finder' => __('Keyword searches'),
            'mistral' => __('AI research tokens'),
        ] as $provider => $label) {
            $limit = $meter->limit($user, $provider);
            if ($limit === null) {
                continue; // unlimited on this plan — nothing to show
            }
            $used = $meter->consumedInWindow($user, $provider);
            $usage[] = [
                'label' => $label,
                'used' => $used,
                'limit' => $limit,
                'pct' => $limit > 0 ? min(100, (int) round(100 * $used / $limit)) : 100,
            ];
        }

        return view('livewire.billing.subscription-panel', [
            'usage' => $usage,
            'user' => $user,
            'subscription' => $subscription,
            'currentPlan' => $currentPlan,
            'plans' => $plans,
            'isOnTrial' => $isOnTrial,
            'isCancelled' => $isCancelled,
            'isPastDue' => $isPastDue,
            'isFreePromo' => $isFreePromo,
            'endsAt' => $endsAt,
            'trialEndsAt' => $trialEndsAt,
            'nextChargeAt' => $nextChargeAt,
            'invoices' => $invoices,
            'totalWebsites' => $totalWebsites,
            'websiteLimit' => $websiteLimit,
            'frozenSites' => $frozenSites,
            'showWinback' => $showWinback,
            'winbackCode' => $winbackCode,
            'winbackPercent' => (int) config('services.stripe.winback_promo_percent'),
        ]);
    }
}
