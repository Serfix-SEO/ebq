<?php

namespace App\Console\Commands;

use App\Mail\ContentArticlePublishedMail;
use App\Models\ContentPublication;
use App\Models\ContentTopic;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Preview/QA helper: render the "your article is live" email for a real
 * published topic and send it to an arbitrary address. Read-only — it never
 * touches the topic (no `published_notified_at` stamp), so running it does not
 * suppress the real notification.
 */
class SendContentPublishedTestMail extends Command
{
    protected $signature = 'ebq:content-published-test-mail
        {email : Where to send the preview}
        {--topic= : Topic id to render (default: the most recently published topic)}';

    protected $description = 'Send a preview of the content-published notification email';

    public function handle(): int
    {
        $topic = $this->option('topic')
            ? ContentTopic::query()->with('plan.website')->find($this->option('topic'))
            : ContentTopic::query()->with('plan.website')
                ->where('status', ContentTopic::STATUS_PUBLISHED)
                ->orderByDesc('published_at')
                ->first();

        if ($topic === null) {
            $this->error('No published topic found to render.');

            return self::FAILURE;
        }

        $article = $topic->currentArticle()->first();
        $website = $topic->plan?->website;
        $owner = $website?->owner;

        if ($article === null || $website === null || $owner === null) {
            $this->error("Topic {$topic->id} has no current article / website / owner.");

            return self::FAILURE;
        }

        $publication = ContentPublication::query()
            ->where('article_id', $article->id)
            ->where('status', ContentPublication::STATUS_CONFIRMED)
            ->latest('published_at')
            ->first();

        $email = (string) $this->argument('email');

        Mail::to($email)->send(new ContentArticlePublishedMail(
            user: $owner,
            website: $website,
            topic: $topic,
            article: $article,
            liveUrl: $publication?->external_url,
            platforms: array_values(array_filter([$publication?->integration?->platformLabel()])),
        ));

        $this->info("Sent preview for topic {$topic->id} ({$website->domain}) to {$email}.");

        return self::SUCCESS;
    }
}
