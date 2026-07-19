<?php

namespace App\Jobs;

use App\Models\ContentTopic;
use App\Services\Content\ContentArticleProducer;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Produce one topic's article end-to-end (research → write → score →
 * revise loop) via ContentArticleProducer. Ends with the topic `ready`
 * (or `failed`); publishing is a separate stage.
 *
 * tries=1 — a blind retry would re-bill several LLM calls; failures are
 * surfaced on the topic row and the ops digest, and the client can requeue
 * from the review UI. timeout 1800 < redis-long retry_after 3900.
 */
class ProduceContentArticleJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public int $uniqueFor = 3600;

    public function __construct(public string $topicId)
    {
        $this->onQueue(Queues::CONTENT);
        $this->onConnection('redis-long');
    }

    public function uniqueId(): string
    {
        return 'produce-content-article:'.$this->topicId;
    }

    public function handle(ContentArticleProducer $producer): void
    {
        $topic = ContentTopic::query()->find($this->topicId);
        if ($topic === null) {
            return;
        }

        // Only claimable states — a replayed/duplicate dispatch must not
        // clobber a topic that already moved on.
        if (! in_array($topic->status, [ContentTopic::STATUS_APPROVED, ContentTopic::STATUS_FAILED], true)) {
            return;
        }

        // Entitlement/limit gate — the SINGLE choke point every dispatch path
        // funnels through (manual writeNow/addAndWriteTopic/retry + the
        // dispatcher claim). Blocked = no access, website not covered, trial's
        // 3-article cap, or the monthly 60/website cap. The topic stays
        // APPROVED so it's producible again after upgrade / next month.
        // Publishing is never gated here.
        $reason = app(\App\Services\Content\ContentEntitlements::class)->blockReason($topic);
        if ($reason !== null) {
            Log::info('content_autopilot.generation_blocked', [
                'topic_id' => $topic->id, 'website_id' => $topic->website_id, 'reason' => $reason,
            ]);

            return;
        }

        $article = $producer->produce($topic);

        Log::info('content_autopilot.produced', [
            'topic_id' => $topic->id,
            'website_id' => $topic->website_id,
            'status' => $topic->fresh()->status,
            'score' => $article?->seo_score,
            'version' => $article?->version,
        ]);

        // Article is ready → generate images asynchronously (never blocks
        // publish; the job self-gates on the images toggle + Ideogram config
        // + spend cap). Only when production actually succeeded.
        if ($article !== null && $topic->fresh()->status === ContentTopic::STATUS_READY
            && \App\Support\ContentAutopilotConfig::imagesEnabled()) {
            GenerateContentImagesJob::dispatch($article->id);
        }
    }

    public function failed(\Throwable $e): void
    {
        ContentTopic::query()->find($this->topicId)?->fail('job_failed: '.$e->getMessage());
    }
}
