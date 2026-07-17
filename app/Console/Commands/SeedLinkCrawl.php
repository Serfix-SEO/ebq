<?php

namespace App\Console\Commands;

use App\Models\DomainMetric;
use App\Models\LinkCrawlFrontier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seed the Tier-1.5 link-crawler frontier from the domains we track. Picks
 * the most important domains (active-tier client targets first, then by how
 * often we've seen them referenced) that aren't already queued or recently
 * crawled, and inserts their homepage as a depth-0 frontier row. The crawler
 * discovers a few internal pages from there. Idempotent (insertOrIgnore on
 * url_hash) and re-runnable — scheduled daily; safe to run by hand.
 */
class SeedLinkCrawl extends Command
{
    protected $signature = 'ebq:seed-link-crawl
        {--limit= : max domains to queue this run (default config seed_domains_per_run)}
        {--force : ignore the enabled flag}';

    protected $description = 'Queue important tracked domains into the link-crawler frontier';

    public function handle(): int
    {
        if (! \App\Support\LinkCrawlToggle::enabled() && ! $this->option('force')) {
            $this->warn('Link crawl is disabled (LINK_CRAWL_ENABLED=false). Use --force to seed anyway.');

            return self::SUCCESS;
        }

        $limit = (int) ($this->option('limit') ?: config('crawler.link_crawl.seed_domains_per_run', 3000));

        // Hosts already sitting in the frontier (any status) — don't double-queue.
        $queuedHosts = LinkCrawlFrontier::query()->pluck('host')->flip();

        $seeded = 0;
        DomainMetric::query()
            ->select(['domain', 'tier', 'times_seen', 'cc_harmonic_rank'])
            // Active client targets first, then most-referenced, then closest
            // to the web core (harmonic rank asc = better).
            ->orderByRaw("tier = 'active' DESC")
            ->orderByDesc('times_seen')
            ->orderByRaw('cc_harmonic_rank IS NULL, cc_harmonic_rank ASC')
            ->limit($limit * 3) // over-scan; many will be skipped
            ->chunk(1000, function ($rows) use (&$seeded, $limit, $queuedHosts) {
                $seeded += $this->queueHosts($rows->pluck('domain')->all(), $limit - $seeded, $queuedHosts);

                return $seeded < $limit;
            });

        // Phase 2 — backfill from the DISCOVERED graph. Once every tracked
        // domain_metrics domain is already queued (the steady state), pull the
        // most-linked-to domains the crawler has DISCOVERED (link_domains, ranked
        // by in-degree = how many distinct sites point at them) that we haven't
        // crawled yet. This makes the daily seed self-feed from the link graph —
        // the frontier keeps growing toward the domains that matter most, without
        // any external source. Organic per-page expansion does this gradually;
        // this bulk-tops it up so the crawler never idles waiting for discovery.
        if ($seeded < $limit) {
            $need = $limit - $seeded;
            $names = DB::table('link_edges')
                ->select('to_domain_id', DB::raw('COUNT(*) as indeg'))
                ->groupBy('to_domain_id')
                ->orderByDesc('indeg')
                ->limit($need * 4) // over-scan; many already queued
                ->pluck('to_domain_id')
                ->chunk(1000)
                ->flatMap(fn ($ids) => DB::table('link_domains')->whereIn('id', $ids)->pluck('name'))
                ->all();
            $seeded += $this->queueHosts($names, $need, $queuedHosts);
        }

        $this->info("Seeded {$seeded} domains into the link-crawl frontier.");

        return self::SUCCESS;
    }

    /**
     * Insert up to $max of the given hosts as depth-0 `pending` homepages,
     * skipping any already in the frontier ($queuedHosts, mutated in place so
     * later phases/chunks don't re-queue). Returns the number actually inserted.
     *
     * @param  iterable<string>  $hosts
     * @param  \Illuminate\Support\Collection<string, mixed>  $queuedHosts
     */
    private function queueHosts(iterable $hosts, int $max, $queuedHosts): int
    {
        if ($max <= 0) {
            return 0;
        }
        $now = now();
        $insert = [];
        foreach ($hosts as $raw) {
            if (count($insert) >= $max) {
                break;
            }
            $host = strtolower(trim((string) $raw));
            if ($host === '' || $queuedHosts->has($host)) {
                continue;
            }
            $url = 'https://'.$host.'/';
            $insert[] = [
                'host' => $host,
                'url' => $url,
                'url_hash' => LinkCrawlFrontier::hashFor($url),
                'depth' => 0,
                'status' => 'pending',
                'attempts' => 0,
                'next_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $queuedHosts->put($host, true);
        }

        return $insert === [] ? 0 : DB::table('link_crawl_frontier')->insertOrIgnore($insert);
    }
}
