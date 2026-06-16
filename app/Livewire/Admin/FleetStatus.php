<?php

namespace App\Livewire\Admin;

use App\Models\CrawlRun;
use App\Models\WorkerNode;
use App\Support\AutoscalerConfig;
use App\Support\Queues;
use App\Services\Fleet\WorkerFleetService;
use Illuminate\Support\Facades\Queue;
use Livewire\Component;

/**
 * Live crawl-worker fleet view for /admin/fleet: per-node status + summary cards
 * (crawl backlog, finalize backlog, in-flight runs, billable boxes, estimated
 * hourly cost). Polls, like the crawler panel.
 */
class FleetStatus extends Component
{
    /** Rough Hetzner hourly USD by server type — for the cost estimate card. */
    private const HOURLY_USD = ['cpx21' => 0.008, 'cpx31' => 0.016, 'cpx41' => 0.030, 'cpx51' => 0.060];

    public function render()
    {
        $nodes = WorkerNode::orderByDesc('is_pinned')->orderBy('id')->get();
        $cfg = AutoscalerConfig::all();
        $backlog = $this->queueDepth(Queues::CRAWL);

        $estCost = $nodes->whereIn('status', WorkerNode::BILLABLE_STATUSES)
            ->sum(fn (WorkerNode $n) => self::HOURLY_USD[$n->server_type] ?? 0.0);

        return view('livewire.admin.fleet-status', [
            'nodes' => $nodes,
            'cfg' => $cfg,
            'summary' => [
                'enabled' => (bool) $cfg['enabled'],
                'backlog' => $backlog,
                'finalize_backlog' => $this->queueDepth(Queues::CRAWL_FINALIZE),
                'desired' => WorkerFleetService::desiredFromBacklog($backlog),
                'billable' => WorkerNode::billable()->count(),
                'in_flight' => CrawlRun::whereIn('status', [CrawlRun::STATUS_RUNNING, CrawlRun::STATUS_FINALIZING])->count(),
                'est_cost_hr' => $estCost,
            ],
        ]);
    }

    private function queueDepth(string $queue): int
    {
        try {
            return (int) Queue::connection('redis')->size($queue);
        } catch (\Throwable) {
            return 0;
        }
    }
}
