<?php

namespace App\Services\Content;

use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Support\AdsConversion;
use App\Support\ContentAutopilotConfig;
use App\Support\TrialStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for Content Autopilot ENTITLEMENT (access, trial,
 * per-website coverage) and USAGE (article generation counting / caps).
 * Deliberately mirrors the role TrialStatus plays for the dashboard trial.
 *
 * Access = an active Cashier `content` subscription OR a live app-managed
 * 5-day trial. A subscription covers 1 website + N addon websites; which of a
 * user's websites consume those slots is tracked explicitly by
 * content_plans.billing_covered_at.
 *
 * A "generation" is the FIRST version of a topic's article — revisions create
 * higher content_articles.version rows on the same topic and never count.
 *
 * Stateless: each method recomputes from the DB / loaded relations (no internal
 * memo) so a mid-request state change — startTrial, checkout success, coverage
 * edit — is reflected immediately by later calls in the same request.
 */
class ContentEntitlements
{
    public const SUBSCRIPTION = 'content';

    // ── Access ──────────────────────────────────────────────────────────

    public function hasContentSubscription(User $user): bool
    {
        try {
            return $user->subscribed(self::SUBSCRIPTION);
        } catch (\Throwable) {
            return false; // Stripe not configured / offline — fail closed
        }
    }

    public function onContentTrial(User $user): bool
    {
        return $user->content_trial_ends_at !== null
            && $user->content_trial_ends_at->isFuture();
    }

    /**
     * Admin-comped free content website slots currently in effect. Zero once the
     * optional `content_comp_until` expiry has passed (non-destructive: existing
     * covered sites keep working — only NEW coverage is gated). Null expiry =
     * permanent grant.
     */
    public function compSites(User $user): int
    {
        $sites = (int) ($user->content_comp_sites ?? 0);
        if ($sites <= 0) {
            return 0;
        }
        $until = $user->content_comp_until;
        if ($until !== null && $until->isPast()) {
            return 0;
        }

        return $sites;
    }

    public function hasContentAccess(User $user): bool
    {
        return $this->hasContentSubscription($user)
            || $this->onContentTrial($user)
            || $this->compSites($user) > 0;
    }

    /** Access AND this specific website occupies a covered slot. */
    public function hasContentAccessFor(User $user, Website $website): bool
    {
        if (! $this->hasContentAccess($user)) {
            return false;
        }

        return ContentPlan::query()
            ->where('website_id', $website->id)
            ->whereNotNull('billing_covered_at')
            ->exists();
    }

    // ── Coverage / slots ────────────────────────────────────────────────

    /**
     * Websites the user may run content on. Admin-comped free slots are ADDITIVE
     * to any real allowance (sub = 1 + addon qty; trial = 1; otherwise 0), so an
     * operator can top up a paying client or grant a free-only client N slots.
     */
    public function sitesAllowed(User $user): int
    {
        $base = $this->hasContentSubscription($user)
            ? 1 + $this->addonQuantity($user)
            : ($this->onContentTrial($user) ? 1 : 0);

        return $base + $this->compSites($user);
    }

    /** Quantity of the addon price line on the content subscription. */
    public function addonQuantity(User $user): int
    {
        $sub = $user->subscription(self::SUBSCRIPTION);
        if ($sub === null) {
            return 0;
        }
        $addonIds = array_filter([
            ContentAutopilotConfig::addonPriceId('monthly'),
            ContentAutopilotConfig::addonPriceId('annual'),
        ]);
        if ($addonIds === []) {
            return 0;
        }

        return (int) $sub->items
            ->whereIn('stripe_price', $addonIds)
            ->sum('quantity');
    }

    public function sitesCovered(User $user): int
    {
        return ContentPlan::query()
            ->whereIn('website_id', $user->websites()->select('id'))
            ->whereNotNull('billing_covered_at')
            ->count();
    }

    /**
     * The websites those covered slots are actually spent on — same rule as
     * sitesCovered(), so a billing screen can never show a count and a list
     * that disagree.
     *
     * @return Collection<int, Website>
     */
    public function coveredWebsites(User $user): Collection
    {
        $ids = ContentPlan::query()
            ->whereIn('website_id', $user->websites()->select('id'))
            ->whereNotNull('billing_covered_at')
            ->pluck('website_id');

        return $ids->isEmpty()
            ? collect()
            : $user->websites()->whereIn('id', $ids)->orderBy('domain')->get();
    }

    /**
     * The website Content Autopilot should act on when the session pins none.
     *
     * Prefers a COVERED site over "whichever row is first". Picking the first
     * accessible website put users into a redirect loop (prod 2026-07-21): the
     * account's oldest site was uncovered, so EnsureContentAccess bounced every
     * request — including Livewire's POST /livewire/update — to Get started,
     * which sends you back to the wizard, which re-fires wire:init, which
     * bounces again. The page appeared to "keep refreshing on step 1".
     */
    public function preferredWebsite(User $user): ?Website
    {
        $covered = $user->accessibleWebsitesQuery()
            ->whereIn('id', ContentPlan::query()
                ->whereNotNull('billing_covered_at')
                ->select('website_id'))
            ->first();

        return $covered ?? $user->accessibleWebsitesQuery()->first();
    }

    /**
     * Mark a website as covered. A brand-new stub plan is created as DRAFT so
     * the onboarding wizard shows (an existing plan keeps its status).
     */
    public function coverWebsite(Website $website): void
    {
        $plan = ContentPlan::query()->firstOrNew(['website_id' => $website->id]);
        if (! $plan->exists) {
            // Fresh stub: DRAFT so the wizard shows, carrying the baked cadence
            // defaults (1 article/day, ~2000 words) so a just-activated site
            // matches a fresh wizard plan.
            $plan->status = ContentPlan::STATUS_DRAFT;
            $plan->articles_per_week = 7;
            $plan->article_length = 2000;
        }
        $plan->billing_covered_at = now();
        $plan->save();
    }

    public function uncoverWebsite(Website $website): void
    {
        ContentPlan::query()->where('website_id', $website->id)
            ->update(['billing_covered_at' => null]);
    }

    /** Clamp covered websites down to what the plan allows (newest uncovered first). */
    public function reconcileCoverage(User $user): void
    {
        $allowed = $this->sitesAllowed($user);
        $covered = ContentPlan::query()
            ->whereIn('website_id', $user->websites()->select('id'))
            ->whereNotNull('billing_covered_at')
            ->orderByDesc('billing_covered_at')
            ->get();

        foreach ($covered->slice($allowed) as $plan) {
            $plan->update(['billing_covered_at' => null]);
        }
    }

    // ── Trial ───────────────────────────────────────────────────────────

    /** Start the one-and-only content trial for a user + cover the website. */
    public function startTrial(User $user, Website $website): void
    {
        if ($user->content_trial_started_at === null) {
            $user->forceFill([
                'content_trial_started_at' => now(),
                'content_trial_ends_at' => now()->addDays(ContentAutopilotConfig::trialDays()),
            ])->save();

            // Report the trial to Google Ads from HERE, inside the guard: this
            // is the single moment a trial comes into existence, and the guard
            // is what makes it once-per-user forever. Both entry points (the
            // in-app Get started and the public onboarding converter) get it
            // without either having to remember.
            AdsConversion::queue(
                AdsConversion::TRIAL,
                AdsConversion::trialValueUsd(),
                'USD',
                'trial-'.$user->id,
            );
        }
        $this->coverWebsite($website);
    }

    // ── Usage / caps ────────────────────────────────────────────────────

    /**
     * Generations counted against a website in a window: version-1 articles
     * created since $since, plus currently in-flight topics with no article
     * yet (reservation), excluding $excludeTopicId (the one being checked).
     */
    public function usageForWebsite(string $websiteId, Carbon $since, ?string $excludeTopicId = null): int
    {
        $done = DB::table('content_articles')
            ->join('content_topics', 'content_topics.id', '=', 'content_articles.topic_id')
            ->where('content_topics.website_id', $websiteId)
            ->where('content_articles.version', 1)
            ->where('content_articles.created_at', '>=', $since)
            ->when($excludeTopicId, fn ($q) => $q->where('content_topics.id', '!=', $excludeTopicId))
            ->distinct()
            ->count('content_topics.id');

        // Reserved = actively generating (IN_FLIGHT) OR just kicked off but the
        // job hasn't flipped it to RESEARCHING yet. writeNow() sets an APPROVED
        // topic's stage_started_at=now() right before dispatch, so counting
        // recently-started APPROVED topics closes the rapid-click race where a
        // user fires several "Write now" before any becomes IN_FLIGHT and blows
        // past the trial/monthly cap.
        $reserved = ContentTopic::query()
            ->where('website_id', $websiteId)
            ->whereDoesntHave('articles')
            ->when($excludeTopicId, fn ($q) => $q->where('id', '!=', $excludeTopicId))
            ->where(function ($q) {
                $q->whereIn('status', ContentTopic::IN_FLIGHT)
                    ->orWhere(fn ($q2) => $q2
                        ->where('status', ContentTopic::STATUS_APPROVED)
                        ->whereNotNull('stage_started_at')
                        ->where('stage_started_at', '>=', now()->subMinutes(60)));
            })
            ->count();

        return $done + $reserved;
    }

    /**
     * In-flight reservations for a website (topics generating right now, no
     * article yet) — the live half of usage counting; the durable half is the
     * ledger. Mirrors the reservation logic inside usageForWebsite().
     */
    private function reservedForWebsite(string $websiteId, ?string $excludeTopicId = null): int
    {
        return ContentTopic::query()
            ->where('website_id', $websiteId)
            ->whereDoesntHave('articles')
            ->when($excludeTopicId, fn ($q) => $q->where('id', '!=', $excludeTopicId))
            ->where(function ($q) {
                $q->whereIn('status', ContentTopic::IN_FLIGHT)
                    ->orWhere(fn ($q2) => $q2
                        ->where('status', ContentTopic::STATUS_APPROVED)
                        ->whereNotNull('stage_started_at')
                        ->where('stage_started_at', '>=', now()->subMinutes(60)));
            })
            ->count();
    }

    private function reservedForUser(User $user, ?string $excludeTopicId = null): int
    {
        $total = 0;
        foreach ($user->websites()->pluck('id') as $wid) {
            $total += $this->reservedForWebsite($wid, $excludeTopicId);
        }

        return $total;
    }

    /**
     * Durable generation count for a user since $since — from the ledger
     * (content_generations), which has no FK to websites and therefore
     * SURVIVES website deletion. The old implementation counted rows that
     * cascade-deleted with the website, so delete-site + re-add reset both
     * the trial and monthly caps (loophole found 2026-08-21).
     */
    public function ledgerCount(User $user, Carbon $since): int
    {
        return \App\Models\ContentGeneration::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $since)
            ->count();
    }

    /** Total generations for a user since trial start: ledger + in-flight. */
    public function trialUsage(User $user, ?string $excludeTopicId = null): int
    {
        $since = $user->content_trial_started_at ?? now()->startOfCentury();

        return $this->ledgerCount($user, $since) + $this->reservedForUser($user, $excludeTopicId);
    }

    /**
     * Why a topic cannot be generated right now, or null if it can.
     * Shared by the job, the dispatcher, and the UI pre-checks.
     *
     * @return null|'no_access'|'not_covered'|'trial_limit'|'monthly_limit'
     */
    public function blockReason(ContentTopic $topic): ?string
    {
        $website = $topic->website;
        $user = $website?->user;
        if ($website === null || $user === null) {
            return 'no_access';
        }
        if (! $this->hasContentAccess($user)) {
            return 'no_access';
        }
        if (! $this->hasContentAccessFor($user, $website)) {
            return 'not_covered';
        }

        // On trial (and not a paying subscriber): 3 generations across all sites.
        // An admin comp grant lifts this — a comped client should generate like a
        // paid one (capped only by the monthly per-website limit below), not be
        // stopped by the 3-article trial cap (prod 2026-07-24, daomarketing.com).
        if (! $this->hasContentSubscription($user) && $this->onContentTrial($user) && $this->compSites($user) === 0) {
            if ($this->trialUsage($user, $topic->id) >= ContentAutopilotConfig::trialArticles()) {
                return 'trial_limit';
            }
        }

        // Monthly per-website cap.
        $monthly = $this->usageForWebsite($website->id, now()->startOfMonth(), $topic->id);
        if ($monthly >= ContentAutopilotConfig::monthlyArticlesPerWebsite()) {
            return 'monthly_limit';
        }

        // USER-level monthly cap: allowed sites × per-website cap, counted from
        // the durable ledger — the per-website check alone resets when a site
        // is deleted and re-added (its rows cascade away with the website), so
        // a $1 subscriber could farm 30 articles per site-cycle indefinitely
        // (2026-08-21). The ledger survives deletion; reservations are live.
        $userMonthly = $this->ledgerCount($user, now()->startOfMonth())
            + $this->reservedForUser($user, $topic->id);
        $userCap = ContentAutopilotConfig::monthlyArticlesPerWebsite() * max(1, $this->sitesAllowed($user));
        if ($userMonthly >= $userCap) {
            return 'monthly_limit';
        }

        return null;
    }
}
