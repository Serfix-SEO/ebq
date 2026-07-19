<?php

namespace App\Services\Content;

use App\Jobs\PlanContentTopicsJob;
use App\Jobs\PrepareContentKeywordInsightsJob;
use App\Models\ContentOnboardingSession;
use App\Models\ContentPlan;
use App\Models\User;
use App\Models\Website;
use App\Services\Crawler\CrawlSiteBootstrapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Anonymous content-onboarding: creates a provisional website under the system
 * "content-leads" user during the public wizard, then re-parents it (and its
 * draft ContentPlan) to the real user on signup.
 */
class ContentOnboardingConverter
{
    public function __construct(private readonly ContentEntitlements $entitlements)
    {
    }

    /** The internal user that owns provisional/lead websites (never billed/cleaned). */
    public function systemUser(): User
    {
        $user = User::query()->firstOrNew(['email' => 'content-leads@system.serfix']);
        if (! $user->exists) {
            // is_system is intentionally NOT mass-assignable — set it explicitly.
            $user->forceFill([
                'name' => 'Content Leads',
                'password' => Str::password(32),
                'is_system' => true,
                'email_verified_at' => now(),
            ])->save();
        }

        return $user;
    }

    /**
     * Create a provisional website for a domain + subscribe it to the shared
     * crawl, and open an onboarding session. Returns [session, website].
     *
     * @return array{0: ContentOnboardingSession, 1: Website}
     */
    public function begin(string $domain, ?string $ip): array
    {
        // All provisional sites live under ONE system user, and websites carry a
        // UNIQUE (user_id, domain). So a repeat onboarding of the same domain must
        // REUSE the existing provisional site, not insert a duplicate. (Already-
        // converted sites belong to the real user now, so they never match here.)
        $website = Website::query()->firstOrCreate(
            ['user_id' => $this->systemUser()->id, 'domain' => $domain],
            [
                // Non-null string columns with no DB default (mirror WebsiteAttachService).
                'ga_property_id' => '',
                'ga_google_account_id' => null,
                'gsc_site_url' => '',
                'gsc_google_account_id' => null,
            ]
        );
        app(CrawlSiteBootstrapper::class)->subscribeWebsite($website);

        $session = ContentOnboardingSession::query()->create([
            'token' => (string) Str::ulid(),
            'website_id' => $website->id,
            'domain' => $website->domain,
            'ip' => $ip,
            'step' => 1,
        ]);

        return [$session, $website];
    }

    /**
     * Convert an onboarding session to a real user: re-parent the website (or
     * fold into a site the user already owns for that domain), persist the
     * business profile as a covered DRAFT plan, start the trial, and kick off
     * topic ideation + keyword research. Idempotent-ish: a converted session
     * is a no-op.
     */
    public function convert(ContentOnboardingSession $session, User $user, array $profile): Website
    {
        return DB::transaction(function () use ($session, $user, $profile): Website {
            $provisional = $session->website;

            // If the user already owns this domain, move the plan onto their
            // existing website and drop the provisional site (keeps crawl
            // subscriber bookkeeping correct); else re-parent the provisional.
            $existing = $user->websites()
                ->where('normalized_domain', $provisional?->normalized_domain)
                ->first();

            if ($existing !== null && $provisional !== null && $existing->id !== $provisional->id) {
                $website = $existing;
                $provisional->delete();
            } else {
                $provisional->forceFill(['user_id' => $user->id])->save();
                $website = $provisional;
            }

            // Persist profile as a DRAFT plan (covered by the trial below).
            $plan = ContentPlan::query()->firstOrNew(['website_id' => $website->id]);
            if (! $plan->exists) {
                $plan->status = ContentPlan::STATUS_DRAFT;
                $plan->articles_per_week = 7;
                $plan->article_length = 2000;
            }
            $plan->business_description = (string) ($profile['business_description'] ?? $plan->business_description);
            $plan->offerings = [
                'sell' => array_values(array_filter((array) ($profile['sell'] ?? []))),
                'dont_sell' => array_values(array_filter((array) ($profile['dont_sell'] ?? []))),
            ];
            $plan->save();

            // Trial + coverage, then background research so the calendar is
            // ready when the user lands in the dashboard.
            $this->entitlements->startTrial($user, $website);
            PlanContentTopicsJob::dispatch($plan->id);
            PrepareContentKeywordInsightsJob::dispatch($plan->id);

            $session->forceFill(['converted_user_id' => $user->id, 'converted_at' => now()])->save();

            return $website;
        });
    }
}
