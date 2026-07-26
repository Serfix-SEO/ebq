<?php

namespace App\Console\Commands;

use App\Jobs\CheckTrackedKeywordSerpJob;
use App\Models\ContentTrackedKeyword;
use Illuminate\Console\Command;

/**
 * Weekly live-SERP refresh for every website that has tracked keywords. The job
 * itself only re-queries rows older than ContentTrackedKeyword::SERP_STALE_DAYS,
 * so this is safe to run more often than weekly if needed.
 */
class CheckTrackedSerp extends Command
{
    protected $signature = 'ebq:check-tracked-serp';

    protected $description = 'Refresh live Google SERP positions for tracked content keywords (weekly)';

    public function handle(): int
    {
        $websiteIds = ContentTrackedKeyword::query()
            ->distinct()
            ->pluck('website_id');

        foreach ($websiteIds as $id) {
            CheckTrackedKeywordSerpJob::dispatch((string) $id);
        }

        $this->info('SERP checks dispatched for '.$websiteIds->count().' website(s).');

        return self::SUCCESS;
    }
}
