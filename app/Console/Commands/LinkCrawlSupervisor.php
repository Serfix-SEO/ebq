<?php

namespace App\Console\Commands;

use App\Jobs\LinkCrawlPassJob;
use App\Models\LinkCrawlFrontier;
use App\Services\LinkGraph\LinkCrawlBudget;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps the Tier-1.5 link crawler alive. The pass chain self-perpetuates
 * while there's work + budget, so this supervisor only needs to (re)start it
 * when the chain has stopped (crashed, or ran dry and new work was seeded).
 * Scheduled every few minutes withoutOverlapping. Cheap no-op when disabled,
 * out of budget, idle, or already running (heartbeat present).
 */
class LinkCrawlSupervisor extends Command
{
    protected $signature = 'ebq:link-crawl-supervisor';

    protected $description = 'Start the link-crawl pass chain when there is due work and no chain is running';

    public function handle(LinkCrawlBudget $budget): int
    {
        if (! \App\Support\LinkCrawlToggle::enabled()) {
            return self::SUCCESS;
        }
        if (Cache::has(LinkCrawlPassJob::HEARTBEAT_KEY)) {
            return self::SUCCESS; // a chain is alive
        }
        if ($budget->exhausted()) {
            return self::SUCCESS;
        }

        $hasWork = LinkCrawlFrontier::query()
            ->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('next_at')->orWhere('next_at', '<=', now()))
            ->exists();
        if (! $hasWork) {
            return self::SUCCESS;
        }

        Cache::put(LinkCrawlPassJob::HEARTBEAT_KEY, now()->timestamp, now()->addMinutes(15));
        LinkCrawlPassJob::dispatch();
        $this->info('Link-crawl pass chain started.');

        return self::SUCCESS;
    }
}
