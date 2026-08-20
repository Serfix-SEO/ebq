<?php

namespace App\Jobs\Content;

use App\Jobs\PublishContentArticleJob;
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
 * Remove blocked competitor terms from a topic's CURRENT article, then send
 * it (back) to the connected platforms. Two entry points:
 *
 *  - PublishContentArticleJob's brand gate: an article about to go out still
 *    carries a blocked term (added after generation, or via a client edit) —
 *    scrub it, then re-enter publish with $afterScrub so a second gate trip
 *    fails the topic instead of looping.
 *  - The review page's "Remove blocked mentions" action on a topic the
 *    retroactive scan flagged (brand_safety in topic.meta) — scrub the live
 *    article and republish the clean version over the same external ids.
 *
 * If the scrub cannot fully clean the article the topic FAILS with a
 * brand_safety error — the dispatcher explicitly does NOT regenerate those
 * (a stubborn everyday word would otherwise regenerate forever, billing every
 * cycle), so it sits visibly in "needs attention" until a human decides.
 */
class CleanBlockedTermsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600; // two 240s scrub passes + scoring

    public function __construct(public string $topicId)
    {
        $this->onQueue(Queues::CONTENT);
        $this->onConnection('redis-long');
    }

    public function uniqueId(): string
    {
        return $this->topicId;
    }

    public function handle(ContentArticleProducer $producer): void
    {
        $topic = ContentTopic::query()->with('plan.website')->find($this->topicId);
        if ($topic === null) {
            return;
        }

        $wasPublished = $topic->status === ContentTopic::STATUS_PUBLISHED;

        $clean = $producer->cleanCurrentArticle($topic);
        if (! $clean) {
            $topic->fail('brand_safety: could not remove blocked terms from the current article.');
            Log::warning('content_autopilot.brand_clean_failed', ['topic_id' => $topic->id]);

            return;
        }

        // Clear the retroactive-scan flag — the article it flagged is gone.
        $meta = (array) ($topic->meta ?? []);
        unset($meta['brand_safety']);
        $topic->forceFill(['meta' => $meta])->saveQuietly();

        // The scrub stored a NEW article version, so the publish job claims a
        // fresh publication row per integration and seeds it with the topic's
        // prior external_id — the platforms get an UPDATE over the same post,
        // never a duplicate. No re-arming needed here.
        $topic->enterStage(ContentTopic::STATUS_SCHEDULED);
        PublishContentArticleJob::dispatch($topic->id, afterScrub: true);
        Log::info('content_autopilot.brand_clean_ok', ['topic_id' => $topic->id, 'was_published' => $wasPublished]);
    }

    public function failed(\Throwable $e): void
    {
        ContentTopic::query()->whereKey($this->topicId)
            ->first()?->fail('brand_safety: cleanup error — '.mb_substr($e->getMessage(), 0, 300));
    }
}
