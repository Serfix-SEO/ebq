<?php

namespace App\Console\Commands;

use App\Jobs\Content\AuditAeoReadinessJob;
use App\Models\ContentPlan;
use App\Models\Website;
use App\Services\Content\ContentEntitlements;
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
        $skipped = 0;
        $entitlements = app(ContentEntitlements::class);
        $paidByUser = [];   // one Stripe check per owner, not per website

        ContentPlan::query()
            ->whereNotNull('billing_covered_at')
            ->whereNotNull('website_id')
            ->select('website_id')
            ->distinct()
            ->chunkById(100, function ($plans) use (&$queued, &$skipped, &$paidByUser, $entitlements): void {
                $websites = Website::query()
                    ->whereIn('id', $plans->pluck('website_id'))
                    ->with('owner')
                    ->get();

                foreach ($websites as $website) {
                    $owner = $website->owner;
                    if ($owner === null) {
                        $skipped++;

                        continue;
                    }
                    // Paid only. A free signup sees the sample report, so
                    // checking their site would spend requests on a page they
                    // are not being shown.
                    $paid = $paidByUser[$owner->id] ??= $entitlements->hasPaidContentAccess($owner);
                    if (! $paid) {
                        $skipped++;

                        continue;
                    }
                    AuditAeoReadinessJob::dispatch((string) $website->id);
                    $queued++;
                }
            }, 'website_id');

        $this->info("Queued {$queued} AI-readiness checks ({$skipped} skipped — not on a paid plan).");

        return self::SUCCESS;
    }
}
