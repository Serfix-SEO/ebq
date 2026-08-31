<?php

namespace App\Console\Commands;

use App\Jobs\PlanContentTopicsJob;
use App\Jobs\ProduceContentArticleJob;
use App\Jobs\PublishContentArticleJob;
use App\Models\ContentIntegration;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Services\Content\ContentEntitlements;
use App\Support\ContentAutopilotConfig;
use App\Services\Content\ContentKeywordInsights;
use App\Services\Content\ContentLlmSpendMeter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The Content Autopilot heartbeat (scheduled every 15 min):
 *
 *  1. REAP    — in-flight topics stuck >45 min (lost worker, crashed job)
 *               are failed so the calendar never wedges silently.
 *  2. TOP-UP  — active plans running thin (<7 future topics) get a
 *               PlanContentTopicsJob (unique-per-plan, cheap no-op if racing).
 *  3. CLAIM   — approved topics due within the write-ahead window (48h before
 *               their publish date) are dispatched to ProduceContentArticleJob,
 *               one per website per tick, bounded per tick, and gated by the
 *               ContentLlmSpendMeter (exhausted => stop claiming; dates shift,
 *               clients just see "Scheduled" — admin-only knowledge).
 *  4. PUBLISH — (Phase 3) topics ready to go live are dispatched to
 *               PublishContentArticleJob:
 *                 - SCHEDULED (client-approved) topics whose date arrived, and
 *                 - READY topics on auto_publish plans whose review window
 *                   (plan.review_hours, anchored on stage_started_at) elapsed
 *                   with no client veto — promoted to SCHEDULED here.
 *               Both honor the plan's publish window: allowed weekday
 *               (publish_days) + hour band (publish_hour_start..end) in the
 *               plan's timezone. Only fires for websites with at least one
 *               CONNECTED integration — nothing connected means articles wait
 *               in SCHEDULED and flush automatically after connect.
 */
class ContentAutopilotDispatcher extends Command
{
    protected $signature = 'ebq:content-autopilot
        {--claim-limit=5 : Max article productions dispatched per tick}';

    protected $description = 'Reap stuck topics, top up thin calendars, dispatch due article productions.';

    private const STUCK_AFTER_MINUTES = 45;

    private const WRITE_AHEAD_HOURS = 48;

    /** Anything due within this window MUST be generated — the normal fairness
     *  throttle is bypassed so an imminent slot is never missed. */
    private const CATCH_UP_HOURS = 24;

    /**
     * Max from-scratch write attempts before a repeatedly-FAILED topic stops
     * being auto-regenerated (2026-08-29 runaway: 64 writes on one topic).
     */
    private const MAX_REGENERATION_WRITES = 4;

    /** Keep the calendar filled this many topics ahead (matches the monthly cap). */
    private const THIN_CALENDAR_TOPICS = 30;

    /** Topic ids already dispatched this tick, so the catch-up pass never double-fires. */
    private array $claimedTopicIds = [];

    public function handle(ContentLlmSpendMeter $meter): int
    {
        $this->claimedTopicIds = [];
        $reaped = $this->reapStuck();
        $topped = $this->topUpThinCalendars();
        $researched = $this->advanceKeywordResearch();
        $claimed = $meter->exhausted() ? 0 : $this->claimDueTopics((int) $this->option('claim-limit'));
        // Safety net: guarantee anything due within 24h is generated even if the
        // per-tick claim limit / one-per-site throttle skipped it above.
        $rushed = $meter->exhausted() ? 0 : $this->catchUpImminent();
        $published = $this->claimPublishable();

        if ($meter->exhausted()) {
            Log::warning('content_autopilot.llm_cap_exhausted', ['spent' => $meter->spent(), 'cap' => $meter->cap()]);
        }

        $this->info("reaped={$reaped} topup_plans={$topped} kw_research={$researched} claimed={$claimed} rushed={$rushed} published={$published}");

        return self::SUCCESS;
    }

    /**
     * Advance DB-backed competitor-keyword research for active, not-yet-classified
     * plans: kick the free keyword-server ranking harvest (own + top-3 competitors
     * → domain_keyword_rankings) and, once all competitors have landed, classify
     * the gap. Both idempotent + monthly-guarded, so this is a cheap no-op most
     * ticks. Replaces the disabled DataForSEO ebq:content-keyword-harvest trigger.
     */
    private function advanceKeywordResearch(): int
    {
        $insights = app(ContentKeywordInsights::class);
        $n = 0;
        ContentPlan::query()
            ->where('status', ContentPlan::STATUS_ACTIVE)
            ->whereNull('keywords_classified_at')
            ->get()
            ->each(function (ContentPlan $plan) use ($insights, &$n): void {
                try {
                    $insights->ensureCompetitorResearch($plan);
                    $n++;
                } catch (\Throwable $e) {
                    Log::warning('content_autopilot.kw_research_error', [
                        'plan_id' => $plan->id, 'error' => mb_substr($e->getMessage(), 0, 200),
                    ]);
                }
            });

        // Already-classified plans: monthly library growth (Research page feed).
        // Guards inside make this a cheap no-op except once a month per plan.
        ContentPlan::query()
            ->where('status', ContentPlan::STATUS_ACTIVE)
            ->whereNotNull('keywords_classified_at')
            ->get()
            ->each(function (ContentPlan $plan) use ($insights): void {
                try {
                    $insights->ensureMonthlyRefresh($plan);
                    $insights->ensureEnrichment($plan);
                } catch (\Throwable $e) {
                    Log::warning('content_autopilot.kw_refresh_error', [
                        'plan_id' => $plan->id, 'error' => mb_substr($e->getMessage(), 0, 200),
                    ]);
                }
            });

        return $n;
    }

    private function reapStuck(): int
    {
        $stuck = ContentTopic::query()
            ->whereIn('status', ContentTopic::IN_FLIGHT)
            ->where('stage_started_at', '<', now()->subMinutes(self::STUCK_AFTER_MINUTES))
            ->get();

        foreach ($stuck as $topic) {
            $topic->fail('reaped: stuck in '.$topic->status.' since '.$topic->stage_started_at?->toDateTimeString());
            Log::warning('content_autopilot.reaped', ['topic_id' => $topic->id, 'stage' => $topic->status]);
        }

        return $stuck->count();
    }

    private function topUpThinCalendars(): int
    {
        $dispatched = 0;

        ContentPlan::query()
            ->where('status', ContentPlan::STATUS_ACTIVE)
            // The pool = every UNPUBLISHED, non-skipped topic (planned + in-flight
            // + ready). Keep exactly THIN_CALENDAR_TOPICS of these: publishing one
            // drops the pool and the planner adds just the shortfall back.
            ->withCount(['topics as future_topics_count' => function ($q) {
                $q->whereNotIn('status', [ContentTopic::STATUS_PUBLISHED, ContentTopic::STATUS_SKIPPED]);
            }])
            ->get()
            ->filter(fn (ContentPlan $plan) => $plan->future_topics_count < self::THIN_CALENDAR_TOPICS)
            ->each(function (ContentPlan $plan) use (&$dispatched): void {
                PlanContentTopicsJob::dispatch($plan->id);
                $dispatched++;
            });

        return $dispatched;
    }

    private function claimDueTopics(int $limit): int
    {
        // Websites with a topic already in flight are skipped this tick —
        // one production per site at a time keeps spend and load smooth.
        $busyWebsites = ContentTopic::query()
            ->whereIn('status', ContentTopic::IN_FLIGHT)
            ->pluck('website_id')->unique()->all();

        // DeepSeek off-peak steering (owner 2026-08-22): peak hours (01–04 +
        // 06–10 UTC, per their pricing page) bill 2×. During peak, only claim
        // topics genuinely due soon (CATCH_UP_HOURS) — the bulk 24-48h
        // write-ahead cohort waits at most one peak block (≤4h) for half-price
        // generation. Off-peak claims the full write-ahead window as before.
        // Generating EARLIER also means review_hours elapse before the publish
        // slot, so auto-publish timing only improves. "Write now" bypasses the
        // dispatcher entirely and is never delayed.
        $aheadHours = (ContentAutopilotConfig::offPeakDispatchEnabled()
            && ! ContentAutopilotConfig::isDeepSeekOffPeak())
            ? self::CATCH_UP_HOURS
            : self::WRITE_AHEAD_HOURS;

        $due = ContentTopic::query()
            ->where('status', ContentTopic::STATUS_APPROVED)
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now()->addHours($aheadHours)->toDateString())
            ->whereNotIn('website_id', $busyWebsites)
            ->whereHas('plan', fn ($q) => $q->where('status', ContentPlan::STATUS_ACTIVE))
            ->orderBy('scheduled_for')
            ->limit(max(1, $limit) * 3) // headroom for the one-per-site filter
            ->get()
            ->unique('website_id')
            ->take(max(1, $limit));

        // Skip topics whose website can't generate right now (no content
        // access/coverage, trial or monthly cap) so blocked sites aren't
        // re-claimed and re-dispatched every tick. The job re-checks anyway.
        $entitlements = app(ContentEntitlements::class);
        $count = 0;
        foreach ($due as $topic) {
            if ($entitlements->blockReason($topic) !== null) {
                continue;
            }
            ProduceContentArticleJob::dispatch($topic->id);
            $this->claimedTopicIds[] = $topic->id;
            $count++;
        }

        return $count;
    }

    /**
     * Guarantee generation for any topic due within CATCH_UP_HOURS that still has
     * no article — bypassing the one-per-site + claim-limit fairness throttle that
     * claimDueTopics() applies, so an imminent publish slot is never missed. Only
     * hard blocks (no coverage / trial / monthly cap) still stop it. Imminent
     * SUGGESTED topics are auto-approved first so they enter the pipeline.
     */
    private function catchUpImminent(): int
    {
        $cutoff = now()->addHours(self::CATCH_UP_HOURS)->toDateString();

        ContentTopic::query()
            ->where('status', ContentTopic::STATUS_SUGGESTED)
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', $cutoff)
            ->whereHas('plan', fn ($q) => $q->where('status', ContentPlan::STATUS_ACTIVE))
            ->update(['status' => ContentTopic::STATUS_APPROVED, 'stage_started_at' => now()]);

        $imminent = ContentTopic::query()
            ->whereIn('status', [ContentTopic::STATUS_APPROVED, ContentTopic::STATUS_FAILED])
            // brand_safety failures are NOT "regenerate me": the pipeline
            // already proved it can't write this topic without the blocked
            // term (or refused to publish a dirty article). Regenerating just
            // re-fails and re-bills every tick — a human has to decide
            // (unblock the word / edit / skip). Cocomii 2026-08-20.
            ->where(fn ($q) => $q->whereNull('last_error')
                ->orWhere('last_error', 'not like', 'brand_safety%'))
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', $cutoff)
            ->whereNotIn('id', $this->claimedTopicIds ?: ['-'])
            ->whereHas('plan', fn ($q) => $q->where('status', ContentPlan::STATUS_ACTIVE))
            ->orderBy('scheduled_for')
            ->get();

        $entitlements = app(ContentEntitlements::class);
        $count = 0;
        foreach ($imminent as $topic) {
            if ($entitlements->blockReason($topic) !== null) {
                continue;
            }
            // Regeneration cap (prod 2026-08-29, namesforfreefire.com): a
            // FAILED dated topic that keeps failing for any non-brand reason
            // (there: below_publish_floor, an Arabic article stuck at ~50)
            // was regenerated EVERY tick — 64 full writes + 191 revises in
            // 16 hours, all billed, until the version tinyint overflowed at
            // 255. After a handful of from-scratch attempts the pipeline has
            // proven it can't write this topic; park it in "needs attention"
            // like brand_safety instead of burning money forever.
            if ($topic->status === ContentTopic::STATUS_FAILED
                && $this->writeAttempts($topic) >= self::MAX_REGENERATION_WRITES) {
                continue;
            }
            ProduceContentArticleJob::dispatch($topic->id);
            $this->claimedTopicIds[] = $topic->id;
            $count++;
        }

        return $count;
    }

    /** Full from-scratch write attempts already spent on a topic. */
    private function writeAttempts(ContentTopic $topic): int
    {
        return \App\Models\ContentArticle::query()
            ->where('topic_id', $topic->id)
            ->where('generation_meta->stage', 'write')
            ->count();
    }

    /** Phase 3: dispatch publish jobs for topics whose moment has come. */
    private function claimPublishable(): int
    {
        $dispatched = 0;

        $plans = ContentPlan::query()
            ->where('status', ContentPlan::STATUS_ACTIVE)
            ->whereHas('website.contentIntegrations', fn ($q) => $q->where('status', ContentIntegration::STATUS_CONNECTED))
            ->get();

        foreach ($plans as $plan) {
            $now = now($plan->timezone ?: 'UTC');
            $today = $now->toDateString();
            $inWindow = $this->withinPublishWindow($plan);
            // Today's publish window has already ENDED (we're past the hour band
            // on an allowed publish day). A dated article that missed its window —
            // because it finished/was approved late, or no one approved it — must
            // NOT wait another full day: publish it now (2026-07-24 rule).
            $windowPassed = ! $inWindow
                && $this->isPublishDay($plan, $now)
                && $now->hour > (int) ($plan->publish_hour_end ?? 23);

            if ($plan->auto_publish) {
                // Normal in-window promotion: READY topics whose review-veto
                // window elapsed → SCHEDULED (published during the chosen hours).
                if ($inWindow) {
                    $plan->topics()
                        ->where('status', ContentTopic::STATUS_READY)
                        ->where('stage_started_at', '<=', now()->subHours(max(0, (int) $plan->review_hours)))
                        ->get()
                        ->each(fn (ContentTopic $t) => $t->enterStage(ContentTopic::STATUS_SCHEDULED));
                }
                // Force rule: once the window has PASSED, promote any READY topic
                // DUE today-or-earlier even if the review-veto window hasn't
                // elapsed and no one approved — a missed window must never strand
                // a dated article.
                if ($windowPassed) {
                    $plan->topics()
                        ->where('status', ContentTopic::STATUS_READY)
                        ->whereNotNull('scheduled_for')
                        ->whereDate('scheduled_for', '<=', $today)
                        ->get()
                        ->each(fn (ContentTopic $t) => $t->enterStage(ContentTopic::STATUS_SCHEDULED));
                }
            }

            // Publish scheduled topics. In-window OR after today's window passed:
            // anything due today or earlier. BEFORE today's window (or a non-
            // publish day): only cross-day OVERDUE items, so we never pre-empt the
            // client's chosen hours — the picked date is a floor, not a hard gate.
            $q = $plan->topics()->where('status', ContentTopic::STATUS_SCHEDULED);
            if ($inWindow || $windowPassed) {
                $q->where(fn ($x) => $x->whereNull('scheduled_for')->orWhereDate('scheduled_for', '<=', $today));
            } else {
                $q->whereNotNull('scheduled_for')->whereDate('scheduled_for', '<', $today);
            }

            // One per plan per tick — steady drip, matches the 1/day cadence.
            $topic = $q->orderBy('scheduled_for')->first();
            if ($topic !== null) {
                PublishContentArticleJob::dispatch($topic->id);
                $dispatched++;
            }
        }

        return $dispatched;
    }

    /** Whether today (plan tz) is an allowed publish weekday (empty = every day). */
    private function isPublishDay(ContentPlan $plan, Carbon $now): bool
    {
        $days = array_map('intval', (array) ($plan->publish_days ?? []));

        return $days === [] || in_array($now->isoWeekday(), $days, true);
    }

    /** Allowed weekday + hour band in the plan's timezone. */
    private function withinPublishWindow(ContentPlan $plan): bool
    {
        $now = now($plan->timezone ?: 'UTC');

        if (! $this->isPublishDay($plan, $now)) {
            return false;
        }

        $start = (int) ($plan->publish_hour_start ?? 0);
        $end = (int) ($plan->publish_hour_end ?? 23);
        if ($start === $end) {
            return $now->hour === $start;
        }

        // Wrapping bands (22..2) supported.
        return $start < $end
            ? ($now->hour >= $start && $now->hour <= $end)
            : ($now->hour >= $start || $now->hour <= $end);
    }
}
