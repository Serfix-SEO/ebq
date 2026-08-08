<?php

namespace Tests\Unit;

use App\Support\StripePeriod;
use PHPUnit\Framework\TestCase;

/**
 * Where Stripe keeps "when does this bill next".
 *
 * It moved. Up to API 2025-03-31 the Subscription carried
 * `current_period_end`; from `basil` onward it lives on each subscription
 * ITEM, because items can bill on different cycles. Reading only the old
 * location returns null on every modern account — /billing showed
 * "Next charge —" for live monthly subscribers on both products (2026-08-08).
 */
class StripePeriodTest extends TestCase
{
    private function stripeSub(?int $subLevel, array $itemEnds): object
    {
        return (object) [
            'current_period_end' => $subLevel,
            'items' => (object) [
                'data' => array_map(fn ($end) => (object) ['current_period_end' => $end], $itemEnds),
            ],
        ];
    }

    public function test_it_reads_the_period_end_from_the_subscription_items(): void
    {
        $at = StripePeriod::fromStripeSubscription($this->stripeSub(null, [1788868429]));

        $this->assertNotNull($at, 'a basil-era subscription must still report a next charge');
        $this->assertSame(1788868429, $at->getTimestamp());
    }

    /** Older API versions answer at the subscription level; keep working. */
    public function test_it_falls_back_to_the_subscription_level_field(): void
    {
        $at = StripePeriod::fromStripeSubscription($this->stripeSub(1788868429, []));

        $this->assertSame(1788868429, $at?->getTimestamp());
    }

    /** Items win: the subscription-level value is stale on a basil account. */
    public function test_items_take_precedence_over_the_subscription_field(): void
    {
        $at = StripePeriod::fromStripeSubscription($this->stripeSub(1, [1788868429]));

        $this->assertSame(1788868429, $at?->getTimestamp());
    }

    /**
     * A base plan plus an extra-website addon can sit on different cycles. The
     * next charge is the EARLIEST — that is when money actually moves.
     */
    public function test_the_earliest_item_wins_when_cycles_differ(): void
    {
        $at = StripePeriod::fromStripeSubscription($this->stripeSub(null, [1788868429, 1786190029]));

        $this->assertSame(1786190029, $at?->getTimestamp());
    }

    public function test_it_returns_null_when_stripe_says_nothing(): void
    {
        $this->assertNull(StripePeriod::fromStripeSubscription($this->stripeSub(null, [])));
        $this->assertNull(StripePeriod::nextChargeAt(null));
    }
}
