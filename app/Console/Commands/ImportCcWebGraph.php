<?php

namespace App\Console\Commands;

use App\Services\Reports\CcDomainRanks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Build/refresh the Common Crawl domain-rank SQLite sidecar consumed by
 * {@see CcDomainRanks}. Quarterly releases live at
 * https://data.commoncrawl.org/projects/hyperlinkgraph/ — pass the new
 * release name each quarter (they are announced on the CC blog).
 *
 * The heavy lifting (121M rows) is deliberately done by zcat|awk|sqlite3
 * (C speed, ~minutes) instead of PHP row loops (hours). The build happens in
 * a temp file and is atomically renamed over the live sidecar, so readers
 * never see a half-built database; a mid-build crash leaves the previous
 * sidecar untouched.
 *
 * Input line format (tab-separated, verified 2026-07-16):
 *   #harmonicc_pos  #harmonicc_val  #pr_pos  #pr_val  #host_rev  #n_hosts
 * host_rev is reversed notation (com.example) — flipped to example.com here.
 */
class ImportCcWebGraph extends Command
{
    protected $signature = 'ebq:import-cc-webgraph
        {--release=cc-main-2026-apr-may-jun : CC web-graph release name}
        {--file= : use an already-downloaded .txt.gz instead of downloading}
        {--top=0 : import only the top-N domains by harmonic rank (0 = all)}
        {--total=0 : override total graph size for percentiles (needed with --top)}
        {--keep-download : keep the downloaded .gz after a successful import}
        {--snapshot-history : after the rebuild, write cc rank history rows for every domain_metrics row}';

    protected $description = 'Download the Common Crawl domain web-graph ranks and rebuild the local SQLite lookup sidecar';

    public function handle(): int
    {
        $release = (string) $this->option('release');
        $top = max(0, (int) $this->option('top'));
        $gz = (string) ($this->option('file') ?: '');
        $downloaded = false;

        if ($gz === '') {
            $url = sprintf(
                'https://data.commoncrawl.org/projects/hyperlinkgraph/%s/domain/%s-domain-ranks.txt.gz',
                $release,
                $release,
            );
            $gz = storage_path("app/{$release}-domain-ranks.txt.gz");
            $this->info("Downloading {$url}");
            $result = Process::timeout(3600 * 3)->run(['curl', '-fSL', '--retry', '3', '-o', $gz, $url]);
            if (! $result->successful()) {
                $this->error('Download failed: '.trim($result->errorOutput()));

                return self::FAILURE;
            }
            $downloaded = true;
        }
        if (! is_file($gz)) {
            $this->error("Input file not found: {$gz}");

            return self::FAILURE;
        }
        $this->info(sprintf('Input: %s (%.1f GB)', $gz, filesize($gz) / 1e9));

        $final = CcDomainRanks::path();
        $tmp = $final.'.tmp';
        @unlink($tmp);

        // zcat → awk (skip header, reverse host notation, cap via --top,
        // emit "domain \t harmonic \t pagerank") → SORT BY DOMAIN → sqlite3
        // .import. The sort is load-bearing: the table is WITHOUT ROWID with
        // domain as PRIMARY KEY, so sorted input means sequential b-tree
        // appends. Unsorted input (file arrives ordered by harmonic rank)
        // degrades to random b-tree inserts that thrash the page cache once
        // the DB outgrows RAM (~10× slower at 121M rows; observed live
        // 2026-07-16). Whole-line C-locale sort IS domain order: the tab
        // separator byte (0x09) sorts below every legal domain character, and
        // LC_ALL=C matches SQLite's BINARY collation.
        $limit = $top > 0 ? "NR-1 > {$top} { exit }" : '';
        $awk = <<<AWK
        BEGIN { FS = OFS = "\\t" }
        /^#/ { next }
        {$limit}
        {
            n = split(\$5, p, ".");
            d = p[n];
            for (i = n - 1; i >= 1; i--) d = d "." p[i];
            print d, \$1, \$3;
        }
        AWK;

        $shell = sprintf(
            'set -o pipefail; zcat %s | awk %s | LC_ALL=C sort -S 1500M -T %s | sqlite3 %s '.
            '".mode tabs" '.
            '"PRAGMA journal_mode=OFF" "PRAGMA synchronous=OFF" "PRAGMA cache_size=-200000" '.
            '"CREATE TABLE ranks(domain TEXT PRIMARY KEY, harmonic INTEGER NOT NULL, pagerank INTEGER NOT NULL) WITHOUT ROWID" '.
            '".import /dev/stdin ranks"',
            escapeshellarg($gz),
            escapeshellarg($awk),
            escapeshellarg(dirname($tmp)), // sort temp on the same volume
            escapeshellarg($tmp),
        );

        $this->info('Building SQLite sidecar (zcat | awk | sqlite3)…');
        $result = Process::timeout(3600 * 6)->run(['bash', '-c', $shell]);
        if (! $result->successful()) {
            @unlink($tmp);
            $this->error('Build failed: '.trim(substr($result->errorOutput(), 0, 2000)));

            return self::FAILURE;
        }

        $count = (int) trim(Process::run(['sqlite3', $tmp, 'SELECT COUNT(*) FROM ranks'])->output());
        if ($count < 1000) {
            @unlink($tmp);
            $this->error("Build produced only {$count} rows — refusing to replace the sidecar.");

            return self::FAILURE;
        }

        // Percentile denominator: the FULL graph size. With --top the imported
        // row count understates it, so require/derive --total.
        $total = (int) $this->option('total');
        if ($total <= 0) {
            $total = $top > 0 ? 121_000_000 : $count;
            if ($top > 0) {
                $this->warn("--top used without --total; assuming graph size {$total}.");
            }
        }

        Process::run(['sqlite3', $tmp,
            'CREATE TABLE meta(key TEXT PRIMARY KEY, value TEXT);'
            ."INSERT INTO meta VALUES ('total_domains','{$total}'),('release','".str_replace("'", '', $release)."'),('imported_at','".now()->toIso8601String()."');",
        ]);

        if (! rename($tmp, $final)) {
            @unlink($tmp);
            $this->error("Could not move {$tmp} into place.");

            return self::FAILURE;
        }

        if ($downloaded && ! $this->option('keep-download')) {
            @unlink($gz);
        }

        if ($this->option('snapshot-history')) {
            $this->snapshotHistory();
        }

        $this->info(sprintf(
            'Done: %s domains (release %s, %.1f GB) → %s',
            number_format($count),
            $release,
            filesize($final) / 1e9,
            $final,
        ));

        return self::SUCCESS;
    }

    /**
     * Quarterly history snapshot: update cc ranks on every domain_metrics row
     * from the freshly-imported sidecar and append one history row per rank
     * source (upsert-idempotent per day — safe to re-run).
     */
    private function snapshotHistory(): void
    {
        $cc = new CcDomainRanks;
        if (! $cc->available()) {
            $this->warn('Sidecar unavailable — skipping history snapshot.');

            return;
        }

        $today = now()->toDateString();
        $written = 0;

        \App\Models\DomainMetric::query()->select(['id', 'domain'])->chunkById(500, function ($chunk) use ($cc, $today, &$written) {
            $ranks = $cc->ranksFor($chunk->pluck('domain')->all());
            foreach ($chunk as $metric) {
                $r = $ranks[$metric->domain] ?? null;
                if ($r === null) {
                    continue;
                }
                $metric->update([
                    'cc_harmonic_rank' => $r['harmonic'],
                    'cc_pagerank_rank' => $r['pagerank'],
                    'cc_refreshed_at' => now(),
                ]);
                foreach (['cc_harmonic' => $r['harmonic'], 'cc_pagerank' => $r['pagerank']] as $source => $value) {
                    \Illuminate\Support\Facades\DB::table('domain_metric_history')->updateOrInsert(
                        ['domain_metric_id' => $metric->id, 'source' => $source, 'captured_on' => $today],
                        ['value' => $value],
                    );
                }
                $written++;
            }
        });

        $this->info(number_format($written).' domains snapshotted into history.');
    }
}
