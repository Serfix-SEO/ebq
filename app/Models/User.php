<?php

namespace App\Models;

use App\Services\Content\ContentEntitlements;
use App\Support\TeamPermissions;
use App\Support\TrialStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Subscription;

// Email verification uses a GRACE WINDOW, not immediate enforcement. User
// implements MustVerifyEmail so the Registered event still sends a verification
// mail, but the `verified` middleware is overridden by
// App\Http\Middleware\EnsureEmailVerifiedAfterGrace: unverified users may use
// the app for config('auth.verification.grace_days') days after signup, then
// are forced to verify. See RegisteredUserController::store().
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use Billable, HasFactory, Notifiable;

    use HasUlids;

    /**
     * Tag any matching marketing lead as converted on signup — covers both
     * password registration and Google SSO (both ultimately create a User).
     */
    protected static function booted(): void
    {
        static::created(function (User $user): void {
            Lead::markConvertedFor($user);
        });

        // Delete owned websites through the model so the per-website app-level
        // shard cascade runs (cross-tier FKs are app-enforced, so a DB cascade on
        // users→websites would otherwise orphan the websites' tenant-node data).
        static::deleting(function (User $user): void {
            $user->websites()->get()->each(fn (Website $w) => $w->delete());
        });
    }

    /**
     * Subscription tier constants. After the 2026-06-26 5-tier rework:
     *
     *   trial      — default/no-subscription resting tier (was 'free')
     *   solo       — entry-level paid
     *   pro        — mid-tier paid
     *   agency     — high-volume paid
     *   enterprise — custom/contact-sales (no self-serve checkout)
     *
     * `effectiveTier()` returns one of these exact slugs. These strings
     * must stay in sync with the WP plugin's tier comparator — a plugin
     * update is required in lockstep when deploying this rename.
     *
     * Legacy constant TIER_FREE kept as an alias for TIER_TRIAL so any
     * call site that hasn't been updated yet still compiles correctly.
     */
    public const TIER_TRIAL = 'trial';

    public const TIER_FREE = self::TIER_TRIAL; // backward-compat alias

    public const TIER_SOLO = 'solo';

    public const TIER_PRO = 'pro';

    public const TIER_AGENCY = 'agency';

    public const TIER_ENTERPRISE = 'enterprise';

    /**
     * Tier ordinal — higher = more capable. Used by the `isAtLeast()`
     * helper so callers can ask "is this user on at least Pro?" without
     * hardcoding the full slug list.
     */
    public const TIER_ORDER = [
        self::TIER_TRIAL => 0,
        self::TIER_SOLO => 1,
        self::TIER_PRO => 2,
        self::TIER_AGENCY => 3,
        self::TIER_ENTERPRISE => 4,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'db_node_id',
        'timezone',
        'is_admin',
        'is_disabled',
        'password',
        // Cashier billing columns + plan snapshot. Cashier reads these
        // off the billable model; current_plan_slug is our snapshot of
        // the active subscription's plan slug for fast read-path checks
        // (website limits, frozen-site decisions).
        'stripe_id',
        'pm_type',
        'pm_last_four',
        'trial_ends_at',
        'current_plan_slug',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_growth_report_sent_at' => 'datetime',
            'is_admin' => 'boolean',
            'is_disabled' => 'boolean',
            'password' => 'hashed',
            'trial_ends_at' => 'datetime',
            'trial_deletion_notices' => 'array',
            'trial_data_deleted_at' => 'datetime',
            'content_trial_started_at' => 'datetime',
            'content_trial_ends_at' => 'datetime',
            'is_system' => 'boolean',
        ];
    }

    // ── Content product entitlement proxies (see ContentEntitlements) ────

    public function hasContentAccess(): bool
    {
        return app(ContentEntitlements::class)->hasContentAccess($this);
    }

    public function contentSubscription(): ?Subscription
    {
        return $this->subscription(ContentEntitlements::SUBSCRIPTION);
    }

    /**
     * A "content-only" user: has content access but their DASHBOARD trial has
     * lapsed — they get content features + teasered dashboard reports/crawl.
     */
    public function isContentOnly(): bool
    {
        return $this->hasContentAccess() && TrialStatus::isExpired($this);
    }

    public function googleAccounts(): HasMany
    {
        return $this->hasMany(GoogleAccount::class);
    }

    public function websites(): HasMany
    {
        return $this->hasMany(Website::class);
    }

    public function customPageAudits(): HasMany
    {
        return $this->hasMany(CustomPageAudit::class);
    }

    public function aiWriterPrompts(): HasMany
    {
        return $this->hasMany(AiWriterPrompt::class);
    }

    public function sharedWebsites(): BelongsToMany
    {
        return $this->belongsToMany(Website::class, 'website_user')
            ->withPivot(['role', 'permissions'])
            ->withTimestamps();
    }

    /**
     * Role for this user on the given website ('owner', 'admin', 'member') or null.
     */
    public function roleForWebsite(string $websiteId): ?string
    {
        if (($websiteId === null || $websiteId === '')) {
            return null;
        }

        $ownerCount = Website::query()->whereKey($websiteId)->where('user_id', $this->id)->count();
        if ($ownerCount > 0) {
            return TeamPermissions::ROLE_OWNER;
        }

        $row = DB::table('website_user')
            ->where('website_id', $websiteId)
            ->where('user_id', $this->id)
            ->first();

        if (! $row) {
            return null;
        }

        return (string) ($row->role ?: TeamPermissions::ROLE_MEMBER);
    }

    /**
     * @return list<string>|null
     */
    public function permissionsForWebsite(string $websiteId): ?array
    {
        $role = $this->roleForWebsite($websiteId);
        if ($role === null) {
            return null;
        }
        if ($role === TeamPermissions::ROLE_OWNER || $role === TeamPermissions::ROLE_ADMIN) {
            return null;
        }

        $row = DB::table('website_user')
            ->where('website_id', $websiteId)
            ->where('user_id', $this->id)
            ->first();

        if (! $row || $row->permissions === null) {
            return null;
        }

        $decoded = json_decode((string) $row->permissions, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : null;
    }

    public function hasFeatureAccess(string $feature, string $websiteId): bool
    {
        $role = $this->roleForWebsite($websiteId);
        if ($role === null) {
            return false;
        }

        return TeamPermissions::allows($role, $this->permissionsForWebsite($websiteId), $feature);
    }

    /**
     * Route name of the first feature this user can access on the given website.
     * Falls back to websites.index (accessible to anyone with ≥1 website).
     */
    public function firstAccessibleRoute(?string $websiteId): string
    {
        if ($websiteId !== null && $websiteId !== '') {
            // A content-covered site with NO Analytics / Search Console connected
            // is a Content Autopilot site — land on the content calendar, not the
            // dashboard (whose empty GA/GSC state reads as the reports onboarding
            // and traps content-only users who never connected those sources).
            if ($this->hasFeatureAccess('content', $websiteId)) {
                $website = Website::query()->select(['id', 'ga_property_id', 'gsc_site_url'])->find($websiteId);
                if ($website !== null && blank($website->ga_property_id) && blank($website->gsc_site_url)) {
                    return 'content.index';
                }
            }

            foreach (TeamPermissions::FEATURES as $key => $meta) {
                if ($this->hasFeatureAccess($key, $websiteId)) {
                    return $meta['route'];
                }
            }
        }

        return 'websites.index';
    }

    public function canManageTeamFor(string $websiteId): bool
    {
        $role = $this->roleForWebsite($websiteId);

        return $role === TeamPermissions::ROLE_OWNER || $role === TeamPermissions::ROLE_ADMIN;
    }

    public function timezoneForDisplay(): string
    {
        $tz = $this->timezone;
        if (is_string($tz) && $tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
            return $tz;
        }

        return (string) config('app.timezone');
    }

    /**
     * Websites this user owns or has been granted access to.
     *
     * @return Builder<Website>
     */
    public function accessibleWebsitesQuery(): Builder
    {
        return Website::query()
            ->where(function (Builder $q): void {
                $q->where('websites.user_id', $this->id)
                    ->orWhereExists(function ($sub): void {
                        $sub->selectRaw('1')
                            ->from('website_user')
                            ->whereColumn('website_user.website_id', 'websites.id')
                            ->where('website_user.user_id', $this->id);
                    });
            });
    }

    public function hasAccessibleWebsites(): bool
    {
        return $this->accessibleWebsitesQuery()->exists();
    }

    public function canViewWebsiteId(string $websiteId): bool
    {
        if ($websiteId === '') {
            return false;
        }

        $website = Website::find($websiteId);

        return $website !== null && $this->can('view', $website);
    }

    /* ─── Billing & plan helpers ────────────────────────────────────
     * Per-user billing: one Cashier subscription per user, the active
     * plan caps how many websites the user can manage. Below are the
     * read-paths every consumer (controllers, middleware, views, jobs)
     * uses to ask "what plan? how many sites? which sites are frozen?"
     */

    /**
     * The Plan row the user is currently on. Resolution order:
     *   1. `config('app.free')` (FREE=true env) — every user clones into
     *      the Pro tier regardless of subscription state. Falls back to
     *      the next resolution step if the Pro row doesn't exist.
     *   2. Active Cashier subscription → match by stripe_price_id_monthly/_yearly
     *   3. Snapshotted current_plan_slug (set by webhook + on swap)
     *   4. The `trial` plan row, so admin-edited max_websites etc. on
     *      Trial actually take effect for users without a paid sub
     *
     * Returns null only if the database has no Plan rows at all (fresh
     * install, seeder hasn't run).
     */
    public function effectivePlan(): ?Plan
    {
        $plan = $this->resolveSubscribedPlan();

        // Free-promo override: when the platform is in "all Pro free" mode
        // (env FREE=true) every user is entitled to at least the Pro tier
        // regardless of subscription state. Flipping FREE=false snaps them
        // back to their real plan on the very next request.
        //
        // It must only ever *upgrade* — never downgrade a user already on a
        // higher tier (Startup/Agency). The previous unconditional return of
        // the Pro row silently stripped Agency/Startup-only entitlements
        // (e.g. ai_writer / AI Studio) from those users while FREE was on.
        // Falls through to the resolved plan when the Pro row is missing
        // (deleted from admin) so we never 500 on a misconfig.
        if ((bool) config('app.free', false)) {
            $pro = Plan::where('slug', self::TIER_PRO)->first();
            if ($pro) {
                $currentRank = self::TIER_ORDER[$plan->slug ?? self::TIER_TRIAL] ?? 0;
                $proRank = self::TIER_ORDER[self::TIER_PRO] ?? 0;
                if ($plan === null || $currentRank < $proRank) {
                    return $pro;
                }
            }
        }

        return $plan;
    }

    /**
     * Resolve the user's real plan from subscription state, honouring (in
     * order): an active Cashier subscription matched by monthly or yearly price ID, the
     * snapshotted `current_plan_slug`, then the Trial plan row so admin edits
     * to Trial's max_websites / features apply to unsubscribed users. Returns
     * null only when the plans table is empty (fresh install pre-seeder).
     */
    private function resolveSubscribedPlan(): ?Plan
    {
        $subscription = $this->subscription('default');
        if ($subscription && $subscription->valid()) {
            $price = (string) $subscription->stripe_price;
            if ($price !== '') {
                $plan = Plan::findByStripePrice($price);
                if ($plan) {
                    return $plan;
                }
            }
        }
        if (! empty($this->current_plan_slug)) {
            $plan = Plan::where('slug', $this->current_plan_slug)->first();
            if ($plan) {
                return $plan;
            }
        }

        return Plan::where('slug', self::TIER_TRIAL)->first();
    }

    /**
     * Exact slug of the user's effective plan. Post-5-tier-rework, one of:
     * `trial`, `solo`, `pro`, `agency`, `enterprise`. The WP plugin reads this as
     * the `tier` field on every authenticated JSON response (injected
     * by `InjectFeatureFlags`).
     *
     * Honours the free-promo short-circuit transparently: when
     * `effectivePlan()` resolves to the Pro row because of FREE=true,
     * this returns `'pro'` and the plugin treats it identically to a
     * paid Pro user. Falls back to TIER_TRIAL (not TIER_FREE — same
     * string, alias kept for backward compat) when plan is null.
     */
    public function effectiveTier(): string
    {
        $plan = $this->effectivePlan();
        if ($plan === null) {
            return self::TIER_TRIAL;
        }

        return (string) $plan->slug;
    }

    /**
     * Convenience for "is the user on any paid tier". Kept as a
     * backward-compat shim for the dozens of `$user->isPro()` /
     * `$website->isPro()` call sites; new code should prefer
     * `isAtLeast()` to express specific tier requirements.
     */
    public function isPro(): bool
    {
        return $this->effectiveTier() !== self::TIER_TRIAL;
    }

    /**
     * Ordinal-comparison helper. Returns true iff the user's effective
     * tier ranks at or above the requested slug.
     *
     *   $user->isAtLeast(User::TIER_STARTUP)
     *
     * Unknown slugs return false (defensive — a typo never accidentally
     * grants access).
     */
    public function isAtLeast(string $slug): bool
    {
        $required = self::TIER_ORDER[$slug] ?? null;
        if ($required === null) {
            return false;
        }
        $current = self::TIER_ORDER[$this->effectiveTier()] ?? 0;

        return $current >= $required;
    }

    /**
     * The 8-key plugin entitlement map for this user's current plan.
     * Thin wrapper around `effectivePlan()->featureMap()` with a safe
     * all-false fallback when no Plan rows exist at all.
     *
     * @return array<string, bool>
     */
    public function effectivePlanFeatures(): array
    {
        $plan = $this->effectivePlan();
        if ($plan === null) {
            return array_fill_keys(Plan::FEATURE_KEYS, false);
        }

        return $plan->featureMap();
    }

    /**
     * Maximum websites the user's current plan allows. Null = unlimited
     * (Agency or any plan with `max_websites` cleared in the admin).
     * Reads straight off the resolved plan, including Free, so admin
     * edits to the Free plan's max_websites take effect for free-tier
     * users.
     */
    public function websiteLimit(): ?int
    {
        $plan = $this->effectivePlan();
        // Only when the entire plans table is missing (fresh install
        // before the seeder runs) do we fall back to a conservative
        // single-site default. In normal operation effectivePlan()
        // always returns the Free row at minimum.
        if ($plan === null) {
            return 1;
        }

        return $plan->max_websites;
    }

    /**
     * This user's ACCOUNT-WIDE page budget, pooled across ALL of their owned
     * websites (not a per-site number) — see Website::crawlPageCap(), which
     * derives each site's individual share of this pool, hard-capped at
     * config('crawler.max_pages_per_site') regardless of plan. null = no
     * account pool (each site still gets the hard per-site cap).
     */
    public function crawlPageLimit(): ?int
    {
        return $this->effectivePlan()?->max_crawl_pages;
    }

    /**
     * IDs of websites past the user's current limit, ordered by
     * created_at — i.e. the oldest sites stay active, newer ones are
     * frozen on a downgrade. Computed live (no stored column) so plan
     * changes take effect on the next read with no migration drift.
     *
     * @return list<int>
     */
    public function frozenWebsiteIds(): array
    {
        $limit = $this->websiteLimit();
        if ($limit === null) {
            return [];
        }
        $owned = Website::where('user_id', $this->id)
            ->orderBy('created_at')
            ->pluck('id')
            ->all();
        if (count($owned) <= $limit) {
            return [];
        }

        return array_slice($owned, $limit);
    }

    /**
     * True when the user can add another website without breaking
     * their plan limit. Onboarding + the admin "add website" flow gate
     * on this; the UI can also use it to render a disabled CTA.
     */
    public function canAddWebsite(): bool
    {
        $limit = $this->websiteLimit();
        if ($limit === null) {
            return true;
        }
        if (Website::where('user_id', $this->id)->count() < $limit) {
            return true;
        }

        // Out of DASHBOARD slots — but Content Autopilot is a separate product
        // with its own per-site billing (base site + the paid per-extra-site
        // addon). A customer who bought a slot for another content site must be
        // able to add it, or they are charged for something they cannot use.
        // The new site is still frozen for dashboard features; only its content
        // entitlement is honoured (see Website::effectiveFeatureFlags).
        return $this->hasFreeContentSlot();
    }

    /** An unused Content Autopilot website slot (base + addon quantity). */
    public function hasFreeContentSlot(): bool
    {
        try {
            $ent = app(ContentEntitlements::class);

            return $ent->hasContentAccess($this) && $ent->sitesCovered($this) < $ent->sitesAllowed($this);
        } catch (\Throwable) {
            return false; // content tables absent / Stripe offline → never widen
        }
    }
}
