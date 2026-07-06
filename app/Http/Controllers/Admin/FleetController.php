<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\Fleet\DrainCrawlWorkerJob;
use App\Jobs\Fleet\ProvisionCrawlWorkerJob;
use App\Models\DbNode;
use App\Models\WorkerNode;
use App\Services\Fleet\HetznerClient;
use App\Services\Fleet\WorkerFleetService;
use App\Support\AutoscalerConfig;
use App\Support\DbFleetConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin "Fleet" panel: live crawl-worker fleet status + editable autoscaler
 * settings + manual operator actions (provision / drain / reconcile). The live
 * table is the App\Livewire\Admin\FleetStatus component; this controller serves
 * the page shell, persists settings, and runs operator actions. Mirrors the
 * keyword-servers admin pattern.
 */
class FleetController extends Controller
{
    /**
     * Hetzner server types the admin can pick (slug => human). cx = shared Intel,
     * cpx = shared AMD. Slugs + specs verified against the live Hetzner API for
     * this account (fsn1): the CX line is cx23/cx33/cx43/cx53 — there is NO
     * cx22/cx32. Keep this list in sync if Hetzner's catalog changes.
     */
    private const SERVER_TYPES = [
        'cx23' => 'cx23 — 2 vCPU / 4 GB',
        'cx33' => 'cx33 — 4 vCPU / 8 GB',
        'cx43' => 'cx43 — 8 vCPU / 16 GB',
        'cx53' => 'cx53 — 16 vCPU / 32 GB',
        'cpx31' => 'cpx31 — 4 vCPU / 8 GB (AMD)',
        'cpx41' => 'cpx41 — 8 vCPU / 16 GB (AMD)',
    ];

    /**
     * Unified fleet page: BOTH the crawl-worker (compute) fleet and the DB-shard
     * (data) fleet, as two tabs. The DB-fleet actions still POST to DbFleetController
     * routes; this just renders both on one screen. {@see DbFleetController::index}
     * redirects here.
     */
    public function index(): View
    {
        // Self-heal the DB-node counters from actual data before display: they are
        // otherwise only bumped by ShardMover moves, so organic signups / new sites
        // (which land on the primary via NULL node ids) would never be counted.
        DbNode::reconcileCounts();

        // Snapshot dropdowns (so an operator picks a REAL image id, not a typo that
        // fails provisioning with "image not found"). Cached 5 min — snapshots change
        // rarely and the page auto-refreshes, so don't hit the Hetzner API every load.
        $hz = app(HetznerClient::class);
        $workerSnapshots = Cache::remember('fleet:snapshots:worker', 300, fn () => $hz->listSnapshots('role=ebq-crawl-worker')['snapshots']);
        $dbSnapshots = Cache::remember('fleet:snapshots:db', 300, fn () => $hz->listSnapshots('role=ebq-db-node')['snapshots']);

        return view('admin.fleet.index', [
            // Crawl-worker (compute) fleet — "Crawl workers" tab.
            'cfg' => AutoscalerConfig::all(),
            'serverTypes' => self::SERVER_TYPES,
            'workerSnapshots' => $workerSnapshots,
            // Database-shard (data) fleet — "Database shards" tab.
            'dbNodes' => DbNode::orderByDesc('is_pinned')->orderBy('role')->orderBy('id')->get(),
            'dbCfg' => DbFleetConfig::all(),
            'dbServerTypes' => DbFleetController::SERVER_TYPES,
            'dbSnapshots' => $dbSnapshots,
            // moveOptions moved into Livewire\Admin\DbShardPanel (live, host-aware).
        ]);
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'auto_snapshot' => ['nullable', 'boolean'],
            'min_boxes' => ['required', 'integer', 'min:1', 'max:50'],
            'max_boxes' => ['required', 'integer', 'min:1', 'max:50'],
            'target_backlog_per_box' => ['required', 'integer', 'min:1', 'max:100000'],
            'server_type' => ['required', 'string', 'in:'.implode(',', array_keys(self::SERVER_TYPES))],
            'snapshot_id' => ['nullable', 'string', 'max:64'],
            'scale_up_cooldown_s' => ['required', 'integer', 'min:0', 'max:3600'],
            'scale_down_idle_s' => ['required', 'integer', 'min:0', 'max:86400'],
            'min_box_lifetime_s' => ['required', 'integer', 'min:0', 'max:86400'],
            'per_domain_rate' => ['required', 'integer', 'min:1', 'max:100'],
        ]);
        $data['enabled'] = $request->boolean('enabled');
        $data['auto_snapshot'] = $request->boolean('auto_snapshot');

        AutoscalerConfig::update($data);

        return back()->with('status', 'Autoscaler settings saved.');
    }

    public function provision(): RedirectResponse
    {
        // Provision + bootstrap (rsync code + SSH) run on the FLEET queue (web box,
        // root) — they take minutes and need root's SSH key, so never in-request.
        // The live table (wire:poll) shows the new box provisioning → active.
        ProvisionCrawlWorkerJob::dispatch();

        return back()->with('status', 'Provisioning a new crawl-worker box in the background — watch it appear below as it boots → active.');
    }

    public function drain(WorkerNode $node): RedirectResponse
    {
        if ($node->is_pinned) {
            return back()->with('error', 'The pinned permanent box cannot be drained.');
        }
        // Graceful drain SSHes to stop containers → run on the FLEET queue (root).
        DrainCrawlWorkerJob::dispatch($node->id);

        return back()->with('status', "Draining node {$node->id} in the background (containers stopping gracefully).");
    }

    public function destroy(WorkerNode $node, WorkerFleetService $fleet): RedirectResponse
    {
        if ($node->is_pinned) {
            return back()->with('error', 'The pinned permanent box cannot be destroyed.');
        }
        $fleet->destroy($node);

        return back()->with('status', "Destroyed node {$node->id}.");
    }

    public function reconcile(WorkerFleetService $fleet): RedirectResponse
    {
        $r = $fleet->reconcile();

        return back()->with('status', "Reconciled: {$r['vanished']} vanished, ".count($r['orphans']).' orphan(s).');
    }
}
