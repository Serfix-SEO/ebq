<?php

namespace App\Jobs\Content;

use App\Models\Website;
use App\Services\Content\Aeo\AeoReadinessService;
use App\Support\Queues;
use App\Support\ShardContext;
use App\Support\ShardLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Weekly AI-readiness check for one website: two small HTTP fetches
 * (robots.txt, llms.txt) plus a read of our own crawl. No vendor, no LLM.
 *
 * ShouldBeUnique so the weekly sweep and any on-demand "check now" collapse
 * into one run per site.
 */
class AuditAeoReadinessJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public string $websiteId)
    {
        $this->onQueue(Queues::SYNC);
    }

    public function uniqueId(): string
    {
        return $this->websiteId;
    }

    public function handle(AeoReadinessService $readiness): void
    {
        if (ShardLock::websiteLocked($this->websiteId)) {
            $this->release(30);

            return;
        }
        app(ShardContext::class)->forWebsite($this->websiteId);

        $website = Website::find($this->websiteId);
        if ($website === null) {
            return;
        }

        $audit = $readiness->audit($website);
        if ($audit === null) {
            return;
        }

        // Blocked retrieval agents are the finding clients act on, so make the
        // count greppable in the logs rather than only visible in the UI.
        Log::info('aeo.readiness_checked', [
            'website_id' => $website->id,
            'score' => $audit->readiness_score,
            'robots_fetched' => $audit->robots_fetched,
            'blocked' => $audit->blockedAgents(),
            'llms_txt' => $audit->llms_txt_present,
        ]);
    }
}
