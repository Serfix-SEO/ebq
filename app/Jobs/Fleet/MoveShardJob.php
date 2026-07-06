<?php

namespace App\Jobs\Fleet;

use App\Models\DbNode;
use App\Services\Sharding\ShardMover;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Move a tenant (by owner user id) or a crawl-site between DB nodes off the
 * request thread. The ShardMover holds the migrating lock for the window, so
 * in-flight write jobs defer. FLEET queue (web box, root). The admin UI polls
 * the anchors / node counts to see completion.
 */
class MoveShardJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public string $kind, public string $id, public string $targetNodeId) // kind: tenant|crawl
    {
        $this->onQueue(Queues::FLEET);
    }

    public function handle(ShardMover $mover): void
    {
        $target = DbNode::find($this->targetNodeId);
        if ($target === null) {
            Log::error('MoveShardJob: target node not found', ['target' => $this->targetNodeId]);

            return;
        }
        if ($this->kind === 'crawl') {
            $mover->moveCrawlSite($this->id, $target);
        } else {
            $mover->moveTenant($this->id, $target);
        }
    }

    /**
     * The mover marks its own ShardMove row failed on exceptions, but a
     * TimeoutExceeded/worker-kill bypasses its catch block — without this the
     * progress row would show "copying" forever (the June 18 timeout did
     * exactly that in spirit, pre-tracking). Data-wise a killed copy is safe:
     * insertOrIgnore makes re-runs idempotent and the source is intact until
     * the purge phase.
     */
    public function failed(?\Throwable $e): void
    {
        \App\Models\ShardMove::query()
            ->where('kind', $this->kind)
            ->where('subject_id', $this->id)
            ->whereNotIn('status', [\App\Models\ShardMove::STATUS_COMPLETED, \App\Models\ShardMove::STATUS_FAILED])
            ->latest()
            ->first()?->update([
                'status' => \App\Models\ShardMove::STATUS_FAILED,
                'error' => 'job killed: '.mb_substr($e?->getMessage() ?? 'timeout/worker restart', 0, 1900),
                'finished_at' => now(),
            ]);
    }
}
