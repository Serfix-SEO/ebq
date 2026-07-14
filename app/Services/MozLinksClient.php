<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Moz Links API (v2) `url_metrics` wrapper — the source of the report's real
 * Domain Authority, Page Authority and Spam Score headline gauges.
 *
 * ONE call = ONE row = one URL's DA/PA/Spam together. The account is on the
 * free tier (50 rows/month), so callers MUST hit this only ONCE per report,
 * on the client's own domain — never per referring domain (DataForSEO `rank`
 * covers those). Freshness is enforced upstream by ReportFreshnessGate.
 *
 * Auth = HTTP Basic of `access_id:secret_key`. Supply either the pre-encoded
 * base64 `token` OR the two parts in config/services.php `moz` (from .env).
 * Returns null on any failure — callers render the gauges as "—".
 */
class MozLinksClient
{
    public function isConfigured(): bool
    {
        return $this->authHeader() !== null;
    }

    /**
     * DA / PA / Spam for a single target URL or domain.
     *
     * @return array{domain_authority:?int, page_authority:?int, spam_score:?int, linking_root_domains:?int}|null
     */
    public function urlMetrics(string $target): ?array
    {
        $auth = $this->authHeader();
        if ($auth === null) {
            Log::warning('MozLinksClient: missing credentials');

            return null;
        }

        $target = $this->normalizeTarget($target);
        if ($target === '') {
            return null;
        }

        $base = rtrim((string) config('services.moz.base_url', 'https://lsapi.seomoz.com/v2'), '/');
        $timeout = (int) config('services.moz.timeout', 30);

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout(8)
                ->withHeaders([
                    'Authorization' => $auth,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->post($base.'/url_metrics', [
                    'targets' => [$target],
                ]);

            if ($response->failed()) {
                Log::warning('Moz url_metrics HTTP failure', [
                    'status' => $response->status(),
                    'target' => $target,
                    'body_snippet' => substr((string) $response->body(), 0, 300),
                ]);

                return null;
            }

            $json = $response->json();
            $row = $json['results'][0] ?? null;
            if (! is_array($row)) {
                return null;
            }

            return [
                'domain_authority' => $this->intOrNull($row['domain_authority'] ?? null),
                'page_authority' => $this->intOrNull($row['page_authority'] ?? null),
                'spam_score' => $this->intOrNull($row['spam_score'] ?? null),
                'linking_root_domains' => $this->intOrNull($row['root_domains_to_root_domain'] ?? ($row['linking_root_domains'] ?? null)),
            ];
        } catch (\Throwable $e) {
            Log::warning('Moz url_metrics request threw', [
                'target' => $target,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function intOrNull(mixed $v): ?int
    {
        return is_numeric($v) ? (int) round((float) $v) : null;
    }

    /**
     * Moz accepts a bare domain or a full URL. We send the bare host so the
     * metric reflects the domain (DA) — PA still comes back for the homepage.
     */
    private function normalizeTarget(string $raw): string
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

    /**
     * Prefer the pre-encoded token; otherwise base64(access_id:secret_key).
     */
    private function authHeader(): ?string
    {
        $token = config('services.moz.token');
        if (is_string($token) && trim($token) !== '') {
            return 'Basic '.trim($token);
        }

        $id = config('services.moz.access_id');
        $secret = config('services.moz.secret_key');
        if (is_string($id) && trim($id) !== '' && is_string($secret) && trim($secret) !== '') {
            return 'Basic '.base64_encode(trim($id).':'.trim($secret));
        }

        return null;
    }
}
