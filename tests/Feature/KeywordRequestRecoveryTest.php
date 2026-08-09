<?php

namespace Tests\Feature;

use App\Models\ClientActivity;
use App\Models\KeywordApiRequest;
use App\Models\KeywordApiServer;
use App\Models\User;
use App\Services\KeywordFinder\KeywordFinderPool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Self-recovery for keyword requests.
 *
 * The node's queue is IN-MEMORY: a headless-browser crash drops every queued
 * job with no webhook, and before this the reaper/webhook only marked rows
 * failed — recovery took a human (2026-08-09, an onboarding lead's whole
 * keyword batch). Now the stored payload is automatically re-sent, on the
 * SAME row and request_id, never re-billed, capped at MAX_ATTEMPTS.
 */
class KeywordRequestRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function makeServer(array $overrides = []): KeywordApiServer
    {
        return KeywordApiServer::create(array_merge([
            'name' => 'Server A',
            'base_url' => 'http://server-a.test',
            'api_key' => 'key-a',
            'webhook_secret' => 'secret-a',
            'is_active' => true,
        ], $overrides));
    }

    private function stuckRequest(KeywordApiServer $server, array $overrides = []): KeywordApiRequest
    {
        $request = KeywordApiRequest::create(array_merge([
            'request_id' => (string) Str::uuid(),
            'keyword_api_server_id' => $server->id,
            'type' => KeywordApiRequest::TYPE_VOLUME,
            'payload' => ['keywords' => ['seo audit'], 'country_key' => 'us'],
            'status' => KeywordApiRequest::STATUS_RUNNING,
            'attempts' => 1,
            'dispatched_at' => now()->subMinutes(20),
        ], $overrides));

        // The reaper's cutoff reads created_at, which create() stamps as now.
        $request->forceFill(['created_at' => $overrides['dispatched_at'] ?? now()->subMinutes(20)])->save();

        return $request->fresh();
    }

    // ── The reaper retries before giving up ─────────────────────────────

    public function test_the_reaper_redispatches_a_stuck_request_once(): void
    {
        $server = $this->makeServer();
        Http::fake(['server-a.test/*' => Http::response(['queued' => true], 200)]);
        $request = $this->stuckRequest($server);

        $this->artisan('ebq:reap-stuck-keyword-requests')->assertSuccessful();

        $request->refresh();
        $this->assertSame(KeywordApiRequest::STATUS_RUNNING, $request->status, 'resent, not failed');
        $this->assertSame(2, $request->attempts);
        // The request_id is unchanged — whoever polls this row still gets the
        // eventual webhook result.
        Http::assertSent(fn ($req) => str_contains($req->url(), '/keywords/volume')
            && $req['request_id'] === $request->request_id);
    }

    public function test_the_second_reap_fails_the_request_for_good(): void
    {
        $server = $this->makeServer();
        Http::fake(['server-a.test/*' => Http::response(['queued' => true], 200)]);
        $request = $this->stuckRequest($server, ['attempts' => KeywordApiRequest::MAX_ATTEMPTS]);

        $this->artisan('ebq:reap-stuck-keyword-requests')->assertSuccessful();

        $this->assertSame(KeywordApiRequest::STATUS_FAILED, $request->fresh()->status, 'the poison-pill cap holds');
        Http::assertNothingSent();
    }

    /** A retry must never bill the customer a second time. */
    public function test_a_redispatch_is_not_metered(): void
    {
        $server = $this->makeServer();
        Http::fake(['server-a.test/*' => Http::response(['queued' => true], 200)]);
        $request = $this->stuckRequest($server, ['user_id' => User::factory()->create()->id]);

        app(KeywordFinderPool::class)->redispatch($request);

        $this->assertSame(0, ClientActivity::query()->where('provider', 'keyword_finder')->count());
    }

    // ── Webhook transient failures retry, real failures do not ──────────

    private function signedWebhook(KeywordApiRequest $request, array $data): TestResponse
    {
        $payload = array_merge(['request_id' => $request->request_id], $data);
        $body = json_encode($payload);
        $sig = hash_hmac('sha256', $body, 'secret-a');

        return $this->call('POST', '/webhooks/keyword-finder', [], [], [], [
            'HTTP_X_WEBHOOK_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public function test_a_browser_crash_error_is_retried_via_the_webhook(): void
    {
        $server = $this->makeServer();
        Http::fake(['server-a.test/*' => Http::response(['queued' => true], 200)]);
        $request = $this->stuckRequest($server);

        $this->signedWebhook($request, [
            'status' => 'failed',
            'error' => 'page.waitForEvent: Target page, context or browser has been closed',
        ])->assertOk()->assertJson(['retried' => true]);

        $request->refresh();
        $this->assertSame(KeywordApiRequest::STATUS_RUNNING, $request->status);
        $this->assertSame(2, $request->attempts);
    }

    public function test_a_needs_login_error_is_never_retried(): void
    {
        $server = $this->makeServer();
        Http::fake();
        $request = $this->stuckRequest($server);

        $this->signedWebhook($request, [
            'status' => 'failed',
            'error' => ['message' => 'page.goto: needs login', 'needsLogin' => true],
        ])->assertOk();

        $this->assertSame(KeywordApiRequest::STATUS_FAILED, $request->fresh()->status,
            'retrying into a logged-out browser hides the real problem');
        Http::assertNothingSent();
        $this->assertFalse((bool) $server->fresh()->is_healthy, 'the server is flagged for re-login');
    }

    public function test_a_non_transient_error_is_not_retried(): void
    {
        $server = $this->makeServer();
        Http::fake();
        $request = $this->stuckRequest($server);

        $this->signedWebhook($request, ['status' => 'failed', 'error' => 'quota exhausted for this account'])
            ->assertOk();

        $this->assertSame(KeywordApiRequest::STATUS_FAILED, $request->fresh()->status);
        Http::assertNothingSent();
    }

    // ── Fast orphan detection ───────────────────────────────────────────

    public function test_an_empty_node_queue_with_in_flight_rows_recovers_them(): void
    {
        $server = $this->makeServer();
        Http::fake([
            'server-a.test/queue' => Http::response(['waiting' => 0, 'running' => 0], 200),
            'server-a.test/keywords/*' => Http::response(['queued' => true], 200),
        ]);
        $request = $this->stuckRequest($server, ['dispatched_at' => now()->subMinutes(5)]);

        $this->artisan('ebq:detect-orphaned-keyword-requests')->assertSuccessful();

        $request->refresh();
        $this->assertSame(KeywordApiRequest::STATUS_RUNNING, $request->status);
        $this->assertSame(2, $request->attempts);
    }

    /** A BUSY node proves nothing — our rows may simply be next in line. */
    public function test_a_busy_node_queue_leaves_rows_alone(): void
    {
        $server = $this->makeServer();
        Http::fake(['server-a.test/queue' => Http::response(['waiting' => 3, 'running' => 1], 200)]);
        $request = $this->stuckRequest($server, ['dispatched_at' => now()->subMinutes(5)]);

        $this->artisan('ebq:detect-orphaned-keyword-requests')->assertSuccessful();

        $this->assertSame(KeywordApiRequest::STATUS_RUNNING, $request->fresh()->status);
        $this->assertSame(1, $request->fresh()->attempts);
    }

    /** An UNREACHABLE node proves nothing — the 15-min reaper stays the backstop. */
    public function test_an_unreachable_node_leaves_rows_alone(): void
    {
        $server = $this->makeServer();
        Http::fake(['server-a.test/*' => Http::response(null, 500)]);
        $request = $this->stuckRequest($server, ['dispatched_at' => now()->subMinutes(5)]);

        $this->artisan('ebq:detect-orphaned-keyword-requests')->assertSuccessful();

        $this->assertSame(KeywordApiRequest::STATUS_RUNNING, $request->fresh()->status);
    }

    /** Fresh dispatches are inside the grace period — never touched. */
    public function test_recent_rows_are_inside_the_grace_period(): void
    {
        $server = $this->makeServer();
        Http::fake(['server-a.test/queue' => Http::response(['waiting' => 0, 'running' => 0], 200)]);
        $request = $this->stuckRequest($server, ['dispatched_at' => now()->subSeconds(30)]);

        $this->artisan('ebq:detect-orphaned-keyword-requests')->assertSuccessful();

        $this->assertSame(1, $request->fresh()->attempts);
    }
}
