<?php

namespace App\Livewire\Admin;

use App\Jobs\Fleet\MoveShardJob;
use App\Models\CrawlSite;
use App\Models\DbNode;
use App\Models\User;
use App\Models\Website;
use App\Services\Sharding\ShardCleanup;
use App\Support\ShardTables;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Live Database-shards panel on /admin/fleet (replaces the static tab that
 * refreshed via window.location.reload every 10s — reloads wiped in-progress
 * form input and flashed the whole page; now only this component re-renders
 * via wire:poll).
 *
 * Shows WHO lives on each node (tenants with their websites; crawl sites),
 * with per-resident row counts (computed on expand, cached 1h — counting a
 * 1M-row tenant on every poll would hammer the nodes). The move form knows
 * each subject's current host: that node is disabled in the target list and
 * labelled, so you can't "move" something onto the node it's already on
 * (the merombedrift/mineanbud confusion, 2026-07-06).
 */
class DbShardPanel extends Component
{
    public string $moveKind = 'tenant';

    public string $moveSearch = '';

    public string $moveSubjectId = '';

    public string $moveTargetId = '';

    /** @var array<int,string> node ids whose resident list is expanded */
    public array $expanded = [];

    public function toggleExpand(string $nodeId): void
    {
        abort_unless(Auth::user()?->is_admin, 403);
        if (in_array($nodeId, $this->expanded, true)) {
            $this->expanded = array_values(array_diff($this->expanded, [$nodeId]));
        } else {
            $this->expanded[] = $nodeId;
        }
    }

    /** Bust one resident's cached row count — recomputed on this render. */
    public function refreshRowCount(string $kind, string $subjectId): void
    {
        abort_unless(Auth::user()?->is_admin, 403);
        Cache::forget("shard-rows:{$kind}:{$subjectId}");
    }

    /** Bust EVERY resident's cached row count on one node — all recounted on this render. */
    public function refreshNodeRowCounts(string $nodeId): void
    {
        abort_unless(Auth::user()?->is_admin, 403);
        $primaryId = DbNode::where('is_pinned', true)->value('id');
        $isPrimary = $nodeId === $primaryId;

        User::query()->whereHas('websites')
            ->when($isPrimary, fn ($q) => $q->whereNull('db_node_id'), fn ($q) => $q->where('db_node_id', $nodeId))
            ->pluck('id')
            ->each(fn ($id) => Cache::forget('shard-rows:tenant:'.$id));

        CrawlSite::query()->where('subscriber_count', '>', 0)
            ->when($isPrimary, fn ($q) => $q->whereNull('crawl_node_id'), fn ($q) => $q->where('crawl_node_id', $nodeId))
            ->pluck('id')
            ->each(fn ($id) => Cache::forget('shard-rows:crawl:'.$id));

        // Make sure the recount is visible immediately.
        if (! in_array($nodeId, $this->expanded, true)) {
            $this->expanded[] = $nodeId;
        }
    }

    public function updatedMoveKind(): void
    {
        $this->moveSearch = '';
        $this->moveSubjectId = '';
        $this->moveTargetId = '';
    }

    public function updatedMoveSubjectId(): void
    {
        // Re-picking a subject invalidates a target choice that may now be its host.
        $this->moveTargetId = '';
    }

    public function move(): void
    {
        abort_unless(Auth::user()?->is_admin, 403);

        $this->validate([
            'moveKind' => 'required|in:tenant,crawl',
            'moveSubjectId' => 'required|string|max:32',
            'moveTargetId' => 'required|string|exists:db_nodes,id',
        ]);

        $current = $this->currentHostFor($this->moveKind, $this->moveSubjectId);
        if ($current === $this->moveTargetId) {
            $this->addError('moveTargetId', 'Already hosted on that node — pick a different target.');

            return;
        }

        MoveShardJob::dispatch($this->moveKind, $this->moveSubjectId, $this->moveTargetId);
        $this->moveSubjectId = '';
        $this->moveTargetId = '';
        session()->flash('shard-move-status', 'Move queued — progress appears in the Data moves panel below.');
    }

    /** The node id currently hosting a subject (null = unanchored ⇒ pinned primary). */
    private function currentHostFor(string $kind, string $subjectId): ?string
    {
        $anchor = $kind === 'crawl'
            ? CrawlSite::whereKey($subjectId)->value('crawl_node_id')
            : User::whereKey($subjectId)->value('db_node_id');

        return $anchor ?? DbNode::where('is_pinned', true)->value('id');
    }

    public function render()
    {
        abort_unless(Auth::user()?->is_admin, 403);

        $nodes = DbNode::orderByDesc('is_pinned')->orderBy('role')->orderBy('id')->get();
        $primaryId = $nodes->firstWhere('is_pinned', true)?->id;

        // ── Residents per node (anchor NULL ⇒ pinned primary) ─────────────
        $tenants = User::query()
            ->whereHas('websites')
            ->with(['websites:id,user_id,domain,db_node_id'])
            ->get(['id', 'name', 'email', 'db_node_id'])
            ->groupBy(fn (User $u) => $u->db_node_id ?? $primaryId ?? 'unassigned');

        $crawlSites = CrawlSite::query()
            ->where('subscriber_count', '>', 0)
            ->get(['id', 'normalized_domain', 'crawl_node_id', 'status'])
            ->groupBy(fn (CrawlSite $c) => $c->crawl_node_id ?? $primaryId ?? 'unassigned');

        // Row counts only for expanded nodes (1h cache per subject).
        $rowCounts = [];
        foreach ($this->expanded as $nodeId) {
            foreach ($tenants->get($nodeId, collect()) as $tenant) {
                $rowCounts['tenant:'.$tenant->id] = $this->tenantRowCount($tenant, $nodeId === $primaryId ? null : $nodeId);
            }
            foreach ($crawlSites->get($nodeId, collect()) as $site) {
                $rowCounts['crawl:'.$site->id] = $this->crawlRowCount($site, $nodeId === $primaryId ? null : $nodeId);
            }
        }

        // ── Move-form options with current-host info ───────────────────────
        $moveOptions = $this->moveKind === 'crawl'
            ? CrawlSite::where('subscriber_count', '>', 0)->orderBy('normalized_domain')
                ->get(['id', 'normalized_domain', 'crawl_node_id'])
                ->map(fn ($c) => ['id' => (string) $c->id, 'label' => $c->normalized_domain, 'host' => $c->crawl_node_id ?? $primaryId])
            : User::whereHas('websites')->orderBy('name')
                ->get(['id', 'name', 'email', 'db_node_id'])
                ->map(fn ($u) => ['id' => (string) $u->id, 'label' => trim(($u->name ?: '—').' — '.$u->email), 'host' => $u->db_node_id ?? $primaryId]);

        if ($this->moveSearch !== '') {
            $q = mb_strtolower($this->moveSearch);
            $moveOptions = $moveOptions->filter(fn ($o) => str_contains(mb_strtolower($o['label']), $q) || str_contains(mb_strtolower($o['id']), $q));
        }

        $subjectHost = $this->moveSubjectId !== ''
            ? $this->currentHostFor($this->moveKind, $this->moveSubjectId)
            : null;

        return view('livewire.admin.db-shard-panel', [
            'nodes' => $nodes,
            'primaryId' => $primaryId,
            'tenants' => $tenants,
            'crawlSites' => $crawlSites,
            'rowCounts' => $rowCounts,
            'moveOptions' => $moveOptions->values(),
            'subjectHost' => $subjectHost,
            'nodeNames' => $nodes->pluck('name', 'id'),
        ]);
    }

    /** Total rows across all tenant-tier tables for one tenant, on their node. Cached 1h. */
    private function tenantRowCount(User $tenant, ?string $nodeId): int
    {
        return (int) Cache::remember('shard-rows:tenant:'.$tenant->id, 3600, function () use ($tenant, $nodeId): int {
            $websiteIds = $tenant->websites->pluck('id')->map(fn ($v) => (string) $v)->all();
            if ($websiteIds === []) {
                return 0;
            }
            $conn = ShardCleanup::connectionFor($nodeId);
            $total = 0;
            foreach (array_keys(ShardTables::TENANT) as $table) {
                try {
                    $total += DB::connection($conn)->table($table)
                        ->whereRaw(ShardTables::tenantWhere($table, $websiteIds))->count();
                } catch (\Throwable) {
                    // Node briefly unreachable — show what we have.
                }
            }

            return $total;
        });
    }

    /** Total rows across all crawl-tier tables for one crawl site, on its node. Cached 1h. */
    private function crawlRowCount(CrawlSite $site, ?string $nodeId): int
    {
        return (int) Cache::remember('shard-rows:crawl:'.$site->id, 3600, function () use ($site, $nodeId): int {
            $conn = ShardCleanup::connectionFor($nodeId);
            $total = 0;
            foreach (array_keys(ShardTables::CRAWL) as $table) {
                try {
                    $total += DB::connection($conn)->table($table)
                        ->whereRaw(ShardTables::crawlWhere($table, (string) $site->id))->count();
                } catch (\Throwable) {
                }
            }

            return $total;
        });
    }
}
