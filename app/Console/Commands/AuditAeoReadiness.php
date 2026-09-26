<?php

namespace App\Console\Commands;

use App\Jobs\Content\AuditAeoReadinessJob;
use App\Models\ContentPlan;
use App\Models\Website;
use Illuminate\Console\Command;

/**
 * Weekly AI-readiness sweep: one job per content-covered website.
 *
 * Scoped to plans with billing_covered_at (the same filter
 * SyncContentPerformance uses) — an uncovered site has no AI Visibility page
 * to read the result, so checking it would spend requests on nobody's behalf.
 */
class AuditAeoReadiness extends Command
{
    protected $signature = 'ebq:aeo-audit {--website= : Run for one website id only}';

    protected $description = 'Check which AI crawlers each content website allows, and whether it publishes an llms.txt';

    public function handle(): int
    {
        $one = (string) ($this->option('website') ?? '');
        if ($one !== '') {
            if (Website::find($one) === null) {
                $this->error("No website {$one}.");

                return self::FAILURE;
            }
            AuditAeoReadinessJob::dispatch($one);
            $this->info("Queued AI-readiness check for {$one}.");

            return self::SUCCESS;
        }

        $queued = 0;
        ContentPlan::query()
            ->whereNotNull('billing_covered_at')
            ->whereNotNull('website_id')
            ->select('website_id')
            ->distinct()
            ->chunkById(100, function ($plans) use (&$queued): void {
                foreach ($plans as $plan) {
                    AuditAeoReadinessJob::dispatch((string) $plan->website_id);
                    $queued++;
                }
            }, 'website_id');

        $this->info("Queued {$queued} AI-readiness checks.");

        return self::SUCCESS;
    }
}
