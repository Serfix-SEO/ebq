<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DbNode;
use App\Services\Fleet\DbFleetService;
use App\Services\Sharding\ShardMover;
use App\Support\DbFleetConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin "Database fleet" panel — the DbNode equivalent of {@see FleetController}.
 * Lists shard nodes, edits provisioning defaults, runs operator lifecycle
 * actions (provision / bootstrap / migrate / drain / destroy), and moves a
 * tenant / crawl-site between nodes. DB nodes change rarely, so the page is
 * server-rendered (no live-poll component).
 */
class DbFleetController extends Controller
{
    private const SERVER_TYPES = [
        'cx23' => 'cx23 — 4 vCPU / 8 GB',
        'cx33' => 'cx33 — 8 vCPU / 16 GB',
        'cpx41' => 'cpx41 — 8 vCPU / 16 GB (AMD)',
        'ccx33' => 'ccx33 — 8 dedicated vCPU / 32 GB',
    ];

    public function index(): View
    {
        return view('admin.db-fleet.index', [
            'nodes' => DbNode::orderByDesc('is_pinned')->orderBy('role')->orderBy('id')->get(),
            'cfg' => DbFleetConfig::all(),
            'serverTypes' => self::SERVER_TYPES,
        ]);
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'server_type' => ['required', 'string', 'in:'.implode(',', array_keys(self::SERVER_TYPES))],
            'snapshot_id' => ['nullable', 'string', 'max:64'],
            'placement' => ['required', 'in:least_loaded,round_robin'],
            'max_tenants_per_node' => ['required', 'integer', 'min:1', 'max:100000'],
            'max_sites_per_node' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);
        DbFleetConfig::update($data);

        return back()->with('status', 'DB-fleet settings saved.');
    }

    public function registerPrimary(DbFleetService $fleet): RedirectResponse
    {
        $node = $fleet->registerPrimary(
            (string) config('database.connections.global.host'),
            (string) config('database.connections.global.database'),
        );

        return back()->with('status', "Primary node registered ({$node->name}).");
    }

    public function provision(Request $request, DbFleetService $fleet): RedirectResponse
    {
        $role = $request->input('role') === DbNode::ROLE_CRAWL ? DbNode::ROLE_CRAWL : DbNode::ROLE_TENANT;
        $node = $fleet->provision($role);
        if ($node->status === DbNode::STATUS_FAILED) {
            return back()->with('error', "Provision failed: {$node->last_error}");
        }

        return back()->with('status', "Provisioned node {$node->id}. Run bootstrap to configure + migrate it.");
    }

    public function bootstrap(DbNode $node, DbFleetService $fleet): RedirectResponse
    {
        return $fleet->bootstrap($node)
            ? back()->with('status', "Bootstrapped node {$node->id}.")
            : back()->with('error', "Bootstrap failed: {$node->fresh()?->last_error}");
    }

    public function migrate(DbNode $node, DbFleetService $fleet): RedirectResponse
    {
        return $fleet->migrateNode($node)
            ? back()->with('status', "Schema migrated on node {$node->id}.")
            : back()->with('error', "Migrate failed: {$node->fresh()?->last_error}");
    }

    public function drain(DbNode $node, DbFleetService $fleet): RedirectResponse
    {
        $fleet->drain($node);

        return back()->with('status', "Draining node {$node->id}.");
    }

    public function destroy(DbNode $node, DbFleetService $fleet): RedirectResponse
    {
        return $fleet->destroy($node)
            ? back()->with('status', "Destroyed node {$node->id}.")
            : back()->with('error', $node->fresh()?->last_error ?: 'Cannot destroy this node.');
    }

    public function move(Request $request, ShardMover $mover): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:tenant,crawl'],
            'id' => ['required', 'string'],
            'to' => ['required', 'string', 'exists:db_nodes,id'],
        ]);
        $target = DbNode::findOrFail($data['to']);
        try {
            $counts = $data['kind'] === 'crawl'
                ? $mover->moveCrawlSite($data['id'], $target)
                : $mover->moveTenant($data['id'], $target);
        } catch (\Throwable $e) {
            return back()->with('error', 'Move failed: '.$e->getMessage());
        }

        return back()->with('status', "Moved {$data['kind']} {$data['id']} → {$target->name}: ".array_sum($counts).' rows.');
    }
}
