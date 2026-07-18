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

        // Detect the Serfix plugin so publish can fill its on-page SEO fields,
        // and cache the result on the integration (re-checked on every verify).
        $hasPlugin = $this->detectSeoPlugin($base);
        $integration->forceFill([
            'config' => ((array) ($integration->config ?? [])) + ['seo_plugin' => $hasPlugin],
        ])->save();

        return PublishResult::success((string) $response->json('id'), $base, [
            'user' => $response->json('name'),
            'seo_plugin' => $hasPlugin,
        ]);
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

        // Sideload generated images into the WP media library first: uploads
        // the featured image (→ featured_media) and every inline image,
        // rewriting inline <img src> from our storage URL to the WP-hosted
        // one so published posts never hotlink our disk. Best-effort — a
        // media failure leaves the post text intact.
        [$html, $featuredMediaId] = $this->sideloadImages($article, $base, $auth);

        $config = (array) ($integration->config ?? []);
        $payload = [
            'title' => (string) ($article->meta_title ?: $article->h1),
            'content' => $html,
            'slug' => (string) $article->slug,
            'status' => in_array($config['post_status'] ?? 'publish', ['publish', 'draft'], true)
                ? ($config['post_status'] ?? 'publish') : 'publish',
            'excerpt' => (string) ($article->meta_description ?? ''),
        ];
        if ($featuredMediaId !== null) {
            $payload['featured_media'] = $featuredMediaId;
        }

        // Populate the Serfix plugin's on-page SEO / self-check fields — but
        // ONLY when the plugin is present. WP rejects unregistered protected
        // (`_`-prefixed) meta, which would fail the whole publish on
        // plugin-less sites the app-password driver must still serve.
        $meta = $this->seoMeta($article, $integration, $base);
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

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
     * The Serfix plugin's on-page SEO / self-check meta fields (registered
     * show_in_rest), keyed off the article + its topic. Empty when the plugin
     * isn't detected on the target site.
     *
     * @return array<string, string>
     */
    private function seoMeta(ContentArticle $article, ContentIntegration $integration, ?string $base): array
    {
        $cfg = (array) ($integration->config ?? []);
        // Detect+cache lazily for integrations connected before this existed.
        if (! array_key_exists('seo_plugin', $cfg)) {
            $cfg['seo_plugin'] = $base !== null && $this->detectSeoPlugin($base);
            $integration->forceFill(['config' => $cfg])->save();
        }
        if (empty($cfg['seo_plugin'])) {
            return [];
        }

        $topic = $article->topic;
        $meta = array_filter([
            '_ebq_title' => (string) ($article->meta_title ?? ''),
            '_ebq_description' => (string) ($article->meta_description ?? ''),
            '_ebq_focus_keyword' => (string) ($topic?->target_keyword ?? ''),
        ], static fn (string $v): bool => $v !== '');

        $secondary = array_values(array_filter(array_map(
            static fn ($k): string => trim((string) $k),
            (array) ($topic?->secondary_keywords ?? [])
        )));
        if ($secondary !== []) {
            $meta['_ebq_additional_keywords'] = (string) json_encode(
                array_slice($secondary, 0, 5),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        }

        return $meta;
    }

    /** True when the Serfix WP plugin's REST namespace (ebq/v1) is present. */
    private function detectSeoPlugin(string $base): bool
    {
        try {
            $response = Http::timeout(10)->connectTimeout(6)->acceptJson()->get($base.'/wp-json/');
            if (! $response->ok()) {
                return false;
            }

            return in_array('ebq/v1', (array) ($response->json('namespaces') ?? []), true);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Upload generated images to the WP media library. Returns the article
     * HTML with inline <img src> rewritten to WP-hosted URLs, plus the
     * featured attachment id (or null). Fully best-effort — any failure
     * leaves that image as-is and never blocks the post.
     *
     * @param  array{username: string, app_password: string}  $auth
     * @return array{0: string, 1: ?int}
     */
    private function sideloadImages(ContentArticle $article, string $base, array $auth): array
    {
        $html = (string) $article->html;
        $featuredId = null;

        $images = $article->images()->where('status', \App\Models\ContentImage::STATUS_GENERATED)->get();
        foreach ($images as $image) {
            $localUrl = $image->url();
            $bytes = $image->disk_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($image->disk_path)
                ? \Illuminate\Support\Facades\Storage::disk('public')->get($image->disk_path)
                : null;
            if ($bytes === null) {
                continue;
            }

            $media = $this->uploadMedia($base, $auth, $bytes, (string) ($image->filename ?: 'image.png'), (string) ($image->alt_text ?? ''));
            if ($media === null) {
                continue;
            }

            if ($image->role === \App\Models\ContentImage::ROLE_FEATURED) {
                $featuredId = $media['id'];
            }
            // Rewrite the inline (and featured, harmlessly) src to the WP URL.
            if ($localUrl !== null && ! empty($media['url'])) {
                $html = str_replace($localUrl, $media['url'], $html);
            }
        }

        return [$html, $featuredId];
    }

    /**
     * POST raw bytes to /wp/v2/media; set alt text; return {id, url}.
     *
     * @param  array{username: string, app_password: string}  $auth
     * @return array{id:int, url:string}|null
     */
    private function uploadMedia(string $base, array $auth, string $bytes, string $filename, string $alt): ?array
    {
        try {
            $response = Http::timeout(60)->connectTimeout(8)
                ->withBasicAuth($auth['username'], $auth['app_password'])
                ->withHeaders([
                    'Content-Type' => 'image/png',
                    'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $filename).'"',
                ])
                ->withBody($bytes, 'image/png')
                ->post($base.'/wp-json/wp/v2/media');

            if ($response->failed() || ! $response->json('id')) {
                return null;
            }
            $id = (int) $response->json('id');
            $url = (string) ($response->json('source_url') ?? '');

            if ($alt !== '') {
                // Best-effort alt for the media library entry.
                Http::timeout(20)->withBasicAuth($auth['username'], $auth['app_password'])
                    ->acceptJson()->post($base.'/wp-json/wp/v2/media/'.$id, ['alt_text' => $alt]);
            }

            return ['id' => $id, 'url' => $url];
        } catch (\Throwable) {
            return null;
        }
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
