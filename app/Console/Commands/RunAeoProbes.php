<?php

namespace App\Console\Commands;

use App\Jobs\Content\RunAeoProbesJob;
use App\Models\ContentPlan;
use App\Models\Website;
use App\Services\Content\ContentEntitlements;
use App\Support\ContentAutopilotConfig;
use Illuminate\Console\Command;

/**
 * Weekly AI-visibility probing, one job per paying website.
 *
 * Paid-only for the same reason the readiness audit is: free signups see the
 * sample report, so probing their site would spend money on a page they are
 * not being shown.
 */
class RunAeoProbes extends Command
{
    protected $signature = 'ebq:aeo-probe {--website= : Run for one website id only}';

    protected $description = 'Ask the models this week\'s buyer questions and score each site\'s AI visibility';

    public function handle(): int
    {
        if (! ContentAutopilotConfig::aeoProbesEnabled()) {
            $this->info('AI visibility probes are switched off.');

            return self::SUCCESS;
        }

        $one = (string) ($this->option('website') ?? '');
        if ($one !== '') {
            if (Website::find($one) === null) {
                $this->error("No website {$one}.");

                return self::FAILURE;
            }
            RunAeoProbesJob::dispatch($one);
            $this->info("Queued AI visibility probes for {$one}.");

            return self::SUCCESS;
        }

        $entitlements = app(ContentEntitlements::class);
        $paidByUser = [];
        $queued = 0;
        $skipped = 0;

        ContentPlan::query()
            ->whereNotNull('billing_covered_at')
            ->whereNotNull('website_id')
            ->select('website_id')
            ->distinct()
            ->chunkById(100, function ($plans) use (&$queued, &$skipped, &$paidByUser, $entitlements): void {
                foreach (Website::query()->whereIn('id', $plans->pluck('website_id'))->with('owner')->get() as $website) {
                    $owner = $website->owner;
                    $paid = $owner !== null && ($paidByUser[$owner->id] ??= $entitlements->hasPaidContentAccess($owner));
                    if (! $paid) {
                        $skipped++;

                        continue;
                    }
                    RunAeoProbesJob::dispatch((string) $website->id);
                    $queued++;
                }
            }, 'website_id');

        $this->info("Queued {$queued} AI visibility runs ({$skipped} skipped — not on a paid plan).");

        return self::SUCCESS;
    }
}
