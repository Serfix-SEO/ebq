<?php

namespace App\Services;

use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteReportSnapshot;
use App\Services\Crawler\CrawlSiteBootstrapper;
use Illuminate\Support\Facades\Artisan;

/**
 * The one canonical "attach a domain to a user's account" recipe, extracted
 * from WebsitesList::addWebsite() so the Site Explorer signup/signin funnel
 * can attach the pre-signup domain with the exact same side effects:
 * 365-day GSC/GA historical import + shared crawl_site subscription +
 * current-website session pin.
 *
 * NOT used by onboarding's ConnectGoogle::persistWebsite() — the pay-first
 * Stripe flow reuses a placeholder row the subscription is linked to, which
 * is deliberately different from this create-or-reuse recipe.
 */
class WebsiteAttachService
{
    public function __construct(private CrawlSiteBootstrapper $bootstrapper)
    {
    }

    /**
     * Attach a domain to the user's account, idempotently.
     *
     * - Reuses the user's existing website (owned or shared) for the same
     *   normalized domain instead of double-creating.
     * - Enforces the plan limit on the create path only.
     * - On create: queues the historical import and subscribes the shared
     *   crawl (cap-charged; instantly reuses shared data when the domain is
     *   already covered).
     * - Pins the resulting website as the session's current website.
     *
     * @param  array{ga_property_id?: string, ga_google_account_id?: string|int|null,
     *               gsc_site_url?: string, gsc_google_account_id?: string|int|null}  $sourceAttrs
     * @return array{website: ?Website, created: bool, blocked: ?string}
     *         blocked: null | 'invalid_domain' | 'plan_limit'
     */
    public function attach(User $user, string $rawDomain, array $sourceAttrs = []): array
    {
        $normalized = WebsiteReportSnapshot::normalizeDomain($rawDomain);
        if ($normalized === '') {
            return ['website' => null, 'created' => false, 'blocked' => 'invalid_domain'];
        }

        $existing = Website::query()
            ->where('normalized_domain', $normalized)
            ->get()
            ->first(fn (Website $w) => $user->canViewWebsiteId($w->id));

        if ($existing !== null) {
            // Re-adding an owned domain may carry fresh GA/GSC selections —
            // apply them (the old WebsitesList updateOrCreate did). Never
            // touch a website merely shared with this user.
            if ($sourceAttrs !== [] && $existing->user_id === $user->id) {
                $existing->fill($sourceAttrs)->save();
            }

            session(['current_website_id' => $existing->id]);

            return ['website' => $existing, 'created' => false, 'blocked' => null];
        }

        if (! $user->canAddWebsite()) {
            return ['website' => null, 'created' => false, 'blocked' => 'plan_limit'];
        }

        $website = Website::updateOrCreate(
            ['user_id' => $user->id, 'domain' => $rawDomain],
            $sourceAttrs + [
                'ga_property_id' => '',
                'ga_google_account_id' => null,
                'gsc_site_url' => '',
                'gsc_google_account_id' => null,
            ],
        );

        if ($website->wasRecentlyCreated) {
            Artisan::queue('ebq:import-historical', [
                '--days' => 365,
                '--website' => (string) $website->id,
            ]);

            // Subscribe to the shared crawl_site: charge the cap and crawl only
            // if the domain isn't already covered (else reuse the shared data).
            $this->bootstrapper->subscribeWebsite($website);
        }

        session(['current_website_id' => $website->id]);

        return ['website' => $website, 'created' => $website->wasRecentlyCreated, 'blocked' => null];
    }
}
