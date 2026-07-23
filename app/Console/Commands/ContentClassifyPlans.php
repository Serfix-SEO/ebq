<?php

namespace App\Console\Commands;

use App\Models\ContentPlan;
use App\Services\Content\SiteProfileExtractor;
use Illuminate\Console\Command;

/**
 * Backfill `content_plans.site_type` for plans onboarded before site types
 * existed. One flash LLM call per plan (stored profile text only — no page
 * fetches). Idempotent: already-classified plans and profile-less stubs are
 * skipped, and a user-chosen type is never overwritten (source stays 'user').
 *
 * Run with --dry-run first; --limit caps LLM spend per invocation.
 */
class ContentClassifyPlans extends Command
{
    protected $signature = 'ebq:content-classify-plans
        {--dry-run : List what would be classified without calling the LLM or writing}
        {--limit=50 : Maximum plans to classify in this run}';

    protected $description = 'Backfill site_type/audience on content plans that predate site-type classification';

    public function handle(SiteProfileExtractor $extractor): int
    {
        $candidates = ContentPlan::query()
            ->whereNull('site_type')
            ->whereNotNull('business_description')
            ->where('business_description', '!=', '')
            ->orderBy('created_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('Nothing to classify — all profiled plans already have a site_type.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['Plan', 'Website', 'Description (head)'],
                $candidates->map(fn ($p) => [
                    $p->id,
                    $p->website?->domain ?? $p->website_id,
                    mb_substr((string) $p->business_description, 0, 60),
                ])->all()
            );
            $this->info("Dry run: {$candidates->count()} plan(s) would be classified.");

            return self::SUCCESS;
        }

        $done = 0;
        $failed = 0;
        foreach ($candidates as $plan) {
            $result = $extractor->classifyStoredProfile($plan);
            if ($result === null) {
                $failed++;
                $this->warn("  {$plan->id} ({$plan->website?->domain}): classification failed — left null");

                continue;
            }

            $plan->update([
                'site_type' => $result['site_type'],
                'site_type_source' => 'auto',
                // Never clobber an audience the client typed themselves.
                'audience' => $plan->audience ?: $result['audience'],
                'ymyl' => $plan->ymyl ?? ($result['ymyl'] ?? null),
            ]);
            $done++;
            $this->line("  {$plan->id} ({$plan->website?->domain}): {$result['site_type']}");
        }

        $this->info("Classified {$done}, failed {$failed}. Failures stay null (type-blind pipeline) and retry on the next run.");

        return self::SUCCESS;
    }
}
