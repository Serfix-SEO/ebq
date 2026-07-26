<?php

namespace App\Jobs\Competitive;

use App\Jobs\EnrichTopicalTrustJob;
use App\Models\CompetitorBacklink;
use App\Models\DiscoveredCompetitor;
use App\Models\DomainMetric;
use App\Models\Website;
use App\Services\Crawler\CrawlFetcher;
use App\Services\Llm\LlmClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * OPT-IN topical relevance for a Site Explorer website's discovered competitors.
 *
 * This is the one enrichment the caveat says must NOT run automatically during
 * discovery (an LLM call costs money per batch), so it fires only when the user
 * clicks "Classify topics" on the competitor table. It classifies each competitor
 * into the shared {@see EnrichTopicalTrustJob::TOPICS} taxonomy and flags whether
 * it plausibly overlaps the site's own niche — the same signal the Content
 * Autopilot analysis surfaces, reusing the platform-wide `domain_metrics.topic`
 * cache (classified once, reused everywhere, forever).
 *
 * ONE completeJson call for the whole (capped) batch. Domains already classified
 * in `domain_metrics` skip the LLM entirely — a repeat click is nearly free.
 */
class ClassifyCompetitorTopicJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public string $websiteId)
    {
        $this->onQueue(\App\Support\Queues::INTERACTIVE);
    }

    public function handle(CrawlFetcher $fetcher, LlmClient $llm): void
    {
        if (! $llm->isAvailable()) {
            return;
        }

        $website = Website::query()->find($this->websiteId);
        if (! $website instanceof Website) {
            return;
        }

        $cap = max(1, min(50, (int) config('services.competitive.discovery_enrich_max', 10)));
        $competitors = DiscoveredCompetitor::query()
            ->forWebsite($this->websiteId)
            ->orderByDesc('score')
            ->limit($cap)
            ->get();
        if ($competitors->isEmpty()) {
            return;
        }

        $ownDomain = CompetitorBacklink::extractDomain((string) $website->domain);

        // Reuse any platform-wide cached topic; only the rest need the LLM.
        $domains = $competitors->pluck('competitor_domain')->all();
        $cached = DomainMetric::query()
            ->whereIn('domain', $domains)
            ->whereNotNull('topic_classified_at')
            ->pluck('topic', 'domain')
            ->all();

        $lines = [];
        foreach ($domains as $d) {
            if (isset($cached[$d])) {
                $lines[] = "{$d} | known topic: {$cached[$d]}";
            } else {
                $lines[] = "{$d} | ".($this->homepageSnippet($fetcher, $d) ?: 'no homepage text available');
            }
        }

        $taxonomy = implode('; ', EnrichTopicalTrustJob::TOPICS);
        try {
            $result = $llm->completeJson([
                [
                    'role' => 'system',
                    'content' => 'You classify websites into topics. Reply with strict JSON only: '
                        .'{"target_topic": string, "domains": [{"domain": string, "topic": string, "relevant": bool}]}. '
                        ."Allowed topics (pick exactly one per site): {$taxonomy}. "
                        .'"relevant" is true when the domain\'s topic/audience plausibly overlaps the target site\'s topic.',
                ],
                [
                    'role' => 'user',
                    'content' => "Target site: {$ownDomain} | ".($this->homepageSnippet($fetcher, $ownDomain) ?: 'no homepage text available')
                        ."\n\nCompetitor domains:\n".implode("\n", $lines),
                ],
            ], array_filter([
                'json_object' => true,
                'temperature' => 0.1,
                'max_tokens' => 2000,
                'timeout' => 60,
                'model' => $this->cheapModel(),
                '__source' => 'competitor_topic',
            ]));
        } catch (\Throwable $e) {
            Log::warning('ClassifyCompetitorTopicJob: LLM failed', ['website' => $this->websiteId, 'message' => $e->getMessage()]);

            return;
        }

        $topics = [];
        $relevant = [];
        foreach ((array) ($result['domains'] ?? []) as $r) {
            $d = strtolower(trim((string) ($r['domain'] ?? '')));
            $topic = (string) ($r['topic'] ?? '');
            if ($d === '' || ! in_array($topic, EnrichTopicalTrustJob::TOPICS, true)) {
                continue;
            }
            $topics[$d] = $topic;
            $relevant[$d] = (bool) ($r['relevant'] ?? false);
        }

        foreach ($competitors as $c) {
            $d = $c->competitor_domain;
            $topic = $topics[$d] ?? $cached[$d] ?? null;
            if ($topic === null) {
                continue;
            }
            // Cache platform-wide (shared asset) …
            DomainMetric::query()->updateOrCreate(
                ['domain' => $d],
                ['topic' => $topic, 'topic_classified_at' => now(), 'first_seen_at' => now(), 'last_seen_at' => now()],
            );
            // … and denormalize onto the per-website row for display/export.
            $c->forceFill([
                'topic' => $topic,
                'topic_classified_at' => now(),
            ])->save();
        }
    }

    private function homepageSnippet(CrawlFetcher $fetcher, string $domain): string
    {
        try {
            $res = $fetcher->fetch('https://'.$domain.'/', timeout: 12);
            $body = (string) ($res['body'] ?? '');
            if (! ($res['ok'] ?? false) || $body === '') {
                return '';
            }
            $title = preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $m) ? trim(html_entity_decode(strip_tags($m[1]))) : '';
            $desc = preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']/is', $body, $m)
                ? trim(html_entity_decode($m[1]))
                : '';

            return mb_substr(trim($title.' — '.$desc, " —"), 0, 220);
        } catch (\Throwable) {
            return '';
        }
    }

    private function cheapModel(): string
    {
        $configured = trim((string) config('services.report.topical_trust.model', ''));
        if ($configured !== '') {
            return $configured;
        }

        return match (\App\Support\LlmProviderConfig::currentProvider()) {
            'deepseek' => 'deepseek-v4-flash',
            'mistral' => 'mistral-small-latest',
            default => '',
        };
    }
}
