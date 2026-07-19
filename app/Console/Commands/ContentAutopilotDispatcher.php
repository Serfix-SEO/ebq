<?php

namespace App\Console\Commands;

use App\Jobs\PlanContentTopicsJob;
use App\Jobs\ProduceContentArticleJob;
use App\Jobs\PublishContentArticleJob;
use App\Models\ContentIntegration;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Services\Content\ContentLlmSpendMeter;
use Illuminate\Console\Command;
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

    private const THIN_CALENDAR_TOPICS = 7;

    public function handle(ContentLlmSpendMeter $meter): int
    {
        $reaped = $this->reapStuck();
        $topped = $this->topUpThinCalendars();
        $claimed = $meter->exhausted() ? 0 : $this->claimDueTopics((int) $this->option('claim-limit'));
        $published = $this->claimPublishable();

        if ($meter->exhausted()) {
            Log::warning('content_autopilot.llm_cap_exhausted', ['spent' => $meter->spent(), 'cap' => $meter->cap()]);
        }

        $this->info("reaped={$reaped} topup_plans={$topped} claimed={$claimed} published={$published}");

        return self::SUCCESS;
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
            ->withCount(['topics as future_topics_count' => function ($q) {
                $q->whereIn('status', [ContentTopic::STATUS_SUGGESTED, ContentTopic::STATUS_APPROVED])
                    ->where('scheduled_for', '>=', now()->toDateString());
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

        $due = ContentTopic::query()
            ->where('status', ContentTopic::STATUS_APPROVED)
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now()->addHours(self::WRITE_AHEAD_HOURS)->toDateString())
            ->whereNotIn('website_id', $busyWebsites)
            ->whereHas('plan', fn ($q) => $q->where('status', ContentPlan::STATUS_ACTIVE))
            ->orderBy('scheduled_for')
            ->limit(max(1, $limit) * 3) // headroom for the one-per-site filter
            ->get()
            ->unique('website_id')
            ->take(max(1, $limit));

        foreach ($due as $topic) {
            ProduceContentArticleJob::dispatch($topic->id);
        }

        return $due->count();
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
            $inWindow = $this->withinPublishWindow($plan);
            $today = now($plan->timezone ?: 'UTC')->toDateString();

            // Auto-publish: promote READY topics whose veto window elapsed (only
            // in-window, so they publish during the client's chosen hours).
            if ($inWindow && $plan->auto_publish) {
                $plan->topics()
                    ->where('status', ContentTopic::STATUS_READY)
                    ->where('stage_started_at', '<=', now()->subHours(max(0, (int) $plan->review_hours)))
                    ->get()
                    ->each(fn (ContentTopic $t) => $t->enterStage(ContentTopic::STATUS_SCHEDULED));
            }

            // In-window: publish anything due today or earlier. Outside the
            // window: still flush OVERDUE items (scheduled date already passed)
            // so a missed window never leaves an article stuck forever — the
            // date the client picked is honoured as a floor, not a hard gate.
            $q = $plan->topics()->where('status', ContentTopic::STATUS_SCHEDULED);
            if ($inWindow) {
                $q->where(fn ($x) => $x->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', $today));
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

    /** Allowed weekday + hour band in the plan's timezone. */
    private function withinPublishWindow(ContentPlan $plan): bool
    {
        $now = now($plan->timezone ?: 'UTC');

        $days = array_map('intval', (array) ($plan->publish_days ?? []));
        if ($days !== [] && ! in_array($now->isoWeekday(), $days, true)) {
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
