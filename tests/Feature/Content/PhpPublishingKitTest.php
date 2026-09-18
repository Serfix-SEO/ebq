<?php

namespace Tests\Feature\Content;

use App\Models\ContentArticle;
use App\Models\ContentIntegration;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\Publishing\PhpKitBuilder;
use App\Services\Content\Publishing\WebhookDriver;
use App\Support\Audit\SafeHttpGuard;
use GuzzleHttp\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

/**
 * The PHP publishing kit, run for real.
 *
 * The kit is PHP that executes on a customer's web host, so mocking it would
 * prove nothing. These tests unzip the kit exactly as a customer would, serve
 * it with PHP's built-in server, and drive it with the REAL WebhookDriver: the
 * genuine payload, the genuine HMAC signature. Only the network hop is
 * redirected (https://client.test → the local server), because the driver
 * refuses non-https endpoints and private addresses — correctly.
 *
 * Built for safibusinessservice.com (support ticket 2026-09-13): a paying
 * client on plain PHP hosting who could not write a webhook receiver and had
 * nothing published for a week.
 */
class PhpPublishingKitTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private string $docroot;

    private string $imageRoot;

    private int $sitePort;

    private int $imagePort;

    /** @var list<Process> */
    private array $servers = [];

    private ContentIntegration $integration;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create(['domain' => 'client.test', 'normalized_domain' => 'client.test']);
        $this->secret = Str::random(48);
        $this->integration = ContentIntegration::create([
            'website_id' => $website->id,
            'platform' => ContentIntegration::PLATFORM_WEBHOOK,
            'status' => ContentIntegration::STATUS_PENDING,
            'credentials' => ['endpoint_url' => 'https://client.test/serfix/receiver.php', 'secret' => $this->secret],
            'config' => ['flavor' => 'php'],
        ]);

        // Unzip the kit exactly as the customer receives it.
        $this->docroot = sys_get_temp_dir().'/serfix-kit-site-'.Str::random(8);
        mkdir($this->docroot);
        $zipPath = $this->docroot.'.zip';
        file_put_contents($zipPath, app(PhpKitBuilder::class)->build($this->integration));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $zip->extractTo($this->docroot);
        $zip->close();
        unlink($zipPath);

        // A stand-in for Serfix object storage, serving one real PNG.
        $this->imageRoot = sys_get_temp_dir().'/serfix-kit-images-'.Str::random(8);
        mkdir($this->imageRoot);
        file_put_contents($this->imageRoot.'/pic.png', base64_decode(self::PNG));

        $this->imagePort = $this->serve($this->imageRoot);
        $this->sitePort = $this->serve($this->docroot);

        // Test-only settings: the local image server is http on a port, and
        // the built-in server never honours .htaccess, so pretty URLs are off.
        $this->setKitConfig([
            'image_hosts' => ['127.0.0.1:'.$this->imagePort],
            'image_allow_http' => true,
            'pretty_urls' => false,
        ]);

        // Let the driver "reach" https://client.test, then forward every
        // request, byte for byte, to the kit on the local server.
        $this->app->instance(SafeHttpGuard::class, new class extends SafeHttpGuard
        {
            public function check(string $url): array
            {
                return ['ok' => true];
            }
        });
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $response = $this->client()->post('http://127.0.0.1:'.$this->sitePort.$path, [
                'body' => $request->body(),
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Serfix-Signature' => $request->header('X-Serfix-Signature')[0] ?? '',
                ],
            ]);

            return Http::response((string) $response->getBody(), $response->getStatusCode(), ['Content-Type' => 'application/json']);
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop(0);
        }
        foreach ([$this->docroot ?? null, $this->imageRoot ?? null] as $dir) {
            if ($dir !== null && is_dir($dir)) {
                (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($dir);
            }
        }
        parent::tearDown();
    }

    // ── harness ────────────────────────────────────────────────────────

    private function serve(string $root): int
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = random_int(20000, 39999);
            $server = new Process([PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', $root]);
            $server->start();
            for ($i = 0; $i < 50; $i++) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if ($socket !== false) {
                    fclose($socket);
                    $this->servers[] = $server;

                    return $port;
                }
                if (! $server->isRunning()) {
                    break;
                }
                usleep(50_000);
            }
            $server->stop(0);
        }
        $this->fail('Could not start a PHP built-in server.');
    }

    private function client(): Client
    {
        return new Client(['http_errors' => false, 'allow_redirects' => false, 'timeout' => 20]);
    }

    /** @param array<string, mixed> $overrides */
    private function setKitConfig(array $overrides): void
    {
        $path = $this->docroot.'/serfix/config.php';
        $config = array_merge(include $path, $overrides);
        file_put_contents($path, '<?php return '.var_export($config, true).';');
    }

    private function article(string $slug, string $html, string $h1 = 'How Virtual Ejari Works in Dubai'): ContentArticle
    {
        $plan = ContentPlan::factory()->create(['website_id' => $this->integration->website_id]);
        $topic = ContentTopic::factory()->create(['plan_id' => $plan->id]);

        return ContentArticle::create([
            'topic_id' => $topic->id,
            'version' => 1,
            'is_current' => true,
            'h1' => $h1,
            'slug' => $slug,
            'html' => $html,
            'meta_title' => 'Virtual Ejari in Dubai: A Practical Guide',
            'meta_description' => 'What Virtual Ejari is, who needs it and how to get one in Dubai.',
            'word_count' => 1200,
        ]);
    }

    private function driver(): WebhookDriver
    {
        return app(WebhookDriver::class);
    }

    private function page(string $pathAndQuery): \Psr\Http\Message\ResponseInterface
    {
        return $this->client()->get('http://127.0.0.1:'.$this->sitePort.$pathAndQuery);
    }

    /** @param array<string, mixed> $payload */
    private function signedPost(array $payload, ?string $secret = null): \Psr\Http\Message\ResponseInterface
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->client()->post('http://127.0.0.1:'.$this->sitePort.'/serfix/receiver.php', [
            'body' => $body,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Serfix-Signature' => 'sha256='.hash_hmac('sha256', $body, $secret ?? $this->secret),
            ],
        ]);
    }

    /** @return list<string> */
    private function storedPosts(): array
    {
        return glob($this->docroot.'/serfix/data/posts/*.json') ?: [];
    }

    // ── the happy path ─────────────────────────────────────────────────

    public function test_verify_succeeds_and_falls_back_to_query_urls_when_pretty_urls_are_unproven(): void
    {
        // 'auto': the receiver must PROVE rewrites work before using them.
        // client.test cannot be reached, so it must choose query URLs.
        $this->setKitConfig(['pretty_urls' => 'auto']);

        $result = $this->driver()->verify($this->integration);

        $this->assertTrue($result->ok, (string) $result->error);
        $settings = json_decode((string) file_get_contents($this->docroot.'/serfix/data/settings.json'), true);
        $this->assertFalse($settings['pretty_urls'], 'an unproven rewrite must never produce pretty links');
    }

    public function test_a_published_article_becomes_a_real_seo_page_on_the_clients_domain(): void
    {
        $html = '<p>Intro paragraph.</p>'
            .'<figure><img src="http://127.0.0.1:'.$this->imagePort.'/pic.png" alt="Office"></figure>'
            .'<h2>Who needs it</h2><p>Businesses renewing a trade licence.</p>';

        $result = $this->driver()->publish($this->article('virtual-ejari-dubai', $html), $this->integration);

        $this->assertTrue($result->ok, (string) $result->error);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', (string) $result->externalId, 'the kit hands back a stable id');
        $this->assertSame('https://client.test/articles/?post=virtual-ejari-dubai', $result->externalUrl,
            'the live URL comes from the kit, so indexing and rank tracking work');

        // Stored, with the image copied onto the client's own host.
        $this->assertCount(1, $this->storedPosts());
        $post = json_decode((string) file_get_contents($this->storedPosts()[0]), true);
        $this->assertStringContainsString('https://client.test/serfix/media/'.$result->externalId.'/', $post['html']);
        $this->assertStringNotContainsString('127.0.0.1', $post['html'], 'the image must no longer hotlink Serfix storage');
        $this->assertCount(1, glob($this->docroot.'/serfix/media/'.$result->externalId.'/*.png'));

        // Served as a real page with the tags search engines read.
        $page = $this->page('/articles/?post=virtual-ejari-dubai');
        $body = (string) $page->getBody();
        $this->assertSame(200, $page->getStatusCode());
        $this->assertStringContainsString('<h1>How Virtual Ejari Works in Dubai</h1>', $body);
        $this->assertStringContainsString('<title>Virtual Ejari in Dubai: A Practical Guide | client.test</title>', $body);
        $this->assertStringContainsString('<link rel="canonical" href="https://client.test/articles/?post=virtual-ejari-dubai">', $body);
        $this->assertStringContainsString('<meta name="robots" content="index, follow">', $body);
        $this->assertStringContainsString('application/ld+json', $body);
        $this->assertStringContainsString('"@type":"BlogPosting"', $body);
        $this->assertStringContainsString('Who needs it', $body);

        // Listed, and in the sitemap.
        $this->assertStringContainsString('How Virtual Ejari Works in Dubai', (string) $this->page('/articles/')->getBody());
        $this->assertStringContainsString(
            '<loc>https://client.test/articles/?post=virtual-ejari-dubai</loc>',
            (string) $this->page('/articles/?sitemap=1')->getBody(),
        );
    }

    /**
     * A rewritten article can come back with a new slug. Keyed by the id the
     * kit handed out, it must update the SAME post — and the old address must
     * redirect — rather than leave an orphan duplicate live, which is exactly
     * what happened on serfix.io's own blog on 2026-09-15/16.
     */
    public function test_a_changed_slug_updates_the_same_post_and_redirects_the_old_address(): void
    {
        $article = $this->article('old-slug', '<p>First version.</p>');
        $first = $this->driver()->publish($article, $this->integration);

        $article->forceFill(['slug' => 'new-slug', 'html' => '<p>Second version.</p>'])->save();
        $second = $this->driver()->update($article->fresh(), $this->integration, (string) $first->externalId);

        $this->assertTrue($second->ok, (string) $second->error);
        $this->assertSame($first->externalId, $second->externalId);
        $this->assertCount(1, $this->storedPosts(), 'no duplicate post');
        $this->assertStringContainsString('Second version.', (string) $this->page('/articles/?post=new-slug')->getBody());

        $old = $this->page('/articles/?post=old-slug');
        $this->assertSame(301, $old->getStatusCode());
        $this->assertSame('https://client.test/articles/?post=new-slug', $old->getHeaderLine('Location'));
    }

    /**
     * The "Send test article" button: prove the kit could store it, without
     * leaving a post the customer has no way to delete.
     */
    public function test_a_test_delivery_is_accepted_but_never_stored(): void
    {
        $result = $this->driver()->testDelivery($this->integration);

        $this->assertTrue($result->ok, (string) $result->error);
        $this->assertSame('https://client.test/articles/', $result->externalUrl);
        $this->assertSame([], $this->storedPosts());
    }

    public function test_draft_articles_are_stored_but_not_public(): void
    {
        $this->integration->forceFill(['config' => ['flavor' => 'php', 'post_status' => 'draft']])->save();

        $result = $this->driver()->publish($this->article('draft-post', '<p>Not yet.</p>'), $this->integration->fresh());

        $this->assertTrue($result->ok);
        $this->assertCount(1, $this->storedPosts());
        $this->assertSame(404, $this->page('/articles/?post=draft-post')->getStatusCode());
        $this->assertStringNotContainsString('draft-post', (string) $this->page('/articles/?sitemap=1')->getBody());
    }

    /**
     * The self-check endpoint the receiver probes at verify time. The built-in
     * server ignores .htaccess, so the rewrite itself is exercised on real
     * hosting; this pins the answer the probe looks for.
     */
    public function test_the_pretty_url_self_check_answers_with_its_marker(): void
    {
        $this->assertSame('serfix-rewrite-ok', (string) $this->page('/articles/?post=serfix-rewrite-check')->getBody());
    }

    public function test_proven_pretty_urls_are_what_gets_reported_back(): void
    {
        $this->setKitConfig(['pretty_urls' => true]);

        $result = $this->driver()->publish($this->article('pretty-post', '<p>x</p>'), $this->integration);

        $this->assertSame('https://client.test/articles/pretty-post', $result->externalUrl);
    }

    public function test_opening_the_receiver_in_a_browser_confirms_it_is_installed(): void
    {
        $response = $this->page('/serfix/receiver.php');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('receiver is installed', (string) $response->getBody());
    }

    // ── what must be refused ───────────────────────────────────────────

    public function test_a_wrong_signature_is_refused_and_nothing_is_written(): void
    {
        $response = $this->signedPost([
            'event' => 'article.published',
            'article' => ['slug' => 'forged', 'h1' => 'Forged', 'html' => '<p>x</p>'],
            'sent_at' => now()->toIso8601String(),
        ], secret: Str::random(48));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([], $this->storedPosts());
    }

    public function test_a_replayed_old_delivery_is_refused(): void
    {
        $response = $this->signedPost([
            'event' => 'article.published',
            'article' => ['slug' => 'replayed', 'h1' => 'Replayed', 'html' => '<p>x</p>'],
            'sent_at' => now()->subHour()->toIso8601String(),
        ]);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([], $this->storedPosts());
    }

    /** The slug names a file on the customer's disk: traversal must be impossible. */
    public function test_a_path_traversal_slug_is_refused(): void
    {
        // (Uppercase is not in this list: the kit lowercases slugs first, which
        // is harmless normalisation, not a way out of the whitelist.)
        foreach (['../../config', 'a/b', 'with space', 'dots.php', '%2e%2e', 'serfix-rewrite-check'] as $slug) {
            $response = $this->signedPost([
                'event' => 'article.published',
                'article' => ['slug' => $slug, 'h1' => 'x', 'html' => '<p>x</p>'],
                'sent_at' => now()->toIso8601String(),
            ]);
            $this->assertSame(422, $response->getStatusCode(), "slug {$slug} must be refused");
        }
        $this->assertSame([], $this->storedPosts());
    }

    /**
     * Only Serfix's own image hosts are fetched — otherwise a forged payload
     * could make the customer's server request internal addresses. Executable
     * markup is stripped as a second line behind the signature.
     */
    public function test_foreign_images_are_not_fetched_and_scripts_are_stripped(): void
    {
        $html = '<p onclick="steal()">Hello</p>'
            .'<script>alert(1)</script>'
            .'<img src="https://attacker.example/x.png">'
            .'<a href="javascript:alert(1)">link</a>';

        $result = $this->driver()->publish($this->article('safe-post', $html), $this->integration);
        $post = json_decode((string) file_get_contents($this->storedPosts()[0]), true);

        $this->assertTrue($result->ok);
        $this->assertStringContainsString('https://attacker.example/x.png', $post['html'], 'a foreign image is left alone, never downloaded');
        $this->assertSame([], glob($this->docroot.'/serfix/media/'.$result->externalId.'/*') ?: []);
        $this->assertStringNotContainsString('<script', $post['html']);
        $this->assertStringNotContainsString('onclick', $post['html']);
        $this->assertStringNotContainsString('javascript:', $post['html']);
    }
}
