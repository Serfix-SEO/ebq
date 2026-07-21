<?php

namespace Serfix\ContentAi\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Signs and posts a `verify` event at your OWN endpoint, exactly the way
 * Serfix would. Catches a wrong secret, a route behind auth middleware, or a
 * proxy stripping the signature header — without waiting for a real publish
 * to fail silently.
 */
class VerifyCommand extends Command
{
    protected $signature = 'content-ai:verify {--url= : Override the endpoint to test}';

    protected $description = 'Send a signed test delivery to this app’s Content AI webhook';

    public function handle(): int
    {
        $secret = (string) config('content-ai.webhook.secret');
        if ($secret === '') {
            $this->components->error('CONTENT_AI_WEBHOOK_SECRET is not set.');

            return self::FAILURE;
        }

        $url = (string) ($this->option('url')
            ?: url((string) config('content-ai.webhook.path', 'serfix/content-ai/webhook')));

        $body = (string) json_encode([
            'event' => 'verify',
            'message' => 'Local verification from content-ai:verify',
            'sent_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->components->info('POST '.$url);

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Serfix-Signature' => 'sha256='.hash_hmac('sha256', $body, $secret),
                ])
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (\Throwable $e) {
            $this->components->error('Could not reach the endpoint: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($response->successful()) {
            $this->components->info('HTTP '.$response->status().' — webhook is reachable and the signature verified.');

            return self::SUCCESS;
        }

        $this->components->error('HTTP '.$response->status().' — '.$response->body());
        $this->line('  401 → secret mismatch, or a proxy dropped X-Serfix-Signature.');
        $this->line('  419 → the route picked up CSRF middleware; keep it on the `api` group.');
        $this->line('  404 → route not registered (content-ai.webhook.enabled).');

        return self::FAILURE;
    }
}
