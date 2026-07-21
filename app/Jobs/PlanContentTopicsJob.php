<?php

namespace App\Jobs;

use App\Models\ContentPlan;
use App\Services\Content\ContentTopicPlanner;
use App\Services\Llm\LlmClientFactory;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Build/top-up a website's content calendar (ContentTopicPlanner).
 * Dispatched at plan creation and by the ebq:content-autopilot dispatcher
 * whenever a plan runs thin (< 7 future topics).
 *
 * tries=1: a retry would re-bill the ideation call; the dispatcher simply
 * tries again next tick if the calendar is still thin.
 */
class PlanContentTopicsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public string $planId, public int $count = 30)
    {
        $this->onQueue(Queues::CONTENT);
        $this->onConnection('redis-long');
    }

    public function uniqueId(): string
    {
        return 'plan-content-topics:'.$this->planId;
    }

    public function handle(): void
    {
        $plan = ContentPlan::query()->find($this->planId);
        // Runs for active AND draft plans — draft = wizard in progress, we
        // pre-generate the calendar so the "your first articles" step already
        // has real topics to show. Paused plans are skipped.
        if ($plan === null || ! in_array($plan->status, [ContentPlan::STATUS_ACTIVE, ContentPlan::STATUS_DRAFT], true)) {
            return;
        }

        // No business profile → do NOT ideate. Both of the planner's guardrails
        // are profile-derived: the ideation prompt interpolates description /
        // sell / don't-sell (so an empty profile leaves "They do NOT offer: "
        // forbidding nothing), and filterRelevant() vets against the same empty
        // strings and fails open. Planning here yields a full, plausible calendar
        // for a business the system knows nothing about — prod 2026-07-20 built
        // 31 such topics off GSC data alone and nobody noticed the profile was
        // missing. An empty calendar until onboarding completes is the honest
        // state; the dispatcher re-runs this every 15 min, so it self-heals the
        // moment the wizard is saved.
        if (blank($plan->business_description)) {
            Log::info('content_autopilot.topics_skipped_no_profile', [
                'plan_id' => $plan->id,
                'website_id' => $plan->website_id,
            ]);

            return;
        }

        $created = app(ContentTopicPlanner::class, [
            'llm' => LlmClientFactory::make(),
        ])->plan($plan, $this->count);

        Log::info('content_autopilot.topics_planned', [
            'plan_id' => $plan->id,
            'website_id' => $plan->website_id,
            'created' => count($created),
        ]);
    }
}
