<?php

namespace App\Jobs\Content;

use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Services\Content\CompetitorMentionGuard;
use App\Services\Content\HumanizerService;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Retroactive brand-safety scan (Phase E): when a brand is newly blocked, the
 * guard only protects FUTURE articles — anything already published may still
 * recommend the rival. This job lints every published article's current
 * version against the plan's blocked terms/domains and stamps hits on
 * `topic.meta['brand_safety']` so the review page can warn the client.
 *
 * Deterministic (no LLM, no spend), idempotent (re-stamps from scratch each
 * run, clearing stale flags), flag-only — published content is NEVER edited.
 */
class ScanPublishedForBlockedTermsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public string $planId)
    {
        $this->onQueue(Queues::CONTENT);
        $this->onConnection('redis-long');
    }

    public function uniqueId(): string
    {
        return $this->planId;
    }

    public function handle(CompetitorMentionGuard $guard, HumanizerService $humanizer): void
    {
        $plan = ContentPlan::query()->find($this->planId);
        if ($plan === null) {
            return;
        }

        $blockedDomains = $guard->blockedDomains($plan);

        $plan->topics()
            ->where('status', ContentTopic::STATUS_PUBLISHED)
            ->with(['articles' => fn ($q) => $q->where('is_current', true)])
            ->chunkById(50, function ($topics) use ($plan, $guard, $humanizer, $blockedDomains) {
                foreach ($topics as $topic) {
                    $article = $topic->articles->first();
                    if ($article === null || blank($article->html)) {
                        continue;
                    }

                    // Same per-topic exemption as generation: an article ABOUT
                    // a rival ("semrush alternatives") is allowed to name it.
                    $terms = $guard->termsForTopic($plan, $topic);

                    $hits = [];
                    if ($terms !== [] || $blockedDomains !== []) {
                        foreach ($humanizer->lint((string) $article->html, $terms, $blockedDomains) as $issue) {
                            if (($issue['code'] ?? '') === 'competitor_mentions') {
                                $hits[] = $issue;
                            }
                        }
                    }

                    $meta = (array) ($topic->meta ?? []);
                    if ($hits !== []) {
                        $meta['brand_safety'] = [
                            'flagged_at' => now()->toIso8601String(),
                            'issues' => array_slice($hits, 0, 5),
                        ];
                    } else {
                        unset($meta['brand_safety']); // clear stale flags
                    }
                    $topic->forceFill(['meta' => $meta])->saveQuietly();
                }
            });
    }
}
