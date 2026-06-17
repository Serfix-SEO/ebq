<?php

namespace App\Models;

use App\Jobs\ReprocessCompetitiveData;
use App\Jobs\SyncAnalyticsData;
use App\Jobs\SyncSearchConsoleData;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class Website extends Model
{
    use HasUlids;
    // Billing has moved to the User model — Cashier's Billable trait
    // now lives on App\Models\User. Tier and freeze state are derived
    // from the owning user's plan (see effectiveTier / isFrozen below).
    use HasApiTokens, HasFactory;

    protected static function booted(): void
    {
        // Keep the normalized-domain key in sync from `domain` on every write so
        // the shared-crawl lookup (crawl_sites.normalized_domain) is consistent
        // across onboarding, WebsitesList, admin, and factories.
        static::saving(function (Website $website): void {
            $domain = (string) $website->domain;
            $website->normalized_domain = $domain !== '' ? CrawlSite::normalizeDomain($domain) : null;
        });

        // Link every website to its shared crawl_site (one per normalized domain).
        // The bootstrapper adds the charge + crawl dispatch on top of this; here we
        // only guarantee the membership so all crawl reads/writes have a crawl_site.
        static::saved(function (Website $website): void {
            if (! $website->normalized_domain) {
                return;
            }
            if ($website->crawl_site_id && ! $website->wasChanged('normalized_domain')) {
                return;
            }
            $site = CrawlSite::firstOrCreate(['normalized_domain' => $website->normalized_domain]);
            if ((string) $website->crawl_site_id !== (string) $site->id) {
                $website->crawl_site_id = $site->id;
                $website->saveQuietly();
            }
            $site->recomputeEffectiveCap();
        });

        // On delete, detach from the shared crawl_site and recompute its cap. If no
        // subscribers remain, GC the shared crawl + its data (deleting one user's
        // website never touches the shared crawl while others still subscribe — the
        // website_id cascade FK was dropped for exactly this reason).
        static::deleted(function (Website $website): void {
            // App-level cascade: cross-tier FKs are app-enforced (a tenant node has
            // no central tables to cascade from), so delete this website's tenant
            // rows explicitly on its node. Resolved from the row's own anchors
            // (the websites row is already gone). Idempotent w.r.t. any DB cascade.
            $cleanup = app(\App\Services\Sharding\ShardCleanup::class);
            $cleanup->purgeWebsiteTenantData(
                (string) $website->id,
                \App\Services\Sharding\ShardCleanup::connectionFor($website->db_node_id),
            );

            if (! $website->crawl_site_id) {
                return;
            }
            $site = CrawlSite::find($website->crawl_site_id);
            if (! $site) {
                return;
            }
            if ($site->websites()->count() === 0) {
                // Last subscriber gone → GC the shared crawl on its crawl node
                // (also clears website_finding_states, which the old loop missed).
                $cleanup->purgeCrawlSiteData(
                    (string) $site->id,
                    \App\Services\Sharding\ShardCleanup::connectionFor($site->crawl_node_id),
                );
                $site->delete();
            } else {
                $site->recomputeEffectiveCap();
            }
        });

        static::created(function (Website $website): void {
            // Skip the 365-day backfill for websites that boot up
            // already over the user's plan limit (would happen in
            // theoretical race conditions during onboarding while the
            // canAddWebsite gate is being added; in steady-state the
            // gate prevents this).
            if ($website->isFrozen()) {
                return;
            }
            // Only sync the sources that are actually connected. A site
            // onboarded GA-only (or GSC-only, or with neither — the
            // PageSpeed-only path) must not dispatch a job for a source
            // it can't fetch.
            if ($website->hasGa()) {
                SyncAnalyticsData::dispatch($website->id, 365);
            }
            if ($website->hasGsc()) {
                SyncSearchConsoleData::dispatch($website->id, 365);
            }
        });

        // When Search Console connects AFTER the fact (a client who skipped
        // Google on signup, then connected later), upgrade any competitive
        // artifacts to the higher-fidelity tier. Fires only on the genuine
        // hasGsc() false → true edge; the job is unique/debounced so a
        // back-to-back GSC + GA connect reprocesses just once.
        static::updated(function (Website $website): void {
            if ($website->wasChanged('gsc_google_account_id')
                && $website->getOriginal('gsc_google_account_id') === null
                && $website->gsc_google_account_id !== null
                && $website->hasGsc()) {
                ReprocessCompetitiveData::dispatch($website->id);
            }
        });
    }

    /** Subscription tier constants — kept for API-response back-compat */
    public const TIER_FREE = 'free';
    public const TIER_PRO  = 'pro';

    protected $fillable = [
        'user_id',
        'db_node_id',
        'crawl_site_id',
        'domain',
        'normalized_domain',
        'feature_flags',
        'ga_property_id',
        'ga_google_account_id',
        'gsc_site_url',
        'gsc_google_account_id',
        'gsc_keyword_lookback_days',
        'report_recipients',
        'last_analytics_sync_at',
        'last_search_console_sync_at',
        'last_traffic_drop_alert_at',
        'last_rank_drop_alert_at',
        'crawl_protection',
        'crawl_protection_at',
        'sitemap_lastmod_true',
        'sitemap_lastmod_false',
    ];

    /**
     * Canonical list of toggleable features kept in sync with the
     * WordPress plugin's `EBQ_Feature_Flags::KNOWN_FEATURES`. The admin
     * UI iterates this list to render the toggle grid; the API endpoint
     * merges stored overrides on top of these defaults.
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
    ];

    /**
     * Per-feature defaults applied when a website hasn't customised the
     * flag explicitly. Heavy AI surfaces (the floating chatbot + the
     * full-post AI Writer) default OFF so customers explicitly enable
     * them via the admin grid; everything else defaults ON because they
     * are core editor enhancements with low risk.
     *
     * Keep this in sync with the marketing default tier presentation —
     * if a customer signs up and the plugin shows nothing they expected,
     * the bug is here.
     *
     * @var array<string, bool>
     */
    public const FEATURE_DEFAULTS = [
        'chatbot'          => false,
        'ai_writer'        => false,
        'ai_inline'        => true,
        'live_audit'       => true,
        'hq'               => true,
        'redirects'        => true,
        'dashboard_widget' => true,
        'post_column'      => true,
    ];

    /**
     * Resolve the effective feature-flag map for this website.
     *
     * Composition (highest-priority "off" wins):
     *
     *   freeze            → all-off, short-circuit
     *   plan ceiling      → if the owning user's plan doesn't allow a
     *                       feature, it's OFF regardless of overrides
     *   global kill       → emergency platform-wide kill-switch (AND'd)
     *   per-site override → admin-set per-customer (can only narrow,
     *                       never widen — capped by the plan)
     *   plan defaults     → starting point when no override is set
     *
     * The previous behaviour blended FEATURE_DEFAULTS with overrides;
     * the canonical starting state is now the user's plan entitlement
     * matrix (`User::effectivePlanFeatures()`), with FEATURE_DEFAULTS
     * kept only as a fallback for orphan / userless websites (tests,
     * fixture data, transient onboarding rows before user_id is set).
     *
     * @return array<string, bool>
     */
    public function effectiveFeatureFlags(): array
    {
        // 1. Freeze short-circuits everything — over-plan-limit sites
        //    behave like locked free trials regardless of upstream state.
        if ($this->isFrozen()) {
            return array_fill_keys(self::FEATURE_KEYS, false);
        }

        // 2. Start from the owning user's plan map. Orphan rows fall
        //    back to FEATURE_DEFAULTS so test fixtures / transient
        //    onboarding sites don't 500 just because no plan resolved.
        $owner = $this->user;
        $effective = $owner !== null
            ? $owner->effectivePlanFeatures()
            : self::FEATURE_DEFAULTS;

        // 3. Per-site override — can NARROW (turn off a plan-allowed
        //    flag) but cannot WIDEN (a per-site true on a plan-disallowed
        //    flag is ignored; the plan is the ceiling).
        $stored = $this->feature_flags;
        if (is_array($stored)) {
            foreach ($stored as $key => $value) {
                if (! array_key_exists($key, $effective)) {
                    continue;
                }
                if ((bool) $value === false) {
                    $effective[$key] = false;
                }
                // (bool) $value === true: noop — plan already permits or
                // forbids this; per-site can't override the plan ceiling.
            }
        }

        // 4. Global kill-switch — AND'd last so an emergency disable
        //    propagates regardless of per-plan or per-site state.
        $global = self::globalFeatureFlags();
        foreach ($effective as $key => $value) {
            if (($global[$key] ?? true) === false) {
                $effective[$key] = false;
            }
        }

        // 5. Trim to the plugin-shipped key set. The Plan map is wider
        //    than Website::FEATURE_KEYS (it includes platform-only
        //    features like `report_whitelabel` that the WP plugin
        //    doesn't consume). Sending unknown keys to the plugin is
        //    harmless (KNOWN_FEATURES drops them) but wasteful and
        //    leaks platform internals into the public payload.
        return array_intersect_key($effective, array_flip(self::FEATURE_KEYS));
    }

    /**
     * Returns the slug of the cheapest plan that would unlock the given
     * feature key for this website's owner. Null when the feature is on
     * already, when no plan enables it, or when the website has no
     * owner. Used by API gating to populate `required_tier` on
     * `tier_required` responses so the plugin can render contextual
     * upgrade copy ("AI Writer is on Startup or above").
     */
    public function featureRequiresUpgrade(string $key): ?string
    {
        $effective = $this->effectiveFeatureFlags();
        if (($effective[$key] ?? false) === true) {
            return null;
        }
        return Plan::requiredPlanFor($key);
    }

    /**
     * Single-call feature gate for the API surface. Returns null when
     * the feature is currently allowed for this website (plan + global
     * + per-site overrides + freeze all permit it), or a structured
     * payload the controller can splat into its 402 JsonResponse.
     *
     * The error code distinguishes two failure modes:
     *
     *   tier_required     — the owner's plan doesn't include the
     *                       feature; the plugin should show an
     *                       "Upgrade to <slug>" CTA. `required_tier`
     *                       points at the cheapest qualifying plan.
     *   feature_disabled  — the plan allows the feature, but either
     *                       the global kill-switch or a per-site
     *                       override turned it off. No amount of
     *                       upgrading fixes this — the workspace admin
     *                       has to flip the switch back.
     *
     * Frozen sites get tier_required too: the user CAN unfreeze by
     * upgrading or removing other sites, so the upgrade CTA is the
     * correct affordance.
     *
     * @return array<string, mixed>|null
     */
    public function featureGateInfo(string $key): ?array
    {
        $effective = $this->effectiveFeatureFlags();
        if (($effective[$key] ?? false) === true) {
            return null;
        }

        $currentTier = $this->effectiveTier();
        $required = Plan::requiredPlanFor($key);
        $order = User::TIER_ORDER;

        // Distinguish "you need a bigger plan" from "your plan supports
        // this but it's been disabled". A required slug that exists AND
        // ranks strictly above the current tier is an upgrade path; the
        // owner's plan already allowing it but the flag still being off
        // means a kill-switch / per-site override is suppressing it.
        $isUpgradePath = $required !== null
            && ($order[$required] ?? 0) > ($order[$currentTier] ?? 0);

        // Frozen sites are coded as tier_required so the plugin renders
        // an Upgrade CTA — unfreezing requires either removing sites or
        // upgrading to a larger plan.
        if ($this->isFrozen()) {
            $isUpgradePath = true;
        }

        return [
            'ok' => false,
            'error' => $isUpgradePath ? 'tier_required' : 'feature_disabled',
            'tier' => $currentTier,
            'required_tier' => $required ?? self::TIER_PRO,
            'feature' => $key,
        ];
    }

    /**
     * Global per-feature kill-switch map, the same shape as
     * FEATURE_DEFAULTS. Persisted in the `settings` table under the
     * `global_feature_flags` key; cached forever in Laravel's cache,
     * invalidated on admin save. Returns FEATURE_DEFAULTS when no
     * row exists yet (fresh database, pre-seeding).
     *
     * Used by both `effectiveFeatureFlags()` (per-site AND'ing) and
     * `WordPressPluginVersionController` (broadcast to unconnected
     * installs via the public version-check endpoint).
     *
     * @return array<string, bool>
     */
    public static function globalFeatureFlags(): array
    {
        $stored = Setting::get('global_feature_flags', null);
        $defaults = self::FEATURE_DEFAULTS;
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
     * True when this website's tier unlocks paid AI features. Derived
     * from the owning user's plan AND a "not frozen" check, since a
     * website over the user's plan limit drops back to free even if
     * the user themselves is on Pro.
     *
     * Used by every Pro-gating controller. The WP plugin reads `tier`
     * (string) from API responses; effectiveTier() flows through that
     * pathway unchanged. After the slug rename, "Pro" is the entry-
     * level paid tier; `isPro()` returns true for any paid tier
     * (pro/startup/agency).
     */
    public function isPro(): bool
    {
        if ($this->isFrozen()) {
            return false;
        }
        $owner = $this->user;
        return $owner !== null && $owner->isPro();
    }

    /**
     * Exact tier slug for API responses (the WP plugin reads this).
     * Frozen sites always report 'free' so plugin features lock out.
     *
     * After the 2026-05-17 rename returns one of: `free`, `pro`,
     * `startup`, `agency`. The free-promo (FREE=true) short-circuit
     * lives in `User::effectivePlan()` — when set, the platform
     * resolves every user to the Pro plan row, and this method then
     * returns `pro` naturally without a parallel check here.
     */
    public function effectiveTier(): string
    {
        if ($this->isFrozen()) {
            return self::TIER_FREE;
        }
        $owner = $this->user;
        return $owner !== null ? $owner->effectiveTier() : self::TIER_FREE;
    }

    /**
     * Ordinal-comparison helper for tier gates: "is this website on at
     * least the Startup tier?". Delegates to the owning user when one
     * is set; orphan websites always return false.
     */
    public function isAtLeast(string $slug): bool
    {
        if ($this->isFrozen()) {
            return $slug === self::TIER_FREE;
        }
        $owner = $this->user;
        return $owner !== null && $owner->isAtLeast($slug);
    }

    /**
     * True when this website is past the owning user's plan limit.
     * Computed live (no stored column) so plan changes take effect on
     * the next read with no migration drift. The user's frozen list
     * is ordered by created_at so the oldest sites stay active.
     */
    public function isFrozen(): bool
    {
        if (! $this->user_id) {
            return false;
        }
        $owner = $this->user;
        if ($owner === null) {
            return false;
        }
        return in_array($this->id, $owner->frozenWebsiteIds(), true);
    }

    protected function casts(): array
    {
        return [
            'report_recipients' => 'array',
            'feature_flags' => 'array',
            'gsc_keyword_lookback_days' => 'integer',
            'last_analytics_sync_at' => 'datetime',
            'last_search_console_sync_at' => 'datetime',
            'last_traffic_drop_alert_at' => 'datetime',
            'last_rank_drop_alert_at' => 'datetime',
            'crawl_protection_at' => 'datetime',
            'sitemap_lastmod_true' => 'integer',
            'sitemap_lastmod_false' => 'integer',
        ];
    }

    /** Has the crawler detected this site is behind Cloudflare/a WAF, or blocking us? */
    public function isCrawlProtected(): bool
    {
        return $this->crawlSite?->isCrawlProtected() ?? false;
    }

    /**
     * Whether this site's sitemap <lastmod> is a trustworthy freshness signal.
     * Learned from how often a lastmod bump actually led to a content change
     * (sitemap_lastmod_true) vs not (…_false). Always-bumping sitemaps accumulate
     * mostly false and stay untrusted, so we fall back to adaptive scheduling.
     */
    public function sitemapLastmodTrusted(): bool
    {
        return $this->crawlSite?->sitemapLastmodTrusted() ?? false;
    }

    /**
     * Enough evidence to conclude this site's sitemap <lastmod> is NOT meaningful
     * (an always-bumping sitemap). Once confirmed, we stop spending effort tracking
     * its per-page lastmod and lean entirely on adaptive content-frequency scheduling.
     */
    public function sitemapLastmodConfirmedUntrusted(): bool
    {
        return $this->crawlSite?->sitemapLastmodConfirmedUntrusted() ?? false;
    }

    /**
     * Users who should receive reports. Falls back to the owner if none configured.
     *
     * @return Collection<int, User>
     */
    public function getReportRecipientUsers(): Collection
    {
        $ids = $this->report_recipients;

        if (empty($ids)) {
            return User::where('id', $this->user_id)->get();
        }

        return User::whereIn('id', $ids)->get();
    }

    /**
     * Rolling window (days) for Search Console rows used in page audits and page-level GSC UI.
     */
    public function effectiveGscKeywordLookbackDays(): int
    {
        $default = (int) config('audit.gsc_keyword_lookback_days_default', 28);
        $min = (int) config('audit.gsc_keyword_lookback_days_min', 7);
        $max = (int) config('audit.gsc_keyword_lookback_days_max', 480);
        $raw = $this->gsc_keyword_lookback_days;

        if ($raw === null) {
            return max($min, min($max, $default));
        }

        return max($min, min($max, (int) $raw));
    }

    /**
     * Inclusive lower bound date (Y-m-d) for GSC keyword aggregates: date >= today - N days.
     */
    public function gscKeywordWindowStartDate(?Carbon $today = null): string
    {
        $today ??= Carbon::today((string) config('app.timezone'));

        return $today->copy()->subDays($this->effectiveGscKeywordLookbackDays())->toDateString();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The Google account that owns this site's GA4 property. Nullable —
     * a website may have no GA source connected (degraded mode), or its
     * account may have been deleted (FK nulled on delete).
     */
    public function gaAccount(): BelongsTo
    {
        return $this->belongsTo(GoogleAccount::class, 'ga_google_account_id');
    }

    /**
     * The Google account that owns this site's Search Console property.
     * Independent from {@see gaAccount()} so GA and GSC can come from
     * different Google logins.
     */
    public function gscAccount(): BelongsTo
    {
        return $this->belongsTo(GoogleAccount::class, 'gsc_google_account_id');
    }

    /**
     * True when this website has a usable GA4 source: both a property id
     * and the specific Google account that can read it. We treat the
     * empty string as "absent" to match the existing placeholder
     * convention (pay-first rows store '' before onboarding completes).
     */
    public function hasGa(): bool
    {
        return $this->ga_property_id !== null
            && $this->ga_property_id !== ''
            && $this->ga_google_account_id !== null;
    }

    /**
     * True when this website has a usable Search Console source.
     */
    public function hasGsc(): bool
    {
        return $this->gsc_site_url !== null
            && $this->gsc_site_url !== ''
            && $this->gsc_google_account_id !== null;
    }

    /**
     * Resolve the Google account to use for GA fetches. Prefers the
     * explicit per-source account; falls back to the owner's most-recent
     * account so legacy rows the backfill missed still sync. The fallback
     * is transitional — drop it once backfill is confirmed so account
     * deletion degrades cleanly.
     */
    public function gaAccountResolved(): ?GoogleAccount
    {
        return $this->gaAccount ?? $this->user?->googleAccounts()->latest()->first();
    }

    /**
     * Resolve the Google account to use for GSC / indexing fetches.
     */
    public function gscAccountResolved(): ?GoogleAccount
    {
        return $this->gscAccount ?? $this->user?->googleAccounts()->latest()->first();
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'website_user')
            ->withPivot(['role', 'permissions'])
            ->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(WebsiteInvitation::class);
    }

    public function analyticsData(): HasMany
    {
        return $this->hasMany(AnalyticsData::class);
    }

    public function searchConsoleData(): HasMany
    {
        return $this->hasMany(SearchConsoleData::class);
    }

    public function sitemaps(): HasMany
    {
        return $this->hasMany(WebsiteSitemap::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(WebsitePage::class);
    }

    public function internalLinks(): HasMany
    {
        return $this->hasMany(WebsiteInternalLink::class);
    }

    public function crawlRuns(): HasMany
    {
        return $this->hasMany(CrawlRun::class);
    }

    public function crawlFindings(): HasMany
    {
        return $this->hasMany(CrawlFinding::class);
    }

    /** The shared crawl this website subscribes to (one per normalized domain). */
    public function crawlSite(): BelongsTo
    {
        return $this->belongsTo(CrawlSite::class);
    }

    public function latestCrawlRun(): ?CrawlRun
    {
        return $this->crawlSite?->latestRun();
    }

    /**
     * The in-progress crawl for this site's shared crawl, if any (recency-guarded).
     * Delegates to the crawl_site since the crawl is now shared per domain.
     */
    public function runningCrawl(): ?CrawlRun
    {
        return $this->crawlSite?->runningCrawl();
    }

    public function isCrawling(): bool
    {
        return $this->runningCrawl() !== null;
    }

    /** Has the shared crawl ever produced a finished crawl (i.e. real data exists)? */
    public function hasCompletedCrawl(): bool
    {
        return $this->crawl_site_id
            ? CrawlRun::where('crawl_site_id', $this->crawl_site_id)->where('status', CrawlRun::STATUS_COMPLETED)->exists()
            : false;
    }

    /**
     * First-ever crawl is in progress: a crawl is running and nothing has
     * finished yet. While true, crawl-derived dashboard widgets (Site Health,
     * action queue) are hidden behind the crawl-in-progress banner because they
     * have no data to show. On re-crawls of an established site this is false,
     * so existing data keeps showing.
     */
    public function isInitialCrawl(): bool
    {
        return $this->isCrawling() && ! $this->hasCompletedCrawl();
    }

    /**
     * Resolved max pages a crawl run may fetch for this site: the owner's plan
     * cap (max_crawl_pages) when set, otherwise the global crawler budget. Always
     * a positive integer, so the crawler can use it directly as the run budget.
     */
    public function crawlPageCap(): int
    {
        $planLimit = $this->owner?->crawlPageLimit();
        if ($planLimit !== null && $planLimit > 0) {
            return (int) $planLimit;
        }

        return max(1, (int) config('crawler.max_pages_per_run', 200000));
    }

    public function backlinks(): HasMany
    {
        return $this->hasMany(Backlink::class);
    }

    public function pageIndexingStatuses(): HasMany
    {
        return $this->hasMany(PageIndexingStatus::class);
    }

    public function customPageAudits(): HasMany
    {
        return $this->hasMany(CustomPageAudit::class);
    }

    public function pluginInstall(): HasOne
    {
        return $this->hasOne(WebsitePluginInstall::class);
    }

    /**
     * Whether a page URL is on this website's domain or a subdomain of it (www normalized).
     */
    public function isAuditUrlForThisSite(string $url): bool
    {
        $domain = strtolower(trim((string) $this->domain));
        $domain = preg_replace('/^www\./', '', $domain) ?: $domain;
        if ($domain === '') {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower($host);
        $host = preg_replace('/^www\./', '', $host) ?: $host;

        return $host === $domain || str_ends_with($host, '.'.$domain);
    }
}
