<?php

namespace Tests\Feature;

use App\Models\DbNode;
use App\Services\Fleet\DbFleetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for the db_nodes localhost landmine (incident 2026-07-06):
 * the pinned primary was registered with private_ip=127.0.0.1, which resolves
 * on the web box but connects to nothing on the worker box — every anchored
 * crawl job died with "Connection refused" for three days, silently.
 * DbFleetService now rejects loopback/link-local addresses at registration.
 */
class DbFleetNodeAddressTest extends TestCase
{
    use RefreshDatabase;

    private function service(): DbFleetService
    {
        return app(DbFleetService::class);
    }

    public function test_registering_primary_with_loopback_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->registerPrimary('127.0.0.1', 'ebq_v2');
    }

    public function test_registering_existing_node_with_localhost_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->registerExisting('bad-node', DbNode::ROLE_TENANT, 'localhost', 'ebq_v2');
    }

    public function test_registering_with_ipv6_loopback_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->registerExisting('bad-node6', DbNode::ROLE_TENANT, '::1', 'ebq_v2');
    }

    public function test_registering_with_private_net_ip_succeeds(): void
    {
        $node = $this->service()->registerPrimary('10.0.0.2', 'ebq_v2');

        $this->assertSame('10.0.0.2', $node->private_ip);
        $this->assertTrue($node->is_pinned);
    }
}
