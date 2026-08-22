<?php

namespace App\Services;

use App\Http\Middleware\CaptureReferralCode;
use App\Mail\ReferralRewardEarned;
use App\Models\Referral;
use App\Models\User;
use App\Support\ContentAutopilotConfig;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Referral program: ?ref=CODE → 60-day cookie → pending Referral row at
 * signup → invoice.payment_succeeded webhook detects the referred account's
 * first FULL-price content BASE invoice (the $1 intro month never qualifies)
 * → Stripe customer-balance credit of 50% of the REFERRER's base content
 * price. A balance credit — not a coupon — because a coupon is
 * subscription-wide and would discount the addon websites too; the credit
 * amount is computed from the base price only (owner rule) and stacks
 * naturally across multiple successful referrals (owner: unlimited stacking).
 *
 * grant() is the only Stripe-touching method; both touches are injectable
 * closures so tests never reach the network.
 */
class ReferralProgram
{
    /** @param ?\Closure(User,int,string):void $creditor @param ?\Closure(string):?int $priceResolver */
    public function __construct(
        private ?\Closure $creditor = null,
        private ?\Closure $priceResolver = null,
    ) {}

    // ── Codes ───────────────────────────────────────────────────────────

    /** Lazily generate the user's shareable code (a-z0-9, 8 chars). */
    public function codeFor(User $user): string
    {
        if (is_string($user->referral_code) && $user->referral_code !== '') {
            return $user->referral_code;
        }

        for ($i = 0; $i < 5; $i++) {
            // Str::random is alphanumeric; lowercase keeps URLs friendly and
            // matching case-insensitive by construction.
            $code = Str::lower(Str::random(8));
            try {
                $user->forceFill(['referral_code' => $code])->save();

                return $code;
            } catch (\Illuminate\Database\QueryException) {
                // unique collision — retry
            }
        }

        throw new \RuntimeException('referral code generation failed');
    }

    /** Shared shape for generated AND custom codes (middleware mirrors it). */
    public static function isValidCode(string $code): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{2,14}[a-z0-9]$/', $code) === 1;
    }

    /**
     * Custom (vanity) code: honored only when no other user holds it — the
     * unique index is the race-proof arbiter. Changing the code kills links
     * shared under the old one (it resolves to nobody afterwards).
     *
     * @return string|null null on success, else a machine reason
     *                     ('invalid_format'|'taken')
     */
    public function setCustomCode(User $user, string $code): ?string
    {
        $code = Str::lower(trim($code));
        if (! self::isValidCode($code)) {
            return 'invalid_format';
        }
        if ($code === $user->referral_code) {
            return null; // no-op
        }
        if (User::query()->where('referral_code', $code)->where('id', '!=', $user->id)->exists()) {
            return 'taken';
        }

        try {
            $user->forceFill(['referral_code' => $code])->save();
        } catch (\Illuminate\Database\QueryException) {
            return 'taken'; // lost the race to the unique index
        }

        return null;
    }

    public function resolveCodeToUser(string $code): ?User
    {
        $code = Str::lower(trim($code));
        if ($code === '') {
            return null;
        }

        return User::query()
            ->where('referral_code', $code)
            ->where('is_system', false)
            ->first();
    }

    // ── Attribution (User::created choke point — covers all signup paths) ─

    /**
     * Never throws and never runs outside a real web request: users are also
     * created from queue/console contexts (system lead users, seeders).
     */
    public static function attributeFromRequest(User $user): void
    {
        try {
            if (app()->runningInConsole() && ! app()->runningUnitTests()) {
                return;
            }
            if ($user->is_system || ! app()->bound('request') || request() === null) {
                return;
            }

            $code = (string) request()->cookie(CaptureReferralCode::COOKIE, '');
            if ($code === '') {
                return;
            }

            $referrer = app(self::class)->resolveCodeToUser($code);
            if ($referrer === null || $referrer->is($user)) {
                return;
            }

            Referral::query()->create([
                'referrer_user_id' => $referrer->id,
                'referred_user_id' => $user->id,
                'code_used' => Str::lower(trim($code)),
                'status' => Referral::STATUS_PENDING,
            ]);

            Cookie::queue(Cookie::forget(CaptureReferralCode::COOKIE));
        } catch (\Throwable $e) {
            // Attribution must never break signup.
            Log::warning('referral attribution skipped: '.$e->getMessage(), ['user_id' => $user->id]);
        }
    }

    // ── Qualification (invoice.payment_succeeded) ───────────────────────

    /**
     * @param array $payload full Stripe webhook payload
     */
    public function qualifyFromInvoicePayload(array $payload): void
    {
        $invoice = $payload['data']['object'] ?? [];
        $customer = (string) ($invoice['customer'] ?? '');
        $invoiceId = (string) ($invoice['id'] ?? '');
        if ($customer === '' || $invoiceId === '') {
            return;
        }

        // Fast exits: most invoices belong to nobody with a pending referral.
        $referred = User::query()->where('stripe_id', $customer)->first();
        if ($referred === null) {
            return;
        }
        $referral = Referral::query()
            ->where('referred_user_id', $referred->id)
            ->where('status', Referral::STATUS_PENDING)
            ->first();
        if ($referral === null) {
            return;
        }
        if (Referral::query()->where('stripe_invoice_id', $invoiceId)->exists()) {
            return; // webhook retry
        }

        // The invoice must carry a content BASE price line (addon-only and
        // SEO-product invoices never qualify)…
        $baseIds = array_values(array_filter([
            ContentAutopilotConfig::priceId('monthly'),
            ContentAutopilotConfig::priceId('annual'),
        ]));
        $baseLine = null;
        $interval = null;
        foreach ((array) ($invoice['lines']['data'] ?? []) as $line) {
            // Classic and basil API line shapes (ContentSubscriptionPanel precedent).
            $priceId = $line['price']['id'] ?? ($line['pricing']['price_details']['price'] ?? null);
            if ($priceId !== null && in_array($priceId, $baseIds, true)) {
                $baseLine = $line;
                $interval = $priceId === ContentAutopilotConfig::priceId('annual') ? 'annual' : 'monthly';
                break;
            }
        }
        if ($baseLine === null) {
            return;
        }

        // …paid at FULL price. The $1 intro month is an invoice-level coupon,
        // so amount_paid (~100¢) falls far short of the base price; a full
        // monthly invoice pays ≥ the base even before addons; an annual first
        // invoice (never couponed) qualifies immediately. 0.9 slack tolerates
        // small prorations.
        $expected = $this->expectedBaseCents($interval);
        if ($expected <= 0 || (int) ($invoice['amount_paid'] ?? 0) < (int) ($expected * 0.9)) {
            return;
        }

        $claimed = DB::transaction(function () use ($referral, $invoiceId): bool {
            $fresh = Referral::query()->lockForUpdate()->find($referral->id);
            if ($fresh === null || $fresh->status !== Referral::STATUS_PENDING) {
                return false;
            }
            $fresh->update([
                'status' => Referral::STATUS_QUALIFIED,
                'stripe_invoice_id' => $invoiceId,
                'qualified_at' => now(),
            ]);

            return true;
        });

        if ($claimed) {
            // Best-effort inline; a failure leaves the row for the hourly
            // ebq:grant-referral-rewards sweep.
            $this->grant($referral->fresh());
        }
    }

    // ── Granting the reward (the only Stripe-touching path) ─────────────

    public function grant(Referral $referral): bool
    {
        if (! in_array($referral->status, [Referral::STATUS_QUALIFIED, Referral::STATUS_CREDIT_FAILED], true)) {
            return false;
        }

        $referrer = User::query()->find($referral->referrer_user_id);
        if ($referrer === null || $referrer->is_disabled) {
            // Referrer gone/blocked: park the row so the sweep stops retrying.
            $referral->update(['status' => Referral::STATUS_CREDIT_FAILED, 'last_error' => 'referrer unavailable']);

            return false;
        }

        try {
            $cents = $this->creditCentsFor($referrer);

            ($this->creditor ?? function (User $u, int $amount, string $description): void {
                $u->createOrGetStripeCustomer();
                $u->creditBalance($amount, $description);
            })($referrer, $cents, 'Referral reward — 50% off your next bill');

            $referral->update([
                'status' => Referral::STATUS_CREDITED,
                'credit_cents' => $cents,
                'credited_at' => now(),
                'last_error' => null,
            ]);
        } catch (\Throwable $e) {
            $referral->update([
                'status' => Referral::STATUS_CREDIT_FAILED,
                'last_error' => Str::limit($e->getMessage(), 500),
            ]);
            Log::warning('referral credit failed: '.$e->getMessage(), ['referral_id' => $referral->id]);

            return false;
        }

        try {
            Mail::to($referrer->email)->queue(new ReferralRewardEarned($referrer, $cents));
        } catch (\Throwable $e) {
            Log::warning('referral reward mail failed: '.$e->getMessage(), ['referral_id' => $referral->id]);
        }

        try {
            app(ClientActivityLogger::class)->log(
                'referral_credited',
                userId: $referrer->id,
                meta: ['referral_id' => $referral->id, 'credit_cents' => $cents],
            );
        } catch (\Throwable) {
            // audit is best-effort
        }

        return true;
    }

    /**
     * 50% of the REFERRER's base content price (never the addons — owner
     * rule). Interval from their own subscription's base item; a referrer
     * without a content subscription earns the monthly-rate credit, which
     * sits on their Stripe balance until they subscribe.
     */
    public function creditCentsFor(User $referrer): int
    {
        $interval = 'monthly';
        $monthlyId = ContentAutopilotConfig::priceId('monthly');
        $annualId = ContentAutopilotConfig::priceId('annual');

        try {
            $sub = $referrer->subscription(\App\Services\Content\ContentEntitlements::SUBSCRIPTION);
            if ($sub !== null && $annualId !== null && $sub->items->contains('stripe_price', $annualId)) {
                $interval = 'annual';
            } elseif ($sub !== null && $monthlyId !== null && $sub->items->contains('stripe_price', $monthlyId)) {
                $interval = 'monthly';
            }
        } catch (\Throwable) {
            // fall through to monthly
        }

        return intdiv($this->expectedBaseCents($interval), 2);
    }

    /** Full base price in cents for an interval (Stripe truth, display fallback). */
    private function expectedBaseCents(string $interval): int
    {
        $priceId = ContentAutopilotConfig::priceId($interval);

        if ($priceId !== null) {
            try {
                $amount = ($this->priceResolver ?? function (string $id): ?int {
                    return \Laravel\Cashier\Cashier::stripe()->prices->retrieve($id)->unit_amount;
                })($priceId);
                if (is_int($amount) && $amount > 0) {
                    return $amount;
                }
            } catch (\Throwable) {
                // fall through to display price
            }
        }

        // Display prices are per-month; the annual PRICE bills 12 months at
        // the discounted monthly rate (ContentSubscriptionPanel semantics).
        $monthlyUsd = ContentAutopilotConfig::displayPrice($interval === 'annual' ? 'annual' : 'monthly');

        return $monthlyUsd * 100 * ($interval === 'annual' ? 12 : 1);
    }
}
