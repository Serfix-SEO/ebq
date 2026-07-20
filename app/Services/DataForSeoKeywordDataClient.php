<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DataForSEO "Keywords Data → Google Ads → Search Volume (live)" client.
 *
 * Batched by design: one task carries up to 1000 keywords and DataForSEO bills
 * roughly a flat ~$0.05 per task (NOT per keyword), so callers MUST pass the
 * whole keyword set at once — never one call per keyword. Results are the real
 * Google Ads averages (search_volume, CPC, competition, 12-month trend) used to
 * enrich {@see \App\Models\KeywordMetric} for reuse across clients.
 */
class DataForSeoKeywordDataClient
{
    private bool $sandbox = false;

    private float $totalCost = 0.0;

    public function useSandbox(bool $on = true): static
    {
        $this->sandbox = $on;

        return $this;
    }

    public function isConfigured(): bool
    {
        return $this->authHeader() !== null;
    }

    /** Real billed cost (USD) accumulated across every task this instance made. */
    public function totalCost(): float
    {
        return $this->totalCost;
    }

    /**
     * Google Ads monthly search volume for a batch of keywords (≤1000/task,
     * auto-chunked). One request per 1000 — the cheapest way to price a set.
     *
     * @param  list<string>  $keywords
     * @return array<string, array{search_volume:?int, cpc:?float, competition:?float, competition_index:?int, low_top_of_page_bid:?float, high_top_of_page_bid:?float, monthly:?array}>
     *         keyed by lowercased keyword
     */
    public function searchVolume(array $keywords, int $locationCode = 2840, string $languageCode = 'en'): array
    {
        if ($this->authHeader() === null) {
            return [];
        }
        $keywords = array_values(array_unique(array_filter(
            array_map(static fn ($k) => mb_strtolower(trim((string) $k)), $keywords),
            static fn ($k) => $k !== '' && mb_strlen($k) <= 80,
        )));
        if ($keywords === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk($keywords, 1000) as $chunk) {
            foreach ($this->postTask('/keywords_data/google_ads/search_volume/live', [
                'keywords' => array_values($chunk),
                'location_code' => $locationCode,
                'language_code' => $languageCode,
            ]) as $item) {
                $kw = mb_strtolower(trim((string) ($item['keyword'] ?? '')));
                if ($kw === '') {
                    continue;
                }
                $out[$kw] = [
                    'search_volume' => is_numeric($item['search_volume'] ?? null) ? (int) $item['search_volume'] : null,
                    'cpc' => is_numeric($item['cpc'] ?? null) ? (float) $item['cpc'] : null,
                    'competition' => is_numeric($item['competition'] ?? null) ? (float) $item['competition'] : null,
                    'competition_index' => is_numeric($item['competition_index'] ?? null) ? (int) $item['competition_index'] : null,
                    'low_top_of_page_bid' => is_numeric($item['low_top_of_page_bid'] ?? null) ? (float) $item['low_top_of_page_bid'] : null,
                    'high_top_of_page_bid' => is_numeric($item['high_top_of_page_bid'] ?? null) ? (float) $item['high_top_of_page_bid'] : null,
                    'monthly' => is_array($item['monthly_searches'] ?? null) ? $item['monthly_searches'] : null,
                ];
            }
        }

        return $out;
    }

    /**
     * POST one task; return tasks[0].result (the keyword list — NOT result[0],
     * this endpoint's result IS the array of keyword items).
     *
     * @return list<array<string, mixed>>
     */
    private function postTask(string $path, array $task): array
    {
        $auth = $this->authHeader();
        if ($auth === null) {
            return [];
        }
        $url = rtrim($this->baseUrl(), '/').(str_starts_with($path, '/') ? $path : '/'.$path);

        try {
            $resp = Http::timeout((int) config('services.dataforseo.timeout', 60))
                ->connectTimeout(8)
                ->withHeaders(['Authorization' => $auth, 'Accept' => 'application/json', 'Content-Type' => 'application/json'])
                ->post($url, [$task]);

            if ($resp->failed()) {
                Log::warning('DataForSEO keyword-data HTTP failure', ['status' => $resp->status(), 'body' => substr((string) $resp->body(), 0, 400)]);

                return [];
            }
            $t = $resp->json()['tasks'][0] ?? null;
            if (! is_array($t)) {
                return [];
            }
            $this->totalCost += (float) ($t['cost'] ?? 0.0);
            $sc = $t['status_code'] ?? null;
            if (is_int($sc) && $sc >= 40000) {
                Log::warning('DataForSEO keyword-data task error', ['status_code' => $sc, 'status_message' => $t['status_message'] ?? null]);

                return [];
            }
            $result = $t['result'] ?? null;

            return is_array($result) ? array_values(array_filter($result, 'is_array')) : [];
        } catch (\Throwable $e) {
            Log::warning('DataForSEO keyword-data threw', ['message' => $e->getMessage()]);

            return [];
        }
    }

    private function baseUrl(): string
    {
        if ($this->sandbox || (bool) config('services.dataforseo.force_sandbox')) {
            return (string) config('services.dataforseo.sandbox_base_url', 'https://sandbox.dataforseo.com/v3');
        }

        return (string) config('services.dataforseo.base_url', 'https://api.dataforseo.com/v3');
    }

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
