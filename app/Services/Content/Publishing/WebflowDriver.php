<?php

namespace App\Services\Content\Publishing;

use App\Models\ContentArticle;
use App\Models\ContentImage;
use App\Models\ContentIntegration;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Webflow CMS driver via the Data API v2. The customer generates a site token
 * (Site settings → Apps & integrations → API access) with CMS read/write +
 * Sites read and pastes it.
 *
 *  - verify:  GET /v2/sites (caches the site picker)
 *  - target:  site → collections list → collection → field auto-map
 *  - publish: POST /v2/collections/{id}/items/live  (draft: POST …/items)
 *  - update:  PATCH /v2/collections/{id}/items/{id}/live  (draft: PATCH …/items/{id})
 *
 * Credentials: {api_token}. Config: {site_id, site_domain, collection_id,
 * collection_slug, body_field, image_field, summary_field, available_sites,
 * available_collections, post_status}. RichText fields accept our HTML
 * verbatim; the image field takes a URL Webflow fetches. Fixed vendor host —
 * no SSRF surface. 60 req/min rate limit → 429 is transient.
 */
class WebflowDriver implements ProvidesTargets, PublishDriver
{
    private const BASE = 'https://api.webflow.com/v2';

    public function verify(ContentIntegration $integration): PublishResult
    {
        [$token, $err] = $this->token($integration);
        if ($err !== null) {
            return PublishResult::failure($err);
        }

        [$response, $failure] = $this->request($token, 'get', '/sites');
        if ($failure !== null) {
            return $failure;
        }

        $sites = [];
        foreach ((array) $response->json('sites', []) as $site) {
            $domain = '';
            foreach ((array) ($site['customDomains'] ?? []) as $custom) {
                $domain = (string) ($custom['url'] ?? '');
                if ($domain !== '') {
                    break;
                }
            }
            if ($domain === '' && ! empty($site['shortName'])) {
                $domain = $site['shortName'].'.webflow.io';
            }
            $sites[] = [
                'id' => (string) ($site['id'] ?? ''),
                'label' => (string) ($site['displayName'] ?? $site['shortName'] ?? 'Site'),
                'domain' => preg_replace('#^https?://#', '', rtrim($domain, '/')) ?? $domain,
            ];
        }
        if ($sites === []) {
            return PublishResult::failure('This Webflow token can see no sites. Generate a site token with the Sites read and CMS read/write scopes.');
        }

        $config = (array) ($integration->config ?? []);
        $config['available_sites'] = $sites;
        if (! in_array($config['site_id'] ?? null, array_column($sites, 'id'), true)) {
            unset($config['site_id'], $config['site_domain'], $config['collection_id'],
                $config['collection_slug'], $config['body_field'], $config['image_field'],
                $config['summary_field'], $config['available_collections']);
        }
        $integration->forceFill(['config' => $config])->save();

        return PublishResult::success(null, null, ['sites' => count($sites)]);
    }

    public function targets(ContentIntegration $integration): array
    {
        $config = (array) ($integration->config ?? []);
        if (empty($config['site_id'])) {
            return [[
                'key' => 'site',
                'label' => __('Choose your Webflow site'),
                'options' => array_map(
                    fn (array $s) => ['id' => $s['id'], 'label' => $s['label']],
                    (array) ($config['available_sites'] ?? []),
                ),
            ]];
        }
        if (empty($config['collection_id'])) {
            return [[
                'key' => 'collection',
                'label' => __('Choose the collection articles should publish to'),
                'options' => array_map(
                    fn (array $c) => ['id' => $c['id'], 'label' => $c['label']],
                    (array) ($config['available_collections'] ?? []),
                ),
            ]];
        }

        return [];
    }

    public function selectTarget(ContentIntegration $integration, string $key, string $id): PublishResult
    {
        [$token, $err] = $this->token($integration);
        if ($err !== null) {
            return PublishResult::failure($err);
        }
        $config = (array) ($integration->config ?? []);

        if ($key === 'site') {
            foreach ((array) ($config['available_sites'] ?? []) as $site) {
                if (($site['id'] ?? null) !== $id) {
                    continue;
                }

                [$response, $failure] = $this->request($token, 'get', '/sites/'.rawurlencode($id).'/collections');
                if ($failure !== null) {
                    return $failure;
                }
                $collections = array_map(fn (array $c) => [
                    'id' => (string) ($c['id'] ?? ''),
                    'label' => (string) ($c['displayName'] ?? $c['slug'] ?? 'Collection'),
                    'slug' => (string) ($c['slug'] ?? ''),
                ], (array) $response->json('collections', []));
                if ($collections === []) {
                    return PublishResult::failure('That Webflow site has no CMS collections. Create a Blog Posts collection (with a Rich text field) first.');
                }

                $config['site_id'] = $site['id'];
                $config['site_domain'] = $site['domain'] ?? '';
                $config['available_collections'] = $collections;
                unset($config['collection_id'], $config['collection_slug'],
                    $config['body_field'], $config['image_field'], $config['summary_field']);
                $integration->forceFill(['config' => $config])->save();

                return PublishResult::success($id, null);
            }

            return PublishResult::failure('That site is no longer available — reconnect to refresh the list.');
        }

        if ($key === 'collection') {
            foreach ((array) ($config['available_collections'] ?? []) as $collection) {
                if (($collection['id'] ?? null) !== $id) {
                    continue;
                }

                // Auto-map the collection's fields from its schema.
                [$response, $failure] = $this->request($token, 'get', '/collections/'.rawurlencode($id));
                if ($failure !== null) {
                    return $failure;
                }
                $body = null;
                $image = null;
                $summary = null;
                foreach ((array) $response->json('fields', []) as $field) {
                    $type = (string) ($field['type'] ?? '');
                    $slug = (string) ($field['slug'] ?? '');
                    if ($slug === '' || in_array($slug, ['name', 'slug'], true)) {
                        continue;
                    }
                    if ($type === 'RichText') {
                        $body ??= $slug;
                    } elseif ($type === 'Image') {
                        $image ??= $slug;
                    } elseif ($type === 'PlainText') {
                        $summary ??= $slug;
                    }
                }
                if ($body === null) {
                    return PublishResult::failure('That collection has no Rich text field for the article body. Pick a collection with one (like a standard Blog Posts collection).');
                }

                $config['collection_id'] = $collection['id'];
                $config['collection_slug'] = $collection['slug'] ?? '';
                $config['body_field'] = $body;
                $config['image_field'] = $image;
                $config['summary_field'] = $summary;
                $integration->forceFill(['config' => $config])->save();

                return PublishResult::success($id, null);
            }

            return PublishResult::failure('That collection is no longer available — reconnect to refresh the list.');
        }

        return PublishResult::failure('Unknown Webflow connection step.');
    }

    public function publish(ContentArticle $article, ContentIntegration $integration): PublishResult
    {
        return $this->push($article, $integration, null);
    }

    public function update(ContentArticle $article, ContentIntegration $integration, string $externalId): PublishResult
    {
        return $this->push($article, $integration, $externalId);
    }

    // ── internals ───────────────────────────────────────────────────────

    private function push(ContentArticle $article, ContentIntegration $integration, ?string $externalId): PublishResult
    {
        [$token, $err] = $this->token($integration);
        if ($err !== null) {
            return PublishResult::failure($err);
        }

        $config = (array) ($integration->config ?? []);
        if (empty($config['collection_id']) || empty($config['body_field'])) {
            return PublishResult::failure('No Webflow collection selected. Open Settings → Integrations and finish the Webflow connection.');
        }

        $live = ($config['post_status'] ?? 'publish') !== 'draft';

        $fieldData = [
            'name' => (string) ($article->meta_title ?: $article->h1),
            'slug' => (string) $article->slug,
            $config['body_field'] => (string) $article->html,
        ];
        if (! empty($config['summary_field']) && ! empty($article->meta_description)) {
            $fieldData[$config['summary_field']] = (string) $article->meta_description;
        }
        if (! empty($config['image_field'])) {
            $featured = $this->featuredImage($article);
            if ($featured !== null) {
                // Webflow fetches the URL and re-hosts the asset itself.
                $ref = ['url' => $featured['url']];
                if ($featured['alt'] !== '') {
                    $ref['alt'] = $featured['alt'];
                }
                $fieldData[$config['image_field']] = $ref;
            }
        }

        $collection = rawurlencode((string) $config['collection_id']);
        if ($externalId === null) {
            $path = '/collections/'.$collection.'/items'.($live ? '/live' : '');
            $payload = ['fieldData' => $fieldData];
            if (! $live) {
                $payload['isDraft'] = true;
            }
            [$response, $failure] = $this->request($token, 'post', $path, $payload, timeout: 45, authError: 'Webflow rejected the token during publish.');
        } else {
            $path = '/collections/'.$collection.'/items/'.rawurlencode($externalId).($live ? '/live' : '');
            [$response, $failure] = $this->request($token, 'patch', $path, ['fieldData' => $fieldData], timeout: 45, authError: 'Webflow rejected the token during publish.');
        }
        if ($failure !== null) {
            return $failure;
        }

        $id = (string) ($response->json('id') ?? '');
        if ($id === '') {
            return PublishResult::failure('Webflow returned no item id.', transient: true);
        }

        $url = null;
        $domain = (string) ($config['site_domain'] ?? '');
        $collectionSlug = (string) ($config['collection_slug'] ?? '');
        $itemSlug = (string) ($response->json('fieldData.slug') ?? $article->slug);
        if ($live && $domain !== '' && $collectionSlug !== '' && $itemSlug !== '') {
            // Deterministic collection-page route. Requires the designer to
            // have built a template page for the collection — the connect
            // guide says so; live verification stays best-effort either way.
            $url = 'https://'.$domain.'/'.$collectionSlug.'/'.$itemSlug;
        }

        return PublishResult::success($id, $url, ['isDraft' => (bool) ($response->json('isDraft') ?? false)]);
    }

    /** @return array{url: string, alt: string}|null */
    private function featuredImage(ContentArticle $article): ?array
    {
        $image = $article->images()
            ->where('status', ContentImage::STATUS_GENERATED)
            ->where('role', ContentImage::ROLE_FEATURED)
            ->first();
        $url = $image?->url();

        return $url !== null ? ['url' => $url, 'alt' => (string) ($image->alt_text ?? '')] : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: ?Response, 1: ?PublishResult}
     */
    private function request(
        string $token,
        string $method,
        string $path,
        array $payload = [],
        int $timeout = 20,
        string $authError = 'Webflow rejected the token. Check it has the CMS read/write and Sites read scopes.',
    ): array {
        try {
            $pending = Http::timeout($timeout)->connectTimeout(8)
                ->withToken($token)
                ->acceptJson();
            $response = $method === 'get'
                ? $pending->get(self::BASE.$path, $payload)
                : $pending->{$method}(self::BASE.$path, $payload);
        } catch (\Throwable $e) {
            return [null, PublishResult::failure('Could not reach Webflow: '.$e->getMessage(), transient: true)];
        }

        if (in_array($response->status(), [401, 403], true)) {
            return [null, PublishResult::failure($authError)];
        }
        if ($response->status() === 429) {
            return [null, PublishResult::failure('Webflow rate limit hit.', transient: true)];
        }
        if ($response->failed()) {
            return [null, PublishResult::failure(
                'Webflow returned HTTP '.$response->status().': '.mb_substr((string) $response->body(), 0, 200),
                transient: $response->serverError(),
                response: ['status' => $response->status()],
            )];
        }

        return [$response, null];
    }

    /** @return array{0: string, 1: ?string} [token, error] */
    private function token(ContentIntegration $integration): array
    {
        $creds = $integration->credentials !== null ? $integration->credentials->toArray() : [];
        $token = trim((string) ($creds['api_token'] ?? ''));

        return $token === ''
            ? ['', 'The Webflow connection is missing its API token.']
            : [$token, null];
    }
}
