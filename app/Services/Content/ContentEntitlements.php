<?php

namespace App\Services\Content;

use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Support\ContentAutopilotConfig;
use App\Support\TrialStatus;
use Illuminate\Support\Carbon;
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

    public function hasContentAccess(User $user): bool
    {
        return $this->hasContentSubscription($user) || $this->onContentTrial($user);
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

    /** Websites the user may run content on: trial = 1; sub = 1 + addon qty. */
    public function sitesAllowed(User $user): int
    {
        if ($this->hasContentSubscription($user)) {
            return 1 + $this->addonQuantity($user);
        }

        return $this->onContentTrial($user) ? 1 : 0;
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

    /** Mark a website as covered (create a stub plan if none exists yet). */
    public function coverWebsite(Website $website): void
    {
        ContentPlan::query()->updateOrCreate(
            ['website_id' => $website->id],
            ['billing_covered_at' => now()],
        );
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

        $reserved = ContentTopic::query()
            ->where('website_id', $websiteId)
            ->whereIn('status', ContentTopic::IN_FLIGHT)
            ->when($excludeTopicId, fn ($q) => $q->where('id', '!=', $excludeTopicId))
            ->whereDoesntHave('articles')
            ->count();

        return $done + $reserved;
    }

    /** Total generations for a user across all websites since trial start. */
    public function trialUsage(User $user, ?string $excludeTopicId = null): int
    {
        $since = $user->content_trial_started_at ?? now()->startOfCentury();
        $total = 0;
        foreach ($user->websites()->pluck('id') as $wid) {
            $total += $this->usageForWebsite($wid, $since, $excludeTopicId);
        }

        return $total;
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
        if (! $this->hasContentSubscription($user) && $this->onContentTrial($user)) {
            if ($this->trialUsage($user, $topic->id) >= ContentAutopilotConfig::trialArticles()) {
                return 'trial_limit';
            }
        }

        // Monthly per-website cap.
        $monthly = $this->usageForWebsite($website->id, now()->startOfMonth(), $topic->id);
        if ($monthly >= ContentAutopilotConfig::monthlyArticlesPerWebsite()) {
            return 'monthly_limit';
        }

        return null;
    }
}
