<?php

namespace Tests\Feature;

use App\Models\WorkerNode;
use App\Services\Fleet\HetznerClient;
use App\Services\Fleet\WorkerFleetService;
use App\Support\AutoscalerConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkerFleetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.hetzner.token' => 'test-token',
            'services.hetzner.location' => 'fsn1',
            'services.hetzner.image' => '12345', // a worker snapshot id, so provision() passes the image guard
        ]);
    }

    public function test_desired_from_backlog_clamps_between_min_and_max(): void
    {
        AutoscalerConfig::update(['min_boxes' => 1, 'max_boxes' => 3, 'target_backlog_per_box' => 400]);

        $this->assertSame(1, WorkerFleetService::desiredFromBacklog(0), 'empty backlog → floor');
        $this->assertSame(1, WorkerFleetService::desiredFromBacklog(399), 'under one box of work → 1');
        $this->assertSame(3, WorkerFleetService::desiredFromBacklog(1200), '1200/400 = 3');
        $this->assertSame(3, WorkerFleetService::desiredFromBacklog(50000), 'huge backlog clamps to max');
    }

    public function test_max_boxes_never_drops_below_min(): void
    {
        AutoscalerConfig::update(['min_boxes' => 4, 'max_boxes' => 2]); // misconfigured
        $this->assertSame(4, AutoscalerConfig::maxBoxes());
    }

    public function test_hetzner_client_creates_server(): void
    {
        Http::fake(['api.hetzner.cloud/*' => Http::response([
            'server' => [
                'id' => 98765, 'status' => 'initializing',
                'private_net' => [['ip' => '10.0.0.7']],
                'public_net' => ['ipv4' => ['ip' => '5.6.7.8']],
            ],
        ], 201)]);

        $out = app(HetznerClient::class)->createServer('ebq-crawl-worker-1');

        $this->assertTrue($out['ok']);
        $this->assertSame(98765, $out['server_id']);
        $this->assertSame('10.0.0.7', $out['private_ip']);
    }

    public function test_provision_records_a_node(): void
    {
        Http::fake(['api.hetzner.cloud/*' => Http::response([
            'server' => ['id' => 555, 'status' => 'initializing', 'private_net' => [['ip' => '10.0.0.9']]],
        ], 201)]);

        $node = app(WorkerFleetService::class)->provision();

        $this->assertSame(WorkerNode::STATUS_PROVISIONING, $node->status);
        $this->assertSame(555, $node->hetzner_server_id);
        $this->assertSame('10.0.0.9', $node->private_ip);
        $this->assertSame("ebq-crawl-worker-{$node->id}", $node->name);
        $this->assertSame(1, WorkerNode::billable()->count());
    }

    public function test_provision_fails_clearly_when_no_image_configured(): void
    {
        config(['services.hetzner.image' => null]);
        AutoscalerConfig::update(['snapshot_id' => null]);
        Http::fake(); // any Hetzner call here would be a bug — we must fail before it

        $node = app(WorkerFleetService::class)->provision();

        $this->assertSame(WorkerNode::STATUS_FAILED, $node->status);
        $this->assertStringContainsString('worker image', (string) $node->last_error);
        Http::assertNothingSent();
    }

    public function test_provision_marks_failed_on_api_error(): void
    {
        Http::fake(['api.hetzner.cloud/*' => Http::response(['error' => ['message' => 'rate limited']], 429)]);

        $node = app(WorkerFleetService::class)->provision();

        $this->assertSame(WorkerNode::STATUS_FAILED, $node->status);
        $this->assertStringContainsString('rate limited', (string) $node->last_error);
    }

    public function test_reconcile_marks_vanished_servers_failed(): void
    {
        // A tracked, billable node whose server no longer exists on Hetzner.
        WorkerNode::create([
            'name' => 'ebq-crawl-worker-1', 'hetzner_server_id' => 999,
            'status' => WorkerNode::STATUS_ACTIVE, 'private_ip' => '10.0.0.8',
        ]);
        Http::fake(['api.hetzner.cloud/*' => Http::response(['servers' => []], 200)]); // none live

        $result = app(WorkerFleetService::class)->reconcile();

        $this->assertSame(1, $result['vanished']);
        $this->assertSame(WorkerNode::STATUS_FAILED, WorkerNode::first()->status);
    }

    public function test_domain_rate_limiter_caps_per_window_and_shares_bucket(): void
    {
        config(['crawler.rate_max_wait_ms' => 0]); // fail-open instantly — no sleeping in the test
        AutoscalerConfig::update(['per_domain_rate' => 2]);
        $limiter = new \App\Services\Crawler\DomainRateLimiter();

        // The limiter is a raw-Redis fixed-window counter now (key
        // crawl-rl:<domain>:<second-slot>), not Laravel's RateLimiter.
        // Make sure all three calls land inside ONE second-slot: if the
        // boundary is close, wait for the next slot to open first.
        $msLeft = (1.0 - fmod(microtime(true), 1.0)) * 1000;
        if ($msLeft < 250) {
            usleep(((int) ceil($msLeft) + 10) * 1000);
        }
        $slot = (int) floor(microtime(true));

        $limiter->throttle('example.com');
        $limiter->throttle('https://www.example.com/some/page'); // normalizes to the SAME bucket
        $limiter->throttle('example.com'); // over the per-second rate → fail-open, undoes its increment

        $count = (int) \Illuminate\Support\Facades\Redis::connection()->get('crawl-rl:example.com:'.$slot);
        $this->assertSame(2, $count,
            'only `rate` requests counted per window; www/scheme variants share one domain bucket');
    }

    public function test_pinned_node_is_never_destroyed(): void
    {
        $pinned = WorkerNode::create([
            'name' => 'pinned', 'is_pinned' => true, 'status' => WorkerNode::STATUS_ACTIVE,
            'hetzner_server_id' => 1, 'private_ip' => '10.0.0.3',
        ]);
        Http::fake(); // any Hetzner call would be a failure of intent

        app(WorkerFleetService::class)->destroy($pinned);

        $this->assertDatabaseHas('worker_nodes', ['id' => $pinned->id]); // still there
        Http::assertNothingSent();
    }

    public function test_image_exists_is_tri_state(): void
    {
        Http::fake([
            'api.hetzner.cloud/v1/images/111' => Http::response(['image' => ['id' => 111]], 200),
            'api.hetzner.cloud/v1/images/404404' => Http::response(['error' => ['message' => 'image not found']], 404),
            'api.hetzner.cloud/v1/images/500500' => Http::response(['error' => ['message' => 'boom']], 500),
        ]);
        $c = app(HetznerClient::class);

        $this->assertTrue($c->imageExists(111), '200 → exists');
        $this->assertFalse($c->imageExists(404404), 'confirmed 404 → gone');
        $this->assertNull($c->imageExists(500500), '5xx → unknown, never treated as gone');
    }

    public function test_provision_aborts_when_snapshot_missing(): void
    {
        AutoscalerConfig::update(['snapshot_id' => '999999']);
        Http::fake([
            'api.hetzner.cloud/v1/images/*' => Http::response(['error' => ['message' => 'image not found']], 404),
            'api.hetzner.cloud/v1/servers' => Http::response(['server' => ['id' => 1]], 201), // must NOT be reached
        ]);

        $node = app(WorkerFleetService::class)->provision();

        $this->assertSame(WorkerNode::STATUS_FAILED, $node->status);
        $this->assertStringContainsString('not found in Hetzner', (string) $node->last_error);
        // It bailed on the existence preflight — never attempted to create the server.
        Http::assertNotSent(fn ($req) => $req->method() === 'POST' && str_contains($req->url(), '/servers'));
    }
}
