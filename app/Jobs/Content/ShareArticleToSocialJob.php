<?php

namespace App\Jobs\Content;

use App\Models\ContentSocialAccount;
use App\Models\ContentTopic;
use App\Services\Content\Social\SocialPoster;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Best-effort auto-share of a freshly published article's REAL public URL to
 * the website's connected social accounts (Facebook Page / X).
 *
 * Guards, in order: kill switch → topic PUBLISHED → once-per-topic
 * `meta.social_shared_at` stamp → live-link pre-flight (a dead or missing URL
 * is NEVER shared) → per-account post with independent failure isolation.
 * Account failures land on the account row (status/last_error) so the
 * Integrations card shows a reconnect prompt; nothing here ever affects the
 * publish itself.
 */
class ShareArticleToSocialJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 120;

    public function __construct(public string $topicId, public string $liveUrl)
    {
        $this->onQueue(Queues::CONTENT);
        $this->onConnection('redis-long');
    }

    public function handle(SocialPoster $poster): void
    {
        if (! config('services.content_autopilot.social_sharing', true)) {
            return;
        }
        $topic = ContentTopic::query()->with('website')->find($this->topicId);
        $article = $topic?->currentArticle()->first();
        if ($topic === null || $article === null || $topic->website === null
            || $topic->status !== ContentTopic::STATUS_PUBLISHED) {
            return;
        }
        if (! filter_var($this->liveUrl, FILTER_VALIDATE_URL) || ! str_starts_with($this->liveUrl, 'http')) {
            return; // only ever share a real URL
        }
        // Once per topic, ever — retries of the publish job must not repost.
        $meta = (array) ($topic->meta ?? []);
        if (! empty($meta['social_shared_at'])) {
            return;
        }

        $accounts = ContentSocialAccount::query()
            ->where('website_id', $topic->website->id)
            ->where('status', ContentSocialAccount::STATUS_CONNECTED)
            ->where('share_enabled', true)
            ->get();
        if ($accounts->isEmpty()) {
            return;
        }

        // Pre-flight: the link must actually be live before we put it on the
        // client's social feeds. Transient failure → retry via backoff.
        if (! $this->linkIsLive()) {
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 300);

                return;
            }
            Log::warning('content_social.link_not_live', ['topic_id' => $topic->id, 'url' => $this->liveUrl]);

            return;
        }

        $results = [];
        foreach ($accounts as $account) {
            $text = SocialPoster::compose(
                $account->provider,
                (string) ($article->h1 ?: $topic->title),
                (string) ($article->meta_description ?? ''),
                $this->liveUrl,
            );
            $result = $poster->post($account, $text, $this->liveUrl);
            $results[$account->provider] = $result['status'];

            if ($result['ok']) {
                $account->forceFill([
                    'status' => ContentSocialAccount::STATUS_CONNECTED,
                    'last_posted_at' => now(),
                    'last_error' => null,
                ])->save();
            } elseif ($result['status'] === 'reconnect') {
                $account->forceFill([
                    'status' => ContentSocialAccount::STATUS_ERROR,
                    'last_error' => mb_substr($result['message'], 0, 500),
                ])->save();
            } else {
                $account->forceFill(['last_error' => mb_substr($result['message'], 0, 500)])->save();
            }
            Log::info('content_social.share', [
                'topic_id' => $topic->id, 'provider' => $account->provider, 'status' => $result['status'],
            ]);
        }

        // Stamp only when at least one network accepted the post, so a fully
        // failed run can retry on the next publish attempt/backoff.
        if (in_array('posted', $results, true)) {
            $meta['social_shared_at'] = now()->toIso8601String();
            $meta['social_share_results'] = $results;
            $topic->forceFill(['meta' => $meta])->saveQuietly();
        }
    }

    private function linkIsLive(): bool
    {
        try {
            $response = Http::timeout(15)->connectTimeout(8)
                ->withHeaders(['User-Agent' => 'SerfixBot/1.0 (+https://serfix.io)'])
                ->get($this->liveUrl);

            return $response->status() < 400;
        } catch (\Throwable) {
            return false;
        }
    }
}
