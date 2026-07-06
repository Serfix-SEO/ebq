<?php

namespace App\Http\Controllers;

use App\Models\CrawlSite;
use App\Models\Plan;
use App\Models\User;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stripe webhook handler — extends Cashier's so we keep all the standard
 * subscription bookkeeping (subscriptions / subscription_items, trial
 * state, etc.) and just override the events we care about for plan-slug
 * sync.
 *
 * Source of truth: `User->current_plan_slug`, snapshot of the active
 * subscription's stripe_price → Plan slug. Read-paths (website limit
 * checks, frozen-site decisions, dashboard tier badges) all read this
 * column on the hot path; computing live every time would force a
 * subscriptions+plans join per request.
 *
 * The plugin reads `tier` from every authenticated API response (via
 * the InjectFeatureFlags middleware), and `tier` is derived from
 * `User->effectiveTier()` which checks the active subscription. So a
 * webhook firing here means the customer's WordPress editor flips into
 * / out of Pro within seconds of the Stripe state change.
 */
class StripeWebhookController extends CashierController
{
    /**
     * Handle subscription created / updated. Cashier's parent method
     * does the table bookkeeping; we run after to snapshot the plan
     * slug on the User.
     */
    public function handleCustomerSubscriptionUpdated(array $payload): Response
    {
        $response = parent::handleCustomerSubscriptionUpdated($payload);
        $this->syncPlanSlugFromStripeCustomer($payload['data']['object']['customer'] ?? null);
        return $response;
    }

    public function handleCustomerSubscriptionCreated(array $payload): Response
    {
        // Use the parent's `updated` handler — Cashier doesn't define a
        // dedicated `created` override; the same row-upsert logic is fine.
        $response = parent::handleCustomerSubscriptionUpdated($payload);
        $this->syncPlanSlugFromStripeCustomer($payload['data']['object']['customer'] ?? null);
        return $response;
    }

    public function handleCustomerSubscriptionDeleted(array $payload): Response
    {
        $response = parent::handleCustomerSubscriptionDeleted($payload);
        $this->syncPlanSlugFromStripeCustomer($payload['data']['object']['customer'] ?? null);
        return $response;
    }

    /**
     * Look up the User by Stripe customer ID, derive the active plan
     * slug from the current subscription's price, and snapshot it on
     * `current_plan_slug`. Free-tier users (cancelled / never paid)
     * land with `current_plan_slug = null`.
     *
     * The lookup goes User->stripe_id (where Cashier wrote it after
     * the first subscription mint). Frozen-website status is derived
     * separately from this snapshot via User::frozenWebsiteIds() so no
     * additional sync work is needed here.
     */
    private function syncPlanSlugFromStripeCustomer(?string $stripeCustomerId): void
    {
        if (! $stripeCustomerId) {
            return;
        }
        $user = User::query()->where('stripe_id', $stripeCustomerId)->first();
        if (! $user) {
            return;
        }

        $newSlug = null;
        if ($user->subscribed('default')) {
            $subscription = $user->subscription('default');
            $price = (string) ($subscription->stripe_price ?? '');
            if ($price !== '') {
                $plan = Plan::findByStripePrice($price);
                if ($plan) {
                    $newSlug = $plan->slug;
                }
            }
        }

        if ($user->current_plan_slug !== $newSlug) {
            $user->forceFill(['current_plan_slug' => $newSlug])->save();

            // crawl_sites.effective_cap is a stored aggregate, only recomputed on
            // website create/delete or first crawl link — an upgrade/downgrade alone
            // never touches a Website row, so without this the crawler keeps
            // enforcing the OLD plan's page cap until something else re-links the site.
            $user->websites()->whereNotNull('crawl_site_id')->with('crawlSite')->get()
                ->pluck('crawlSite')->filter()->unique('id')
                ->each(fn (CrawlSite $cs) => $cs->recomputeEffectiveCap());
        }
    }
}
