<?php

namespace App\Services\Content\Publishing;

use App\Models\ContentArticle;
use App\Models\ContentImage;
use App\Models\ContentIntegration;
use App\Services\Content\Publishing\RichText\HtmlBlockParser;
use App\Services\Content\Publishing\RichText\ImageRef;
use App\Services\Content\Publishing\RichText\ImageRefResolver;
use App\Services\Content\Publishing\RichText\PortableTextAdapter;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Sanity driver via the HTTP Mutations API. The customer creates a robot
 * token with Editor permissions (sanity.io/manage → project → API → Tokens)
 * and pastes it together with the project id.
 *
 *  - verify:  GET /v{v}/datasets (dataset picker)
 *  - publish: image assets uploaded as bytes, then one mutate call with
 *             createOrReplace on a deterministic _id — which makes update()
 *             the exact same call with the stored id. Body is Portable Text.
 *
 * Credentials: {project_id, token}. Config: {dataset, doc_type ('post'),
 * url_pattern?, available_datasets, post_status}. Field names follow Sanity's
 * standard blog-template schema (title/slug/excerpt/mainImage/body/
 * publishedAt); doc_type is the only mapping knob — a custom field map is a
 * deliberate non-goal. Sanity is headless: external_url stays null unless the
 * customer sets url_pattern (e.g. https://site.com/blog/{slug}).
 */
class SanityDriver implements ProvidesTargets, PublishDriver
{
    public const API_VERSION = '2025-02-19';

    public function verify(ContentIntegration $integration): PublishResult
    {
        [$base, $token, $err] = $this->connection($integration);
        if ($err !== null) {
            return PublishResult::failure($err);
        }

        [$response, $failure] = $this->request($token, 'get', $base.'/datasets');
        if ($failure !== null) {
            return $failure;
        }

        $datasets = [];
        foreach ((array) $response->json() as $dataset) {
            $name = (string) ($dataset['name'] ?? '');
            if ($name !== '') {
                $datasets[] = ['id' => $name, 'label' => $name];
            }
        }
        if ($datasets === []) {
            return PublishResult::failure('This Sanity project has no datasets, or the token cannot list them. Use a token with Editor permissions.');
        }

        $config = (array) ($integration->config ?? []);
        $config['available_datasets'] = $datasets;
        $config['doc_type'] = trim((string) ($config['doc_type'] ?? '')) ?: 'post';
        if (! in_array($config['dataset'] ?? null, array_column($datasets, 'id'), true)) {
            unset($config['dataset']);
        }
        $integration->forceFill(['config' => $config])->save();

        return PublishResult::success(null, null, ['datasets' => count($datasets)]);
    }

    public function targets(ContentIntegration $integration): array
    {
        $config = (array) ($integration->config ?? []);
        if (! empty($config['dataset'])) {
            return [];
        }

        return [[
            'key' => 'dataset',
            'label' => __('Choose the dataset articles should publish to'),
            'options' => (array) ($config['available_datasets'] ?? []),
        ]];
    }

    public function selectTarget(ContentIntegration $integration, string $key, string $id): PublishResult
    {
        $config = (array) ($integration->config ?? []);
        if (! in_array($id, array_column((array) ($config['available_datasets'] ?? []), 'id'), true)) {
            return PublishResult::failure('That dataset is no longer available — reconnect to refresh the list.');
        }

        $config['dataset'] = $id;
        $integration->forceFill(['config' => $config])->save();

        return PublishResult::success($id, null);
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
        [$base, $token, $err] = $this->connection($integration);
        if ($err !== null) {
            return PublishResult::failure($err);
        }

        $config = (array) ($integration->config ?? []);
        $dataset = (string) ($config['dataset'] ?? '');
        if ($dataset === '') {
            return PublishResult::failure('No Sanity dataset selected. Open Settings → Integrations and finish the Sanity connection.');
        }

        // Re-host generated images as Sanity assets first (best-effort, the
        // WP-sideload pattern): local URL → asset _id map for the body
        // resolver, plus the featured asset for mainImage.
        $assetMap = [];
        $featuredAssetId = null;
        $featuredAlt = '';
        $images = $article->images()->where('status', ContentImage::STATUS_GENERATED)->get();
        foreach ($images as $image) {
            $assetId = $this->uploadAsset($base, $token, $dataset, $image);
            if ($assetId === null) {
                continue;
            }
            $localUrl = $image->url();
            if ($localUrl !== null) {
                $assetMap[$localUrl] = $assetId;
            }
            if ($image->role === ContentImage::ROLE_FEATURED) {
                $featuredAssetId = $assetId;
                $featuredAlt = (string) ($image->alt_text ?? '');
            }
        }

        $resolver = new class($assetMap) implements ImageRefResolver
        {
            /** @param  array<string, string>  $map */
            public function __construct(private readonly array $map) {}

            public function resolve(string $src): ?ImageRef
            {
                return isset($this->map[$src]) ? new ImageRef($this->map[$src]) : null;
            }
        };

        $body = app(PortableTextAdapter::class)->convert(
            app(HtmlBlockParser::class)->parse((string) $article->html),
            $resolver,
        );

        // Deterministic id → createOrReplace is create AND update. Draft mode
        // uses Sanity's drafts. prefix so the document waits in the Studio.
        $id = $externalId ?? (
            (($config['post_status'] ?? 'publish') === 'draft' ? 'drafts.' : '')
            .'serfix-'.strtolower((string) $article->id)
        );

        $document = [
            '_id' => $id,
            '_type' => trim((string) ($config['doc_type'] ?? '')) ?: 'post',
            'title' => (string) ($article->meta_title ?: $article->h1),
            'slug' => ['_type' => 'slug', 'current' => (string) $article->slug],
            'excerpt' => (string) ($article->meta_description ?? ''),
            'body' => $body,
            'publishedAt' => now()->toIso8601String(),
        ];
        if ($featuredAssetId !== null) {
            $mainImage = [
                '_type' => 'image',
                'asset' => ['_type' => 'reference', '_ref' => $featuredAssetId],
            ];
            if ($featuredAlt !== '') {
                $mainImage['alt'] = $featuredAlt;
            }
            $document['mainImage'] = $mainImage;
        }

        [$response, $failure] = $this->request(
            $token,
            'post',
            $base.'/data/mutate/'.rawurlencode($dataset).'?returnIds=true',
            ['mutations' => [['createOrReplace' => $document]]],
            timeout: 45,
            authError: 'Sanity rejected the token during publish.',
        );
        if ($failure !== null) {
            return $failure;
        }

        $resultId = (string) ($response->json('results.0.id') ?? $id);

        return PublishResult::success(
            $resultId,
            $this->publicUrl($config, (string) $article->slug, $resultId),
            ['transactionId' => $response->json('transactionId')],
        );
    }

    /** url_pattern substitution ({slug}), only for live (non-draft) documents. */
    private function publicUrl(array $config, string $slug, string $documentId): ?string
    {
        $pattern = trim((string) ($config['url_pattern'] ?? ''));
        if ($pattern === '' || str_starts_with($documentId, 'drafts.')) {
            return null;
        }

        return str_replace('{slug}', $slug, $pattern);
    }

    /** Upload one image's bytes as a Sanity asset; null on any failure. */
    private function uploadAsset(string $base, string $token, string $dataset, ContentImage $image): ?string
    {
        try {
            $disk = Storage::disk(ContentImage::disk());
            $bytes = $image->disk_path && $disk->exists($image->disk_path)
                ? $disk->get($image->disk_path)
                : null;
            if ($bytes === null) {
                return null;
            }

            $filename = str_replace('"', '', (string) ($image->filename ?: 'image.png'));
            $response = Http::timeout(60)->connectTimeout(8)
                ->withToken($token)
                ->withBody($bytes, 'image/png')
                ->post($base.'/assets/images/'.rawurlencode($dataset).'?filename='.rawurlencode($filename));

            if ($response->failed()) {
                return null;
            }
            $assetId = (string) ($response->json('document._id') ?? '');

            return $assetId !== '' ? $assetId : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: ?Response, 1: ?PublishResult}
     */
    private function request(
        string $token,
        string $method,
        string $url,
        array $payload = [],
        int $timeout = 20,
        string $authError = 'Sanity rejected the token. Use a token with Editor permissions.',
    ): array {
        try {
            $pending = Http::timeout($timeout)->connectTimeout(8)
                ->withToken($token)
                ->acceptJson();
            $response = $method === 'get'
                ? $pending->get($url, $payload)
                : $pending->{$method}($url, $payload);
        } catch (\Throwable $e) {
            return [null, PublishResult::failure('Could not reach Sanity: '.$e->getMessage(), transient: true)];
        }

        if (in_array($response->status(), [401, 403], true)) {
            return [null, PublishResult::failure($authError)];
        }
        if ($response->status() === 429) {
            return [null, PublishResult::failure('Sanity rate limit hit.', transient: true)];
        }
        if ($response->failed()) {
            $description = (string) ($response->json('error.description') ?? $response->body());

            return [null, PublishResult::failure(
                'Sanity returned HTTP '.$response->status().': '.mb_substr($description, 0, 200),
                transient: $response->serverError(),
                response: ['status' => $response->status()],
            )];
        }

        return [$response, null];
    }

    /** @return array{0: ?string, 1: string, 2: ?string} [versioned API base, token, error] */
    private function connection(ContentIntegration $integration): array
    {
        $creds = $integration->credentials !== null ? $integration->credentials->toArray() : [];
        $projectId = strtolower(trim((string) ($creds['project_id'] ?? '')));
        $token = trim((string) ($creds['token'] ?? ''));

        if ($projectId === '' || $token === '') {
            return [null, '', 'The Sanity connection is missing its project ID or token.'];
        }
        // The project id is interpolated into a hostname — keep it strictly
        // alphanumeric so no crafted value can change the host.
        if (preg_match('/^[a-z0-9-]+$/', $projectId) !== 1) {
            return [null, '', 'That does not look like a Sanity project ID (letters and numbers only).'];
        }

        return ['https://'.$projectId.'.api.sanity.io/v'.self::API_VERSION, $token, null];
    }
}
