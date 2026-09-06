<?php

namespace App\Services\Content\Catalog;

use App\Jobs\PlanContentTopicsJob;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use Illuminate\Support\Facades\Log;

/**
 * The ONE entry point for every strict/normal decision — wizard launch,
 * calendar card, existing-client banner, settings switch, admin. Keeping it
 * single-path is what makes the dual-wizard-host landmine and the
 * "planner already ran at wizard step 2" wrinkle safe: choosing strict always
 * clears the unwritten future topics (whenever they were planned) and replans
 * from the catalog once scraping lands.
 */
class StrictModeActivator
{
    public function __construct(private readonly ProductCatalogService $catalog) {}

    /** @param 'strict'|'normal' $mode */
    public function choose(ContentPlan $plan, string $mode, string $trigger = 'settings'): void
    {
        $mode = $mode === ContentPlan::PRODUCT_MODE_STRICT
            ? ContentPlan::PRODUCT_MODE_STRICT
            : ContentPlan::PRODUCT_MODE_NORMAL;

        $was = $plan->product_mode;
        $plan->forceFill([
            'product_mode' => $mode,
            'product_mode_decided_at' => now(),
        ])->save();

        Log::info('content_autopilot.product_mode_chosen', [
            'plan_id' => $plan->id, 'mode' => $mode, 'was' => $was, 'trigger' => $trigger,
        ]);

        if ($mode === ContentPlan::PRODUCT_MODE_NORMAL) {
            // Planner is never gated for normal; if it was strict-gated
            // before, the next dispatcher tick resumes planning by itself.
            return;
        }

        // STRICT: unwritten future topics were planned without the catalog —
        // clear them so the replan is grounded. Written / in-production /
        // published topics are never touched.
        $cleared = $this->clearUnwrittenFutureTopics($plan);
        Log::info('content_autopilot.strict_topics_cleared', [
            'plan_id' => $plan->id, 'cleared' => $cleared,
        ]);

        if ($this->catalog->readyFor((string) $plan->website_id)) {
            // Catalog already scraped (re-enable, or admin pre-scan) —
            // replan immediately.
            PlanContentTopicsJob::dispatch($plan->id);
        } else {
            // Scrape first; FinalizeProductCatalogJob auto-dispatches the
            // planner when the catalog is ready (owner: auto-proceed).
            $this->catalog->startRun($plan, $trigger);
        }
    }

    /**
     * Shared with the admin "clear future topics" action — identical scope:
     * only unwritten, unstarted future topics.
     */
    public function clearUnwrittenFutureTopics(ContentPlan $plan): int
    {
        $scope = $plan->topics()
            ->whereIn('status', [ContentTopic::STATUS_SUGGESTED, ContentTopic::STATUS_APPROVED])
            ->whereDoesntHave('articles');
        $cleared = (clone $scope)->count();
        $scope->delete();

        return $cleared;
    }
}
