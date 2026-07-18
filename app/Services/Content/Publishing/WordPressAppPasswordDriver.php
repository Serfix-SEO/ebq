<?php

namespace App\Services\Content\Publishing;

use App\Models\ContentArticle;
use App\Models\ContentIntegration;
use App\Support\Audit\SafeHttpGuard;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * WordPress core REST API driver using an Application Password
 * (Users → Profile → Application Passwords) — the no-plugin publish path.
 *
 *  - verify:  GET  /wp-json/wp/v2/users/me   (Basic auth; needs edit_posts)
 *  - publish: POST /wp-json/wp/v2/posts
 *  - update:  POST /wp-json/wp/v2/posts/{id}
 *
 * Credentials shape: {site_url, username, app_password}.
 * Config knobs (all optional): {post_status: 'publish'|'draft'} — defaults
 * to publish. Featured-image sideload arrives with Phase 4 (no
 * content_images rows exist yet); html arrives with inline styling intact.
 */
class WordPressAppPasswordDriver implements PublishDriver
{
    public function __construct(private readonly SafeHttpGuard $guard) {}

    public function verify(ContentIntegration $integration): PublishResult
    {
        [$base, $auth, $err] = $this->connection($integration);
        if ($err !== null) {
            return PublishResult::failure($err);
        }

        try {
            $response = Http::timeout(20)->connectTimeout(8)
                ->withBasicAuth($auth['username'], $auth['app_password'])
                ->acceptJson()
                ->get($base.'/wp-json/wp/v2/users/me', ['context' => 'edit']);
        } catch (\Throwable $e) {
            return PublishResult::failure('Could not reach the site: '.$e->getMessage(), transient: true);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return PublishResult::failure('WordPress rejected the username or application password.');
        }
        if ($response->failed()) {
            return PublishResult::failure('WordPress returned HTTP '.$response->status().'.', transient: $response->serverError());
        }

        $caps = (array) ($response->json('capabilities') ?? []);
        if ($caps !== [] && empty($caps['edit_posts'])) {
            return PublishResult::failure('This WordPress user cannot create posts. Use an Author, Editor or Administrator account.');
        }

        return PublishResult::success((string) $response->json('id'), $base, ['user' => $response->json('name')]);
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
        [$base, $auth, $err] = $this->connection($integration);
        if ($err !== null) {
            return PublishResult::failure($err);
        }

        $config = (array) ($integration->config ?? []);
        $payload = [
            'title' => (string) ($article->meta_title ?: $article->h1),
            'content' => (string) $article->html,
            'slug' => (string) $article->slug,
            'status' => in_array($config['post_status'] ?? 'publish', ['publish', 'draft'], true)
                ? ($config['post_status'] ?? 'publish') : 'publish',
            'excerpt' => (string) ($article->meta_description ?? ''),
        ];

        $url = $base.'/wp-json/wp/v2/posts'.($externalId !== null ? '/'.rawurlencode($externalId) : '');

        try {
            $response = Http::timeout(45)->connectTimeout(8)
                ->withBasicAuth($auth['username'], $auth['app_password'])
                ->acceptJson()
                ->post($url, $payload);
        } catch (\Throwable $e) {
            return PublishResult::failure('Could not reach the site: '.$e->getMessage(), transient: true);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return PublishResult::failure('WordPress rejected the credentials during publish.');
        }
        if ($response->failed()) {
            return PublishResult::failure(
                'WordPress returned HTTP '.$response->status().': '.mb_substr((string) $response->body(), 0, 200),
                transient: $response->serverError(),
                response: ['status' => $response->status()],
            );
        }

        return PublishResult::success(
            (string) $response->json('id'),
            (string) ($response->json('link') ?? ''),
            ['status' => $response->json('status')],
        );
    }

    /**
     * @return array{0: ?string, 1: array{username: string, app_password: string}, 2: ?string}
     */
    private function connection(ContentIntegration $integration): array
    {
        $creds = $integration->credentials !== null ? $integration->credentials->toArray() : [];
        $siteUrl = rtrim(trim((string) ($creds['site_url'] ?? '')), '/');
        $username = trim((string) ($creds['username'] ?? ''));
        $password = trim((string) ($creds['app_password'] ?? ''));

        if ($siteUrl === '' || $username === '' || $password === '') {
            return [null, ['username' => '', 'app_password' => ''], 'The WordPress connection is missing its site URL, username, or application password.'];
        }
        if (! str_starts_with($siteUrl, 'http://') && ! str_starts_with($siteUrl, 'https://')) {
            $siteUrl = 'https://'.$siteUrl;
        }
        $check = $this->guard->check($siteUrl);
        if (! ($check['ok'] ?? false)) {
            return [null, ['username' => '', 'app_password' => ''], 'The site URL is not reachable from our servers.'];
        }

        return [$siteUrl, ['username' => $username, 'app_password' => $password], null];
    }
}
