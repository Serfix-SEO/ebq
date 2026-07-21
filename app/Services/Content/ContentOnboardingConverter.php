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
    public function __construct(private readonly ContentEntitlements $entitlements) {}

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
    /**
     * @param  array{business_description?:?string, sell?:array, dont_sell?:array}  $profile
     *                                                                                        Wizard profile. May be EMPTY (e.g. Google SSO round-trip loses the
     *                                                                                        Livewire state) — the plan already carries the persisted profile
     *                                                                                        from the wizard, so empty input must NOT wipe it.
     * @return array{website: Website, covered: bool}
     *                                                covered=false → the site is attached but not on a plan yet (trial
     *                                                already used for another site, or subscription slots full) → the
     *                                                caller sends the user to Get started to pay for this (additional) site.
     */
    public function convert(ContentOnboardingSession $session, User $user, array $profile): array
    {
        return DB::transaction(function () use ($session, $user, $profile): array {
            $provisional = $session->website;
            $leadUser = $provisional?->user;
            /** @var array<string, mixed> $carried Wizard setup rescued off a provisional plan we are about to delete. */
            $carried = [];

            // Attach the onboarded site: fold into the user's existing site for
            // this domain if they already have one, else re-parent the provisional.
            $existing = $user->websites()
                ->where('normalized_domain', $provisional?->normalized_domain)
                ->first();

            if ($existing !== null && $provisional !== null && $existing->id !== $provisional->id) {
                $website = $existing;
                // The provisional plan is what the wizard has been writing to,
                // and content_plans.website_id is ON DELETE CASCADE — deleting
                // the provisional site destroys it. Carry its wizard-authored
                // setup over first, or a user who ALREADY owned this domain
                // silently ends up on a bare stub plan (prod 2026-07-20).
                $carried = $this->carryOverProfile($provisional);
                $provisional->delete();
            } else {
                $provisional->forceFill(['user_id' => $user->id])->save();
                $website = $provisional;
            }

            // Retire the throwaway lead user (system-owned, now owns no sites).
            if ($leadUser !== null && $leadUser->is_system
                && $leadUser->id !== $user->id && $leadUser->websites()->count() === 0) {
                $leadUser->delete();
            }

            // DRAFT plan carrying the profile. Only overwrite fields the caller
            // actually supplied — an empty profile keeps what the wizard persisted.
            $plan = ContentPlan::query()->firstOrNew(['website_id' => $website->id]);
            $planIsNew = ! $plan->exists;
            if ($planIsNew) {
                $plan->status = ContentPlan::STATUS_DRAFT;
                $plan->articles_per_week = 7;
                $plan->article_length = 2000;
            }
            // Setup rescued from the now-deleted provisional plan. Applied
            // BEFORE $profile so an explicitly supplied profile still wins.
            // A brand-new plan takes ALL of it — the stub's 7/2000 are defaults,
            // not choices, and must not beat the cadence picked in the wizard.
            // An EXISTING plan only gets its blanks filled: a site the user
            // configured earlier must not be clobbered by a later funnel run.
            foreach ($carried as $column => $value) {
                if ($planIsNew || blank($plan->{$column})) {
                    $plan->{$column} = $value;
                }
            }
            if (array_key_exists('business_description', $profile) && filled($profile['business_description'])) {
                $plan->business_description = (string) $profile['business_description'];
            }
            if (array_key_exists('sell', $profile) || array_key_exists('dont_sell', $profile)) {
                $plan->offerings = [
                    'sell' => array_values(array_filter((array) ($profile['sell'] ?? ($plan->offerings['sell'] ?? [])))),
                    'dont_sell' => array_values(array_filter((array) ($profile['dont_sell'] ?? ($plan->offerings['dont_sell'] ?? [])))),
                ];
            }
            $plan->save();

            // ── Coverage / billing decision ──────────────────────────────────
            // Trial allows exactly ONE site. Priority: already-covered → keep;
            // never-trialed & no sub → start the trial (covers this site);
            // active subscription with a free slot → cover it; otherwise leave
            // UNCOVERED — the user must pay (single site, or the per-extra-site
            // addon) from Get started.
            $covered = ContentPlan::query()->where('website_id', $website->id)
                ->whereNotNull('billing_covered_at')->exists();

            if (! $covered) {
                if ($user->content_trial_started_at === null && ! $this->entitlements->hasContentSubscription($user)) {
                    $this->entitlements->startTrial($user, $website);
                    $covered = true;
                } elseif ($this->entitlements->hasContentAccess($user)
                    && $this->entitlements->sitesCovered($user) < $this->entitlements->sitesAllowed($user)) {
                    $this->entitlements->coverWebsite($website);
                    $covered = true;
                }
            }

            // The public wizard IS the onboarding — a covered plan must go LIVE
            // (like the dashboard's launch()), or the dashboard would treat the
            // still-DRAFT plan as first-run and show the wizard again / the
            // calendar would say "no content plan yet". Uncovered plans stay
            // DRAFT until the user pays from Get started.
            if ($covered) {
                $plan->refresh()->update(['status' => ContentPlan::STATUS_ACTIVE]);
            }

            // Research runs regardless; article GENERATION is gated by coverage
            // (blockReason) so an uncovered site simply can't generate until paid.
            PlanContentTopicsJob::dispatch($plan->id);
            PrepareContentKeywordInsightsJob::dispatch($plan->id);

            $session->forceFill(['converted_user_id' => $user->id, 'converted_at' => now()])->save();

            return ['website' => $website, 'covered' => $covered];
        });
    }

    /**
     * Wizard-authored setup on a provisional plan that is about to be deleted
     * along with its website (FK is ON DELETE CASCADE).
     *
     * Only the columns the public wizard actually writes, and only when filled.
     * Cadence and structure travel with the profile: the user chose them in the
     * same wizard, so dropping them would leave the plan half-configured.
     *
     * @return array<string, mixed>
     */
    private function carryOverProfile(Website $provisional): array
    {
        $plan = ContentPlan::query()->where('website_id', $provisional->id)->first();
        if ($plan === null) {
            return [];
        }

        $columns = [
            'business_description', 'offerings', 'language', 'country',
            'articles_per_week', 'article_length', 'images_enabled', 'image_style',
            'image_style_prompt', 'toggles', 'competitor_overrides', 'internal_urls',
            'custom_instructions', 'cta_url',
        ];

        $carried = [];
        foreach ($columns as $column) {
            $value = $plan->{$column};
            if (! blank($value)) {
                $carried[$column] = $value;
            }
        }

        return $carried;
    }
}
