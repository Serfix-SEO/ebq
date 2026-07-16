<?php

namespace App\Jobs;

use App\Models\DomainMetric;
use App\Models\WebsiteReportSnapshot;
use App\Services\Crawler\CrawlFetcher;
use App\Services\Llm\LlmClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Topical Trust enrichment (the "Topical relevance" report section): classify
 * the strongest referring domains of a ready report into a fixed topic
 * taxonomy and flag whether each is topically relevant to the target site.
 *
 *  - Homepage title/meta fetched free via CrawlFetcher (SSRF-guarded);
 *    domains with a cached topic in domain_metrics skip the fetch — each
 *    domain is ever classified once platform-wide, so LLM cost decays.
 *  - ONE completeJson call for the whole batch.
 *  - Patches payload['topical_trust'] with a guarded update (same pattern as
 *    ReportEnrichmentService) — never regenerates, never bumps the schema.
 *  - Deliberately does NOT feed the Trust Score number: scores stay
 *    deterministic/reproducible; this section is a separate lens.
 *
 * Any failure → section simply absent (views are fully guarded).
 */
class EnrichTopicalTrustJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 240;

    public int $tries = 1;

    public const TOPICS = [
        'Business & Industry', 'Technology & Internet', 'News & Media',
        'Shopping & E-commerce', 'Health & Medicine', 'Finance & Insurance',
        'Education & Science', 'Travel & Tourism', 'Arts & Entertainment',
        'Sports & Recreation', 'Home & Lifestyle', 'Law & Government',
        'Directories & Link Sites', 'Adult & Gambling', 'Other',
    ];

    /** Chain backstop far above total_cap/batch — a runaway guard, not a tuning knob. */
    private const MAX_ROUNDS = 60;

    public function __construct(public string $domain, public int $round = 0)
    {
        // First batch is what the user is watching (the "Analyzing…" card) —
        // it rides the INTERACTIVE queue so it never waits behind long
        // backfill chains; the self-chained continuation rounds are backfill
        // and queue at DEFAULT priority.
        $this->onQueue($round === 0 ? \App\Support\Queues::INTERACTIVE : \App\Support\Queues::DEFAULT);
    }

    public function handle(CrawlFetcher $fetcher, LlmClient $llm): void
    {
        if (! (bool) config('services.report.topical_trust.enabled', true)) {
            $this->clearPending(); // kill switch flipped after the stub was stamped

            return;
        }

        try {
            $this->run($fetcher, $llm);
        } catch (\Throwable $e) {
            Log::warning('EnrichTopicalTrustJob: failed', ['domain' => $this->domain, 'message' => $e->getMessage()]);
            $this->clearPending(); // never leave the UI spinner stuck
        }
    }

    /**
     * Remove the `topical_trust` pending stub so the UI stops showing the
     * progress card when classification failed or wasn't possible.
     */
    private function clearPending(): void
    {
        try {
            $snapshot = WebsiteReportSnapshot::forDomain($this->domain);
            if ($snapshot === null || $snapshot->status !== 'ready' || empty($snapshot->payload)) {
                return;
            }
            $payload = $snapshot->payload;
            if (! empty($payload['topical_trust']['pending'])) {
                unset($payload['topical_trust']);
                WebsiteReportSnapshot::query()
                    ->where('id', $snapshot->id)
                    ->where('status', 'ready')
                    ->update(['payload' => json_encode($payload)]);
            }
        } catch (\Throwable) {
            // best-effort — a stale stub also expires client-side after 30 min
        }
    }

    private function run(CrawlFetcher $fetcher, LlmClient $llm): void
    {
        $snapshot = WebsiteReportSnapshot::forDomain($this->domain);
        if ($snapshot === null || $snapshot->status !== 'ready' || empty($snapshot->payload)) {
            return;
        }

        // ALL referring domains (capped), processed one batch per job run —
        // the job re-dispatches itself until every domain is classified, so
        // the section appears after the first batch and coverage grows to
        // 100% without any single long-running queue job.
        $cap = max(25, (int) config('services.report.topical_trust.total_cap', 1000));
        $batchSize = max(3, (int) config('services.report.topical_trust.batch', 25));

        $referring = array_values(array_unique(array_filter(
            array_map(
                static fn ($r) => is_array($r) ? strtolower(trim((string) ($r['domain'] ?? ''))) : '',
                array_slice($snapshot->payload['top_referring_domains'] ?? [], 0, $cap),
            ),
        )));
        if (count($referring) < 3) {
            $this->clearPending();

            return; // nothing meaningful to classify
        }

        // Rows already classified for THIS report (earlier rounds).
        $existing = [];
        foreach ((array) ($snapshot->payload['topical_trust']['rows'] ?? []) as $r) {
            if (is_array($r) && isset($r['domain'])) {
                $existing[$r['domain']] = $r;
            }
        }

        $batch = array_slice(
            array_values(array_filter($referring, fn ($d) => ! isset($existing[$d]))),
            0,
            $batchSize,
        );
        if ($batch === []) {
            return; // fully classified already
        }

        $cachedTopics = DomainMetric::query()
            ->whereIn('domain', $batch)
            ->whereNotNull('topic_classified_at')
            ->pluck('topic', 'domain')
            ->all();

        // Snippets: target site first (context for relevance), then each
        // batch domain — cached topics skip the live fetch entirely.
        $targetSnippet = $this->homepageSnippet($fetcher, $this->domain);
        $lines = [];
        foreach ($batch as $d) {
            if (isset($cachedTopics[$d]) && $cachedTopics[$d] !== null) {
                $lines[] = "{$d} | known topic: {$cachedTopics[$d]}";
            } else {
                $lines[] = "{$d} | ".($this->homepageSnippet($fetcher, $d) ?: 'no homepage text available');
            }
        }

        $taxonomy = implode('; ', self::TOPICS);
        $result = $llm->completeJson([
            [
                'role' => 'system',
                'content' => 'You classify websites into topics. Reply with strict JSON only: '
                    .'{"target_topic": string, "domains": [{"domain": string, "topic": string, "relevant": bool}]}. '
                    ."Allowed topics (pick exactly one per site): {$taxonomy}. "
                    .'"relevant" is true when the domain\'s topic/audience plausibly overlaps the target site\'s topic '
                    .'(a niche blog linking to a shop in the same niche is relevant; a generic link directory is not).',
            ],
            [
                'role' => 'user',
                'content' => "Target site: {$this->domain} | ".($targetSnippet ?: 'no homepage text available')
                    ."\n\nReferring domains:\n".implode("\n", $lines),
            ],
        ], array_filter([
            'json_object' => true,
            'temperature' => 0.1,
            'max_tokens' => 2000,
            'timeout' => 60,
            // Cheap-tier model: fixed-taxonomy classification with snippet
            // evidence doesn't need the premium model (config override wins;
            // falls back to the provider's default when unmapped).
            'model' => $this->cheapModel(),
            '__source' => 'topical_trust',
        ]));

        $newRows = [];
        foreach ((array) ($result['domains'] ?? []) as $r) {
            $d = strtolower(trim((string) ($r['domain'] ?? '')));
            $topic = (string) ($r['topic'] ?? '');
            if ($d === '' || ! in_array($d, $batch, true) || ! in_array($topic, self::TOPICS, true)) {
                continue;
            }
            $newRows[$d] = ['domain' => $d, 'topic' => $topic, 'relevant' => (bool) ($r['relevant'] ?? false)];
        }
        if ($newRows === [] || ($existing === [] && count($newRows) < 3)) {
            $this->clearPending();

            return; // LLM answer unusable — leave section absent (or as-is)
        }

        // Cache topics platform-wide (classified once, reused forever).
        foreach ($newRows as $d => $row) {
            DomainMetric::query()->updateOrCreate(
                ['domain' => $d],
                ['topic' => $row['topic'], 'topic_classified_at' => now(),
                    'first_seen_at' => now(), 'last_seen_at' => now()],
            );
        }

        // Merge this batch into the accumulated rows and recompute the section
        // over EVERYTHING classified so far.
        $rows = array_merge($existing, $newRows);
        $topicCounts = [];
        $relevant = 0;
        foreach ($rows as $row) {
            $topicCounts[$row['topic']] = ($topicCounts[$row['topic']] ?? 0) + 1;
            $relevant += ! empty($row['relevant']) ? 1 : 0;
        }
        arsort($topicCounts);

        $previousTarget = $snapshot->payload['topical_trust']['target_topic'] ?? null;
        $section = [
            'target_topic' => in_array($result['target_topic'] ?? '', self::TOPICS, true)
                ? $result['target_topic']
                : (in_array($previousTarget, self::TOPICS, true) ? $previousTarget : null),
            'topics' => array_map(
                static fn ($topic, $count) => ['topic' => $topic, 'count' => $count],
                array_keys($topicCounts),
                array_values($topicCounts),
            ),
            'relevant_pct' => (int) round(100 * $relevant / count($rows)),
            'sample' => count($rows),
            'total' => count($referring),
            'rows' => array_values($rows),
        ];

        // Guarded patch — only lands on the same still-ready snapshot.
        WebsiteReportSnapshot::query()
            ->where('id', $snapshot->id)
            ->where('status', 'ready')
            ->update(['payload' => json_encode(array_merge($snapshot->fresh()->payload ?? [], ['topical_trust' => $section]))]);

        // More domains left → chain the next batch. Each run stays well under
        // the queue's retry_after; the section above already renders and its
        // numbers refine as batches land.
        if (count($rows) < count($referring) && $this->round < self::MAX_ROUNDS) {
            self::dispatch($this->domain, $this->round + 1)->delay(now()->addSeconds(3));
        }
    }

    /**
     * The cheap model for the current provider ('' = provider default, which
     * the LLM clients treat as "use the configured model"). Config
     * services.report.topical_trust.model overrides per env.
     */
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

    /** Homepage <title> + meta description, capped — free, SSRF-guarded. */
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
}
