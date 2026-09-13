<?php

namespace App\Services\Admin;

use App\Models\Subscription;
use App\Support\StripePeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

/**
 * Money in, and money due, for Content Autopilot subscribers over a date range.
 *
 * Two series on one timeline:
 *   - COLLECTED  — paid Stripe invoices, bucketed by the day they were paid.
 *                  History, not an estimate.
 *   - SCHEDULED  — what Stripe will charge on each future renewal date inside
 *                  the range, projected forward by the billing interval.
 *
 * Rules this class keeps (mirrors DashboardController::stripeSnapshot):
 *  - Money comes from STRIPE, never derived from local price settings. Local
 *    rows are used only to decide WHICH subscriptions are Content Autopilot
 *    (`subscriptions.type = 'content'`), because Stripe has no idea about our
 *    two-product split.
 *  - Failure-tolerant: a Stripe outage returns `available => false` so the
 *    admin page renders a dash, never a 500.
 *  - Cached per range, briefly, because the dashboard is hit often and every
 *    build costs two paginated Stripe calls.
 *
 * Honest limits, surfaced in the UI:
 *  - SCHEDULED is list price × quantity. Coupons (the $1 first month),
 *    proration and tax are applied by Stripe at invoice time, so a scheduled
 *    bar can read higher than the invoice eventually does.
 *  - A subscription set to cancel at period end contributes NOTHING: it will
 *    not be charged again.
 *  - Nobody can predict a future cancellation, so scheduled money is a
 *    forecast of today's subscriber base, not a promise.
 */
class ContentPaymentSeries
{
    /** Stripe page size; both calls auto-page beyond this. */
    private const PAGE = 100;

    /** Safety rail on auto-paging so a runaway account can't hang the page. */
    private const MAX_ROWS = 2000;

    /**
     * @return array{available: bool, currency: string, days: list<array{date: string, label: string, collected: float, scheduled: float}>,
     *               collected_total: float, scheduled_total: float, collected_count: int,
     *               subscribers: int, upcoming: list<array{at: Carbon, email: ?string, amount: float, interval: string}>,
     *               recent: list<array{at: Carbon, email: ?string, amount: float, url: ?string}>}
     */
    public function build(Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $unavailable = [
            'available' => false, 'currency' => 'USD', 'days' => $this->emptyDays($from, $to),
            'collected_total' => 0.0, 'scheduled_total' => 0.0, 'collected_count' => 0,
            'subscribers' => 0, 'upcoming' => [], 'recent' => [],
        ];

        $secret = (string) config('cashier.secret');
        if ($secret === '') {
            return $unavailable;
        }

        $key = 'admin:content-payments:v1:'.$from->toDateString().':'.$to->toDateString();

        return Cache::remember($key, 600, function () use ($secret, $from, $to, $unavailable) {
            try {
                return $this->compute(new StripeClient($secret), $from, $to);
            } catch (\Throwable $e) {
                Log::warning('Admin content payments: Stripe read failed: '.$e->getMessage());

                return $unavailable;
            }
        });
    }

    /** @return array<string, mixed> */
    private function compute(StripeClient $stripe, Carbon $from, Carbon $to): array
    {
        // Which Stripe subscriptions are Content Autopilot? Local rows are the
        // only place that split exists. Every content sub ever created counts
        // for COLLECTED (a cancelled sub still paid real invoices); only live
        // ones can be charged again.
        $content = Subscription::query()
            ->where('type', 'content')
            ->get(['stripe_id', 'stripe_status', 'user_id']);

        $contentIds = $content->pluck('stripe_id')->filter()->flip();          // stripe_id => idx
        $liveIds = $content->whereIn('stripe_status', ['active', 'trialing', 'past_due'])
            ->pluck('stripe_id')->filter()->flip();

        $emails = \App\Models\User::query()
            ->whereIn('id', $content->pluck('user_id')->filter()->unique())
            ->pluck('email', 'id');
        $emailFor = fn (?string $userId) => $userId !== null ? ($emails[$userId] ?? null) : null;
        $userForSub = $content->pluck('user_id', 'stripe_id');

        $days = $this->emptyDays($from, $to);
        $index = array_flip(array_column($days, 'date'));   // 'Y-m-d' => position
        $currency = 'USD';

        // ── Collected: paid invoices inside the window ──────────────────
        $collectedTotal = 0.0;
        $collectedCount = 0;
        $recent = [];
        $seen = 0;
        foreach ($stripe->invoices->all([
            'status' => 'paid',
            'created' => ['gte' => $from->getTimestamp(), 'lte' => $to->getTimestamp()],
            'limit' => self::PAGE,
        ])->autoPagingIterator() as $inv) {
            if (++$seen > self::MAX_ROWS) {
                break;
            }
            $subId = $this->invoiceSubscriptionId($inv);
            if ($subId === null || ! isset($contentIds[$subId])) {
                continue;   // SEO-product or one-off invoice — not this graph
            }
            $amount = (int) ($inv->amount_paid ?? 0);
            if ($amount <= 0) {
                continue;   // $0 trial-start invoices are noise
            }
            $currency = strtoupper((string) ($inv->currency ?? 'usd'));
            $at = Carbon::createFromTimestamp((int) $inv->created);
            $key = $at->toDateString();
            if (isset($index[$key])) {
                $days[$index[$key]]['collected'] += $amount / 100;
            }
            $collectedTotal += $amount / 100;
            $collectedCount++;
            if (count($recent) < 8) {
                $recent[] = [
                    'at' => $at,
                    'email' => $inv->customer_email ?? $emailFor($userForSub[$subId] ?? null),
                    'amount' => round($amount / 100, 2),
                    'url' => $inv->hosted_invoice_url ?? null,
                ];
            }
        }

        // ── Scheduled: every future renewal that lands inside the window ──
        $scheduledTotal = 0.0;
        $upcoming = [];
        $subscribers = 0;
        $seen = 0;
        foreach ($stripe->subscriptions->all([
            'status' => 'active',
            'limit' => self::PAGE,
        ])->autoPagingIterator() as $sub) {
            if (++$seen > self::MAX_ROWS) {
                break;
            }
            if (! isset($liveIds[$sub->id])) {
                continue;
            }
            $subscribers++;

            // Set to cancel at period end → Stripe will not charge it again.
            if (($sub->cancel_at_period_end ?? false) === true) {
                continue;
            }

            $next = StripePeriod::fromStripeSubscription($sub);
            if ($next === null) {
                continue;
            }
            [$amount, $interval, $count] = $this->recurringAmount($sub);
            if ($amount <= 0) {
                continue;
            }
            $currency = strtoupper((string) ($sub->currency ?? strtolower($currency)));

            foreach ($this->projectCharges($next, $interval, $count, $from, $to) as $at) {
                $key = $at->toDateString();
                if (isset($index[$key])) {
                    $days[$index[$key]]['scheduled'] += $amount;
                }
                $scheduledTotal += $amount;
                if (count($upcoming) < 200) {
                    $upcoming[] = [
                        'at' => $at,
                        'email' => $emailFor($userForSub[$sub->id] ?? null),
                        'amount' => round($amount, 2),
                        'interval' => $interval,
                    ];
                }
            }
        }

        usort($upcoming, fn ($a, $b) => $a['at'] <=> $b['at']);

        return [
            'available' => true,
            'currency' => $currency,
            'days' => array_map(fn ($d) => $d + [
                'collected' => round($d['collected'], 2),
                'scheduled' => round($d['scheduled'], 2),
            ], $days),
            'collected_total' => round($collectedTotal, 2),
            'scheduled_total' => round($scheduledTotal, 2),
            'collected_count' => $collectedCount,
            'subscribers' => $subscribers,
            'upcoming' => $upcoming,
            'recent' => $recent,
        ];
    }

    /**
     * The subscription an invoice belongs to. Stripe moved this: up to API
     * 2025-03-31 it was `invoice.subscription`; from `basil` it lives under
     * `invoice.parent.subscription_details.subscription`. Reading only the old
     * location returns null on a modern account and the whole graph reads
     * zero — the same trap StripePeriod documents for period ends.
     */
    public function invoiceSubscriptionId(object $invoice): ?string
    {
        $direct = $invoice->subscription ?? null;
        if (is_string($direct) && $direct !== '') {
            return $direct;
        }
        if (is_object($direct) && isset($direct->id)) {
            return (string) $direct->id;
        }

        $nested = $invoice->parent->subscription_details->subscription ?? null;
        if (is_string($nested) && $nested !== '') {
            return $nested;
        }
        if (is_object($nested) && isset($nested->id)) {
            return (string) $nested->id;
        }

        return null;
    }

    /**
     * List price of one billing cycle: every item's unit amount × quantity.
     * Coupons, proration and tax are applied by Stripe at invoice time, so
     * this is the ceiling, not a promise — the UI says so.
     *
     * @return array{0: float, 1: string, 2: int}  [amount, interval, interval_count]
     */
    public function recurringAmount(object $sub): array
    {
        $amount = 0.0;
        $interval = 'month';
        $count = 1;
        foreach ($sub->items->data ?? [] as $item) {
            $price = $item->price ?? null;
            if ($price === null || ($price->unit_amount ?? null) === null) {
                continue;
            }
            $amount += ((int) $price->unit_amount / 100) * (int) ($item->quantity ?? 1);
            $interval = (string) ($price->recurring->interval ?? $interval);
            $count = max(1, (int) ($price->recurring->interval_count ?? 1));
        }

        return [round($amount, 2), $interval, $count];
    }

    /**
     * Every renewal date for one subscription that lands inside the window.
     * A 90-day window over a monthly plan is THREE charges, not one — showing
     * only the next renewal would understate a forward-looking range by the
     * majority of its money. Guarded so a daily-interval plan cannot spin.
     *
     * @return list<Carbon>
     */
    public function projectCharges(Carbon $next, string $interval, int $count, Carbon $from, Carbon $to): array
    {
        $out = [];
        $cursor = $next->copy();
        $guard = 0;
        while ($cursor->lte($to) && $guard++ < 400) {
            if ($cursor->gte($from)) {
                $out[] = $cursor->copy();
            }
            $cursor = $this->advance($cursor, $interval, $count);
        }

        return $out;
    }

    private function advance(Carbon $date, string $interval, int $count): Carbon
    {
        return match ($interval) {
            'year' => $date->copy()->addYears($count),
            'week' => $date->copy()->addWeeks($count),
            'day' => $date->copy()->addDays($count),
            default => $date->copy()->addMonths($count),
        };
    }

    /** @return list<array{date: string, label: string, collected: float, scheduled: float}> */
    private function emptyDays(Carbon $from, Carbon $to): array
    {
        $days = [];
        $cursor = $from->copy()->startOfDay();
        $guard = 0;
        while ($cursor->lte($to) && $guard++ < 400) {
            $days[] = [
                'date' => $cursor->toDateString(),
                'label' => $cursor->format('j M'),
                'collected' => 0.0,
                'scheduled' => 0.0,
            ];
            $cursor->addDay();
        }

        return $days;
    }
}
