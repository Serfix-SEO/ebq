<?php

namespace App\Http\Controllers;

use App\Models\Website;
use App\Services\Content\ContentEntitlements;
use App\Support\ContentAutopilotConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Checkout;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Subscription;
use Symfony\Component\HttpFoundation\Response;

/**
 * Billing for the separately-sold Content Autopilot product. It is a Cashier
 * NAMED subscription (`content`) so it never collides with the dashboard
 * `default` subscription (checkout blocks, plan-slug sync, swap flow all key
 * on `default`). The base price covers 1 website; each extra website is an
 * addon subscription-item quantity on the same `content` subscription.
 */
class ContentBillingController extends Controller
{
    private const SUB = ContentEntitlements::SUBSCRIPTION;

    public function __construct(private readonly ContentEntitlements $entitlements)
    {
    }

    // ── Base subscription checkout ──────────────────────────────────────

    public function checkout(Request $request): RedirectResponse|Response|Checkout
    {
        $data = $request->validate([
            'interval' => 'required|in:monthly,annual',
            'website' => 'nullable|string|max:40',
        ]);
        $interval = $data['interval'];
        $user = $request->user();

        if ($this->entitlements->hasContentSubscription($user)) {
            return redirect()->route('content.get-started')
                ->with('status', __('You already have a content subscription.'));
        }
        if (! ContentAutopilotConfig::checkoutReady($interval)) {
            return redirect()->route('content.get-started')
                ->with('error', __('Content checkout is not configured yet.'));
        }

        $this->healStaleCustomer($user);

        $priceId = ContentAutopilotConfig::priceId($interval);
        $websiteId = $this->resolveWebsiteId($user, $data['website'] ?? null);
        $successUrl = route('content.billing.success', array_filter([
            'website' => $websiteId, 'interval' => $interval,
        ]));
        $cancelUrl = route('content.get-started');

        // $1 first month = the configured amount-off coupon, MONTHLY only.
        $coupon = $interval === 'monthly' ? ContentAutopilotConfig::firstMonthCoupon() : null;

        try {
            $builder = $user->newSubscription(self::SUB, $priceId)->skipTrial();
            if ($coupon !== null) {
                $builder->withCoupon($coupon);
            }
            try {
                return $builder->checkout(['success_url' => $successUrl, 'cancel_url' => $cancelUrl]);
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                // A dead coupon must never block the sale — retry at full price.
                if ($coupon === null || ! str_contains(strtolower($e->getMessage()), 'coupon')) {
                    throw $e;
                }
                Log::warning("Content billing: coupon {$coupon} rejected, checking out without it: {$e->getMessage()}");

                return $user->newSubscription(self::SUB, $priceId)->skipTrial()
                    ->checkout(['success_url' => $successUrl, 'cancel_url' => $cancelUrl]);
            }
        } catch (IncompletePayment $exception) {
            return redirect()->route('cashier.payment', [$exception->payment->id, 'redirect' => $cancelUrl]);
        }
    }

    public function success(Request $request): RedirectResponse
    {
        $user = $request->user();
        $this->syncContentSubscription($user);

        // Cover the initiating website (base slot) once the sub is live.
        $websiteId = (string) $request->query('website', '');
        if ($websiteId !== '' && $this->entitlements->hasContentSubscription($user)) {
            $website = $user->websites()->whereKey($websiteId)->first();
            if ($website !== null) {
                $this->entitlements->coverWebsite($website);
                $request->session()->put('current_website_id', $website->id);

                return redirect()->route('content.index')
                    ->with('status', __('Your content subscription is active.'));
            }
        }

        return redirect()->route('content.get-started')
            ->with('status', __('Your content subscription is active.'));
    }

    public function cancelCheckout(Request $request): RedirectResponse
    {
        return redirect()->route('content.get-started');
    }

    // ── Extra websites (addon subscription-item quantity) ───────────────

    public function addWebsite(Request $request): RedirectResponse
    {
        $user = $request->user();
        $sub = $user->subscription(self::SUB);
        $website = $this->currentWebsite($request, $user);
        if ($sub === null || $website === null) {
            return back()->with('error', __('No active content subscription.'));
        }
        $interval = $this->baseInterval($sub);
        $addonId = ContentAutopilotConfig::addonPriceId($interval);
        if ($addonId === null) {
            return back()->with('error', __('Extra-website pricing is not configured.'));
        }

        try {
            $this->hasAddonItem($sub, $addonId)
                ? $sub->incrementQuantity(1, $addonId)
                : $sub->addPriceAndInvoice($addonId);
            $this->entitlements->coverWebsite($website);
        } catch (\Throwable $e) {
            Log::warning('Content addWebsite failed: '.$e->getMessage());

            return back()->with('error', __('Could not add this website. Please try again.'));
        }

        return redirect()->route('content.settings')
            ->with('status', __('This website is now covered by your content plan.'));
    }

    public function removeWebsite(Request $request): RedirectResponse
    {
        $user = $request->user();
        $sub = $user->subscription(self::SUB);
        $website = $this->currentWebsite($request, $user);
        if ($sub === null || $website === null) {
            return back()->with('error', __('No active content subscription.'));
        }
        $interval = $this->baseInterval($sub);
        $addonId = ContentAutopilotConfig::addonPriceId($interval);

        try {
            $this->entitlements->uncoverWebsite($website);
            if ($addonId !== null && $this->hasAddonItem($sub, $addonId)) {
                $qty = (int) $sub->items->firstWhere('stripe_price', $addonId)?->quantity;
                $qty > 1 ? $sub->decrementQuantity(1, $addonId) : $sub->removePrice($addonId);
            }
        } catch (\Throwable $e) {
            Log::warning('Content removeWebsite failed: '.$e->getMessage());
        }

        return redirect()->route('content.settings')
            ->with('status', __('This website is no longer covered by your content plan.'));
    }

    // ── Cancel / resume the whole content product ───────────────────────

    public function cancel(Request $request): RedirectResponse
    {
        $sub = $request->user()->subscription(self::SUB);
        if ($sub !== null && ! $sub->canceled()) {
            $sub->cancel();
        }

        return back()->with('status', __('Your content subscription will end at the period close.'));
    }

    public function resume(Request $request): RedirectResponse
    {
        $sub = $request->user()->subscription(self::SUB);
        if ($sub !== null && $sub->onGracePeriod()) {
            $sub->resume();
        }

        return back()->with('status', __('Your content subscription has been resumed.'));
    }

    // ── internals ───────────────────────────────────────────────────────

    private function healStaleCustomer(\App\Models\User $user): void
    {
        if ($user->hasStripeId() && ! $user->subscribed(self::SUB)) {
            try {
                $user->asStripeCustomer();
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                if (str_contains($e->getMessage(), 'No such customer')) {
                    $user->forceFill(['stripe_id' => null, 'pm_type' => null, 'pm_last_four' => null])->save();
                } else {
                    throw $e;
                }
            }
        }
    }

    /** The interval the base subscription is billed on. */
    private function baseInterval(Subscription $sub): string
    {
        $monthly = ContentAutopilotConfig::priceId('monthly');

        return $monthly !== null && $sub->items->contains('stripe_price', $monthly) ? 'monthly' : 'annual';
    }

    private function hasAddonItem(Subscription $sub, string $addonId): bool
    {
        return $sub->items->contains('stripe_price', $addonId);
    }

    private function currentWebsite(Request $request, \App\Models\User $user): ?Website
    {
        $id = (string) ($request->input('website') ?: $request->session()->get('current_website_id', ''));

        return $id !== '' ? $user->websites()->whereKey($id)->first() : null;
    }

    private function resolveWebsiteId(\App\Models\User $user, ?string $requested): ?string
    {
        if ($requested && $user->websites()->whereKey($requested)->exists()) {
            return $requested;
        }

        return $user->websites()->value('id');
    }

    /** Optimistic pull of the content subscription from Stripe on the success hop. */
    private function syncContentSubscription(\App\Models\User $user): void
    {
        if (! $user->hasStripeId() || $user->subscribed(self::SUB)) {
            return;
        }
        try {
            $subs = $user->stripe()->subscriptions->all(['customer' => $user->stripe_id, 'status' => 'all', 'limit' => 20]);
        } catch (\Throwable $e) {
            Log::warning('Content sub sync failed: '.$e->getMessage());

            return;
        }
        foreach ($subs->data as $s) {
            $name = $s->metadata['type'] ?? $s->metadata['name'] ?? null;
            if ($name !== self::SUB) {
                continue;
            }
            $price = $s->items->data[0] ?? null;
            $sub = $user->subscriptions()->updateOrCreate(['stripe_id' => $s->id], [
                'type' => self::SUB,
                'stripe_status' => $s->status,
                'stripe_price' => $price?->price?->id,
                'quantity' => $price?->quantity,
                'ends_at' => $s->cancel_at ? \Illuminate\Support\Carbon::createFromTimestamp((int) $s->cancel_at) : null,
            ]);
            // Mirror the subscription items so addonQuantity() reads correctly.
            foreach ($s->items->data as $item) {
                $sub->items()->updateOrCreate(['stripe_id' => $item->id], [
                    'stripe_product' => $item->price->product,
                    'stripe_price' => $item->price->id,
                    'quantity' => $item->quantity ?? 1,
                ]);
            }
        }
        $user->load('subscriptions.items');
    }
}
