<?php

namespace Serfix\ContentAi\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Serfix\ContentAi\ContentAiServiceProvider;

abstract class TestCase extends Orchestra
{
    protected string $secret = 'test-webhook-secret';

    /**
     * Routes are registered at BOOT, so anything affecting them has to be set
     * here rather than with config() inside a test. Subclasses override these
     * to exercise a different routing shape.
     */
    protected string $routePrefix = 'blog';

    protected bool $routesEnabled = true;

    protected function getPackageProviders($app): array
    {
        return [ContentAiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Signed preview URLs and the session middleware both need a key.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('content-ai.webhook.secret', $this->secret);
        $app['config']->set('content-ai.route.prefix', $this->routePrefix);
        $app['config']->set('content-ai.route.enabled', $this->routesEnabled);
        $app['config']->set('content-ai.images.localize', false);
        $app['config']->set('filesystems.disks.public', [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => 'http://localhost/storage',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * Post a payload signed exactly the way Serfix's WebhookDriver signs it:
     * HMAC over the RAW json body, never over a re-encoded array.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function deliver(array $payload, ?string $secret = null)
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->call(
            'POST',
            '/'.config('content-ai.webhook.path'),
            [], [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SERFIX_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, $secret ?? $this->secret),
            ],
            $body
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function articlePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'event' => 'article.published',
            'external_id' => null,
            'article' => [
                'h1' => 'Best PUBG Clan Names',
                'slug' => 'best-pubg-clan-names',
                'html' => '<p>Some article body.</p>',
                'markdown' => '# Best PUBG Clan Names',
                'meta_title' => 'Best PUBG Clan Names: 150+ Ideas',
                'meta_description' => 'A list of clan names that actually work.',
                'word_count' => 1200,
                'language' => 'en',
                'target_keyword' => 'pubg clan names',
                'secondary_keywords' => ['clan tags', 'squad names'],
            ],
            'sent_at' => now()->toIso8601String(),
        ], $overrides);
    }
}
