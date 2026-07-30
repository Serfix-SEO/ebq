<?php

namespace App\Jobs;

use App\Jobs\Content\ShareArticleToSocialJob;
use App\Mail\ContentArticlePublishedMail;
use App\Models\ContentIntegration;
use App\Models\ContentPublication;
use App\Models\ContentTopic;
use App\Models\ContentTrackedKeyword;
use App\Services\Content\ContentKeywordTracker;
use App\Services\Content\Publishing\PublishDriverFactory;
use App\Services\Google\GoogleIndexingService;
use App\Support\Audit\SafeHttpGuard;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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
        $this->onConnection('redis-long');
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
            ->where('status', ContentIntegration::STATUS_CONNECTED)
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

            // Carry the external post id forward across article VERSIONS: when
            // a topic is regenerated it produces a new article row, and without
            // this the new publication would have no external_id and publish a
            // DUPLICATE post (…-2 slug) instead of updating the original. Seed
            // the new row's external_id from the topic's most recent delivered
            // publication so it routes through update() (which also re-sideloads
            // images onto the existing post).
            $priorExternalId = ContentPublication::query()
                ->whereIn('article_id', $topic->articles()->select('id'))
                ->where('integration_id', $integration->id)
                ->whereNotNull('external_id')
                ->orderByDesc('id')
                ->value('external_id');

            // Idempotency anchor: claim/reuse the unique row BEFORE any HTTP.
            $publication = ContentPublication::query()->firstOrCreate(
                ['article_id' => $article->id, 'integration_id' => $integration->id],
                ['status' => ContentPublication::STATUS_QUEUED, 'external_id' => $priorExternalId],
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
            $this->submitToGoogleIndex($topic, $article, $liveUrl);
            $this->addToKeywordTracker($topic, $liveUrl);
            $this->notifyPublished($topic, $article, $liveUrl, $integrations);
            $this->shareToSocial($topic, $liveUrl);

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

    /**
     * Queue the social auto-share (Facebook/X) for the freshly published
     * article. Best-effort and asynchronous — social APIs are slow/flaky and
     * must never delay or fail the publish. The job itself re-checks every
     * guard (kill switch, once-per-topic stamp, live-link pre-flight).
     */
    private function shareToSocial(ContentTopic $topic, ?string $liveUrl): void
    {
        try {
            if ($liveUrl === null || $liveUrl === '') {
                return; // no real URL, nothing to share — never guess one
            }
            ShareArticleToSocialJob::dispatch($topic->id, $liveUrl);
        } catch (\Throwable $e) {
            Log::warning('content_autopilot.social_share_dispatch_error', [
                'topic_id' => $topic->id, 'error' => mb_substr($e->getMessage(), 0, 300),
            ]);
        }
    }

    /**
     * When the site has Google Search Console connected, submit the freshly
     * published URL to the Google Indexing API for faster discovery. Best-effort:
     * gated on Website::hasGsc() (no GSC → the review/settings UI shows the
     * "connect Search Console" notice instead), never fails the publish.
     */
    private function submitToGoogleIndex(ContentTopic $topic, $article, ?string $liveUrl): void
    {
        $website = $topic->plan?->website;
        if ($website === null || $article === null || ! $liveUrl || ! $website->hasGsc()) {
            return;
        }
        try {
            $result = app(GoogleIndexingService::class)->submitUrl($website, $liveUrl);
            // Audit trail on the delivered publication(s).
            ContentPublication::query()
                ->where('article_id', $article->id)
                ->where('external_url', $liveUrl)
                ->get()
                ->each(fn (ContentPublication $p) => $p->forceFill([
                    'response' => ((array) $p->response) + ['indexing' => $result],
                ])->save());
            Log::info('content_autopilot.index_submit', ['topic_id' => $topic->id, 'status' => $result['status']]);
        } catch (\Throwable $e) {
            Log::warning('content_autopilot.index_submit_error', ['topic_id' => $topic->id, 'error' => mb_substr($e->getMessage(), 0, 300)]);
        }
    }

    /**
     * Tell the site owner their article is live. Best-effort — never fails the
     * publish. Sent once per topic (stamped in `meta.published_notified_at`) so
     * a regenerated version re-publishing the same post doesn't re-announce it.
     *
     * @param  Collection<int, ContentIntegration>  $integrations
     */
    private function notifyPublished(ContentTopic $topic, $article, ?string $liveUrl, $integrations): void
    {
        $website = $topic->plan?->website;
        $owner = $website?->owner;
        if ($website === null || $owner === null || ! $owner->email) {
            return;
        }
        $meta = (array) ($topic->meta ?? []);
        if (! empty($meta['published_notified_at'])) {
            return;
        }

        try {
            $platforms = $integrations
                ->filter(fn (ContentIntegration $i) => ContentPublication::query()
                    ->where('article_id', $article->id)
                    ->where('integration_id', $i->id)
                    ->where('status', ContentPublication::STATUS_CONFIRMED)
                    ->exists())
                ->map(fn (ContentIntegration $i) => $i->platformLabel())
                ->values()->all();

            Mail::to($owner->email)->queue(new ContentArticlePublishedMail(
                user: $owner,
                website: $website,
                topic: $topic,
                article: $article,
                liveUrl: $liveUrl,
                platforms: $platforms,
            ));

            $topic->forceFill(['meta' => $meta + ['published_notified_at' => now()->toIso8601String()]])->save();
            Log::info('content_autopilot.publish_notified', ['topic_id' => $topic->id, 'to' => $owner->email]);
        } catch (\Throwable $e) {
            Log::warning('content_autopilot.publish_notify_error', ['topic_id' => $topic->id, 'error' => mb_substr($e->getMessage(), 0, 300)]);
        }
    }

    /**
     * Auto-populate the Keyword Tracker with this article's targeted keywords
     * (target + secondaries) on first publish. Best-effort — never fails the
     * publish; respects the per-website capacity (overflow simply isn't added,
     * and the Tracker page shows the delete-to-add banner).
     */
    private function addToKeywordTracker(ContentTopic $topic, ?string $liveUrl): void
    {
        $website = $topic->plan?->website;
        if ($website === null) {
            return;
        }
        try {
            $tracker = app(ContentKeywordTracker::class);
            $result = $tracker->track(
                website: $website,
                keywords: $tracker->keywordsFor($topic),
                topic: $topic,
                source: ContentTrackedKeyword::SOURCE_AUTO,
                pageUrl: $liveUrl,
            );
            if (($result['added'] ?? 0) > 0) {
                // Kick a live-SERP check so a position shows immediately (GSC lags days).
                CheckTrackedKeywordSerpJob::dispatch($website->id);
            }
            Log::info('content_autopilot.tracker_autoadd', ['topic_id' => $topic->id] + $result);
        } catch (\Throwable $e) {
            Log::warning('content_autopilot.tracker_autoadd_error', ['topic_id' => $topic->id, 'error' => mb_substr($e->getMessage(), 0, 300)]);
        }
    }

    /** Best-effort live check: 200 + title present + not noindexed → verified_at. */
    private function verifyLiveUrl(ContentTopic $topic, $article, ?string $liveUrl, SafeHttpGuard $guard): void
    {
        if (! $liveUrl || ! ($guard->check($liveUrl)['ok'] ?? false)) {
            return;
        }
        try {
            $response = Http::timeout(20)->connectTimeout(8)->get($liveUrl);
            $html = (string) $response->body();
            // Match the title we actually published — drivers post
            // meta_title (falling back to h1), which the CMS renders as the
            // page heading. Checking the raw h1 would miss when the two differ.
            $needle = trim((string) ($article->meta_title ?: $article->h1));
            $ok = $response->ok()
                && ($needle === '' || str_contains($html, e($needle)) || str_contains($html, $needle))
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
