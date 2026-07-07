<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

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
    use HasUlids;
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
        // Platform feature — not a plugin flag. No Scheduled Reports
        // feature exists yet; seeded per-tier but not enforced.
        'scheduled_reports',
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
        // Account-wide page budget pooled across ALL of the owner's sites (highest-
        // value pages first within each). Each site is still hard-capped at
        // config('crawler.max_pages_per_site') regardless. null = no pool (sites
        // just get the hard per-site cap). See Website::crawlPageCap().
        'max_crawl_pages',
        // Team seat cap — enforced on new invites only (existing teammates
        // are never removed). null = unlimited.
        'max_seats',
        // Display-only per-seat add-on price. No per-seat billing engine
        // exists; stored so the admin UI and marketing pages can show it.
        'extra_seat_price_usd',
        'features',
        // Sparse map of bullet-index => YouTube URL for the optional
        // explainer video shown next to a marketing bullet. Kept separate
        // from `features` so that column stays a plain string list for the
        // public API + WP wizard.
        'feature_videos',
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
            'feature_videos' => 'array',
            'api_limits' => 'array',
            'plan_features' => 'array',
            'research_limits' => 'array',
            'price_monthly_usd' => 'integer',
            'price_yearly_usd' => 'integer',
            'trial_days' => 'integer',
            'max_websites' => 'integer',
            'max_crawl_pages' => 'integer',
            'max_seats' => 'integer',
            'extra_seat_price_usd' => 'integer',
            'display_order' => 'integer',
            'is_active' => 'boolean',
            'is_highlighted' => 'boolean',
        ];
    }

    /**
     * Extract the 11-char YouTube video ID from any common YouTube URL
     * form (watch?v=, youtu.be/, /embed/, /shorts/, /v/). Returns null
     * when the string isn't a recognisable YouTube link — callers use
     * this both to validate operator input and to build the privacy-
     * friendly embed URL on the marketing page.
     */
    public static function youtubeId(?string $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $pattern = '~(?:youtu\.be/|youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|v/))([A-Za-z0-9_-]{11})~i';
        if (preg_match($pattern, $url, $m) === 1) {
            return $m[1];
        }

        return null;
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
            return __('Unlimited');
        }
        return $this->max_websites === 1 ? __('1 website') : __(':n websites', ['n' => $this->max_websites]);
    }

    /**
     * Human-readable display of the team seat cap. Null = unlimited.
     */
    public function maxSeatsLabel(): string
    {
        if ($this->max_seats === null) {
            return __('Unlimited');
        }
        return $this->max_seats === 1 ? __('1 seat') : __(':n seats', ['n' => $this->max_seats]);
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
     * support a checkout session for the given interval.
     * Free/Trial tier always returns false.
     */
    public function isCheckoutReady(string $interval = 'annual'): bool
    {
        if ($interval === 'monthly') {
            return $this->price_monthly_usd > 0
                && ! empty($this->stripe_price_id_monthly);
        }

        return $this->price_yearly_usd > 0
            && ! empty($this->stripe_price_id_yearly);
    }

    /** Stripe price ID for the given billing interval ('annual'|'monthly'). */
    public function stripePriceIdFor(string $interval): ?string
    {
        return $interval === 'monthly'
            ? ($this->stripe_price_id_monthly ?: null)
            : ($this->stripe_price_id_yearly ?: null);
    }

    /**
     * Find the Plan matching a Stripe price ID, checking both the monthly and
     * yearly columns. Checkout supports either interval, so any code resolving
     * an active subscription back to a Plan must check both — matching only
     * `stripe_price_id_yearly` silently drops monthly subscribers (found 2026-07-06).
     */
    public static function findByStripePrice(?string $priceId): ?self
    {
        if (! $priceId) {
            return null;
        }

        return static::where('stripe_price_id_monthly', $priceId)
            ->orWhere('stripe_price_id_yearly', $priceId)
            ->first();
    }

    /** Billing interval ('monthly'|'annual') a given Stripe price ID belongs to, if any. */
    public static function intervalForStripePrice(?string $priceId): ?string
    {
        if (! $priceId) {
            return null;
        }

        return static::where('stripe_price_id_monthly', $priceId)->exists() ? 'monthly' : 'annual';
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
