<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Open PageRank (Keywords Everywhere) — global popularity rank + 0-10 score +
 * monthly history per domain. Powers the report's "Popularity rank" and
 * enriches competitors and backlink sources with a quality score.
 *
 * POST {base}/domains/bulk  (Bearer auth), body {"domains":[... up to 100 ...]}.
 * Response: { results: [{ domain, found, open_page_rank, rank,
 *             referring_domains, history:[{date, open_page_rank}] }] }.
 * Free tier 30k domains/month. Returns [] on any failure.
 */
class OpenPageRankClient
{
    public function isConfigured(): bool
    {
        $key = config('services.openpagerank.key');

        return is_string($key) && trim($key) !== '';
    }

    /**
     * Bulk metrics for many domains, keyed by normalized host.
     *
     * @param  list<string>  $domains
     * @return array<string, array{rank:?int, score:?float, referring_domains:?int, history:list<array{date:string, score:float}>}>
     */
    public function metricsFor(array $domains): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        // Normalize + dedupe hosts.
        $hosts = [];
        foreach ($domains as $d) {
            $h = $this->host((string) $d);
            if ($h !== '') {
                $hosts[$h] = true;
            }
        }
        $hosts = array_keys($hosts);
        if ($hosts === []) {
            return [];
        }

        $base = rtrim((string) config('services.openpagerank.base_url', 'https://openpagerank.keywordseverywhere.com/v1'), '/');
        $timeout = (int) config('services.openpagerank.timeout', 30);
        $key = trim((string) config('services.openpagerank.key'));

        $out = [];
        foreach (array_chunk($hosts, 100) as $chunk) {
            try {
                $response = Http::timeout($timeout)
                    ->connectTimeout(8)
                    ->withHeaders(['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json'])
                    ->post($base.'/domains/bulk', ['domains' => array_values($chunk)]);

                if ($response->failed()) {
                    Log::warning('OpenPageRank HTTP failure', [
                        'status' => $response->status(),
                        'body_snippet' => substr((string) $response->body(), 0, 300),
                    ]);

                    continue;
                }

                foreach (($response->json('results') ?? []) as $row) {
                    if (! is_array($row) || empty($row['found'])) {
                        continue;
                    }
                    $host = $this->host((string) ($row['domain'] ?? ''));
                    if ($host === '') {
                        continue;
                    }
                    $history = [];
                    foreach (($row['history'] ?? []) as $h) {
                        if (is_array($h) && isset($h['date'], $h['open_page_rank'])) {
                            $history[] = ['date' => (string) $h['date'], 'score' => (float) $h['open_page_rank']];
                        }
                    }
                    $out[$host] = [
                        'rank' => is_numeric($row['rank'] ?? null) ? (int) $row['rank'] : null,
                        'score' => is_numeric($row['open_page_rank'] ?? null) ? round((float) $row['open_page_rank'], 2) : null,
                        'referring_domains' => is_numeric($row['referring_domains'] ?? null) ? (int) $row['referring_domains'] : null,
                        'history' => $history,
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('OpenPageRank request threw', ['message' => $e->getMessage()]);
            }
        }

        return $out;
    }

    /**
     * Registrable domain (eTLD+1) for a host — OPR ranks at this level, so a
     * subdomain source (forum.example.com) must be looked up as example.com.
     * Heuristic last-two-labels with a guard for common multi-part TLDs.
     */
    public static function registrable(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/^www\./', '', $host) ?: $host;
        $parts = explode('.', $host);
        $n = count($parts);
        if ($n <= 2) {
            return $host;
        }
        $twoPart = ['co.uk', 'org.uk', 'gov.uk', 'ac.uk', 'com.au', 'net.au', 'org.au', 'co.nz',
            'co.in', 'co.za', 'com.br', 'com.mx', 'co.jp', 'com.tr', 'com.sg', 'com.hk'];
        $last2 = $parts[$n - 2].'.'.$parts[$n - 1];
        if (in_array($last2, $twoPart, true) && $n >= 3) {
            return $parts[$n - 3].'.'.$last2;
        }

        return $last2;
    }

    private function host(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $forParse = str_contains($raw, '://') ? $raw : 'https://'.$raw;
        $host = parse_url($forParse, PHP_URL_HOST);
        $host = is_string($host) && $host !== '' ? strtolower($host) : strtolower($raw);

        return preg_replace('/^www\./', '', $host) ?: $host;
    }
}
