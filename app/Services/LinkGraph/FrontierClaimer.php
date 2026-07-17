<?php

namespace App\Services\LinkGraph;

use App\Models\LinkCrawlFrontier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Atomic frontier claiming — the core of the concurrent link crawler. A single
 * UPDATE flips up to N due `pending` rows to `in_progress` under a fresh lease
 * id; MySQL's row locks guarantee two concurrent claims get DISJOINT sets, so
 * any number of workers on any number of boxes can crawl in parallel without
 * ever double-fetching a URL. Claimed rows carry `leased_until`; a crashed
 * worker's rows are returned to `pending` by the reaper once the lease lapses.
 */
class FrontierClaimer
{
    /**
     * Claim up to $limit due pending rows. Returns the claimed rows (may be
     * empty when the frontier is drained or fully leased).
     *
     * @return Collection<int, LinkCrawlFrontier>
     */
    public function claim(int $limit): Collection
    {
        $limit = max(1, $limit);
        $leaseMinutes = max(1, (int) config('crawler.link_crawl.lease_minutes', 10));

        // Portable, race-safe claim (works on MySQL prod + sqlite tests —
        // UPDATE…ORDER BY…LIMIT is MySQL-only):
        //   1. pick candidate ids (ORDER BY next_at LIMIT n),
        //   2. flip only those still `pending` under a fresh lease.
        // Concurrent claimers may pick overlapping candidates, but the
        // `status = 'pending'` guard + InnoDB row locks mean each row is won
        // by exactly ONE claimer — so the re-fetch by lease_id yields a
        // DISJOINT set. A contended claimer just wins fewer than $limit.
        $ids = DB::table('link_crawl_frontier')
            ->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('next_at')->orWhere('next_at', '<=', now()))
            ->orderBy('next_at')
            ->limit($limit)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return collect();
        }

        $leaseId = (string) Str::uuid();
        $won = DB::table('link_crawl_frontier')
            ->whereIn('id', $ids)
            ->where('status', 'pending')
            ->update([
                'status' => 'in_progress',
                'lease_id' => $leaseId,
                'leased_until' => now()->addMinutes($leaseMinutes),
                'updated_at' => now(),
            ]);

        if ($won === 0) {
            return collect();
        }

        return LinkCrawlFrontier::query()->where('lease_id', $leaseId)->get();
    }

    /**
     * Flip up to $limit `done` rows whose recrawl window has elapsed
     * (next_at <= now) back to `pending`, so they re-enter the crawl cycle.
     * The claimer only ever takes `pending`, so without this a crawled domain
     * would stay `done` forever and the frontier would drain to empty. Ordered
     * oldest-due first (most-stale recrawled soonest). Returns the count moved.
     */
    public function requeueRecrawls(int $limit): int
    {
        $limit = max(0, $limit);
        if ($limit === 0) {
            return 0;
        }

        // Portable: pick due ids first (MySQL forbids UPDATE…LIMIT via subquery
        // on the same table anyway), then flip. Only `done` rows — failed rows
        // are terminal, blocked rows are re-tried on their own schedule.
        $ids = DB::table('link_crawl_frontier')
            ->where('status', 'done')
            ->whereNotNull('next_at')
            ->where('next_at', '<=', now())
            ->orderBy('next_at')
            ->limit($limit)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return DB::table('link_crawl_frontier')
            ->whereIn('id', $ids)
            ->where('status', 'done')
            ->update([
                'status' => 'pending',
                'lease_id' => null,
                'leased_until' => null,
                'next_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /** True while there is any due, unclaimed work left. */
    public function hasDueWork(): bool
    {
        return LinkCrawlFrontier::query()
            ->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('next_at')->orWhere('next_at', '<=', now()))
            ->exists();
    }

    /**
     * Return expired leases (crashed workers) to `pending` so they get retried.
     * Returns the number reclaimed.
     */
    public function reapExpired(): int
    {
        return DB::table('link_crawl_frontier')
            ->where('status', 'in_progress')
            ->where('leased_until', '<', now())
            ->update([
                'status' => 'pending',
                'lease_id' => null,
                'leased_until' => null,
                'updated_at' => now(),
            ]);
    }
}
