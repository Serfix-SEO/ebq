<?php

namespace App\Services\KeywordFinder;

use App\Models\KeywordApiRequest;
use App\Models\KeywordApiServer;
use App\Support\KeywordFinderLocations;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Load balancer + failover across the self-hosted keyword API fleet.
 *
 * Each dispatch creates a {@see KeywordApiRequest} (status `queued`) carrying a
 * unique `request_id`, then POSTs to the least-busy healthy server. The server
 * ACKs instantly and later calls our webhook with that `request_id`; the
 * webhook fills in the result. If a server fails transiently (timeout / 5xx /
 * 429) we advance to the next candidate. A permanent client error (400/401/409)
 * stops the cascade: we mark the request failed with a friendly message and,
 * for auth/login failures, flag the offending server unhealthy so the load
 * balancer skips it until the next health check clears it.
 */
class KeywordFinderPool
{
    /** Raw detail of the last dispatch attempt — for the admin test panel. */
    private ?array $lastRequestBody = null;

    private ?string $lastEndpoint = null;

    /** @var array{ok: bool, status: ?int, transient: bool, body: ?array, error: ?string}|null */
    private ?array $lastOutcome = null;

    public function lastRequestBody(): ?array
    {
        return $this->lastRequestBody;
    }

    public function lastEndpoint(): ?string
    {
        return $this->lastEndpoint;
    }

    public function lastOutcome(): ?array
    {
        return $this->lastOutcome;
    }

    /**
     * Dispatch a search-volume lookup for known keywords.
     *
     * @param  list<string>  $keywords
     */
    public function dispatchVolume(
        array $keywords,
        string $countryKey = 'global',
        ?string $language = null,
        ?string $userId = null,
        ?string $websiteId = null,
        ?KeywordApiServer $only = null,
        bool $meter = true,
    ): KeywordApiRequest {
        $keywords = array_values(array_filter(array_map(
            static fn ($k): string => is_string($k) ? trim($k) : '',
            $keywords,
        ), static fn (string $k): bool => $k !== ''));

        $payload = [
            'keywords' => $keywords,
            // The internal cache key the webhook upserts keyword_metrics under.
            'country_key' => $countryKey,
            'location' => KeywordFinderLocations::resolveLocation($countryKey),
            'language' => KeywordFinderLocations::resolveLanguage($language),
        ];

        return $this->dispatch(KeywordApiRequest::TYPE_VOLUME, null, $payload, $userId, $websiteId, $only, $meter);
    }

    /**
     * Dispatch a discovery request. Provide either `seeds` (keywords mode) or
     * `url` + optional `scope` (website/page mode).
     *
     * `$countryKey` is the internal keyword_metrics cache key (e.g. 'us',
     * 'global'); pass it so the webhook caches every returned keyword's volume
     * under the right country for future free lookups.
     *
     * @param  array{seeds?: list<string>, url?: string, scope?: string, location?: string, language?: string}  $opts
     */
    public function dispatchIdeas(array $opts, ?string $userId = null, ?string $websiteId = null, ?KeywordApiServer $only = null, ?string $countryKey = null, bool $meter = true): KeywordApiRequest
    {
        [$mode, $payload] = $this->buildIdeasPayload($opts, $countryKey);

        return $this->dispatch(KeywordApiRequest::TYPE_IDEAS, $mode, $payload, $userId, $websiteId, $only, $meter);
    }

    /**
     * Normalizes raw ideas-lookup opts into the (mode, payload) pair
     * {@see dispatchIdeas} would send — split out so callers (the monthly
     * ideas cache) can compute the exact same cache key a dispatch would use,
     * without duplicating the seed/URL normalization logic and risking drift.
     *
     * @param  array{seeds?: list<string>, url?: string, scope?: string, location?: string, language?: string}  $opts
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function buildIdeasPayload(array $opts, ?string $countryKey = null): array
    {
        $location = KeywordFinderLocations::resolveLocation($opts['location'] ?? $countryKey);
        $language = KeywordFinderLocations::resolveLanguage($opts['language'] ?? null);

        if (! empty($opts['url'])) {
            $scope = in_array(($opts['scope'] ?? 'site'), ['site', 'page'], true) ? $opts['scope'] : 'site';
            $mode = $scope === 'page' ? 'page' : 'website';
            $payload = [
                'url' => trim((string) $opts['url']),
                'scope' => $scope,
                'location' => $location,
                'language' => $language,
            ];
        } else {
            $seeds = array_values(array_filter(array_map(
                static fn ($s): string => is_string($s) ? trim($s) : '',
                $opts['seeds'] ?? [],
            ), static fn (string $s): bool => $s !== ''));
            $mode = 'keywords';
            $payload = [
                'seeds' => $seeds,
                'location' => $location,
                'language' => $language,
            ];
        }

        // Internal-only: stripped from the outgoing body in dispatch(), used by
        // the webhook to know which country to cache the returned volumes under.
        if ($countryKey !== null) {
            $payload['country_key'] = $countryKey;
        }

        return [$mode, $payload];
    }

    /**
     * Core dispatch loop: create the tracking row, then walk routable servers
     * until one ACKs or we run out.
     *
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(string $type, ?string $mode, array $payload, ?string $userId, ?string $websiteId, ?KeywordApiServer $only = null, bool $meter = true): KeywordApiRequest
    {
        $request = KeywordApiRequest::create([
            'request_id' => (string) Str::uuid(),
            'type' => $type,
            'mode' => $mode,
            'payload' => $payload,
            'status' => KeywordApiRequest::STATUS_QUEUED,
            'user_id' => $userId,
            'website_id' => $websiteId,
        ]);

        // Plan cap: fleet capacity is the scarce resource (per-plan
        // keyword_research.monthly_searches). Only user-initiated dispatches
        // meter (platform enrichment passes meter:false); the reservation is
        // released by the spend log after a successful ACK below.
        $billed = null;
        if ($meter) {
            $billed = $this->billedUser($userId, $websiteId);
            if ($billed !== null) {
                try {
                    app(\App\Services\Usage\UsageMeter::class)->assertCanSpend($billed, 'keyword_finder', 1);
                } catch (\App\Exceptions\QuotaExceededException $e) {
                    $request->markFailed($e->userMessage);

                    return $request;
                }
            }
        }

        // `$only` targets one specific server (admin "Test" button); otherwise
        // we walk every routable server for real load balancing + failover.
        $servers = $only !== null
            ? collect([$only])
            : KeywordApiServer::query()->routable()->get();
        if ($servers->isEmpty()) {
            $request->markFailed('No keyword API servers are available right now. Please try again later.');

            return $request;
        }

        $endpoint = $type === KeywordApiRequest::TYPE_IDEAS ? '/keywords/ideas' : '/keywords/volume';
        $webhookUrl = url((string) config('services.keyword_finder.webhook_path', '/webhooks/keyword-finder'));

        $lastError = null;
        foreach ($servers as $server) {
            $body = array_merge($payload, [
                'request_id' => $request->request_id,
                'webhook_url' => $webhookUrl,
            ]);
            // Don't leak our internal cache key to the API.
            unset($body['country_key']);

            $this->lastRequestBody = $body;
            $this->lastEndpoint = $server->baseUrl().$endpoint;

            $client = new KeywordFinderClient($server);
            $outcome = $type === KeywordApiRequest::TYPE_IDEAS
                ? $client->postIdeas($body)
                : $client->postVolume($body);
            $this->lastOutcome = $outcome;

            if ($outcome['ok']) {
                $request->forceFill([
                    'keyword_api_server_id' => $server->id,
                    'status' => KeywordApiRequest::STATUS_RUNNING,
                    'dispatched_at' => now(),
                ])->save();

                if ($billed !== null) {
                    // Records the spend AND releases the assertCanSpend reservation.
                    app(\App\Services\ClientActivityLogger::class)->log(
                        'keyword_finder.dispatch',
                        userId: $billed->id,
                        websiteId: $websiteId,
                        provider: 'keyword_finder',
                        meta: ['type' => $type, 'mode' => $mode],
                        unitsConsumed: 1,
                    );
                }

                return $request;
            }

            $lastError = $outcome;

            // Permanent failure — don't try other servers with the same bad body.
            if (! $outcome['transient']) {
                $this->flagServerOnPermanentError($server, (int) ($outcome['status'] ?? 0));
                $request->markFailed($this->friendlyError((int) ($outcome['status'] ?? 0)));

                return $request;
            }

            // Transient — note the attempt and fall through to the next server.
            Log::info('KeywordFinderPool transient failure, trying next server', [
                'request_id' => $request->request_id,
                'server_id' => $server->id,
                'status' => $outcome['status'],
            ]);
        }

        // Every server failed transiently.
        $request->markFailed($this->friendlyError((int) ($lastError['status'] ?? 0)));

        return $request;
    }

    /**
     * Whose plan pays for a metered dispatch: the website's owner when a
     * website is attached (team members spend the owner's quota, mirroring
     * SerperSearchClient), else the acting user.
     */
    private function billedUser(?string $userId, ?string $websiteId): ?\App\Models\User
    {
        if ($websiteId !== null && $websiteId !== '') {
            $ownerId = \Illuminate\Support\Facades\DB::table('websites')->where('id', $websiteId)->value('user_id');
            if ($ownerId !== null) {
                return \App\Models\User::find($ownerId);
            }
        }

        return $userId ? \App\Models\User::find($userId) : null;
    }

    /**
     * On a permanent client-side error, downgrade the server's health so the
     * load balancer skips it. 401/409 specifically mean auth/login problems.
     */
    private function flagServerOnPermanentError(KeywordApiServer $server, int $status): void
    {
        if (in_array($status, [401, 409], true)) {
            $server->forceFill([
                'is_healthy' => false,
                'logged_in' => $status === 409 ? false : $server->logged_in,
                'last_error' => $status === 401 ? 'Unauthorized (check API key)' : 'Browser session needs re-login',
                'last_health_at' => now(),
            ])->save();
        }
    }

    /** User-facing message — never echoes the raw upstream error detail. */
    private function friendlyError(int $status): string
    {
        return match (true) {
            $status === 400 => 'We couldn’t process that request. Please check your input and try again.',
            $status === 401, $status === 409 => 'The keyword service is temporarily unavailable. Please try again shortly.',
            default => 'The keyword service is busy right now. Please try again in a moment.',
        };
    }
}
