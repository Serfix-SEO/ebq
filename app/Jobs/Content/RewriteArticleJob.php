<?php

namespace App\Jobs\Content;

use App\Models\ContentRewriteRequest;
use App\Models\ContentTopic;
use App\Services\Content\ContentArticleProducer;
use App\Services\Content\RewriteCredits;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Client-requested, credit-gated article rewrite. The credit was already
 * spent at dispatch (Livewire action, inside the request-row transaction) —
 * this job only ever REFUNDS, on any failure path (owner rule: failed
 * rewrites give the credit back automatically).
 *
 * tries=1 + unique per topic: one rewrite in flight per topic, and a retry
 * would re-bill LLM calls.
 */
class RewriteArticleJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public string $requestId, public string $topicId)
    {
        $this->onQueue(Queues::CONTENT);
        $this->onConnection('redis-long');
    }

    public function uniqueId(): string
    {
        return 'rewrite-article:'.$this->topicId;
    }

    public function handle(ContentArticleProducer $producer, RewriteCredits $credits): void
    {
        $request = ContentRewriteRequest::query()->find($this->requestId);
        if ($request === null || $request->status !== ContentRewriteRequest::STATUS_QUEUED) {
            return;
        }

        $topic = ContentTopic::query()->with('plan.website')->find($this->topicId);
        if ($topic === null || $topic->plan === null || $topic->website === null) {
            $request->update(['status' => ContentRewriteRequest::STATUS_FAILED, 'error' => 'topic gone']);
            $credits->refund($request);

            return;
        }

        $request->update(['status' => ContentRewriteRequest::STATUS_RUNNING]);
        $beforeVersion = (int) $topic->articles()->max('version');

        try {
            $producer->reviseCurrentArticle($topic, clientInstruction: $request->prompt ?: null);
        } catch (\Throwable $e) {
            $this->markFailed($request, $topic, $credits, $e->getMessage());
            Cache::forget('content:gen-start:'.$this->topicId);

            throw $e;
        }

        $topic->refresh();
        $final = $topic->articles()->where('is_current', true)->first();

        // Success = a version BEYOND the bookkeeping context_rescore one was
        // stored (rescore alone = the revise/scrub calls produced nothing —
        // includes the QuotaExceeded path, which the producer swallows).
        $produced = $final !== null && (int) $final->version > $beforeVersion + 1;

        if ($produced && $topic->status !== ContentTopic::STATUS_FAILED) {
            $request->update([
                'status' => ContentRewriteRequest::STATUS_DONE,
                'article_version' => (int) $final->version,
            ]);
            // Owner rule (2026-08-23): the credit is charged ONLY here — on a
            // FINALIZED rewrite. Internal passes and failures never spend.
            if ($request->credit_event_id === null) {
                $credits->spendForRequest($request);
            }
        } else {
            $this->markFailed($request, $topic, $credits,
                $topic->status === ContentTopic::STATUS_FAILED ? ('topic failed: '.$topic->last_error) : 'no rewrite version produced');
        }

        Cache::forget('content:gen-start:'.$this->topicId);
    }

    private function markFailed(ContentRewriteRequest $request, ?ContentTopic $topic, RewriteCredits $credits, string $error): void
    {
        $request->update([
            'status' => ContentRewriteRequest::STATUS_FAILED,
            'error' => mb_substr($error, 0, 500),
        ]);
        // Spend-at-finalize rows have nothing to refund (the failed request
        // simply stops reserving); refund() is a no-op then, and still
        // reverses any legacy spend-at-dispatch row.
        $credits->refund($request);

        // Put the topic back where it was — EXCEPT when the producer
        // legitimately fail()ed it (brand safety): that state keeps its
        // existing "needs attention" semantics.
        if ($topic !== null && $topic->status !== ContentTopic::STATUS_FAILED
            && $topic->status !== $request->prior_status) {
            $topic->forceFill(['status' => $request->prior_status])->save();
        }

        Log::warning('content_rewrite.failed', ['request_id' => $request->id, 'error' => $error]);
    }

    public function failed(\Throwable $e): void
    {
        $request = ContentRewriteRequest::query()->find($this->requestId);
        if ($request === null) {
            return;
        }
        if ($request->status !== ContentRewriteRequest::STATUS_FAILED) {
            $this->markFailed($request, ContentTopic::query()->find($this->topicId), app(RewriteCredits::class), 'job failed: '.$e->getMessage());
        }
        Cache::forget('content:gen-start:'.$this->topicId);
    }
}
