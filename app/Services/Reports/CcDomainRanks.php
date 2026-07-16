<?php

namespace App\Services\Reports;

use App\Services\OpenPageRankClient;

/**
 * Local lookup over the Common Crawl domain-level web graph ranks
 * (~121M domains, quarterly releases). Two ranks per domain:
 *
 *  - pagerank  → citation/popularity (PageRank family, like Majestic CF)
 *  - harmonic  → trust/quality (harmonic centrality — graph-distance based,
 *                hard to game; spam farms rank poorly — TrustRank-adjacent)
 *
 * Data lives in a read-only SQLite sidecar (storage/app/cc-domain-ranks.sqlite)
 * built by `ebq:import-cc-webgraph` — deliberately NOT MariaDB: ~121M rows
 * would blow the 2G InnoDB buffer pool that currently fits the whole working
 * set. Point PK lookups against the sidecar are sub-millisecond.
 *
 * Absent sidecar / unknown domain → null; AuthorityScoreCalculator weights
 * renormalize, so this whole layer degrades to a no-op gracefully.
 */
class CcDomainRanks
{
    private ?\PDO $pdo = null;

    private bool $opened = false;

    private ?float $logTotal = null;

    /** @param  string|null  $path  override for tests; defaults to the live sidecar */
    public function __construct(private ?string $path = null)
    {
    }

    public static function path(): string
    {
        return storage_path('app/cc-domain-ranks.sqlite');
    }

    public function available(): bool
    {
        return $this->pdo() !== null;
    }

    /**
     * Percentile scores (0–100, higher = better) for one domain, or null when
     * the sidecar is missing or the domain isn't in the graph. Tries the exact
     * host, then its registrable domain (CC ranks at registrable level).
     *
     * @return array{citation_pct: float, trust_pct: float}|null
     */
    public function scoreFor(string $domain): ?array
    {
        $result = $this->lookupMany([$domain]);

        return $result[strtolower(trim($domain))] ?? null;
    }

    /**
     * Batch lookup. Returns only domains that resolved (exact or registrable).
     *
     * @param  list<string>  $domains
     * @return array<string, array{citation_pct: float, trust_pct: float}>
     */
    public function lookupMany(array $domains): array
    {
        return array_map(
            fn (array $r) => [
                'citation_pct' => $this->percentile((int) $r['pagerank']),
                'trust_pct' => $this->percentile((int) $r['harmonic']),
            ],
            $this->ranksFor($domains),
        );
    }

    /**
     * Raw graph ranks (1 = best). Same exact/registrable resolution as
     * lookupMany(); only resolved domains are returned.
     *
     * @param  list<string>  $domains
     * @return array<string, array{harmonic: int, pagerank: int}>
     */
    public function ranksFor(array $domains): array
    {
        $pdo = $this->pdo();
        if ($pdo === null || $domains === []) {
            return [];
        }

        // domain-as-asked => candidate keys to try, in order.
        $wanted = [];
        foreach ($domains as $domain) {
            $host = strtolower(trim((string) $domain));
            if ($host === '') {
                continue;
            }
            $candidates = [$host];
            $registrable = OpenPageRankClient::registrable($host);
            if ($registrable !== $host) {
                $candidates[] = $registrable;
            }
            $wanted[$host] = $candidates;
        }
        if ($wanted === []) {
            return [];
        }

        $keys = array_values(array_unique(array_merge(...array_values($wanted))));
        $rows = [];
        foreach (array_chunk($keys, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare("SELECT domain, harmonic, pagerank FROM ranks WHERE domain IN ($placeholders)");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $rows[$row['domain']] = $row;
            }
        }

        $out = [];
        foreach ($wanted as $host => $candidates) {
            foreach ($candidates as $key) {
                if (isset($rows[$key])) {
                    $out[$host] = [
                        'harmonic' => (int) $rows[$key]['harmonic'],
                        'pagerank' => (int) $rows[$key]['pagerank'],
                    ];
                    break;
                }
            }
        }

        return $out;
    }

    /** Log-scaled percentile: rank 1 → 100, rank N (worst) → 0. */
    private function percentile(int $rank): float
    {
        $logTotal = $this->logTotal ?? log10(121_000_000);
        if ($rank < 1) {
            $rank = 1;
        }

        return round(max(0.0, min(100.0, 100.0 * (1 - log10($rank) / $logTotal))), 1);
    }

    private function pdo(): ?\PDO
    {
        if ($this->opened) {
            return $this->pdo;
        }
        $this->opened = true;

        $path = $this->path ?? self::path();
        if (! is_file($path)) {
            return null;
        }

        try {
            $pdo = new \PDO('sqlite:'.$path, options: [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 2,
            ]);
            $pdo->exec('PRAGMA query_only = 1');
            $total = $pdo->query("SELECT value FROM meta WHERE key = 'total_domains'")->fetchColumn();
            if (is_numeric($total) && (int) $total > 1) {
                $this->logTotal = log10((int) $total);
            }
            $this->pdo = $pdo;
        } catch (\Throwable) {
            $this->pdo = null; // corrupt/mid-build file → behave as absent
        }

        return $this->pdo;
    }
}
