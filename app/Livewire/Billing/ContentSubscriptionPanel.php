<?php

namespace App\Livewire\Billing;

use App\Models\User;
use App\Services\Content\ContentEntitlements;
use App\Support\ContentAutopilotConfig;
use App\Support\StripePeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

/**
 * Content Autopilot billing, on the account billing page.
 *
 * Content is a SEPARATE product on its own Cashier subscription (`content`),
 * so the dashboard panel above this one — which reads `default` — showed a
 * paying content customer nothing at all: no plan, no amount, no renewal date,
 * no way to cancel. A content-only client saw a page that said their trial had
 * expired while they were being charged every month (prod 2026-08-08,
 * daomarketing.com). Cancel/resume endpoints existed since launch but had no
 * UI anywhere.
 *
 * Renders nothing for users with no content relationship at all, so the SEO
 * billing page is unchanged for everyone else.
 */
class ContentSubscriptionPanel extends Component
{
    /** Rendered above the SEO panel (content-only customers) — drops the top gap. */
    public bool $first = false;

    /**
     * 'summary' (the subscription you hold), 'plans' (what you could buy), or
     * 'all'. Mirrors SubscriptionPanel::$section so /billing can show both
     * products' billing above both products' pricing.
     */
    public string $section = 'all';

    public bool $confirmingCancel = false;

    public function openCancelConfirm(): void
    {
        $this->confirmingCancel = true;
    }

    public function dismissCancelConfirm(): void
    {
        $this->confirmingCancel = false;
    }

    private function entitlements(): ContentEntitlements
    {
        return app(ContentEntitlements::class);
    }

    public function render()
    {
        /** @var User $user */
        $user = Auth::user();
        $ent = $this->entitlements();
        $sub = $user->subscription(ContentEntitlements::SUBSCRIPTION);

        $hasSub = $ent->hasContentSubscription($user);
        $onTrial = $ent->onContentTrial($user);
        $comped = $ent->compSites($user);

        // The summary half is about a subscription you hold: with no content
        // relationship at all there is nothing to summarise, and the billing
        // page should not become an ad. The plans half always renders — that
        // IS the ad, and it lives behind the product tab where it belongs.
        $hasRelationship = $hasSub || $onTrial || $comped > 0 || $sub !== null;
        if ($this->section !== 'plans' && ! $hasRelationship) {
            return view('livewire.billing.content-subscription-panel', [
                'show' => false,
                'showPlans' => false,
            ]);
        }

        $interval = $this->interval($sub?->stripe_price);
        [$amount, $currency] = $this->amount($user, $sub?->stripe_price, $interval);

        // Period end comes from StripePeriod, which knows the field moved onto
        // the subscription items in Stripe's basil API version. Unreachable
        // API → "—", never a failed page render.
        $isCancelled = $sub !== null && $sub->canceled() && $sub->onGracePeriod();
        $nextChargeAt = ($this->section !== 'plans' && $sub !== null && $sub->valid() && ! $isCancelled)
            ? StripePeriod::nextChargeAt($sub)
            : null;

        return view('livewire.billing.content-subscription-panel', [
            'show' => $this->section !== 'plans',
            'showPlans' => $this->section !== 'summary',
            'prices' => [
                'monthly' => ContentAutopilotConfig::displayPrice('monthly'),
                'annual' => ContentAutopilotConfig::displayPrice('annual'),
                'first_month' => ContentAutopilotConfig::displayPrice('first_month'),
                'addon_monthly' => ContentAutopilotConfig::displayPrice('addon_monthly'),
                'addon_annual' => ContentAutopilotConfig::displayPrice('addon_annual'),
            ],
            'checkoutReady' => [
                'monthly' => ContentAutopilotConfig::checkoutReady('monthly'),
                'annual' => ContentAutopilotConfig::checkoutReady('annual'),
            ],
            'trialDays' => ContentAutopilotConfig::trialDays(),
            'monthlyArticles' => ContentAutopilotConfig::monthlyArticlesPerWebsite(),
            'checkoutWebsiteId' => $user->websites()->value('id'),
            'user' => $user,
            'subscription' => $sub,
            'hasSub' => $hasSub,
            'onTrial' => $onTrial,
            'compedSites' => $comped,
            'trialEndsAt' => $user->content_trial_ends_at,
            'isCancelled' => $isCancelled,
            'isPastDue' => $sub?->stripe_status === 'past_due',
            'endsAt' => $sub?->ends_at,
            'nextChargeAt' => $nextChargeAt,
            'interval' => $interval,
            'amount' => $amount,
            'currency' => $currency,
            'extraSites' => $ent->addonQuantity($user),
            'sitesAllowed' => $ent->sitesAllowed($user),
            'sitesCovered' => $ent->sitesCovered($user),
            'coveredSites' => $ent->coveredWebsites($user),
            'invoices' => $this->contentInvoices($user),
            'rewriteCredits' => app(\App\Services\Content\RewriteCredits::class)->summary($user),
            'rewritePacks' => ContentAutopilotConfig::rewritePacks(),
            'canBuyRewriteCredits' => $hasSub || $onTrial,
            'hasPortal' => Route::has('billing.portal'),
            'hasGetStarted' => Route::has('content.get-started'),
            'hasContentSettings' => Route::has('content.settings'),
        ]);
    }

    /** monthly | annual, from the subscribed price. */
    private function interval(?string $priceId): string
    {
        $annual = ContentAutopilotConfig::priceId('annual');

        return ($priceId !== null && $annual !== null && $priceId === $annual) ? 'annual' : 'monthly';
    }

    /**
     * What the customer is actually charged, read from Stripe so a price change
     * needs no code edit. Falls back to the configured display price — annual
     * ×12, since that plan is shown per month and billed per year.
     *
     * @return array{0: float|null, 1: string}
     */
    private function amount(User $user, ?string $priceId, string $interval): array
    {
        if ($priceId !== null && $priceId !== '') {
            try {
                $price = $user->stripe()->prices->retrieve($priceId);
                if (($price->unit_amount ?? null) !== null) {
                    return [round($price->unit_amount / 100, 2), strtoupper((string) $price->currency)];
                }
            } catch (\Throwable) {
                // fall through to the configured price
            }
        }

        $display = (float) ContentAutopilotConfig::displayPrice($interval);

        return [$interval === 'annual' ? $display * 12 : $display, 'USD'];
    }

    /**
     * Invoices for THIS product only. One Stripe customer carries both
     * subscriptions, so an unfiltered list would show the SEO plan's charges
     * under a Content Autopilot heading and vice versa.
     *
     * @return array<int, array{date: ?Carbon, total: int, currency: string, url: ?string}>
     */
    private function contentInvoices(User $user): array
    {
        $contentPrices = array_filter([
            ContentAutopilotConfig::priceId('monthly'),
            ContentAutopilotConfig::priceId('annual'),
            ContentAutopilotConfig::addonPriceId('monthly'),
            ContentAutopilotConfig::addonPriceId('annual'),
        ]);
        if ($this->section === 'plans' || $contentPrices === [] || ! $user->hasStripeId()) {
            return [];
        }

        try {
            $invoices = collect($user->invoices())
                ->filter(function ($invoice) use ($contentPrices) {
                    foreach ($invoice->lines->data ?? [] as $line) {
                        $price = $line->price->id ?? $line->pricing->price_details->price ?? null;
                        if ($price !== null && in_array($price, $contentPrices, true)) {
                            return true;
                        }
                    }

                    return false;
                })
                ->take(6);
        } catch (\Throwable) {
            return [];
        }

        return $invoices->map(fn ($invoice) => [
            // date(), not `created`: Cashier's Invoice defines __get but NOT
            // __isset, so isset($invoice->created) is FALSE even though the
            // value is right there — the date rendered as "—" beside a
            // perfectly good amount (prod 2026-08-08).
            'date' => $invoice->date(),
            'total' => (int) $invoice->rawTotal(),
            'currency' => strtoupper((string) ($invoice->currency ?? 'usd')),
            'url' => $invoice->invoice_pdf ?? $invoice->hosted_invoice_url ?? null,
        ])->values()->all();
    }
}
