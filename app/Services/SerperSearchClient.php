<?php

namespace App\Services;

use App\Models\User;
use App\Services\Usage\UsageMeter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SerperSearchClient
{
    private const BASE_URL = 'https://google.serper.dev';

    private const ENDPOINTS = [
        'organic' => '/search',
        'search' => '/search',
        'images' => '/images',
        'videos' => '/videos',
        'news' => '/news',
        'shopping' => '/shopping',
        'maps' => '/maps',
        'places' => '/places',
        'scholar' => '/scholar',
        'autocomplete' => '/autocomplete',
    ];

    /**
     * Legacy simple search kept for existing callers (page audit etc.).
     * `$websiteId` / `$ownerUserId` propagate to the activity log so admin
     * billing can attribute the call.
     *
     * @return array<string, mixed>|null
     */
    public function search(
        string $query,
        int $num = 10,
        ?string $gl = null,
        ?string $hl = null,
        ?string $websiteId = null,
        ?string $ownerUserId = null,
        ?string $source = null,
    ): ?array {
        return $this->query([
            'q' => $query,
            'num' => $num,
            'gl' => $gl,
            'hl' => $hl,
            '__website_id' => $websiteId,
            '__owner_user_id' => $ownerUserId,
            '__source' => $source,
        ]);
    }

    /**
     * Full-featured search supporting every parameter exposed by the Serper API.
     *
     * Accepted keys:
     *  - q (string, required)
     *  - type (string, default 'organic'): organic|search|images|videos|news|shopping|maps|places|scholar|autocomplete
     *  - gl (string, 2-letter ISO country code, e.g. 'us')
     *  - hl (string, language code, e.g. 'en')
     *  - location (string, free-form location, e.g. 'New York, NY, United States')
     *  - num (int 1-100)
     *  - page (int 1-10)
     *  - device (string, 'desktop' or 'mobile')
     *  - autocorrect (bool)
     *  - safe (bool) - safe search
     *  - tbs (string) - time filter (qdr:d, qdr:w, qdr:m, qdr:y)
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|null
     */
    public function query(array $params): ?array
    {
        $key = config('services.serper.key');
        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        $q = isset($params['q']) && is_string($params['q']) ? trim($params['q']) : '';
        if ($q === '') {
            return null;
        }

        $type = isset($params['type']) && is_string($params['type']) ? strtolower(trim($params['type'])) : 'organic';
        $endpoint = self::ENDPOINTS[$type] ?? '/search';
        $url = rtrim((string) config('services.serper.base_url', self::BASE_URL), '/').$endpoint;

        $body = ['q' => $q];

        if (isset($params['num'])) {
            $body['num'] = min(100, max(1, (int) $params['num']));
        }
        if (isset($params['page'])) {
            $body['page'] = min(10, max(1, (int) $params['page']));
        }

        if (isset($params['gl']) && is_string($params['gl'])) {
            $gl = strtolower(trim($params['gl']));
            if (strlen($gl) === 2 && ctype_alpha($gl)) {
                $body['gl'] = $gl;
            }
        }

        if (isset($params['hl']) && is_string($params['hl'])) {
            $hl = strtolower(trim($params['hl']));
            if ($hl !== '' && preg_match('/^[a-z]{2}(-[a-z0-9]{2,8})?$/', $hl) === 1) {
                $body['hl'] = $hl;
            }
        }

        if (isset($params['location']) && is_string($params['location']) && trim($params['location']) !== '') {
            $body['location'] = trim($params['location']);
        }

        if (isset($params['device']) && is_string($params['device'])) {
            $device = strtolower(trim($params['device']));
            if (in_array($device, ['desktop', 'mobile'], true)) {
                $body['device'] = $device;
            }
        }

        if (array_key_exists('autocorrect', $params)) {
            $body['autocorrect'] = (bool) $params['autocorrect'];
        }

        if (array_key_exists('safe', $params) && $params['safe']) {
            $body['safe'] = 'active';
        }

        if (isset($params['tbs']) && is_string($params['tbs']) && trim($params['tbs']) !== '') {
            $body['tbs'] = trim($params['tbs']);
        }

        $billedUser = $this->resolveBilledUser(
            isset($params['__website_id']) ? (string) $params['__website_id'] : null,
            isset($params['__owner_user_id']) ? (string) $params['__owner_user_id'] : null,
        );
        if ($billedUser !== null) {
            app(UsageMeter::class)->assertCanSpend($billedUser, 'serp_api', 1);
        }

        try {
            $response = Http::timeout(30)
                ->connectTimeout(10)
                ->withHeaders([
                    'X-API-KEY' => $key,
                    'Content-Type' => 'application/json',
                ])
                ->post($url, $body);
        } catch (\Throwable $e) {
            Log::warning('SerperSearchClient: request failed: '.$e->getMessage());

            return null;
        }

        if (! $response->successful()) {
            Log::warning('SerperSearchClient: HTTP '.$response->status().' body='.mb_substr((string) $response->body(), 0, 300));

            return null;
        }

        try {
            $json = $response->json();
        } catch (\Throwable $e) {
            Log::warning('SerperSearchClient: invalid JSON body: '.$e->getMessage());

            return null;
        }

        $websiteId = isset($params['__website_id']) ? (string) $params['__website_id'] : null;
        $ownerUserId = isset($params['__owner_user_id']) ? (string) $params['__owner_user_id'] : null;

        $meta = [
            'query' => mb_substr($q, 0, 120),
            'type' => $type,
            'num' => $body['num'] ?? null,
            'page' => $body['page'] ?? null,
            'gl' => $body['gl'] ?? null,
            'device' => $body['device'] ?? null,
        ];
        $source = isset($params['__source']) ? (string) $params['__source'] : '';
        if ($source !== '') {
            $meta['source'] = $source;
        }
        app(ClientActivityLogger::class)->log(
            'api_usage.serp_api',
            userId: $ownerUserId ?? Auth::id(),
            websiteId: $websiteId ?: null,
            provider: 'serp_api',
            meta: $meta,
            unitsConsumed: 1,
        );

        return is_array($json) ? $json : null;
    }

    private function resolveBilledUser(?string $websiteId, ?string $ownerUserId): ?User
    {
        if ($websiteId !== null && ($websiteId !== null && $websiteId !== '')) {
            $ownerId = DB::table('website_user')
                ->where('website_id', $websiteId)
                ->where('role', \App\Support\TeamPermissions::ROLE_OWNER)
                ->value('user_id');
            if ($ownerId === null) {
                $ownerId = DB::table('websites')->where('id', $websiteId)->value('user_id');
            }
            if ($ownerId !== null) {
                return User::find($ownerId);
            }
        }
        $id = $ownerUserId ?: Auth::id();
        return $id ? User::find($id) : null;
    }
}
