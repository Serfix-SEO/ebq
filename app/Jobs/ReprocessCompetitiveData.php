<?php

namespace App\Jobs;

use App\Services\Competitive\CompetitiveReprocessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Upgrades a website's competitive data after Search Console connects. Unique +
 * debounced per website so connecting GSC and GA back-to-back reprocesses once.
 */
class ReprocessCompetitiveData implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    /** Debounce window — collapses near-simultaneous GSC+GA connects. */
    public int $uniqueFor = 120;

    public function __construct(public readonly string $websiteId)
    {
        $this->onQueue(\App\Support\Queues::SYNC);
    }

    public function uniqueId(): string
    {
        return 'competitive-reprocess:'.$this->websiteId;
    }

    public function handle(CompetitiveReprocessService $service): void
    {
        app(\App\Support\ShardContext::class)->forWebsite((string) $this->websiteId);
        $service->reprocess($this->websiteId);
    }
}
