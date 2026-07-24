<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;

/**
 * Public anonymous pricing endpoint.
 *
 * Same access pattern as `/wordpress/plugin/version` — no auth, no
 * caller identifier captured. The WordPress plugin's setup wizard
 * fetches this to populate its Pricing step. Cached briefly client-side
 * (the plugin reuses its EBQ_Updater transient pattern).
 *
 * Response shape:
 *   {
 *     plans: [
 *       {
 *         slug, name, tagline,
 *         price_monthly_usd, price_yearly_usd, trial_days,
 *         features: [],                  // marketing bullet copy
 *         plan_features: {<key>:bool},   // authoritative entitlement
 *         api_limits: {...} | null,      // per-plan API caps
 *         max_websites: int | null,      // null = unlimited
 *         is_highlighted, requires_subscription, checkout_url
 *       }, ...
 *     ],
 *     promo: { headline, expires_at } | null
 *   }
 *
 * `checkout_url` is null for free or for any plan whose Stripe price ID
 * isn't configured yet — the plugin grays-out the CTA. When a paid
 * plan IS checkout-ready, the URL points at `/billing/checkout?plan=…`
 * which Cashier then turns into a Stripe Hosted Checkout session.
 *
 * Falls back to a hardcoded plan list when the `plans` table is empty
 * (fresh install, before seeder runs) so the wizard never sees a blank
 * step. Once the seeder runs, the DB rows take over.
 */
class PricingController extends Controller
{
    public function public(): JsonResponse
    {
        $plans = Plan::ordered()->get();

        if ($plans->isEmpty()) {
            $plans = collect($this->fallbackPlans());
        }

        $payload = $plans->map(function ($plan): array {
            $isReady = $plan instanceof Plan
                ? $plan->isCheckoutReady()
                : (bool) ($plan['_checkout_ready'] ?? false);
            $slug = (string) ($plan['slug'] ?? '');
            $planFeatures = $plan instanceof Plan
                ? $plan->featureMap()
                : (is_array($plan['plan_features'] ?? null) ? $plan['plan_features'] : array_fill_keys(Plan::FEATURE_KEYS, false));
            $apiLimits = $plan instanceof Plan
                ? $plan->api_limits
                : ($plan['api_limits'] ?? null);
            $maxWebsites = $plan instanceof Plan
                ? $plan->max_websites
                : ($plan['max_websites'] ?? null);

            // Optional explainer videos, as a sparse bullet-index => bare
            // YouTube ID map aligned with `features`. Resolved to IDs here
            // so consumers embed the /embed form without parsing URLs.
            $rawVideos = $plan instanceof Plan
                ? (is_array($plan->feature_videos) ? $plan->feature_videos : [])
                : (is_array($plan['feature_videos'] ?? null) ? $plan['feature_videos'] : []);
            $featureVideos = [];
            foreach ($rawVideos as $idx => $url) {
                $videoId = Plan::youtubeId(is_string($url) ? $url : null);
                if ($videoId !== null) {
                    $featureVideos[(string) $idx] = $videoId;
                }
            }

            return [
                'slug' => $slug,
                'name' => $plan['name'],
                'tagline' => $plan['tagline'] ?? null,
                'price_monthly_usd' => (int) ($plan['price_monthly_usd'] ?? 0),
                'price_yearly_usd' => (int) ($plan['price_yearly_usd'] ?? 0),
                'trial_days' => (int) ($plan['trial_days'] ?? 0),
                'features' => is_array($plan['features'] ?? null)
                    ? array_values($plan['features'])
                    : [],
                // Sparse { "<bullet index>": "<youtube id>" } map; empty
                // when no bullet has a video. Optional, additive.
                'feature_videos' => $featureVideos,
                // Authoritative entitlement matrix — the WP plugin's
                // setup wizard reads this to render checkmark grids and
                // the public pricing page derives its "Includes:" list
                // from the same map.
                'plan_features' => is_array($planFeatures) ? $planFeatures : array_fill_keys(Plan::FEATURE_KEYS, false),
                'api_limits' => is_array($apiLimits) ? $apiLimits : null,
                'max_websites' => $maxWebsites !== null ? (int) $maxWebsites : null,
                // The free-entry tier (renamed 'free' → 'trial') is a $0 plan
                // that grants a trial. Detect by shape, not slug, so a rename
                // can't mis-flag it as paid. Enterprise is $0 too but has no
                // trial → still "requires subscription" (contact sales).
                'requires_subscription' => ! (
                    (int) ($plan['price_monthly_usd'] ?? 0) === 0
                    && (int) ($plan['price_yearly_usd'] ?? 0) === 0
                    && (int) ($plan['trial_days'] ?? 0) > 0
                ),
                'is_highlighted' => (bool) ($plan['is_highlighted'] ?? false),
                'checkout_url' => $isReady
                    ? route('billing.checkout', ['plan' => $slug])
                    : null,
            ];
        })->values()->all();

        return response()->json([
            'plans' => $payload,
            'promo' => $this->promoBanner(),
        ])->header('Cache-Control', 'public, max-age=900'); // 15 min CDN edge cache
    }

    /**
     * Promotional banner — only set when the global `app.free` config
     * flag is on (matches the existing logic in `pricing.blade.php`).
     * Lets EBQ run "all Pro features free for X months" promotions
     * without a code change to the plugin.
     *
     * @return array{headline:string, expires_at:string|null}|null
     */
    private function promoBanner(): ?array
    {
        if (! config('app.free')) {
            return null;
        }
        return [
            'headline' => 'Free for a limited time, then just $5/month.',
            'expires_at' => null,
        ];
    }

    /**
     * Fallback plan data used when the `plans` table is empty (typically
     * just-after-deploy before the seeder has run). Keeps the public API
     * non-empty so downstream consumers (the WP plugin wizard) don't
     * crash on a fresh install.
     *
     * Mirrors the CURRENT live plans (trial < solo < pro < agency <
     * enterprise) so the wizard never shows stale prices even on the rare
     * empty-table path. Kept in sync with PlanSeeder. `_checkout_ready` stays
     * false — with no DB rows there are no Stripe price IDs, so paid plans show
     * "coming soon" until the seeder runs and real rows take over.
     *
     * @return list<array<string, mixed>>
     */
    private function fallbackPlans(): array
    {
        $features = [
            'chatbot' => true, 'ai_writer' => true, 'ai_inline' => true,
            'live_audit' => true, 'hq' => true, 'redirects' => true,
            'dashboard_widget' => true, 'post_column' => true,
        ];

        return [
            [
                'slug' => 'trial', 'name' => 'Trial',
                'tagline' => 'Get started — no credit card required.',
                'price_monthly_usd' => 0, 'price_yearly_usd' => 0,
                'trial_days' => 14, 'max_websites' => 1,
                'features' => ['1 website', '1 team seat', '20,000 page crawl budget', '20 tracked keywords', '50 keyword searches / mo', '100 live ranking checks / mo'],
                'plan_features' => $features, 'api_limits' => null,
                'is_highlighted' => false, '_checkout_ready' => false,
            ],
            [
                'slug' => 'solo', 'name' => 'Solo',
                'tagline' => 'For one site you actively grow.',
                'price_monthly_usd' => 19, 'price_yearly_usd' => 168,
                'trial_days' => 0, 'max_websites' => 3,
                'features' => ['3 websites', '1 team seat', '100,000 page crawl budget', '100 tracked keywords', '250 keyword searches / mo', '1,000 live ranking checks / mo'],
                'plan_features' => $features, 'api_limits' => null,
                'is_highlighted' => false, '_checkout_ready' => false,
            ],
            [
                'slug' => 'pro', 'name' => 'Pro',
                'tagline' => 'For growing teams and agencies.',
                'price_monthly_usd' => 49, 'price_yearly_usd' => 444,
                'trial_days' => 0, 'max_websites' => 10,
                'features' => ['10 websites', '3 team seats', '300,000 page crawl budget', '500 tracked keywords', '1,000 keyword searches / mo', '4,000 live ranking checks / mo'],
                'plan_features' => $features, 'api_limits' => null,
                'is_highlighted' => true, '_checkout_ready' => false,
            ],
            [
                'slug' => 'agency', 'name' => 'Agency',
                'tagline' => 'For agencies managing many clients.',
                'price_monthly_usd' => 99, 'price_yearly_usd' => 888,
                'trial_days' => 0, 'max_websites' => 30,
                'features' => ['30 websites', '10 team seats', '1,000,000 page crawl budget', '2,000 tracked keywords', '4,000 keyword searches / mo', '12,000 live ranking checks / mo'],
                'plan_features' => $features, 'api_limits' => null,
                'is_highlighted' => false, '_checkout_ready' => false,
            ],
            [
                'slug' => 'enterprise', 'name' => 'Enterprise',
                'tagline' => 'Custom scale. Contact us.',
                'price_monthly_usd' => 0, 'price_yearly_usd' => 0,
                'trial_days' => 0, 'max_websites' => null,
                'features' => ['Unlimited websites', 'Unlimited team seats', 'Custom crawl budget', 'Unlimited tracked keywords', 'Unlimited keyword searches', 'Unlimited AI tokens & articles'],
                'plan_features' => $features, 'api_limits' => null,
                'is_highlighted' => false, '_checkout_ready' => false,
            ],
        ];
    }
}
