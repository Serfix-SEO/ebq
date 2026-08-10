<?php

namespace App\Services\Content\Publishing;

use App\Models\ContentArticle;
use App\Models\ContentImage;
use App\Models\ContentIntegration;
use App\Support\Audit\SafeHttpGuard;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Shopify Online Store blog driver via the GraphQL Admin API (the REST Admin
 * API is legacy and closed to new custom apps). The customer creates a custom
 * app (Settings → Apps and sales channels → Develop apps) with the
 * read_content + write_content scopes and pastes the shpat_… admin token.
 *
 *  - verify:  shop + blogs query (also caches the blog picker options)
 *  - publish: articleCreate mutation
 *  - update:  articleUpdate mutation
 *
 * Credentials: {store_domain, access_token}. Config: {blog_id, blog_handle,
 * shop_url, available_blogs, post_status}. The featured image is passed as a
 * URL Shopify fetches itself; inline <img> keep our public storage URLs.
 */
class ShopifyDriver implements ProvidesTargets, PublishDriver
{
    public const API_VERSION = '2026-07';

    public function __construct(private readonly SafeHttpGuard $guard) {}

    public function verify(ContentIntegration $integration): PublishResult
    {
        [$endpoint, $token, $err] = $this->connection($integration);
        if ($err !== null) {
            return PublishResult::failure($err);
        }

        $query = '{ shop { name primaryDomain { url } } blogs(first: 50) { nodes { id title handle } } }';
        [$response, $failure] = $this->graphql($endpoint, $token, $query, [], 'Shopify rejected the access token. Check the token and the read_content + write_content scopes.');
        if ($failure !== null) {
            return $failure;
        }

        $blogs = [];
        foreach ((array) $response->json('data.blogs.nodes', []) as $node) {
            $blogs[] = [
                'id' => (string) ($node['id'] ?? ''),
                'label' => (string) ($node['title'] ?? ''),
                'handle' => (string) ($node['handle'] ?? ''),
            ];
        }
        if ($blogs === []) {
            return PublishResult::failure('This Shopify store has no blog yet. Create one under Online Store → Blog posts → Manage blogs first.');
        }

        $shopUrl = rtrim((string) $response->json('data.shop.primaryDomain.url', ''), '/');
        $config = (array) ($integration->config ?? []);
        $config['shop_url'] = $shopUrl;
        $config['available_blogs'] = $blogs;
        // A previously chosen blog that no longer exists must be re-chosen.
        if (! in_array($config['blog_id'] ?? null, array_column($blogs, 'id'), true)) {
            unset($config['blog_id'], $config['blog_handle']);
        }
        $integration->forceFill(['config' => $config])->save();

        return PublishResult::success(null, $shopUrl, [
            'shop' => $response->json('data.shop.name'),
            'blogs' => count($blogs),
        ]);
    }

    public function targets(ContentIntegration $integration): array
    {
        $config = (array) ($integration->config ?? []);
        if (! empty($config['blog_id'])) {
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
                $config['blog_id'] = $blog['id'];
                $config['blog_handle'] = $blog['handle'] ?? '';
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
        [$endpoint, $token, $err] = $this->connection($integration);
        if ($err !== null) {
            return PublishResult::failure($err);
        }

        $config = (array) ($integration->config ?? []);
        if (empty($config['blog_id'])) {
            return PublishResult::failure('No Shopify blog selected. Open Settings → Integrations and finish the Shopify connection.');
        }

        $isPublished = ($config['post_status'] ?? 'publish') !== 'draft';

        $input = [
            'title' => (string) ($article->meta_title ?: $article->h1),
            'body' => (string) $article->html,
            'handle' => (string) $article->slug,
            'summary' => (string) ($article->meta_description ?? ''),
            'isPublished' => $isPublished,
        ];

        $tags = array_values(array_filter(array_map(
            static fn ($k): string => trim((string) $k),
            (array) ($article->topic?->secondary_keywords ?? [])
        )));
        if ($tags !== []) {
            $input['tags'] = array_slice($tags, 0, 5);
        }

        $featured = $this->featuredImage($article);
        if ($featured !== null) {
            $input['image'] = array_filter([
                'url' => $featured['url'],
                'altText' => $featured['alt'],
            ], static fn ($v) => $v !== '');
        }

        if ($externalId === null) {
            $input['blogId'] = (string) $config['blog_id'];
            $query = 'mutation articleCreate($article: ArticleCreateInput!) {
                articleCreate(article: $article) {
                    article { id handle blog { handle } }
                    userErrors { field message }
                }
            }';
            $variables = ['article' => $input];
            $root = 'articleCreate';
        } else {
            $query = 'mutation articleUpdate($id: ID!, $article: ArticleUpdateInput!) {
                articleUpdate(id: $id, article: $article) {
                    article { id handle blog { handle } }
                    userErrors { field message }
                }
            }';
            $variables = ['id' => $externalId, 'article' => $input];
            $root = 'articleUpdate';
        }

        [$response, $failure] = $this->graphql($endpoint, $token, $query, $variables, 'Shopify rejected the credentials during publish.', timeout: 45);
        if ($failure !== null) {
            return $failure;
        }

        $userErrors = (array) $response->json("data.$root.userErrors", []);
        if ($userErrors !== []) {
            $messages = implode('; ', array_map(
                static fn ($e): string => (string) ($e['message'] ?? ''),
                $userErrors,
            ));

            return PublishResult::failure('Shopify rejected the article: '.mb_substr($messages, 0, 200), response: ['userErrors' => $userErrors]);
        }

        $id = (string) $response->json("data.$root.article.id", '');
        if ($id === '') {
            return PublishResult::failure('Shopify returned no article id.', transient: true);
        }

        $url = null;
        $shopUrl = rtrim((string) ($config['shop_url'] ?? ''), '/');
        $blogHandle = (string) ($response->json("data.$root.article.blog.handle") ?? $config['blog_handle'] ?? '');
        $articleHandle = (string) ($response->json("data.$root.article.handle") ?? '');
        if ($isPublished && $shopUrl !== '' && $blogHandle !== '' && $articleHandle !== '') {
            // Deterministic Online Store route for blog articles.
            $url = "$shopUrl/blogs/$blogHandle/$articleHandle";
        }

        return PublishResult::success($id, $url, ['handle' => $articleHandle]);
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
     * Run one GraphQL call and translate transport/auth/throttle problems
     * into PublishResults. Returns [response, null] on success.
     *
     * @param  array<string, mixed>  $variables
     * @return array{0: ?Response, 1: ?PublishResult}
     */
    private function graphql(string $endpoint, string $token, string $query, array $variables, string $authError, int $timeout = 20): array
    {
        try {
            $response = Http::timeout($timeout)->connectTimeout(8)
                ->withHeaders(['X-Shopify-Access-Token' => $token])
                ->acceptJson()
                ->post($endpoint, ['query' => $query, 'variables' => $variables === [] ? (object) [] : $variables]);
        } catch (\Throwable $e) {
            return [null, PublishResult::failure('Could not reach Shopify: '.$e->getMessage(), transient: true)];
        }

        if (in_array($response->status(), [401, 403], true)) {
            return [null, PublishResult::failure($authError)];
        }
        if ($response->status() === 429) {
            return [null, PublishResult::failure('Shopify rate limit hit.', transient: true)];
        }
        if ($response->failed()) {
            return [null, PublishResult::failure(
                'Shopify returned HTTP '.$response->status().': '.mb_substr((string) $response->body(), 0, 200),
                transient: $response->serverError(),
                response: ['status' => $response->status()],
            )];
        }

        // Top-level GraphQL errors (invalid token often surfaces here, and
        // throttling always does).
        $errors = (array) $response->json('errors', []);
        if ($errors !== []) {
            $codes = array_map(static fn ($e) => $e['extensions']['code'] ?? null, $errors);
            if (in_array('THROTTLED', $codes, true)) {
                return [null, PublishResult::failure('Shopify rate limit hit.', transient: true)];
            }
            if (in_array('ACCESS_DENIED', $codes, true) || in_array('UNAUTHENTICATED', $codes, true)) {
                return [null, PublishResult::failure($authError)];
            }
            $message = (string) ($errors[0]['message'] ?? 'GraphQL error');

            return [null, PublishResult::failure('Shopify error: '.mb_substr($message, 0, 200), response: ['errors' => $errors])];
        }

        return [$response, null];
    }

    /** @return array{0: ?string, 1: string, 2: ?string} [graphql endpoint, token, error] */
    private function connection(ContentIntegration $integration): array
    {
        $creds = $integration->credentials !== null ? $integration->credentials->toArray() : [];
        $domain = strtolower(trim((string) ($creds['store_domain'] ?? '')));
        $token = trim((string) ($creds['access_token'] ?? ''));

        // Accept a pasted URL or bare domain.
        $domain = preg_replace('#^https?://#', '', rtrim($domain, '/')) ?? $domain;
        $domain = explode('/', $domain)[0];

        if ($domain === '' || $token === '') {
            return [null, '', 'The Shopify connection is missing its store domain or access token.'];
        }
        if (preg_match('/^[a-z0-9][a-z0-9.-]*\.myshopify\.com$/', $domain) !== 1) {
            return [null, '', 'Enter your permanent store domain ending in .myshopify.com (found under Settings → Domains).'];
        }
        $check = $this->guard->check('https://'.$domain);
        if (! ($check['ok'] ?? false)) {
            return [null, '', 'The store domain is not reachable from our servers.'];
        }

        return ['https://'.$domain.'/admin/api/'.self::API_VERSION.'/graphql.json', $token, null];
    }
}
