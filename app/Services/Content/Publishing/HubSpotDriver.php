<?php

namespace App\Services\Content\Publishing;

use App\Models\ContentArticle;
use App\Models\ContentImage;
use App\Models\ContentIntegration;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HubSpot CMS blog driver via the Blog Posts v3 API. The customer creates a
 * private app (Settings → Integrations → Private Apps) with the `content`
 * scope and pastes the pat-… token. postBody accepts our HTML verbatim.
 *
 *  - verify:  GET /cms/v3/blog-settings/settings (blog picker) +
 *             GET/POST /cms/v3/blogs/authors (a post needs an author)
 *  - publish: POST /cms/v3/blogs/posts — state set directly (single call)
 *  - update:  PATCH /cms/v3/blogs/posts/{id}
 *
 * Credentials: {token}. Config: {content_group_id, blog_url, blog_author_id,
 * available_blogs, post_status}. Fixed vendor host — no SSRF surface.
 */
class HubSpotDriver implements ProvidesTargets, PublishDriver
{
    private const BASE = 'https://api.hubapi.com';

    public function verify(ContentIntegration $integration): PublishResult
    {
        [$token, $err] = $this->token($integration);
        if ($err !== null) {
            return PublishResult::failure($err);
        }

        [$response, $failure] = $this->request($token, 'get', '/cms/v3/blog-settings/settings');
        if ($failure !== null) {
            return $failure;
        }

        $blogs = [];
        foreach ((array) $response->json('results', []) as $blog) {
            $blogs[] = [
                'id' => (string) ($blog['id'] ?? ''),
                'label' => (string) ($blog['name'] ?? $blog['htmlTitle'] ?? 'Blog'),
                'url' => rtrim((string) ($blog['url'] ?? ''), '/'),
            ];
        }
        if ($blogs === []) {
            return PublishResult::failure('This HubSpot account has no blog yet. Create one under Content → Blog first.');
        }

        $config = (array) ($integration->config ?? []);
        $config['available_blogs'] = $blogs;
        if (! in_array($config['content_group_id'] ?? null, array_column($blogs, 'id'), true)) {
            unset($config['content_group_id'], $config['blog_url']);
        }

        // Blog posts need an author; reuse the first existing one, create one
        // named after the customer's site only when the portal has none.
        $authorId = $this->ensureAuthor($token, $integration);
        if ($authorId === null) {
            return PublishResult::failure('Could not find or create a HubSpot blog author. Add one under Content → Blog → Authors, then re-check.');
        }
        $config['blog_author_id'] = $authorId;

        $integration->forceFill(['config' => $config])->save();

        return PublishResult::success(null, $blogs[0]['url'] ?: null, ['blogs' => count($blogs)]);
    }

    public function targets(ContentIntegration $integration): array
    {
        $config = (array) ($integration->config ?? []);
        if (! empty($config['content_group_id'])) {
            return [];
        }

        return [[
            'key' => 'blog',
            'label' => __('Choose the blog articles should publish to'),
            'options' => array_map(
                fn (array $b) => ['id' => $b['id'], 'label' => $b['label']],
                (array) ($config['available_blogs'] ?? []),
            ),
        ]];
    }

    public function selectTarget(ContentIntegration $integration, string $key, string $id): PublishResult
    {
        $config = (array) ($integration->config ?? []);
        foreach ((array) ($config['available_blogs'] ?? []) as $blog) {
            if (($blog['id'] ?? null) === $id) {
                $config['content_group_id'] = $blog['id'];
                $config['blog_url'] = $blog['url'] ?? '';
                $integration->forceFill(['config' => $config])->save();

                return PublishResult::success($id, null);
            }
        }

        return PublishResult::failure('That blog is no longer available — reconnect to refresh the list.');
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
        if (empty($config['content_group_id'])) {
            return PublishResult::failure('No HubSpot blog selected. Open Settings → Integrations and finish the HubSpot connection.');
        }

        $payload = [
            'name' => (string) ($article->meta_title ?: $article->h1),
            'slug' => (string) $article->slug,
            'postBody' => (string) $article->html,
            'metaDescription' => (string) ($article->meta_description ?? ''),
            'htmlTitle' => (string) ($article->meta_title ?: $article->h1),
            'contentGroupId' => (string) $config['content_group_id'],
            // Publish in the same call — a separate state PATCH would create
            // duplicate drafts when a retry re-enters through publish().
            'state' => ($config['post_status'] ?? 'publish') === 'draft' ? 'DRAFT' : 'PUBLISHED',
        ];
        if (! empty($config['blog_author_id'])) {
            $payload['blogAuthorId'] = (string) $config['blog_author_id'];
        }
        if (! empty($article->canonical_url)) {
            $payload['linkRelCanonicalUrl'] = (string) $article->canonical_url;
        }

        $featured = $this->featuredImage($article);
        if ($featured !== null) {
            $payload['featuredImage'] = $featured['url'];
            $payload['useFeaturedImage'] = true;
            if ($featured['alt'] !== '') {
                $payload['featuredImageAltText'] = $featured['alt'];
            }
        }

        [$response, $failure] = $externalId === null
            ? $this->request($token, 'post', '/cms/v3/blogs/posts', $payload, timeout: 45, authError: 'HubSpot rejected the credentials during publish.')
            : $this->request($token, 'patch', '/cms/v3/blogs/posts/'.rawurlencode($externalId), $payload, timeout: 45, authError: 'HubSpot rejected the credentials during publish.');
        if ($failure !== null) {
            return $failure;
        }

        $id = (string) ($response->json('id') ?? '');
        if ($id === '') {
            return PublishResult::failure('HubSpot returned no post id.', transient: true);
        }

        return PublishResult::success(
            $id,
            (string) ($response->json('url') ?? '') ?: null,
            ['state' => $response->json('state')],
        );
    }

    /** First existing author id, else a newly created one named after the site. */
    private function ensureAuthor(string $token, ContentIntegration $integration): ?string
    {
        [$response, $failure] = $this->request($token, 'get', '/cms/v3/blogs/authors', ['limit' => 1]);
        if ($failure !== null) {
            return null;
        }
        $existing = $response->json('results.0.id');
        if ($existing !== null) {
            return (string) $existing;
        }

        $name = (string) ($integration->website?->normalized_domain ?: 'Editorial team');
        [$created, $failure] = $this->request($token, 'post', '/cms/v3/blogs/authors', ['displayName' => $name]);
        if ($failure !== null || $created->json('id') === null) {
            return null;
        }

        return (string) $created->json('id');
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
        string $authError = 'HubSpot rejected the token. Check it has the `content` scope.',
    ): array {
        try {
            $pending = Http::timeout($timeout)->connectTimeout(8)
                ->withToken($token)
                ->acceptJson();
            $response = $method === 'get'
                ? $pending->get(self::BASE.$path, $payload)
                : $pending->{$method}(self::BASE.$path, $payload);
        } catch (\Throwable $e) {
            return [null, PublishResult::failure('Could not reach HubSpot: '.$e->getMessage(), transient: true)];
        }

        if (in_array($response->status(), [401, 403], true)) {
            return [null, PublishResult::failure($authError)];
        }
        if ($response->status() === 429) {
            return [null, PublishResult::failure('HubSpot rate limit hit.', transient: true)];
        }
        if ($response->failed()) {
            return [null, PublishResult::failure(
                'HubSpot returned HTTP '.$response->status().': '.mb_substr((string) $response->body(), 0, 200),
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
        $token = trim((string) ($creds['token'] ?? ''));

        return $token === ''
            ? ['', 'The HubSpot connection is missing its private app token.']
            : [$token, null];
    }
}
