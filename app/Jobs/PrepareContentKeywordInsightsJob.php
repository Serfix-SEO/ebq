<?php

namespace App\Jobs;

use App\Models\ContentPlan;
use App\Services\Content\ContentKeywordInsights;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Kick off the wizard's background keyword research (step 5's data) right
 * after the plan is drafted at step 2 — the self-hosted keyword server takes
 * minutes per job, so it works while the user reads steps 3-4.
 *
 * Queued (not inline) because the dispatch POST to the keyword server can
 * block up to 15s. Idempotent via ContentKeywordInsights::ensureStarted();
 * tries=1 — a lost run just means the step serves its fallback payload.
 */
class PrepareContentKeywordInsightsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public string $planId)
    {
        $this->onQueue(Queues::CONTENT);
    }

    public function handle(ContentKeywordInsights $insights): void
    {
        $plan = ContentPlan::query()->find($this->planId);
        if ($plan === null) {
            return;
        }
        $insights->ensureStarted($plan);
    }
}
