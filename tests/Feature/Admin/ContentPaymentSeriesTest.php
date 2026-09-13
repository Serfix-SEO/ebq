<?php

namespace Tests\Feature\Admin;

use App\Services\Admin\ContentPaymentSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Content Autopilot payment graph's arithmetic, tested without Stripe.
 * The pieces that talk to Stripe are thin; the pieces that are easy to get
 * wrong — which subscription an invoice belongs to, what a renewal costs, and
 * how many renewals fall inside a window — are pure and covered here.
 */
class ContentPaymentSeriesTest extends TestCase
{
    use RefreshDatabase;

    private function series(): ContentPaymentSeries
    {
        return app(ContentPaymentSeries::class);
    }

    /**
     * Stripe moved `invoice.subscription` under
     * `invoice.parent.subscription_details.subscription` in the `basil` API
     * version this account runs. Reading only the old location returns null on
     * every modern invoice and the whole graph silently reads zero — the same
     * trap StripePeriod documents for period ends.
     */
    public function test_it_finds_the_subscription_id_in_both_stripe_api_shapes(): void
    {
        $s = $this->series();

        $legacy = json_decode(json_encode(['subscription' => 'sub_legacy']));
        $this->assertSame('sub_legacy', $s->invoiceSubscriptionId($legacy));

        $basil = json_decode(json_encode([
            'parent' => ['subscription_details' => ['subscription' => 'sub_basil']],
        ]));
        $this->assertSame('sub_basil', $s->invoiceSubscriptionId($basil));

        // Expanded objects, not bare ids.
        $expanded = json_decode(json_encode(['subscription' => ['id' => 'sub_expanded']]));
        $this->assertSame('sub_expanded', $s->invoiceSubscriptionId($expanded));

        // A one-off invoice belongs to no subscription and must be skipped.
        $this->assertNull($s->invoiceSubscriptionId(json_decode(json_encode(['id' => 'in_1']))));
    }

    public function test_a_renewal_costs_every_item_times_its_quantity(): void
    {
        // One base site + two add-ons: $39 + 2 × $15.
        $sub = json_decode(json_encode(['items' => ['data' => [
            ['quantity' => 1, 'price' => ['unit_amount' => 3900, 'recurring' => ['interval' => 'month', 'interval_count' => 1]]],
            ['quantity' => 2, 'price' => ['unit_amount' => 1500, 'recurring' => ['interval' => 'month', 'interval_count' => 1]]],
        ]]]));

        [$amount, $interval, $count] = $this->series()->recurringAmount($sub);

        $this->assertSame(69.0, $amount);
        $this->assertSame('month', $interval);
        $this->assertSame(1, $count);
    }

    public function test_a_metered_item_without_a_unit_amount_is_ignored(): void
    {
        $sub = json_decode(json_encode(['items' => ['data' => [
            ['quantity' => 1, 'price' => ['unit_amount' => null, 'recurring' => ['interval' => 'month']]],
        ]]]));

        [$amount] = $this->series()->recurringAmount($sub);

        $this->assertSame(0.0, $amount);
    }

    /**
     * A forward-looking window over a monthly plan is several charges. Showing
     * only the next renewal would understate a 90-day range by most of its
     * money — the reason this graph exists.
     */
    public function test_it_projects_every_renewal_inside_the_window(): void
    {
        $from = Carbon::create(2026, 9, 1);
        $to = Carbon::create(2026, 11, 30);

        $dates = $this->series()->projectCharges(
            Carbon::create(2026, 9, 15), 'month', 1, $from, $to,
        );

        $this->assertSame(
            ['2026-09-15', '2026-10-15', '2026-11-15'],
            array_map(fn ($d) => $d->toDateString(), $dates),
        );
    }

    public function test_renewals_before_the_window_are_not_counted(): void
    {
        $dates = $this->series()->projectCharges(
            Carbon::create(2026, 6, 10), 'month', 1,
            Carbon::create(2026, 9, 1), Carbon::create(2026, 9, 30),
        );

        $this->assertSame(['2026-09-10'], array_map(fn ($d) => $d->toDateString(), $dates));
    }

    public function test_a_yearly_plan_contributes_at_most_one_charge_to_a_short_window(): void
    {
        $dates = $this->series()->projectCharges(
            Carbon::create(2026, 9, 20), 'year', 1,
            Carbon::create(2026, 9, 1), Carbon::create(2026, 9, 30),
        );

        $this->assertCount(1, $dates);
    }

    /** A daily-interval plan over a long window must not spin forever. */
    public function test_the_projection_loop_is_bounded(): void
    {
        $dates = $this->series()->projectCharges(
            Carbon::create(2026, 1, 1), 'day', 1,
            Carbon::create(2026, 1, 1), Carbon::create(2030, 1, 1),
        );

        $this->assertLessThanOrEqual(400, count($dates));
    }

    public function test_the_dashboard_renders_the_graph_and_honours_a_custom_range(): void
    {
        $admin = \App\Models\User::factory()->create(['is_admin' => true]);

        $html = $this->actingAs($admin)
            ->get(route('admin.dashboard', [
                'pay_range' => 'custom',
                'pay_from' => '2026-09-01',
                'pay_to' => '2026-09-10',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Content Autopilot payments', $html);
        $this->assertStringContainsString('2026-09-01', $html, 'the custom window is reflected back into the form');
        $this->assertStringContainsString('2026-09-10', $html);
        // Stripe is unconfigured in tests, so the section must say so rather
        // than render a misleading empty chart.
        $this->assertStringContainsString('Stripe is unavailable', $html);
    }

    public function test_an_inverted_custom_range_is_swapped_rather_than_rejected(): void
    {
        $admin = \App\Models\User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard', [
                'pay_range' => 'custom', 'pay_from' => '2026-09-30', 'pay_to' => '2026-09-01',
            ]))
            ->assertOk()
            ->assertSee('2026-09-01')
            ->assertSee('2026-09-30');
    }

    /**
     * No Stripe key (the test environment, and any misconfigured box) must
     * render an honest empty chart, never a 500 — the same contract the
     * dashboard's existing Stripe snapshot keeps.
     */
    public function test_without_stripe_it_degrades_to_an_empty_but_shaped_series(): void
    {
        config(['cashier.secret' => '']);

        $out = $this->series()->build(Carbon::create(2026, 9, 1), Carbon::create(2026, 9, 7));

        $this->assertFalse($out['available']);
        $this->assertSame(0.0, $out['collected_total']);
        $this->assertSame(0.0, $out['scheduled_total']);
        $this->assertCount(7, $out['days'], 'the axis still spans the requested window');
        $this->assertSame('2026-09-01', $out['days'][0]['date']);
        $this->assertSame('2026-09-07', $out['days'][6]['date']);
    }
}
