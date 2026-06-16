<?php

namespace App\Console\Commands;

use App\Models\WorkerNode;
use App\Services\Fleet\HetznerClient;
use Illuminate\Console\Command;

/**
 * Refresh the worker-fleet health snapshot (scheduled every 5 min, mirroring
 * `ebq:check-keyword-servers`). For each tracked Hetzner-backed node, poll the
 * server status and stamp is_healthy/last_seen_at. The pinned permanent box has
 * no hetzner_server_id (it's not API-managed) and is assumed healthy.
 *
 * A `provisioning` node whose server is now `running` is left for the autoscaler
 * to bootstrap; a node whose server has vanished is flagged failed.
 */
class CheckWorkerNodes extends Command
{
    protected $signature = 'ebq:check-worker-nodes';

    protected $description = 'Refresh crawl-worker fleet health from the Hetzner API.';

    public function handle(HetznerClient $hetzner): int
    {
        if (! $hetzner->configured()) {
            $this->warn('HCLOUD_TOKEN not configured — skipping');

            return self::SUCCESS;
        }

        $checked = 0;
        foreach (WorkerNode::billable()->whereNotNull('hetzner_server_id')->get() as $node) {
            $s = $hetzner->getServer((int) $node->hetzner_server_id);
            if (! $s['ok']) {
                $node->update(['is_healthy' => false, 'last_error' => $s['error']]);
                continue;
            }
            $node->update([
                'is_healthy' => $s['status'] === 'running',
                'last_seen_at' => now(),
                'private_ip' => $node->private_ip ?: $s['private_ip'],
                'last_error' => null,
            ]);
            $checked++;
        }

        $this->info("worker-node health refreshed ({$checked} node(s))");

        return self::SUCCESS;
    }
}
