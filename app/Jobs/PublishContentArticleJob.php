<?php

namespace App\Jobs;

use App\Models\ContentPublication;
use App\Models\ContentTopic;
use App\Services\Content\Publishing\PublishDriverFactory;
use App\Support\Audit\SafeHttpGuard;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Content Autopilot Phase 3: push one scheduled article to every connected
 * integration for its website. Claimed by the dispatcher (scheduled → this
 * job); topic transitions SCHEDULED → PUBLISHING → PUBLISHED | FAILED.
 *
 * Idempotency contract (the invariant the schema was built for):
 *  - one content_publications row per (article, integration), claimed with
 *    firstOrCreate BEFORE any HTTP call;
 *  - retries re-enter through the same row — when it already carries an
 *    external_id the driver's update() runs, never a second publish();
 *  - a topic is PUBLISHED when at least one integration confirmed; it only
 *    FAILS when every integration hard-failed.
 *
 * Post-publish verify: SSRF-guarded GET of the live URL, expects HTTP 200 +
 * the H1 text present + no noindex — sets verified_at (best-effort; a slow
 * cache/CDN never fails the publication).
 *
 * tries=3 with backoff — unlike the LLM pipeline jobs (tries=1, retries
 * re-bill), publishing is idempotent by construction so retrying is safe.
 */
class PublishContentArticleJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 300;

    public function __construct(public string $topicId)
    {
        $this->onQueue(Queues::CONTENT);
    }

    public function uniqueId(): string
    {
        return $this->topicId;
    }

    public function handle(PublishDriverFactory $drivers, SafeHttpGuard $guard): void
    {
        $topic = ContentTopic::query()->with('plan.website')->find($this->topicId);
        if ($topic === null) {
            return;
        }
        if (! in_array($topic->status, [ContentTopic::STATUS_SCHEDULED, ContentTopic::STATUS_PUBLISHING], true)) {
            return; // veto'd / already handled — never re-publish
        }

        $article = $topic->currentArticle()->first() ?? $topic->articles()->where('is_current', true)->first();
        if ($article === null) {
            $topic->fail('No current article version to publish.');

            return;
        }

        $integrations = $topic->plan?->website?->contentIntegrations()
            ->where('status', \App\Models\ContentIntegration::STATUS_CONNECTED)
            ->get() ?? collect();
        if ($integrations->isEmpty()) {
            // Nothing connected: the article stays SCHEDULED so it publishes
            // automatically once the client connects a platform. Not a failure.
            return;
        }

        $topic->enterStage(ContentTopic::STATUS_PUBLISHING);

        $confirmed = 0;
        $hardFailed = 0;
        $transientFailed = 0;
        $liveUrl = null;

        foreach ($integrations as $integration) {
            $driver = $drivers->for($integration);
            if ($driver === null) {
                continue; // platform not yet supported (plugin/shopify)
            }

            // Idempotency anchor: claim/reuse the unique row BEFORE any HTTP.
            $publication = ContentPublication::query()->firstOrCreate(
                ['article_id' => $article->id, 'integration_id' => $integration->id],
                ['status' => ContentPublication::STATUS_QUEUED],
            );
            if ($publication->status === ContentPublication::STATUS_CONFIRMED) {
                $confirmed++;
                $liveUrl ??= $publication->external_url;

                continue; // already delivered on a previous attempt
            }

            $publication->forceFill([
                'status' => ContentPublication::STATUS_SENT,
                'attempts' => (int) $publication->attempts + 1,
            ])->save();

            $result = $publication->external_id
                ? $driver->update($article, $integration, (string) $publication->external_id)
                : $driver->publish($article, $integration);

            if ($result->ok) {
                $publication->forceFill([
                    'status' => ContentPublication::STATUS_CONFIRMED,
                    'external_id' => $result->externalId ? mb_substr($result->externalId, 0, 100) : $publication->external_id,
                    'external_url' => $result->externalUrl ? mb_substr($result->externalUrl, 0, 600) : $publication->external_url,
                    'response' => $result->response,
                    'published_at' => now(),
                ])->save();
                $confirmed++;
                $liveUrl ??= $publication->external_url;
            } else {
                $publication->forceFill([
                    'status' => ContentPublication::STATUS_FAILED,
                    'response' => $result->response + ['error' => $result->error],
                ])->save();
                $integration->forceFill(['last_error' => mb_substr((string) $result->error, 0, 500)])->save();
                $result->transient ? $transientFailed++ : $hardFailed++;
                Log::warning('Content publish failed', [
                    'topic_id' => $topic->id,
                    'integration' => $integration->platform,
                    'transient' => $result->transient,
                    'error' => $result->error,
                ]);
            }
        }

        if ($confirmed > 0) {
            $topic->forceFill([
                'status' => ContentTopic::STATUS_PUBLISHED,
                'published_at' => now(),
                'last_error' => null,
            ])->save();
            $this->verifyLiveUrl($topic, $article, $liveUrl, $guard);

            return;
        }

        if ($transientFailed > 0 && $this->attempts() < $this->tries) {
            // Put the topic back so the retry re-enters cleanly.
            $topic->enterStage(ContentTopic::STATUS_SCHEDULED);
            $this->release($this->backoff[$this->attempts() - 1] ?? 300);

            return;
        }

        $topic->fail('Publishing failed on every connected platform.');
    }

    public function failed(\Throwable $e): void
    {
        ContentTopic::query()->whereKey($this->topicId)
            ->whereIn('status', [ContentTopic::STATUS_PUBLISHING, ContentTopic::STATUS_SCHEDULED])
            ->first()?->fail('Publishing error: '.$e->getMessage());
    }

    /** Best-effort live check: 200 + H1 present + not noindexed → verified_at. */
    private function verifyLiveUrl(ContentTopic $topic, $article, ?string $liveUrl, SafeHttpGuard $guard): void
    {
        if (! $liveUrl || ! ($guard->check($liveUrl)['ok'] ?? false)) {
            return;
        }
        try {
            $response = Http::timeout(20)->connectTimeout(8)->get($liveUrl);
            $html = (string) $response->body();
            $h1 = trim((string) $article->h1);
            $ok = $response->ok()
                && ($h1 === '' || str_contains($html, e($h1)) || str_contains($html, $h1))
                && ! preg_match('/<meta[^>]+noindex/i', $html);
            if ($ok) {
                ContentPublication::query()
                    ->where('article_id', $article->id)
                    ->where('external_url', $liveUrl)
                    ->update(['verified_at' => now()]);
            }
        } catch (\Throwable) {
            // verification is best-effort; never fail a delivered publication
        }
    }
}
