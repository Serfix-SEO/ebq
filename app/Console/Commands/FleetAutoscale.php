<?php

namespace App\Console\Commands;

use App\Models\WorkerNode;
use App\Services\Fleet\HetznerClient;
use App\Services\Fleet\WorkerFleetService;
use App\Support\AutoscalerConfig;
use App\Support\Queues;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * The autoscaler control loop (scheduled every 2 min, withoutOverlapping).
 *
 *   desired = clamp(ceil(crawlBacklog / target_backlog_per_box), min, max)
 *
 * Scale UP one box per tick (gated by a cooldown + "no node still provisioning")
 * so a spike ramps over ~10 min instead of fanning out instantly. Scale DOWN one
 * box per tick, conservatively: backlog must stay low for the whole idle window,
 * the box must be past its minimum (hourly-billed) lifetime, and the pinned box is
 * never touched. A killed worker's in-flight work is recovered by stop_grace +
 * retry_after + CrawlSupervisor, so drains are safe.
 *
 * `--dry-run` logs the decision without calling Hetzner.
 */
class FleetAutoscale extends Command
{
    protected $signature = 'ebq:fleet-autoscale {--dry-run}';

    protected $description = 'Scale the crawl-worker fleet up/down to match crawl-queue backlog.';

    private const MARKER = 'autoscaler:scale_down_since';

    public function handle(WorkerFleetService $fleet): int
    {
        if (! AutoscalerConfig::enabled()) {
            return self::SUCCESS; // master kill-switch off
        }
        $dry = (bool) $this->option('dry-run');

        if (! $dry) {
            $fleet->reconcile();
            $this->reapDrained($fleet);
        }

        $backlog = $this->backlog();
        $billable = $fleet->billableCount();
        $desired = WorkerFleetService::desiredFromBacklog($backlog);
        $ctx = "backlog={$backlog} billable={$billable} desired={$desired}";

        if ($desired > $billable) {
            $this->clearMarker($dry);
            $this->scaleUp($fleet, $dry, $ctx);
        } elseif ($desired < $billable) {
            $this->scaleDown($fleet, $dry, $ctx);
        } else {
            $this->clearMarker($dry);
            $this->say("hold — {$ctx}", $dry);
        }

        return self::SUCCESS;
    }

    private function scaleUp(WorkerFleetService $fleet, bool $dry, string $ctx): void
    {
        if (WorkerNode::where('status', WorkerNode::STATUS_PROVISIONING)->exists()) {
            $this->say("scale-up skip: a node is still provisioning — {$ctx}", $dry);

            return;
        }
        $last = WorkerNode::max('provisioned_at');
        if ($last && Carbon::parse($last)->diffInSeconds(now()) < AutoscalerConfig::scaleUpCooldownSeconds()) {
            $this->say("scale-up skip: cooldown — {$ctx}", $dry);

            return;
        }
        if (! app(HetznerClient::class)->configured()) {
            $this->say("scale-up skip: HCLOUD_TOKEN not configured — {$ctx}", $dry);

            return;
        }
        if ($dry) {
            $this->say("WOULD scale up (+1 box) — {$ctx}", true);

            return;
        }
        $node = $fleet->provision();
        if ($node->status === WorkerNode::STATUS_FAILED) {
            Log::error('FleetAutoscale: scale-up provision failed', ['node' => $node->id, 'error' => $node->last_error]);

            return;
        }
        $fleet->bootstrap($node);
        $this->say("scaled up: node {$node->id} — {$ctx}", false);
    }

    private function scaleDown(WorkerFleetService $fleet, bool $dry, string $ctx): void
    {
        $since = $this->marker(); // first tick at desired<billable starts the idle clock
        if ($since->diffInSeconds(now()) < AutoscalerConfig::scaleDownIdleSeconds()) {
            $this->say("scale-down pending: idle window not met — {$ctx}", $dry);

            return;
        }
        $node = WorkerNode::drainable()->get()
            ->first(fn (WorkerNode $n) => $n->ageMinutes() * 60 >= AutoscalerConfig::minBoxLifetimeSeconds());
        if (! $node) {
            $this->say("scale-down skip: no drainable box past min lifetime — {$ctx}", $dry);

            return;
        }
        if ($dry) {
            $this->say("WOULD scale down: drain node {$node->id} — {$ctx}", true);

            return;
        }
        $fleet->drain($node);
        $this->say("scaling down: draining node {$node->id} — {$ctx}", false);
    }

    /** Destroy boxes that have been draining past the stop-grace window. */
    private function reapDrained(WorkerFleetService $fleet): void
    {
        $grace = 360 + 90; // stop_grace_period + buffer
        foreach (WorkerNode::where('status', WorkerNode::STATUS_DRAINING)->get() as $node) {
            if ($node->isDrainOverdue($grace)) {
                $fleet->destroy($node);
            }
        }
    }

    private function backlog(): int
    {
        try {
            return (int) Queue::connection('redis')->size(Queues::CRAWL);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function marker(): Carbon
    {
        $ts = Cache::get(self::MARKER);
        if (! $ts) {
            $ts = now()->timestamp;
            Cache::put(self::MARKER, $ts, 86400);
        }

        return Carbon::createFromTimestamp($ts);
    }

    private function clearMarker(bool $dry): void
    {
        if (! $dry) {
            Cache::forget(self::MARKER);
        }
    }

    private function say(string $msg, bool $dry): void
    {
        $this->info(($dry ? '[dry] ' : '').$msg);
        if (! $dry) {
            Log::info('FleetAutoscale: '.$msg);
        }
    }
}
