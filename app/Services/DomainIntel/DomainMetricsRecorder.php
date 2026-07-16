<?php

namespace App\Services\DomainIntel;

use App\Models\Website;
use App\Services\OpenPageRankClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Harvests domain intelligence from every report generation into the
 * accumulating `domain_metrics` store. Pure byproduct — costs nothing extra,
 * and a failure here must NEVER break the report job (call sites wrap it).
 *
 * Upserts preserve first_seen_at, increment times_seen, and only overwrite a
 * metric when the new value is non-null (COALESCE), so a partial payload
 * never erases previously-known data.
 */
class DomainMetricsRecorder
{
    /** Referring-domain rows harvested per report (importance-ordered by rank already). */
    private const MAX_REFERRING = 100;

    private const MAX_COMPETITORS = 25;

    /**
     * @param  array<string, mixed>  $payload  an assembled (augmented) report payload
     */
    public function recordReport(string $domain, array $payload): void
    {
        try {
            $rows = $this->rowsFromPayload($domain, $payload);
            if ($rows !== []) {
                $this->upsert($rows);
            }
        } catch (\Throwable $e) {
            Log::warning('DomainMetricsRecorder: failed', ['domain' => $domain, 'message' => $e->getMessage()]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsFromPayload(string $domain, array $payload): array
    {
        $seeds = (array) config('trusted_seed_domains', []);
        $main = strtolower(trim($domain));
        $isActive = Website::query()->where('domain', $main)->exists();

        $rows = [];
        $scores = $payload['scores'] ?? [];
        $rows[$main] = [
            'domain' => $main,
            'tier' => $isActive ? 'active' : 'free',
            'opr_score' => $this->num($payload['popularity']['score'] ?? null),
            'dfs_rank' => $this->rank1000($this->num($payload['gauges']['authority_score'] ?? null) !== null
                ? ((float) $payload['gauges']['authority_score']) * 10
                : null),
            'trust_score' => $this->num($scores['trust'] ?? null),
            'citation_score' => $this->num($scores['citation'] ?? null),
            'spam_score' => $this->num($payload['gauges']['spam_score'] ?? null),
        ];

        foreach (array_slice($payload['top_referring_domains'] ?? [], 0, self::MAX_REFERRING) as $row) {
            $d = strtolower(trim((string) ($row['domain'] ?? '')));
            if ($d === '' || isset($rows[$d])) {
                continue;
            }
            $rows[$d] = [
                'domain' => $d,
                'tier' => null, // never demote on upsert; default 'free' on insert
                'opr_score' => $this->num($row['opr_score'] ?? null),
                'dfs_rank' => $this->rank1000($this->num($row['rank'] ?? null)),
                'trust_score' => null,
                'citation_score' => $this->num($row['cs'] ?? null),
                'spam_score' => null,
            ];
        }

        foreach (array_slice($payload['competitors'] ?? [], 0, self::MAX_COMPETITORS) as $row) {
            $d = strtolower(trim((string) ($row['domain'] ?? '')));
            if ($d === '' || isset($rows[$d])) {
                continue;
            }
            $rows[$d] = [
                'domain' => $d,
                'tier' => null,
                'opr_score' => $this->num($row['opr_score'] ?? null),
                'dfs_rank' => null,
                'trust_score' => null,
                'citation_score' => $this->num($row['cs'] ?? null),
                'spam_score' => null,
            ];
        }

        foreach ($rows as $d => $row) {
            $registrable = OpenPageRankClient::registrable($d);
            $rows[$d]['is_seed'] = in_array($registrable, $seeds, true) ? 1 : 0;
        }

        return array_values($rows);
    }

    /**
     * Raw ON DUPLICATE KEY upsert: COALESCE keeps known values when the new
     * row has nulls, times_seen increments, first_seen_at is write-once.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsert(array $rows): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->upsertPortable($rows); // sqlite test path

            return;
        }

        $now = now()->toDateTimeString();
        $values = [];
        $bindings = [];
        foreach ($rows as $r) {
            $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)';
            array_push(
                $bindings,
                $r['domain'],
                $r['tier'] ?? 'free',
                $r['opr_score'],
                $r['dfs_rank'],
                $r['trust_score'],
                $r['citation_score'],
                $r['spam_score'],
                $r['is_seed'],
                $now, // first_seen_at
                $now, // last_seen_at
                $now, // created_at
                $now, // updated_at
            );
        }

        DB::statement(
            'INSERT INTO domain_metrics
                (domain, tier, opr_score, dfs_rank, trust_score, citation_score, spam_score, is_seed, times_seen, first_seen_at, last_seen_at, created_at, updated_at)
             VALUES '.implode(', ', $values).'
             ON DUPLICATE KEY UPDATE
                tier = IF(VALUES(tier) = \'active\', \'active\', tier),
                opr_score = COALESCE(VALUES(opr_score), opr_score),
                dfs_rank = COALESCE(VALUES(dfs_rank), dfs_rank),
                trust_score = COALESCE(VALUES(trust_score), trust_score),
                citation_score = COALESCE(VALUES(citation_score), citation_score),
                spam_score = COALESCE(VALUES(spam_score), spam_score),
                is_seed = GREATEST(is_seed, VALUES(is_seed)),
                times_seen = times_seen + 1,
                last_seen_at = VALUES(last_seen_at),
                updated_at = VALUES(updated_at)',
            $bindings,
        );
    }

    /**
     * Row-by-row equivalent for non-MySQL drivers (the sqlite test suite).
     * Same semantics: COALESCE keeps known values, tier only promotes,
     * times_seen increments, first_seen_at write-once.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsertPortable(array $rows): void
    {
        $now = now();
        foreach ($rows as $r) {
            $existing = \App\Models\DomainMetric::query()->where('domain', $r['domain'])->first();
            if ($existing === null) {
                \App\Models\DomainMetric::create([
                    'domain' => $r['domain'],
                    'tier' => $r['tier'] ?? 'free',
                    'opr_score' => $r['opr_score'],
                    'dfs_rank' => $r['dfs_rank'],
                    'trust_score' => $r['trust_score'],
                    'citation_score' => $r['citation_score'],
                    'spam_score' => $r['spam_score'],
                    'is_seed' => (bool) $r['is_seed'],
                    'times_seen' => 1,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]);

                continue;
            }
            $existing->update([
                'tier' => ($r['tier'] ?? null) === 'active' ? 'active' : $existing->tier,
                'opr_score' => $r['opr_score'] ?? $existing->opr_score,
                'dfs_rank' => $r['dfs_rank'] ?? $existing->dfs_rank,
                'trust_score' => $r['trust_score'] ?? $existing->trust_score,
                'citation_score' => $r['citation_score'] ?? $existing->citation_score,
                'spam_score' => $r['spam_score'] ?? $existing->spam_score,
                'is_seed' => $existing->is_seed || (bool) $r['is_seed'],
                'times_seen' => $existing->times_seen + 1,
                'last_seen_at' => $now,
            ]);
        }
    }

    private function num(mixed $v): int|float|null
    {
        return is_numeric($v) ? $v + 0 : null;
    }

    private function rank1000(int|float|null $v): ?int
    {
        return $v === null ? null : (int) max(0, min(1000, $v));
    }
}
