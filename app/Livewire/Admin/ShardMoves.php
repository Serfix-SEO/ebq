<?php

namespace App\Livewire\Admin;

use App\Models\DbNode;
use App\Models\ShardMove;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Live "Data moves" panel on the fleet page's Database tab. Polls the
 * shard_moves progress rows that ShardMover writes (phase, current table,
 * rows copied / total) so a tenant/crawl move is no longer a black box.
 * Poll interval matches the FleetStatus component (blade: wire:poll).
 */
class ShardMoves extends Component
{
    public function render()
    {
        abort_unless(Auth::user()?->is_admin, 403);

        $moves = ShardMove::query()
            ->latest()
            ->limit(10)
            ->get();

        $nodeNames = DbNode::query()
            ->whereIn('id', $moves->pluck('source_node_id')->merge($moves->pluck('target_node_id'))->filter()->unique())
            ->pluck('name', 'id');

        return view('livewire.admin.shard-moves', [
            'moves' => $moves,
            'nodeNames' => $nodeNames,
            'anyRunning' => $moves->contains(fn (ShardMove $m) => $m->isRunning()),
        ]);
    }
}
