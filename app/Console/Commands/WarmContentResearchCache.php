<?php

namespace App\Console\Commands;

use App\Models\ContentPlan;
use App\Models\Website;
use App\Services\Content\ContentResearchAggregates;
use Illuminate\Console\Command;

/**
 * Pre-computes the research page's heavy 90-day GSC aggregation for every
 * content site, so no client request ever pays the 10-20s cold cost (prod
 * lag incident 2026-08-12: uncached striking-distance queries starved the
 * FPM pool). Runs daily after the GSC sync; the cache key is date-scoped so
 * each day's first warm builds the fresh entry.
 */
class WarmContentResearchCache extends Command
{
    protected $signature = 'ebq:warm-research-cache';

    protected $description = 'Warm the research-page striking-distance caches for all content sites';

    public function handle(ContentResearchAggregates $aggregates): int
    {
        $websiteIds = ContentPlan::query()->pluck('website_id')->unique();
        $warmed = 0;
        foreach (Website::query()->whereIn('id', $websiteIds)->get() as $website) {
            if (! $website->hasGsc()) {
                continue;
            }
            $t0 = microtime(true);
            $aggregates->strikingQueries($website);
            $this->line($website->normalized_domain.': '.round(microtime(true) - $t0, 1).'s');
            $warmed++;
        }
        $this->info("Warmed $warmed sites.");

        return self::SUCCESS;
    }
}
