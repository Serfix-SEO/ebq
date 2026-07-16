<?php

namespace App\Console\Commands;

use App\Models\DomainMetric;
use App\Services\OpenPageRankClient;
use App\Services\Reports\CcDomainRanks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Monthly free-feed refresh of the accumulating domain_metrics store —
 * decoupled from client lifecycle: churned clients' domains keep refreshing
 * here at zero cost, so their history never stops growing.
 *
 *  - Open PageRank bulk sweep (free tier: 30k requests/mo, 100 domains/call).
 *    Quota governor: staleness-first, importance-ordered (times_seen), capped
 *    (default 28k) to leave headroom for report generations.
 *  - CC sidecar re-read (local, unlimited) for every swept domain.
 *  - Appends opr history rows (source 'opr', one per domain per day, upsert-
 *    idempotent). CC history rows are written quarterly by
 *    `ebq:import-cc-webgraph --snapshot-history`, not here.
 */
class RefreshDomainMetrics extends Command
{
    protected $signature = 'ebq:refresh-domain-metrics
        {--limit=28000 : max domains to sweep through OPR this run}
        {--stale-days=25 : only refresh domains whose OPR data is older than this}';

    protected $description = 'Refresh domain_metrics from free feeds (Open PageRank + local CC sidecar) and append history';

    public function handle(OpenPageRankClient $opr, CcDomainRanks $cc): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $staleBefore = now()->subDays(max(1, (int) $this->option('stale-days')));

        $domains = DomainMetric::query()
            ->where(fn ($q) => $q->whereNull('opr_refreshed_at')->orWhere('opr_refreshed_at', '<', $staleBefore))
            ->orderByDesc('times_seen')
            ->orderBy('opr_refreshed_at')
            ->limit($limit)
            ->pluck('domain', 'id');

        if ($domains->isEmpty()) {
            $this->info('Nothing stale — done.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Refreshing %s domains (OPR bulk + CC sidecar)…', number_format($domains->count())));
        $today = now()->toDateString();
        $swept = 0;

        foreach ($domains->chunk(100) as $chunk) {
            $metrics = $opr->metricsFor($chunk->values()->all()) ?? [];
            $ccRanks = $cc->available() ? $cc->ranksFor($chunk->values()->all()) : [];

            foreach ($chunk as $id => $domain) {
                $m = $metrics[$domain] ?? ($metrics[OpenPageRankClient::registrable($domain)] ?? null);
                $update = ['opr_refreshed_at' => now()];
                if (is_array($m) && is_numeric($m['score'] ?? null)) {
                    $update['opr_score'] = (float) $m['score'];
                }
                if (isset($ccRanks[$domain])) {
                    $update['cc_harmonic_rank'] = $ccRanks[$domain]['harmonic'];
                    $update['cc_pagerank_rank'] = $ccRanks[$domain]['pagerank'];
                    $update['cc_refreshed_at'] = now();
                }
                DomainMetric::query()->whereKey($id)->update($update);

                if (isset($update['opr_score'])) {
                    DB::table('domain_metric_history')->updateOrInsert(
                        ['domain_metric_id' => $id, 'source' => 'opr', 'captured_on' => $today],
                        ['value' => $update['opr_score']],
                    );
                }
                $swept++;
            }
        }

        $this->info(number_format($swept).' domains refreshed.');

        return self::SUCCESS;
    }
}
