<?php

namespace App\Console\Commands;

use App\Models\CrawlFinding;
use App\Services\Crawler\LinkChecker;
use App\Support\Crawler\LinkStatus;
use Illuminate\Console\Command;

/**
 * Re-verify OPEN `broken_external` findings with the current (2026-08-16)
 * rules and resolve the ones that were never broken.
 *
 * Why this exists: the old checker trusted a HEAD-only 404 and treated every
 * 4xx as dead, so clients were shown live pages as broken links —
 * 136 support.google.com URLs alone. Fixing the checker stops NEW false
 * findings; already-stored ones sit in their Site Health until something
 * re-runs them, and a full recrawl is far too expensive for that.
 *
 * Read-mostly and idempotent: it only ever flips a finding to `resolved`
 * (never creates or deletes), so a re-run is safe.
 *
 *   php artisan ebq:recheck-broken-links --dry-run
 *   php artisan ebq:recheck-broken-links
 */
class RecheckBrokenExternalLinks extends Command
{
    protected $signature = 'ebq:recheck-broken-links
        {--dry-run : Report what would change without writing}
        {--limit=1000 : Max findings to re-check in one pass}';

    protected $description = 'Re-verify open broken-external-link findings and resolve false positives';

    public function handle(LinkChecker $checker): int
    {
        $dry = (bool) $this->option('dry-run');
        $findings = CrawlFinding::query()
            ->where('type', 'broken_external')
            ->where('status', 'open')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($findings->isEmpty()) {
            $this->info('No open broken_external findings.');

            return self::SUCCESS;
        }

        // One check per DISTINCT url — the same dead link is usually stored for
        // several websites, and re-fetching it once per finding would multiply
        // the outbound traffic for no extra information.
        $byUrl = $findings->groupBy('affected_url');
        $this->info($findings->count().' open findings across '.$byUrl->count().' distinct URLs.');

        $verdicts = [];
        $bar = $this->output->createProgressBar($byUrl->count());
        foreach ($byUrl->keys()->chunk(20) as $chunk) {
            $links = $chunk->map(fn ($url) => ['href' => (string) $url, 'anchor' => ''])->values()->all();
            $problems = collect($checker->check($links, count($links)))->keyBy('href');

            foreach ($chunk as $url) {
                $p = $problems->get($url);
                // Absent from the problem list = healthy. Present = keep only if
                // the CURRENT rules still call it dead (redirects are a separate
                // finding type and must not keep a broken-link finding alive).
                $verdicts[$url] = $p !== null
                    && (LinkStatus::isDead($p['status'] ?? null) || ! empty($p['guard_blocked']));
                $bar->advance();
            }
        }
        $bar->finish();
        $this->newLine(2);

        $resolve = $findings->filter(fn (CrawlFinding $f) => ($verdicts[$f->affected_url] ?? true) === false);
        $keep = $findings->count() - $resolve->count();

        $this->table(
            ['verdict', 'findings', 'urls'],
            [
                ['still broken', $keep, collect($verdicts)->filter()->count()],
                ['false positive', $resolve->count(), collect($verdicts)->filter(fn ($v) => ! $v)->count()],
            ]
        );

        foreach ($resolve->groupBy('affected_url')->keys()->take(15) as $url) {
            $this->line('  cleared: '.mb_substr((string) $url, 0, 110));
        }

        if ($dry) {
            $this->comment('Dry run — nothing written.');

            return self::SUCCESS;
        }

        CrawlFinding::whereIn('id', $resolve->pluck('id'))->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'updated_at' => now(),
        ]);

        // Health cards + the action queue are cached per website data-version;
        // without a bump the client keeps seeing the resolved issues all day.
        foreach ($resolve->pluck('website_id')->filter()->unique() as $websiteId) {
            \App\Services\ReportCache::flushWebsite((string) $websiteId);
        }

        $this->info('Resolved '.$resolve->count().' false-positive findings.');

        return self::SUCCESS;
    }
}
