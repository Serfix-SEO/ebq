<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper over the DataForSEO Backlinks API (v3), the primary provider
 * for the customer-facing backlink report. Basic-auth (login:password from
 * config/services.php `dataforseo`, sourced from .env on both boxes).
 *
 * Every endpoint is POSTed in "live" mode (`/backlinks/<endpoint>/live`) with a
 * single-task JSON body: `[{ "target": "<bare-domain>", ... }]`. DataForSEO
 * wraps results as `tasks[0].result[0]` (summary) or `tasks[0].result[0].items`
 * (list endpoints). All methods return null on any failure — callers log/skip
 * rather than crash, mirroring KeywordsEverywhereBacklinkClient.
 *
 * Cost discipline: list endpoints are capped at the configured `row_limit`
 * (default 1000 = one request) so per-report spend stays flat regardless of
 * site size. Billing is $0.024/request + $0.06/1000 rows.
 */
class DataForSeoBacklinkClient
{
    private bool $sandbox = false;

    /**
     * Real, accumulated `cost` (USD) DataForSEO reported across every call
     * made through this instance since construction/reset — each task
     * response carries its own actual billed cost (`tasks[0].cost`), so this
     * is the true spend for a report generation, not the flat ~$0.25
     * estimate the codebase used before real tracking existed. Sandbox
     * responses report cost 0 (mock data, never billed).
     */
    private float $totalCost = 0.0;

    /**
     * Route requests to the free sandbox host (mock data). Used for admin
     * testing so it never bills the live account.
     */
    public function useSandbox(bool $on = true): static
    {
        $this->sandbox = $on;

        return $this;
    }

    public function isConfigured(): bool
    {
        return $this->authHeader() !== null;
    }

    /**
     * Real cumulative cost (USD) of every call made through this instance
     * since the last resetCost() (or construction). Callers making several
     * calls to build one report should read this ONCE at the end.
     */
    public function totalCost(): float
    {
        return $this->totalCost;
    }

    public function resetCost(): static
    {
        $this->totalCost = 0.0;

        return $this;
    }

    /**
     * Full backlink profile of a domain in one call: referring domains/IPs/
     * subnets, backlinks total, rank (0-1000), spam score, dofollow/nofollow.
     *
     * @return array<string, mixed>|null
     */
    public function summary(string $domain): ?array
    {
        $result = $this->firstResult('/backlinks/summary/live', [
            'target' => $this->target($domain),
            'internal_list_limit' => 10,
            'backlinks_status_type' => 'live',
            'include_subdomains' => true,
        ]);

        return is_array($result) ? $result : null;
    }

    /**
     * Monthly time series (rank, backlinks, referring domains, new/lost) that
     * powers the active/lost + growth charts.
     *
     * @return list<array<string, mixed>>
     */
    public function history(string $domain, ?int $months = null): array
    {
        $months = $months ?? (int) config('services.dataforseo.history_months', 12);

        return $this->items('/backlinks/history/live', [
            'target' => $this->target($domain),
            'date_from' => now()->subMonths(max(1, $months))->startOfMonth()->toDateString(),
        ]);
    }

    /**
     * Top referring domains by rank (each with its own DataForSEO rank,
     * backlink count, first/last seen). Capped at row_limit.
     *
     * @return list<array<string, mixed>>
     */
    public function referringDomains(string $domain, ?int $limit = null): array
    {
        return $this->items('/backlinks/referring_domains/live', [
            'target' => $this->target($domain),
            'limit' => $this->rowLimit($limit),
            'order_by' => ['rank,desc'],
            'backlinks_status_type' => 'live',
        ]);
    }

    /**
     * Anchor texts with reference + referring-domain counts and dofollow split.
     *
     * @return list<array<string, mixed>>
     */
    public function anchors(string $domain, ?int $limit = null): array
    {
        return $this->items('/backlinks/anchors/live', [
            'target' => $this->target($domain),
            'limit' => $this->rowLimit($limit),
            'order_by' => ['backlinks,desc'],
            'backlinks_status_type' => 'live',
        ]);
    }

    /**
     * Top content — the target's own pages ranked by referring domains.
     *
     * @return list<array<string, mixed>>
     */
    public function domainPages(string $domain, ?int $limit = null): array
    {
        $limit = $limit ?? (int) config('services.dataforseo.pages_limit', 1000);

        return $this->items('/backlinks/domain_pages/live', [
            'target' => $this->target($domain),
            'limit' => max(1, min(1000, $limit)),
        ]);
    }

    /**
     * Competitor domains sharing the most backlinks with the target.
     *
     * @return list<array<string, mixed>>
     */
    public function competitors(string $domain, ?int $limit = null): array
    {
        $limit = $limit ?? (int) config('services.dataforseo.competitors_limit', 1000);

        return $this->items('/backlinks/competitors/live', [
            'target' => $this->target($domain),
            'limit' => max(1, min(1000, $limit)),
        ]);
    }

    /**
     * Organic SERP competitors (DataForSEO Labs) — domains ranking for the same
     * keywords, ranked by shared-keyword count. Far more meaningful than the
     * Backlinks "competitors" (shared link sources), which is noisy for small
     * sites. Each item: `domain`, `intersections` (shared keywords), `avg_position`.
     *
     * @return list<array<string, mixed>>
     */
    public function labsCompetitors(string $domain, ?int $limit = null): array
    {
        // Labs-specific cap — Labs rows are 10× the Backlinks row price, and
        // competitor value concentrates in the top rows (see config comment).
        $limit = $limit ?? (int) config('services.dataforseo.labs_competitors_limit', 300);

        return $this->items('/dataforseo_labs/google/competitors_domain/live', [
            'target' => $this->target($domain),
            'language_name' => 'English',
            'location_code' => 2840,
            'item_types' => ['organic'],
            'limit' => max(1, min(1000, $limit)),
        ]);
    }

    /**
     * DataForSEO Labs "bulk traffic estimation" — ONE flat-priced task estimates
     * organic/paid traffic (ETV), keyword counts, ranking distribution, etc. for
     * up to 1,000 targets at once. This is how we get every competitor's monthly
     * search/traffic footprint in a single billed call instead of one per domain.
     *
     * Returns a map keyed by the bare domain we asked for → the raw `metrics`
     * blob DataForSEO returned for it (we store "whatever is provided"). Domains
     * DataForSEO has no data for are simply absent from the map.
     *
     * @param  list<string>  $domains
     * @return array<string, array<string, mixed>>
     */
    public function bulkTrafficEstimation(array $domains): array
    {
        // Normalize + de-dupe to bare domains; keep the mapping so we can key the
        // response back to what the caller asked for.
        $targets = [];
        foreach ($domains as $domain) {
            if (! is_string($domain) || trim($domain) === '') {
                continue;
            }
            $bare = $this->target($domain);
            if ($bare !== '') {
                $targets[$bare] = true;
            }
        }
        $targets = array_slice(array_keys($targets), 0, 1000);
        if ($targets === []) {
            return [];
        }

        $result = $this->firstResult('/dataforseo_labs/google/bulk_traffic_estimation/live', [
            'targets' => $targets,
            'language_name' => 'English',
            'location_code' => 2840,
            'item_types' => ['organic', 'paid'],
        ]);

        $items = $result['items'] ?? null;
        if (! is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $target = $item['target'] ?? null;
            if (! is_string($target) || $target === '') {
                continue;
            }
            // Store the whole item minus the redundant target echo — that's the
            // "metrics" blob plus any extra fields DataForSEO chooses to return.
            $blob = $item;
            unset($blob['target']);
            $out[$this->target($target)] = $blob;
        }

        return $out;
    }

    /**
     * A bounded sample of individual backlinks (one per referring domain) for
     * the "example links" feed. Capped at row_limit.
     *
     * @return list<array<string, mixed>>
     */
    public function backlinksSample(string $domain, ?int $limit = null): array
    {
        // mode=as_is → ALL live links (top `limit` by rank), not one per
        // referring domain. Same single request/cost; complete-for-small-
        // sites, honestly-capped-for-big-ones ("top 1,000 links"). The
        // one-per-domain view still exists — derived client-side from row
        // order (rows arrive rank-sorted, first row per domain = the old
        // grouped sample). Changed 2026-07-16: grouped mode hid links whose
        // anchor appeared in the anchors aggregate (e.g. 富88), which read
        // as a data bug.
        return $this->items('/backlinks/backlinks/live', [
            'target' => $this->target($domain),
            'limit' => $this->rowLimit($limit),
            'mode' => 'as_is',
            'backlinks_status_type' => 'live',
        ]);
    }

    /**
     * Links carrying ONE specific anchor — the on-demand drill-down behind
     * "this anchor exists in the aggregate but its links aren't in the
     * top-1000 sample" (huge profiles: spam links rank far below the cap).
     * Tiny filtered request (~$0.024 + rows); callers cache the result.
     *
     * @return list<array<string, mixed>>
     */
    public function backlinksForAnchor(string $domain, string $anchor, int $limit = 100): array
    {
        return $this->items('/backlinks/backlinks/live', [
            'target' => $this->target($domain),
            'limit' => max(1, min(1000, $limit)),
            'mode' => 'as_is',
            'backlinks_status_type' => 'live',
            'filters' => ['anchor', '=', $anchor],
        ]);
    }

    /**
     * POST a single-task live request and return `tasks[0].result[0]`.
     *
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>|null
     */
    private function firstResult(string $path, array $task): ?array
    {
        $auth = $this->authHeader();
        if ($auth === null) {
            Log::warning('DataForSeoBacklinkClient: missing credentials');

            return null;
        }

        $base = rtrim((string) $this->baseUrl(), '/');
        $url = $base.(str_starts_with($path, '/') ? $path : '/'.$path);
        $timeout = (int) config('services.dataforseo.timeout', 60);

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout(8)
                ->withHeaders([
                    'Authorization' => $auth,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->post($url, [$task]);

            if ($response->failed()) {
                Log::warning('DataForSEO HTTP failure', [
                    'path' => $path,
                    'status' => $response->status(),
                    'target' => $task['target'] ?? null,
                    'body_snippet' => substr((string) $response->body(), 0, 500),
                ]);

                return null;
            }

            $json = $response->json();
            if (! is_array($json)) {
                return null;
            }

            $taskResult = $json['tasks'][0] ?? null;
            if (! is_array($taskResult)) {
                return null;
            }

            // Real billed cost for THIS task, whatever DataForSEO says it is
            // (0 on sandbox/mock responses, 0 on most error statuses per their
            // own billing rules) — accumulate regardless of the task
            // succeeding, so a partial-failure report still reflects real spend.
            $this->totalCost += (float) ($taskResult['cost'] ?? 0.0);

            $statusCode = $taskResult['status_code'] ?? null;
            // DataForSEO task-level success codes are in the 20000 range.
            if (is_int($statusCode) && $statusCode >= 40000) {
                Log::warning('DataForSEO task error', [
                    'path' => $path,
                    'status_code' => $statusCode,
                    'status_message' => $taskResult['status_message'] ?? null,
                    'target' => $task['target'] ?? null,
                ]);

                return null;
            }

            $result = $taskResult['result'][0] ?? null;

            return is_array($result) ? $result : null;
        } catch (\Throwable $e) {
            Log::warning('DataForSEO request threw', [
                'path' => $path,
                'target' => $task['target'] ?? null,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Like firstResult() but returns the result's `items` list (list endpoints).
     *
     * @param  array<string, mixed>  $task
     * @return list<array<string, mixed>>
     */
    private function items(string $path, array $task): array
    {
        $result = $this->firstResult($path, $task);
        $items = $result['items'] ?? null;
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, 'is_array'));
    }

    private function rowLimit(?int $limit): int
    {
        $cap = (int) config('services.dataforseo.row_limit', 1000);
        $limit = $limit ?? $cap;

        return max(1, min($cap, $limit));
    }

    /**
     * DataForSEO expects a bare domain (no scheme, no www, no path).
     */
    private function target(string $domain): string
    {
        $raw = trim($domain);
        $forParse = str_contains($raw, '://') ? $raw : 'https://'.$raw;
        $host = parse_url($forParse, PHP_URL_HOST);
        $host = is_string($host) && $host !== '' ? strtolower($host) : strtolower($raw);

        return preg_replace('/^www\./', '', $host) ?: $host;
    }

    /**
     * Sandbox host when admin/forced, otherwise the live API host.
     */
    private function baseUrl(): string
    {
        if ($this->sandbox || (bool) config('services.dataforseo.force_sandbox')) {
            return (string) config('services.dataforseo.sandbox_base_url', 'https://sandbox.dataforseo.com/v3');
        }

        return (string) config('services.dataforseo.base_url', 'https://api.dataforseo.com/v3');
    }

    /**
     * HTTP Basic header from login:password, or null when unconfigured.
     */
    private function authHeader(): ?string
    {
        $login = config('services.dataforseo.login');
        $password = config('services.dataforseo.password');
        if (! is_string($login) || trim($login) === '' || ! is_string($password) || trim($password) === '') {
            return null;
        }

        return 'Basic '.base64_encode(trim($login).':'.trim($password));
    }
}
