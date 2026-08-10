<?php

namespace App\Services\Content\Publishing;

use App\Models\ContentArticle;
use App\Models\ContentImage;
use App\Models\ContentIntegration;
use App\Services\Content\Publishing\RichText\HtmlBlockParser;
use App\Services\Content\Publishing\RichText\ImageRef;
use App\Services\Content\Publishing\RichText\ImageRefResolver;
use App\Services\Content\Publishing\RichText\RicosAdapter;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Wix Blog driver via the REST Blog v3 API. The customer creates an account
 * API key (wix.com/my-account → API Keys) and pastes it together with the
 * site id; every call carries `Authorization: <key>` (raw, no Bearer) plus a
 * `wix-site-id` header.
 *
 * Wix accepts no HTML — the body ships as Ricos rich content (RicosAdapter).
 * Publish flow: import images into the Media Manager (by URL, best-effort) →
 * create draft post → publish it. Republishing an already-published draft
 * updates the live post in place, so update() PATCHes the draft and
 * re-publishes — no duplicates.
 *
 * Credentials: {api_key, site_id}. Config: {member_id?, available_authors,
 * post_status}. An author (memberId) may be required by the Blog app; verify
 * offers a picker when the Members API is readable and publish returns a
 * clear hard error when Wix insists on one.
 */
class WixDriver implements ProvidesTargets, PublishDriver
{
    private const BASE = 'https://www.wixapis.com';

    public function verify(ContentIntegration $integration): PublishResult
    {
        [$headers, $err] = $this->headers($integration);
        if ($err !== null) {
            return PublishResult::failure($err);
        }

        [$response, $failure] = $this->request($headers, 'get', '/blog/v3/posts', ['paging.limit' => 1]);
        if ($failure !== null) {
            if (str_contains((string) $failure->error, 'HTTP 404')) {
                return PublishResult::failure('The Wix Blog app is not installed on this site. Add it from the Wix App Market first.');
            }

            return $failure;
        }

        $config = (array) ($integration->config ?? []);

        // Best-effort author picker — the API key may lack Members access,
        // which is fine: the field stays manual and publish reports clearly
        // if Wix ends up requiring an author.
        try {
            [$members] = $this->request($headers, 'get', '/members/v1/members', ['paging.limit' => 50]);
            $authors = [];
            foreach ((array) ($members?->json('members') ?? []) as $member) {
                $id = (string) ($member['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $profile = (array) ($member['profile'] ?? []);
                $name = trim(((string) ($profile['name']['first'] ?? '')).' '.((string) ($profile['name']['last'] ?? '')));
                $authors[] = [
                    'id' => $id,
                    'label' => (string) ($profile['nickname'] ?? '') ?: ($name ?: (string) ($member['loginEmail'] ?? $id)),
                ];
            }
            $config['available_authors'] = $authors;
            if (! empty($config['member_id']) && ! in_array($config['member_id'], array_column($authors, 'id'), true) && $authors !== []) {
                unset($config['member_id']);
            }
        } catch (\Throwable) {
            $config['available_authors'] = [];
        }

        $integration->forceFill(['config' => $config])->save();

        return PublishResult::success(null, null, ['authors' => count((array) $config['available_authors'])]);
    }

    public function targets(ContentIntegration $integration): array
    {
        $config = (array) ($integration->config ?? []);
        $authors = (array) ($config['available_authors'] ?? []);
        if ($authors === [] || ! empty($config['member_id'])) {
            return [];
        }

        return [[
            'key' => 'author',
            'label' => __('Choose the author articles should publish as'),
            'options' => $authors,
        ]];
    }

    public function selectTarget(ContentIntegration $integration, string $key, string $id): PublishResult
    {
        $config = (array) ($integration->config ?? []);
        if (! in_array($id, array_column((array) ($config['available_authors'] ?? []), 'id'), true)) {
            return PublishResult::failure('That author is no longer available — reconnect to refresh the list.');
        }

        $config['member_id'] = $id;
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
        [$headers, $err] = $this->headers($integration);
        if ($err !== null) {
            return PublishResult::failure($err);
        }
        $config = (array) ($integration->config ?? []);

        // 1. Re-host generated images in the Wix Media Manager (best-effort).
        [$mediaMap, $featuredMediaId] = $this->importImages($headers, $article);

        $resolver = new class($mediaMap) implements ImageRefResolver
        {
            /** @param  array<string, array{id: string, width: ?int, height: ?int}>  $map */
            public function __construct(private readonly array $map) {}

            public function resolve(string $src): ?ImageRef
            {
                $hit = $this->map[$src] ?? null;

                return $hit === null ? null : new ImageRef($hit['id'], $hit['width'], $hit['height']);
            }
        };

        $richContent = app(RicosAdapter::class)->convert(
            app(HtmlBlockParser::class)->parse((string) $article->html),
            $resolver,
        );

        $draftPost = [
            'title' => mb_substr((string) ($article->meta_title ?: $article->h1), 0, 200),
            'excerpt' => mb_substr((string) ($article->meta_description ?? ''), 0, 500),
            'richContent' => $richContent,
        ];
        if (! empty($config['member_id'])) {
            $draftPost['memberId'] = (string) $config['member_id'];
        }
        if ($featuredMediaId !== null) {
            $draftPost['media'] = [
                'wixMedia' => ['image' => ['id' => $featuredMediaId]],
                'displayed' => true,
                'custom' => true,
            ];
        }

        // 2. Create or patch the draft post.
        if ($externalId === null) {
            [$response, $failure] = $this->request($headers, 'post', '/blog/v3/draft-posts', ['draftPost' => $draftPost], timeout: 45);
        } else {
            [$response, $failure] = $this->request($headers, 'patch', '/blog/v3/draft-posts/'.rawurlencode($externalId), ['draftPost' => $draftPost], timeout: 45);
        }
        if ($failure !== null) {
            if (! $failure->transient && str_contains(strtolower((string) $failure->error), 'memberid')) {
                return PublishResult::failure('Wix requires an author for blog posts. Open Settings → Integrations and pick one for the Wix connection.');
            }

            return $failure;
        }

        $draftId = (string) ($response->json('draftPost.id') ?? $externalId ?? '');
        if ($draftId === '') {
            return PublishResult::failure('Wix returned no draft post id.', transient: true);
        }

        // 3. Draft mode stops here — the client publishes from the Wix
        // dashboard. No public URL yet.
        if (($config['post_status'] ?? 'publish') === 'draft') {
            return PublishResult::success($draftId, null, ['draft' => true]);
        }

        // 4. Publish (re-publishing an already-live draft updates in place).
        [$published, $failure] = $this->request($headers, 'post', '/blog/v3/draft-posts/'.rawurlencode($draftId).'/publish', [], timeout: 45);
        if ($failure !== null) {
            return $failure;
        }
        $postId = (string) ($published->json('postId') ?? $draftId);

        // 5. Resolve the live URL (best-effort).
        $url = null;
        try {
            [$post] = $this->request($headers, 'get', '/blog/v3/posts/'.rawurlencode($postId), ['fieldsets' => 'URL']);
            $base = rtrim((string) ($post?->json('post.url.base') ?? ''), '/');
            $path = (string) ($post?->json('post.url.path') ?? '');
            if ($base !== '' && $path !== '') {
                $url = $base.$path;
            }
        } catch (\Throwable) {
            // URL stays null; verification and indexing simply skip.
        }

        return PublishResult::success($draftId, $url, ['postId' => $postId]);
    }

    /**
     * Import generated images into the Wix Media Manager by URL. Returns
     * [local URL → {id,width,height} map, featured media id]. Best-effort —
     * a failed import drops that image from the Ricos body (alt-text
     * fallback) and never blocks the post.
     *
     * @param  array<string, string>  $headers
     * @return array{0: array<string, array{id: string, width: ?int, height: ?int}>, 1: ?string}
     */
    private function importImages(array $headers, ContentArticle $article): array
    {
        $map = [];
        $featuredId = null;

        $images = $article->images()->where('status', ContentImage::STATUS_GENERATED)->get();
        foreach ($images as $image) {
            $localUrl = $image->url();
            if ($localUrl === null) {
                continue;
            }

            try {
                [$response, $failure] = $this->request($headers, 'post', '/site-media/v1/files/import', [
                    'url' => $localUrl,
                    'mimeType' => 'image/png',
                    'displayName' => (string) ($image->filename ?: 'image.png'),
                ], timeout: 60);
                if ($failure !== null) {
                    continue;
                }
                $fileId = (string) ($response->json('file.id') ?? '');
                if ($fileId === '') {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }

            [$width, $height] = $this->dimensions($image);
            $map[$localUrl] = ['id' => $fileId, 'width' => $width, 'height' => $height];
            if ($image->role === ContentImage::ROLE_FEATURED) {
                $featuredId = $fileId;
            }
        }

        return [$map, $featuredId];
    }

    /** @return array{0: ?int, 1: ?int} */
    private function dimensions(ContentImage $image): array
    {
        try {
            $disk = Storage::disk(ContentImage::disk());
            if (! $image->disk_path || ! $disk->exists($image->disk_path)) {
                return [null, null];
            }
            $info = getimagesizefromstring((string) $disk->get($image->disk_path));

            return $info === false ? [null, null] : [(int) $info[0], (int) $info[1]];
        } catch (\Throwable) {
            return [null, null];
        }
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $payload
     * @return array{0: ?Response, 1: ?PublishResult}
     */
    private function request(array $headers, string $method, string $path, array $payload = [], int $timeout = 20): array
    {
        try {
            $pending = Http::timeout($timeout)->connectTimeout(8)
                ->withHeaders($headers)
                ->acceptJson();
            $response = $method === 'get'
                ? $pending->get(self::BASE.$path, $payload)
                : $pending->{$method}(self::BASE.$path, $payload);
        } catch (\Throwable $e) {
            return [null, PublishResult::failure('Could not reach Wix: '.$e->getMessage(), transient: true)];
        }

        if (in_array($response->status(), [401, 403], true)) {
            return [null, PublishResult::failure('Wix rejected the API key or site ID. Check both in the connection settings.')];
        }
        if ($response->status() === 429) {
            return [null, PublishResult::failure('Wix rate limit hit.', transient: true)];
        }
        if ($response->failed()) {
            return [null, PublishResult::failure(
                'Wix returned HTTP '.$response->status().': '.mb_substr((string) $response->body(), 0, 200),
                transient: $response->serverError(),
                response: ['status' => $response->status()],
            )];
        }

        return [$response, null];
    }

    /** @return array{0: array<string, string>, 1: ?string} [headers, error] */
    private function headers(ContentIntegration $integration): array
    {
        $creds = $integration->credentials !== null ? $integration->credentials->toArray() : [];
        $apiKey = trim((string) ($creds['api_key'] ?? ''));
        $siteId = trim((string) ($creds['site_id'] ?? ''));

        if ($apiKey === '' || $siteId === '') {
            return [[], 'The Wix connection is missing its API key or site ID.'];
        }
        if (preg_match('/^[0-9a-f-]{30,40}$/i', $siteId) !== 1) {
            return [[], 'That does not look like a Wix site ID — copy the GUID from your dashboard URL (…/dashboard/{site-id}/…).'];
        }

        return [['Authorization' => $apiKey, 'wix-site-id' => $siteId], null];
    }
}
