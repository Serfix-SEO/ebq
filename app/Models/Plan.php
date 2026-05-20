<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Subscription plan — one row per public price tier.
 *
 * Drives:
 *   - The marketing /pricing page
 *   - The WordPress plugin's setup-wizard pricing step (via the public
 *     /api/v1/plans endpoint)
 *   - The /billing/checkout endpoint that hands off to Cashier + Stripe
 *
 * Stripe price IDs are nullable so plan rows can exist before their
 * Stripe products are configured (e.g., free tier never has a price ID;
 * during early rollout some paid tiers may not have Stripe products yet).
 *
 * `is_active=false` deprecates a plan without orphaning historical
 * subscription rows that reference it.
 */
class Plan extends Model
{
    /**
     * Canonical list of plugin feature keys. Mirrors
     * `App\Models\Website::FEATURE_KEYS` and the WordPress plugin's
     * `EBQ_Feature_Flags::KNOWN_FEATURES`. Drives the admin "Plugin
     * features" checkbox grid and the `featureMap()` reader.
     */
    public const FEATURE_KEYS = [
        'chatbot',
        'ai_writer',
        'ai_inline',
        'live_audit',
        'hq',
        'redirects',
        'dashboard_widget',
        'post_column',
        // Platform feature — not a plugin flag. Unlocks branded report
        // emails + PDF attachments and the per-tenant outbound-mail
        // transport (Gmail / Outlook / SMTP). Reports always send; this
        // flag just controls whether the customer's saved branding is
        // applied or the EBQ default is used.
        'report_whitelabel',
    ];

    protected $fillable = [
        'slug',
        'name',
        'tagline',
        'price_monthly_usd',
        'price_yearly_usd',
        'stripe_price_id_monthly',
        'stripe_price_id_yearly',
        'trial_days',
        'max_websites',
        'features',
        'api_limits',
        // Plugin entitlement matrix — the authoritative "which of the 8
        // feature flags is this plan allowed to enable" boolean map. The
        // `features` column above stays as marketing-bullet copy.
        'plan_features',
        // Per-plan research-engine caps (keyword_lookup, serp_fetch,
        // llm_call, brief). Column exists since 2026_05_06; was missing
        // from $fillable until the plan_features rollout.
        'research_limits',
        'display_order',
        'is_active',
        'is_highlighted',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'api_limits' => 'array',
            'plan_features' => 'array',
            'research_limits' => 'array',
            'price_monthly_usd' => 'integer',
            'price_yearly_usd' => 'integer',
            'trial_days' => 'integer',
            'max_websites' => 'integer',
            'display_order' => 'integer',
            'is_active' => 'boolean',
            'is_highlighted' => 'boolean',
        ];
    }

    /**
     * Look up a per-provider cap from the `api_limits` JSON via a dot path
     * like "keywords_everywhere.monthly_credits". Returns null if the key
     * is absent or its value is null — callers interpret null as
     * "unlimited" for that provider.
     */
    public function apiLimit(string $path): ?int
    {
        $limits = $this->api_limits;
        if (! is_array($limits)) {
            return null;
        }

        $node = $limits;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        if ($node === null || ! is_numeric($node)) {
            return null;
        }

        return (int) $node;
    }

    /**
     * Human-readable display of the website cap. Null = unlimited.
     */
    public function maxWebsitesLabel(): string
    {
        if ($this->max_websites === null) {
            return 'Unlimited';
        }
        return $this->max_websites === 1 ? '1 website' : ($this->max_websites.' websites');
    }

    /**
     * Scope to active plans, ordered for left-to-right display in
     * pricing cards.
     */
    public function scopeOrdered($query)
    {
        return $query->where('is_active', true)->orderBy('display_order');
    }

    /**
     * True when the plan has a real Stripe price configured and can
     * support a checkout session. Free tier always returns false.
     *
     * EBQ only sells yearly subscriptions — the monthly price column
     * exists purely so we can show "$X/mo, billed yearly" copy on the
     * pricing card. Checkout is always gated on the yearly price ID.
     */
    public function isCheckoutReady(): bool
    {
        return $this->price_yearly_usd > 0
            && ! empty($this->stripe_price_id_yearly);
    }

    /**
     * Canonical 8-key boolean entitlement map for this plan. Merges the
     * stored `plan_features` JSON over a defaults-all-false skeleton so
     * the caller always gets a complete map regardless of how partial
     * the DB row is (and zero-fills new flag keys added later).
     *
     * Consumed by:
     *   - Website::effectiveFeatureFlags()   — ceiling for per-site overrides
     *   - InjectFeatureFlags middleware      — plugin payload
     *   - Admin\PlanController edit form     — checkbox state
     *   - Plan::requiredPlanFor()            — upgrade-path resolution
     *
     * @return array<string, bool>
     */
    public function featureMap(): array
    {
        $defaults = array_fill_keys(self::FEATURE_KEYS, false);
        $stored = $this->plan_features;
        if (! is_array($stored)) {
            return $defaults;
        }
        foreach ($stored as $key => $value) {
            if (array_key_exists($key, $defaults)) {
                $defaults[$key] = (bool) $value;
            }
        }
        return $defaults;
    }

    /**
     * The slug of the lowest-priced active plan that enables the given
     * feature key. Used to populate the `required_tier` field on
     * `tier_required` API responses so the plugin can render copy like
     * "AI Writer is on Startup or above" without hardcoding tier names.
     *
     * Walks plans in `display_order` ASC so the cheapest tier that
     * unlocks the feature wins. Returns null when no plan enables the
     * feature (i.e. it's globally disabled or misconfigured).
     */
    public static function requiredPlanFor(string $featureKey): ?string
    {
        if (! in_array($featureKey, self::FEATURE_KEYS, true)) {
            return null;
        }
        $plans = self::ordered()->get(['slug', 'plan_features']);
        foreach ($plans as $plan) {
            if (($plan->featureMap()[$featureKey] ?? false) === true) {
                return (string) $plan->slug;
            }
        }
        return null;
    }
}
