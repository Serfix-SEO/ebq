<?php

namespace App\Services\Content\Publishing;

use App\Models\ContentArticle;
use App\Models\ContentIntegration;
use App\Support\Audit\SafeHttpGuard;
use Illuminate\Support\Facades\Http;

/**
 * Generic outbound-webhook driver: POSTs the full article JSON to the
 * client's endpoint, signed `X-Serfix-Signature: sha256=<hmac>` over the raw
 * body with the per-integration secret (the exact HMAC convention our own
 * keyword-finder webhook receiver verifies — symmetric, easy to document).
 *
 * The receiver must answer 2xx; it MAY return `{"url": "..."}` so we can
 * link + verify the live page. In-request retry is deliberately absent —
 * PublishArticleJob owns retries (tries=3, backoff) so webhook and
 * WordPress failures behave identically.
 *
 * Credentials shape: {endpoint_url, secret}.
 */
class WebhookDriver implements PublishDriver
{
    public function __construct(private readonly SafeHttpGuard $guard) {}

    public function verify(ContentIntegration $integration): PublishResult
    {
        [$endpoint, $secret, $err] = $this->connection($integration);
        if ($err !== null) {
            return PublishResult::failure($err);
        }

        $result = $this->post($endpoint, $secret, [
            'event' => 'verify',
            'message' => 'Connection test from your content platform. Reply with HTTP 2xx to confirm.',
            'sent_at' => now()->toIso8601String(),
        ]);

        return $result->ok ? PublishResult::success(null, $endpoint) : $result;
    }

    public function publish(ContentArticle $article, ContentIntegration $integration): PublishResult
    {
        return $this->push($article, $integration, null);
    }

    public function update(ContentArticle $article, ContentIntegration $integration, string $externalId): PublishResult
    {
        return $this->push($article, $integration, $externalId);
    }

    /**
     * Send a clearly-marked SAMPLE article through the real delivery path —
     * same payload shape, same HMAC signature — so the client can test their
     * receiver end-to-end from the integrations page. A receiver that stores
     * it will create a post titled "Serfix test article"; the `test: true`
     * flag lets receivers skip storing instead. Response is surfaced
     * verbatim to the client (status + returned url), because "verify says
     * OK but articles vanish" is exactly the failure this exists to catch.
     */
    public function testDelivery(ContentIntegration $integration): PublishResult
    {
        [$endpoint, $secret, $err] = $this->connection($integration);
        if ($err !== null) {
            return PublishResult::failure($err);
        }

        $html = '<h2>This is a test delivery</h2>'
            .'<p>Serfix sent this sample article to confirm your webhook receiver stores and displays articles correctly. '
            .'It is <strong>safe to delete</strong>.</p>'
            .'<ul><li>If you can see this as a post on your site, your integration works end to end.</li>'
            .'<li>If your endpoint answered OK but no post appeared, your receiver accepts deliveries without storing them.</li></ul>';

        $payload = [
            'event' => 'article.published',
            'external_id' => null,
            'test' => true,
            'article' => [
                'h1' => 'Serfix test article — safe to delete',
                'slug' => 'serfix-connection-test',
                'html' => $html,
                'markdown' => '',
                'meta_title' => 'Serfix test article — safe to delete',
                'meta_description' => 'A sample delivery sent from the Serfix integrations page. Safe to delete.',
                'word_count' => 60,
                'language' => 'en',
                'target_keyword' => 'serfix connection test',
                'secondary_keywords' => [],
                'focus_keyword' => 'serfix connection test',
                'canonical_url' => '',
                'robots_noindex' => true,
                'robots_nofollow' => true,
                'og_title' => '',
                'og_description' => '',
                'og_image' => '',
                'twitter_title' => '',
                'twitter_description' => '',
                'twitter_image' => '',
                'twitter_card' => '',
            ],
            'sent_at' => now()->toIso8601String(),
        ];

        $result = $this->post($endpoint, $secret, $payload);
        if (! $result->ok) {
            return $result;
        }

        return PublishResult::success(
            (string) ($result->response['id'] ?? ''),
            (string) ($result->response['url'] ?? '') ?: null,
            $result->response,
        );
    }

    // ── internals ───────────────────────────────────────────────────────

    private function push(ContentArticle $article, ContentIntegration $integration, ?string $externalId): PublishResult
    {
        [$endpoint, $secret, $err] = $this->connection($integration);
        if ($err !== null) {
            return PublishResult::failure($err);
        }

        $topic = $article->topic;
        $payload = [
            'event' => $externalId !== null ? 'article.updated' : 'article.published',
            'external_id' => $externalId,
            'article' => [
                'h1' => (string) $article->h1,
                'slug' => (string) $article->slug,
                'html' => (string) $article->html,
                'markdown' => (string) ($article->markdown ?? ''),
                'meta_title' => (string) ($article->meta_title ?? ''),
                'meta_description' => (string) ($article->meta_description ?? ''),
                'word_count' => (int) ($article->word_count ?? 0),
                'language' => (string) ($topic?->plan?->language ?? 'en'),
                'target_keyword' => (string) ($topic?->target_keyword ?? ''),
                'secondary_keywords' => array_values((array) ($topic?->secondary_keywords ?? [])),
                // Per-article SEO overrides set in the in-app editor. focus_keyword
                // prefers the article override, falling back to the topic keyword.
                'focus_keyword' => (string) ($article->focus_keyword ?: ($topic?->target_keyword ?? '')),
                'canonical_url' => (string) ($article->canonical_url ?? ''),
                'robots_noindex' => (bool) $article->robots_noindex,
                'robots_nofollow' => (bool) $article->robots_nofollow,
                'og_title' => (string) ($article->og_title ?? ''),
                'og_description' => (string) ($article->og_description ?? ''),
                'og_image' => (string) ($article->og_image ?? ''),
                'twitter_title' => (string) ($article->twitter_title ?? ''),
                'twitter_description' => (string) ($article->twitter_description ?? ''),
                'twitter_image' => (string) ($article->twitter_image ?? ''),
                'twitter_card' => (string) ($article->twitter_card ?? ''),
            ],
            'sent_at' => now()->toIso8601String(),
        ];

        $result = $this->post($endpoint, $secret, $payload);
        if (! $result->ok) {
            return $result;
        }

        // The receiver's {"url": ...} is the REAL public URL — required for
        // auto-share, Google indexing and rank tracking. When a hand-rolled
        // endpoint answers a bare 200, fall back to the article's own
        // canonical_url (client-authored, also real). NEVER guess base+slug:
        // a wrong link would be shared publicly and submitted to Google.
        $returnedUrl = (string) ($result->response['url'] ?? '');
        if ($returnedUrl === '') {
            $returnedUrl = trim((string) ($article->canonical_url ?? ''));
        }

        return PublishResult::success(
            // Receivers without ids: reuse the slug so retries route via update().
            $externalId ?? ($result->response['id'] ?? $article->slug),
            $returnedUrl !== '' ? $returnedUrl : null,
            $result->response,
        );
    }

    private function post(string $endpoint, string $secret, array $payload): PublishResult
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signature = 'sha256='.hash_hmac('sha256', $body, $secret);

        try {
            $response = Http::timeout(30)->connectTimeout(8)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Serfix-Signature' => $signature,
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint);
        } catch (\Throwable $e) {
            return PublishResult::failure('Could not reach the webhook endpoint: '.$e->getMessage(), transient: true);
        }

        if ($response->failed()) {
            return PublishResult::failure(
                'The webhook endpoint returned HTTP '.$response->status().'.',
                transient: $response->serverError() || $response->status() === 429,
                response: ['status' => $response->status()],
            );
        }

        $json = $response->json();

        return PublishResult::success(null, null, is_array($json) ? $json : []);
    }

    /**
     * @return array{0: string, 1: string, 2: ?string}
     */
    private function connection(ContentIntegration $integration): array
    {
        $creds = $integration->credentials !== null ? $integration->credentials->toArray() : [];
        $endpoint = trim((string) ($creds['endpoint_url'] ?? ''));
        $secret = trim((string) ($creds['secret'] ?? ''));

        if ($endpoint === '' || $secret === '') {
            return ['', '', 'The webhook connection is missing its endpoint URL or signing secret.'];
        }
        // Enforced HERE as well as in the connect form: the HMAC prevents
        // forgery, not disclosure, so plain http would ship every article in
        // cleartext. SafeHttpGuard permits http (it guards against SSRF, not
        // eavesdropping), and rows can be created outside the form — admin
        // tooling, a seeder, a future API — so the transport rule belongs on
        // the path that actually sends.
        if (! str_starts_with(strtolower($endpoint), 'https://')) {
            return ['', '', 'The webhook endpoint must use https:// — articles are sent over the public internet.'];
        }
        $check = $this->guard->check($endpoint);
        if (! ($check['ok'] ?? false)) {
            return ['', '', 'The webhook URL is not reachable from our servers.'];
        }

        return [$endpoint, $secret, null];
    }
}
