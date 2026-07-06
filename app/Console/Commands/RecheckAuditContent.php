<?php

namespace App\Console\Commands;

use App\Models\CustomPageAudit;
use App\Models\PageAuditReport;
use App\Models\Website;
use App\Jobs\RunCustomPageAudit;
use App\Services\PageAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Content-hash re-audit gate (added 2026-07-06). A completed PageAuditReport
 * was reused indefinitely unless the WP-plugin-reported post `modified` time
 * was newer than `audited_at` — if WordPress doesn't bump `modified`
 * correctly (some page builders / ACF updates don't), stale CWV/perf data
 * persists forever (see infra/audits/page-audit.md + live-score-and-language.md
 * §Gotchas).
 *
 * This independently re-fetches a bounded batch of the oldest completed
 * audits, hashes the extracted body text, and compares against the hash
 * stored at audit time — queueing a fresh audit only when content actually
 * changed. Deliberately NOT run on every live-score request (that path is
 * "no extra fetch" by design); this is a scheduled, batched, best-effort
 * sweep instead.
 *
 * Usage:
 *   php artisan ebq:recheck-audit-content --limit=100 --older-than=1
 *   php artisan ebq:recheck-audit-content --dry-run
 */
class RecheckAuditContent extends Command
{
    protected $signature = 'ebq:recheck-audit-content
                            {--limit=100 : Max audits to check per run}
                            {--older-than=1 : Only check audits at least N days old}
                            {--dry-run : Report what would be queued without dispatching}';

    protected $description = 'Independently re-fetch stale-eligible audited pages and queue a re-audit if content actually changed.';

    public function handle(PageAuditService $service): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $cutoff = Carbon::now()->subDays(max(0, (int) $this->option('older-than')));
        $dryRun = (bool) $this->option('dry-run');

        $reports = PageAuditReport::query()
            ->where('status', 'completed')
            ->whereNotNull('content_hash')
            ->where('audited_at', '<=', $cutoff)
            ->orderBy('audited_at')
            ->limit($limit)
            ->get();

        $checked = 0;
        $changed = 0;
        $queued = 0;
        $skipped = 0;

        foreach ($reports as $report) {
            $checked++;
            $freshHash = $service->currentContentHash($report->page);
            if ($freshHash === null) {
                $skipped++; // fetch/SSRF-guard failure — not evidence of a change
                continue;
            }

            if ($freshHash === $report->content_hash) {
                continue; // unchanged
            }

            $changed++;
            if ($dryRun) {
                $this->line("Would re-audit (content changed): {$report->page}");
                continue;
            }

            $website = Website::find($report->website_id);
            $ownerUserId = $website?->user_id;
            if (! $website || ! $ownerUserId) {
                $skipped++;
                continue;
            }

            $audit = CustomPageAudit::queue(
                websiteId: $website->id,
                userId: $ownerUserId,
                pageUrl: $report->page,
                targetKeyword: $report->primary_keyword ?? '',
                serpSampleGl: null,
                source: CustomPageAudit::SOURCE_CUSTOM,
            );
            RunCustomPageAudit::dispatch($audit->id, $audit->website_id);
            $queued++;
        }

        $this->info("Checked {$checked}, changed {$changed}, queued {$queued}, skipped {$skipped}.");

        return self::SUCCESS;
    }
}
