<?php

namespace Tests\Feature\Aeo;

use App\Models\ContentAeoBotHit;
use App\Models\ContentIntegration;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The AI-crawler hit ingest — the one first-party signal in AI Visibility.
 *
 * Two reporters with two different proofs of identity (the WordPress plugin's
 * Sanctum token, the PHP kit's HMAC secret), and one rule they share: a
 * reporter that could not confirm delivery re-sends the same day, so ingest
 * must never double-count.
 */
class AeoBotHitIngestTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Website, string} */
    private function pluginSite(): array
    {
        $website = Website::factory()->for(User::factory())->create();

        return [$website, $website->createToken('test', ['read:insights'])->plainTextToken];
    }

    public function test_the_plugin_reports_a_days_crawler_activity(): void
    {
        [$website, $token] = $this->pluginSite();

        $this->withToken($token)->postJson('/api/v1/aeo/bot-hits', [
            'days' => [
                ['date' => now()->subDay()->toDateString(), 'bot' => 'GPTBot/1.1', 'hits' => 42, 'pages' => 12, 'sample_path' => '/blog/oud'],
                ['date' => now()->subDay()->toDateString(), 'bot' => 'Mozilla/5.0 (compatible; PerplexityBot/1.0)', 'hits' => 7, 'pages' => 5],
            ],
        ])->assertOk()->assertJsonPath('ok', true)->assertJsonPath('stored', 2);

        $this->assertSame(42, (int) ContentAeoBotHit::where('website_id', $website->id)->where('bot', 'gptbot')->value('hits'));
        $this->assertSame(5, (int) ContentAeoBotHit::where('website_id', $website->id)->where('bot', 'perplexitybot')->value('pages'));
    }

    public function test_resending_a_day_does_not_double_count(): void
    {
        [$website, $token] = $this->pluginSite();
        $day = now()->subDay()->toDateString();

        $send = fn (int $hits) => $this->withToken($token)->postJson('/api/v1/aeo/bot-hits', [
            'days' => [['date' => $day, 'bot' => 'GPTBot', 'hits' => $hits, 'pages' => 3]],
        ])->assertOk();

        $send(10);
        $send(10);   // the reporter never saw our first OK and sent again
        $send(14);   // later the same day, its buffer has grown

        $this->assertSame(1, ContentAeoBotHit::where('website_id', $website->id)->count());
        $this->assertSame(14, (int) ContentAeoBotHit::where('website_id', $website->id)->value('hits'));
    }

    public function test_an_unknown_user_agent_is_skipped_not_stored_as_itself(): void
    {
        [$website, $token] = $this->pluginSite();

        $this->withToken($token)->postJson('/api/v1/aeo/bot-hits', [
            'days' => [
                ['date' => now()->toDateString(), 'bot' => 'SomeBrandNewAiBot/2.0', 'hits' => 3],
                ['date' => now()->toDateString(), 'bot' => 'ClaudeBot/1.0', 'hits' => 4],
            ],
        ])->assertOk()->assertJsonPath('stored', 1);

        $this->assertSame(['claudebot'], ContentAeoBotHit::where('website_id', $website->id)->pluck('bot')->all());
    }

    public function test_future_and_ancient_days_are_refused(): void
    {
        [$website, $token] = $this->pluginSite();

        $this->withToken($token)->postJson('/api/v1/aeo/bot-hits', [
            'days' => [
                ['date' => now()->addDays(3)->toDateString(), 'bot' => 'GPTBot', 'hits' => 9],
                ['date' => now()->subDays(400)->toDateString(), 'bot' => 'GPTBot', 'hits' => 9],
            ],
        ])->assertOk()->assertJsonPath('stored', 0);

        $this->assertSame(0, ContentAeoBotHit::where('website_id', $website->id)->count());
    }

    public function test_ingest_requires_a_token(): void
    {
        $this->postJson('/api/v1/aeo/bot-hits', [
            'days' => [['date' => now()->toDateString(), 'bot' => 'GPTBot', 'hits' => 1]],
        ])->assertUnauthorized();
    }

    public function test_the_php_kit_reports_with_its_signing_secret(): void
    {
        $website = Website::factory()->for(User::factory())->create();
        $secret = str_repeat('k', 40);
        $integration = ContentIntegration::create([
            'website_id' => $website->id,
            'platform' => ContentIntegration::PLATFORM_WEBHOOK,
            'status' => ContentIntegration::STATUS_CONNECTED,
            'credentials' => ['secret' => $secret],
        ]);

        $payload = ['days' => [['date' => now()->toDateString(), 'bot' => 'ChatGPT-User/1.0', 'hits' => 5, 'pages' => 5]]];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/v1/aeo/kit/bot-hits', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SERFIX_KIT' => $integration->id,
            'HTTP_X_SERFIX_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, $secret),
        ], $body)->assertOk()->assertJsonPath('stored', 1);

        $this->assertSame('chatgpt_user', ContentAeoBotHit::where('website_id', $website->id)->value('bot'));
    }

    public function test_a_wrong_kit_signature_is_refused(): void
    {
        $website = Website::factory()->for(User::factory())->create();
        $integration = ContentIntegration::create([
            'website_id' => $website->id,
            'platform' => ContentIntegration::PLATFORM_WEBHOOK,
            'status' => ContentIntegration::STATUS_CONNECTED,
            'credentials' => ['secret' => str_repeat('k', 40)],
        ]);

        $body = json_encode(['days' => [['date' => now()->toDateString(), 'bot' => 'GPTBot', 'hits' => 5]]], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/v1/aeo/kit/bot-hits', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SERFIX_KIT' => $integration->id,
            'HTTP_X_SERFIX_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'the-wrong-secret'),
        ], $body)->assertStatus(401);

        $this->assertSame(0, ContentAeoBotHit::count());
    }

    public function test_one_sites_token_cannot_write_another_sites_hits(): void
    {
        [$mine, $token] = $this->pluginSite();
        $theirs = Website::factory()->for(User::factory())->create();

        // There is no website field in the payload by design — the token IS the
        // site. This pins that: the row must land on the token's website.
        $this->withToken($token)->postJson('/api/v1/aeo/bot-hits', [
            'website_id' => $theirs->id,
            'days' => [['date' => now()->toDateString(), 'bot' => 'GPTBot', 'hits' => 1]],
        ])->assertOk();

        $this->assertSame(0, ContentAeoBotHit::where('website_id', $theirs->id)->count());
        $this->assertSame(1, ContentAeoBotHit::where('website_id', $mine->id)->count());
    }
}
