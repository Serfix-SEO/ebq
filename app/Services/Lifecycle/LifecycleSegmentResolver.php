<?php

namespace App\Services\Lifecycle;

use App\Models\ContentArticle;
use App\Models\ContentIntegration;
use App\Models\ContentPlan;
use App\Models\User;
use App\Models\Website;
use App\Support\LifecycleEmailConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Sorts a customer into at most ONE lifecycle-email segment.
 *
 * Precedence is most-blocked-first: 2 (never added a website) → 3 (website
 * added, wizard/strategy never finished) → 4 (strategy live, no publish
 * connection) → 1 (articles flowing — just a feedback ask). A user matching
 * several rules gets the earliest one, so each daily run emails one journey.
 *
 * The predicates deliberately mirror what the product itself checks:
 * - "no website"      → !User::hasAccessibleWebsites()   (User.php)
 * - "wizard unfinished" → content_plans.status = draft   (only
 *   ContentCalendar::launch() and ContentOnboardingConverter flip it active)
 * - "not connected"   → no content_integrations row with status=connected
 *   (same predicate as ContentAutopilotDispatcher's publish pass and
 *   PublishContentArticleJob's skip)
 * - "articles flowing" → content_articles exist via topics→plans→websites
 */
class LifecycleSegmentResolver
{
    /** Most-blocked-first. */
    public const PRECEDENCE = [2, 3, 4, 1];

    public const SEGMENT_ARTICLES_FLOWING = 1;
    public const SEGMENT_NO_WEBSITE = 2;
    public const SEGMENT_STRATEGY_UNFINISHED = 3;
    public const SEGMENT_NOT_CONNECTED = 4;

    /**
     * Users lifecycle mail may consider at all. Excludes internal/system
     * accounts, unverified addresses (deliverability), opt-outs, and accounts
     * younger than the configured minimum age (so the first touch never lands
     * on top of the signup emails).
     *
     * @return Builder<User>
     */
    public function eligibleUsersQuery(): Builder
    {
        return User::query()
            ->where('is_system', false)
            ->where('is_admin', false)
            ->where('is_disabled', false)
            ->whereNotNull('email_verified_at')
            ->whereNull('marketing_emails_opted_out_at')
            // Faker demo rows live in prod (ebq:demo-data) with RFC 2606
            // reserved domains — undeliverable, and they'd pollute the send
            // log + waste cap slots forever.
            ->where('email', 'not like', '%@example.%')
            ->where('created_at', '<=', now()->subDays(LifecycleEmailConfig::minAccountAgeDays()));
    }

    /**
     * The user's current segment + the website the CTA should deep-link to.
     * Null = healthy in-funnel (or a pivot-only team member) — no email.
     *
     * @return array{segment: int, website: ?Website}|null
     */
    public function resolve(User $user): ?array
    {
        if (! $user->hasAccessibleWebsites()) {
            return ['segment' => self::SEGMENT_NO_WEBSITE, 'website' => null];
        }

        // Owned sites only: emailing a team member about "your website" would
        // be wrong, and shared-site state isn't theirs to act on.
        $owned = $user->websites()->get();
        if ($owned->isEmpty()) {
            return null;
        }

        $plans = ContentPlan::query()
            ->whereIn('website_id', $owned->pluck('id'))
            ->get()
            ->keyBy('website_id');

        // Segment 3: EVERY owned website is pre-strategy (no plan, or a draft
        // plan — the wizard was never finished anywhere).
        $allUnfinished = $owned->every(function (Website $site) use ($plans): bool {
            $plan = $plans->get($site->id);

            return $plan === null || $plan->status === ContentPlan::STATUS_DRAFT;
        });

        if ($allUnfinished) {
            return [
                'segment' => self::SEGMENT_STRATEGY_UNFINISHED,
                'website' => $this->preferredSite($owned, $plans),
            ];
        }

        // Segment 4: some site has a live (active) strategy but no verified
        // publish connection — finished articles would silently pile up.
        $connectedIds = ContentIntegration::query()
            ->whereIn('website_id', $owned->pluck('id'))
            ->where('status', ContentIntegration::STATUS_CONNECTED)
            ->pluck('website_id')
            ->all();

        $unconnected = $owned->filter(function (Website $site) use ($plans, $connectedIds): bool {
            $plan = $plans->get($site->id);

            return $plan !== null
                && $plan->status === ContentPlan::STATUS_ACTIVE
                && ! in_array($site->id, $connectedIds, true);
        });

        if ($unconnected->isNotEmpty()) {
            return [
                'segment' => self::SEGMENT_NOT_CONNECTED,
                'website' => $this->preferredSite($unconnected, $plans),
            ];
        }

        // Segment 1: content is actually being produced for an owned site.
        $hasArticles = ContentArticle::query()
            ->whereHas('topic.plan', fn (Builder $q) => $q->whereIn('website_id', $owned->pluck('id')))
            ->exists();

        if ($hasArticles) {
            return ['segment' => self::SEGMENT_ARTICLES_FLOWING, 'website' => null];
        }

        return null;
    }

    /**
     * Live eligible-user tallies per segment for the admin tiles. Chunked
     * resolve over the whole eligible set — fine at current scale, and cached
     * so the admin page never pays for it twice in ten minutes.
     *
     * @return array<int, int> segment => count
     */
    public function countsBySegment(): array
    {
        return Cache::remember('lifecycle:segment-counts', 600, function (): array {
            $counts = [1 => 0, 2 => 0, 3 => 0, 4 => 0];

            $this->eligibleUsersQuery()->chunkById(200, function (Collection $users) use (&$counts): void {
                foreach ($users as $user) {
                    $resolved = $this->resolve($user);
                    if ($resolved !== null) {
                        $counts[$resolved['segment']]++;
                    }
                }
            });

            return $counts;
        });
    }

    /**
     * CTA target among qualifying sites: covered (billing) first — mirrors
     * ContentEntitlements::preferredWebsite(), which exists because linking a
     * user to an uncovered site bounces them to Get started.
     *
     * @param  Collection<int, Website>  $sites
     * @param  \Illuminate\Support\Collection<string, ContentPlan>  $plans
     */
    private function preferredSite(Collection $sites, $plans): ?Website
    {
        $covered = $sites->first(function (Website $site) use ($plans): bool {
            return $plans->get($site->id)?->billing_covered_at !== null;
        });

        return $covered ?? $sites->first();
    }
}
