<?php

namespace App\Jobs\Content;

use App\Jobs\PlanContentTopicsJob;
use App\Models\ContentPlan;
use App\Models\ContentProduct;
use App\Models\ContentProductRun;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Strict Product Mode, stage 3 (crawl-finalize queue — pinned, long timeout):
 * variant dedupe, gone-marking, counts, run verdict — then AUTO-PROCEED:
 * a strict plan waiting on its catalog gets planning dispatched immediately
 * (owner decision 2026-09-06: no review pause; the catalog page in Settings
 * is for later exclusions).
 */
class FinalizeProductCatalogJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public string $runId)
    {
        $this->onQueue(Queues::CRAWL_FINALIZE);
        $this->onConnection('redis-long');
    }

    public function handle(): void
    {
        $run = ContentProductRun::query()->find($this->runId);
        if ($run === null || ! in_array($run->status, ContentProductRun::IN_FLIGHT, true)) {
            return;
        }
        $run->forceFill(['status' => ContentProductRun::STATUS_FINALIZING, 'heartbeat_at' => now()])->save();

        // Wait-for-stragglers: LLM-extract jobs may still be running on the
        // content queue. They only ADD products; finalize with what exists —
        // late rows simply join the catalog (usable immediately).

        $this->dedupeVariants($run->website_id);

        // Full onboarding/settings/admin runs saw the whole catalog: anything
        // not seen this run is gone. Refresh runs are partial — never mark.
        if (in_array($run->trigger, ['onboarding', 'settings', 'banner', 'admin', 'monthly'], true) && $run->started_at !== null) {
            ContentProduct::query()
                ->where('website_id', $run->website_id)
                ->where('status', ContentProduct::STATUS_ACTIVE)
                ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $run->started_at))
                ->update(['status' => ContentProduct::STATUS_GONE]);
        }

        $count = ContentProduct::query()->where('website_id', $run->website_id)
            ->where('status', ContentProduct::STATUS_ACTIVE)->count();

        $run->forceFill([
            'status' => $count > 0 ? ContentProductRun::STATUS_READY : ContentProductRun::STATUS_FAILED,
            'error' => $count > 0 ? null : 'no_products_found',
            'products_extracted' => $count,
            'finished_at' => now(),
        ])->save();

        Log::info('content_catalog.finalized', [
            'website_id' => $run->website_id, 'products' => $count, 'status' => $run->status,
        ]);

        // AUTO-PROCEED: un-gate strict plans the moment the catalog is ready.
        if ($count > 0) {
            ContentPlan::query()
                ->where('website_id', $run->website_id)
                ->where('product_mode', 'strict')
                ->get()
                ->each(fn (ContentPlan $plan) => PlanContentTopicsJob::dispatch($plan->id));
        }
    }

    /**
     * Variant rows (same product, URL differing only by variant query/suffix)
     * collapse to one: keep the row with the richest data (has offer/image),
     * mark the rest gone. Match key = folded name + image.
     */
    private function dedupeVariants(string $websiteId): void
    {
        $products = ContentProduct::query()
            ->where('website_id', $websiteId)
            ->where('status', ContentProduct::STATUS_ACTIVE)
            ->orderByRaw('(price_cents IS NULL), (image_url IS NULL), LENGTH(COALESCE(description, "")) DESC')
            ->get(['id', 'name', 'image_url']);

        $seen = [];
        $gone = [];
        foreach ($products as $p) {
            $key = mb_strtolower(trim((string) $p->name)).'|'.(string) $p->image_url;
            if (isset($seen[$key])) {
                $gone[] = $p->id;
            } else {
                $seen[$key] = true;
            }
        }
        if ($gone !== []) {
            ContentProduct::query()->whereIn('id', $gone)->update(['status' => ContentProduct::STATUS_GONE]);
        }
    }

    public function failed(\Throwable $e): void
    {
        ContentProductRun::query()->whereKey($this->runId)->update([
            'status' => ContentProductRun::STATUS_FAILED,
            'error' => mb_substr('finalize: '.$e->getMessage(), 0, 120),
            'finished_at' => now(),
        ]);
    }
}
