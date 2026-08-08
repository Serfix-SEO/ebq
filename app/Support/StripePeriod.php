<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Subscription;

/**
 * When Stripe will next charge a subscription.
 *
 * Cashier keeps no period-end column, so this has to come off the Stripe
 * object — and WHERE it lives moved. Up to API version 2025-03-31 the
 * Subscription itself carried `current_period_end`; from `basil` (this account
 * is on 2025-08-27.basil) that field is gone from the Subscription and lives on
 * each subscription ITEM instead, because items can now bill on different
 * cycles. Reading only the old location returns null on every modern account,
 * which is exactly what /billing showed: "Next charge —" for a live monthly
 * subscription, on BOTH products.
 *
 * Items are the source of truth; the subscription-level field is kept as a
 * fallback so an older API version keeps working. With several items the
 * EARLIEST end is the honest answer — that is the next time money moves.
 */
final class StripePeriod
{
    public static function nextChargeAt(?Subscription $subscription): ?Carbon
    {
        if ($subscription === null) {
            return null;
        }

        try {
            $stripeSub = $subscription->asStripeSubscription();
        } catch (\Throwable $e) {
            Log::warning('StripePeriod: could not read subscription '.$subscription->stripe_id.': '.$e->getMessage());

            return null;
        }

        return self::fromStripeSubscription($stripeSub);
    }

    /**
     * The read itself, split out so it can be tested without Stripe.
     *
     * @param  object  $stripeSub  a Stripe Subscription (or anything shaped like one)
     */
    public static function fromStripeSubscription(object $stripeSub): ?Carbon
    {
        $ends = [];
        foreach ($stripeSub->items->data ?? [] as $item) {
            if (($item->current_period_end ?? null) !== null) {
                $ends[] = (int) $item->current_period_end;
            }
        }

        // Pre-basil accounts still answer here.
        if ($ends === [] && ($stripeSub->current_period_end ?? null) !== null) {
            $ends[] = (int) $stripeSub->current_period_end;
        }

        return $ends === [] ? null : Carbon::createFromTimestamp(min($ends));
    }
}
