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

    /**
     * A FRESH throwaway system user that owns ONE provisional onboarding site.
     * Each anonymous session gets its own — so two visitors onboarding the same
     * domain get isolated website/plan rows (websites carry a UNIQUE
     * (user_id, domain); a shared owner would collide AND let one visitor's
     * convert re-parent another's site). Never billed/cleaned; GC removes it with
     * its site, and convert() deletes it once the real site is re-parented.
     */
    public function newLeadUser(): User
    {
        $user = new User;
        // is_system is intentionally NOT mass-assignable — set it explicitly.
        $user->forceFill([
            'name' => 'Content Lead',
            'email' => 'lead+'.Str::ulid()->toBase32().'@leads.serfix.internal',
            'password' => Str::password(32),
            'is_system' => true,
            'email_verified_at' => now(),
        ])->save();

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
        // Each session owns its site via a fresh throwaway user → same domain can
        // be onboarded by many visitors independently (shared crawl still dedupes
        // by normalized_domain, so only the Website rows differ, not the crawl).
        $website = Website::query()->create([
            'user_id' => $this->newLeadUser()->id,
            'domain' => $domain,
            // Non-null string columns with no DB default (mirror WebsiteAttachService).
            'ga_property_id' => '',
            'ga_google_account_id' => null,
            'gsc_site_url' => '',
            'gsc_google_account_id' => null,
        ]);
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
            // The throwaway per-session owner; deleted once the site moves off it.
            $leadUser = $provisional?->user;

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

            // Retire the throwaway lead user now that its site belongs to a real
            // account (never touch a non-system user or one that still owns sites).
            if ($leadUser !== null && $leadUser->is_system
                && $leadUser->id !== $user->id && $leadUser->websites()->count() === 0) {
                $leadUser->delete();
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
