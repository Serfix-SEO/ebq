<?php

namespace App\Jobs\Content;

use App\Models\ContentPlan;
use App\Models\ContentPlanKeyword;
use App\Models\DomainKeywordRanking;
use App\Models\KeywordMetric;
use App\Services\Content\ContentSetupInsights;
use App\Services\Llm\LlmClientFactory;
use App\Support\ContentAutopilotConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Classify a plan's harvested keywords ONCE into `own` (client already ranks) and
 * `gap` (relevant competitor keyword the client doesn't rank for). The gap set is
 * bulk-vetted for topical relevance by a cheap/fast LLM (flash tier) in chunks so
 * the FULL gap is covered — not just a top-N sample. Result lands in
 * content_plan_keywords + stamps `content_plans.keywords_classified_at`, so the
 * step-6 gap card shows the FINAL set and the topic planner reuses it without
 * re-filtering. See DATAFORSEO_KEYWORD_GAP_PLAN.md.
 */
class ClassifyPlanKeywordsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const MAX_COMPETITORS = 3;

    /** Bulk-classify this many keywords per LLM call (flash handles it cheaply). */
    private const CHUNK = 500;

    /** Cap the gap candidates classified per run (highest-volume first). */
    private const CANDIDATE_CAP = 2000;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public string $planId)
    {
        $this->onQueue('content');
        $this->onConnection('redis-long');
    }

    public function handle(ContentSetupInsights $setup): void
    {
        $plan = ContentPlan::query()->with('website')->find($this->planId);
        $website = $plan?->website;
        if ($plan === null || $website === null) {
            return;
        }
        $country = strtolower(trim((string) $plan->country)) ?: 'global';
        $ownDomain = strtolower(preg_replace('/^www\./', '', (string) ($website->normalized_domain ?: $website->domain)));

        // Competitor domains (top-3 authority order).
        $competitors = [];
        try {
            $insights = $setup->competitorAuthority($website);
        } catch (\Throwable) {
            $insights = null;
        }
        foreach ((array) ($insights['competitors'] ?? []) as $c) {
            $d = strtolower(preg_replace('/^www\./', '', trim((string) ($c['domain'] ?? ''))));
            if ($d !== '' && $d !== $ownDomain && ! in_array($d, $competitors, true)) {
                $competitors[] = $d;
                if (count($competitors) >= self::MAX_COMPETITORS) {
                    break;
                }
            }
        }
        if ($competitors === []) {
            return; // nothing harvested to classify yet
        }

        // ── OWN: everything the client already ranks for (inherently theirs).
        $ownRows = $ownDomain === '' ? collect() : DomainKeywordRanking::query()
            ->where('domain', $ownDomain)->where('country', $country)
            ->get(['keyword', 'keyword_hash', 'search_volume']);
        $ownHashes = $ownRows->pluck('keyword_hash')->all();

        // ── GAP candidates: competitor keywords the client doesn't rank for,
        // deduped (highest-volume ranking wins), capped, highest-volume first.
        $seen = [];
        foreach (DomainKeywordRanking::query()
            ->whereIn('domain', $competitors)->where('country', $country)
            ->when($ownHashes !== [], fn ($q) => $q->whereNotIn('keyword_hash', $ownHashes))
            ->where('search_volume', '>', 0)
            ->orderByDesc('search_volume')
            ->limit(self::CANDIDATE_CAP * 2)
            ->get(['keyword', 'keyword_hash', 'search_volume']) as $r) {
            if (isset($seen[$r->keyword_hash])) {
                continue;
            }
            $seen[$r->keyword_hash] = ['keyword' => $r->keyword, 'volume' => (int) $r->search_volume];
            if (count($seen) >= self::CANDIDATE_CAP) {
                break;
            }
        }
        if ($seen === [] && $ownRows->isEmpty()) {
            return;
        }

        $relevantHashes = $this->bulkRelevant($seen, $plan);

        // Facts (competition/difficulty/intent) from the shared keyword_metrics.
        $allHashes = array_merge($ownHashes, array_keys($seen));
        $facts = KeywordMetric::query()
            ->where('country', $country)->where('data_source', 'dfs_labs')
            ->whereIn('keyword_hash', $allHashes)
            ->get(['keyword_hash', 'competition', 'keyword_difficulty', 'search_intent'])
            ->keyBy('keyword_hash');

        $now = now();
        $rows = [];
        foreach ($ownRows as $r) {
            $f = $facts->get($r->keyword_hash);
            $rows[] = $this->row($plan->id, $r->keyword_hash, $r->keyword, ContentPlanKeyword::TYPE_OWN, (int) $r->search_volume, $country, $f, $now);
        }
        foreach ($seen as $hash => $c) {
            if (! isset($relevantHashes[$hash])) {
                continue; // off-topic gap keyword → dropped
            }
            $f = $facts->get($hash);
            $rows[] = $this->row($plan->id, $hash, $c['keyword'], ContentPlanKeyword::TYPE_GAP, $c['volume'], $country, $f, $now);
        }

        DB::transaction(function () use ($plan, $rows, $now) {
            ContentPlanKeyword::query()->where('plan_id', $plan->id)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                ContentPlanKeyword::query()->insert($chunk);
            }
            $plan->forceFill(['keywords_classified_at' => $now])->saveQuietly();
        });
    }

    /**
     * Bulk topical-relevance classification of the gap candidates (flash tier,
     * chunked). Returns a set (hash => true) of the relevant keyword hashes. On
     * LLM unavailability it FAILS CLOSED to volume order (keeps the top slice) so
     * we never tag the whole off-topic set as gap.
     *
     * @param  array<string, array{keyword:string, volume:int}>  $candidates
     * @return array<string, true>
     */
    private function bulkRelevant(array $candidates, ContentPlan $plan): array
    {
        if ($candidates === []) {
            return [];
        }
        $model = ContentAutopilotConfig::modelFor('classify'); // → provider default (flash)
        $llm = LlmClientFactory::make($model['provider']);
        $offer = implode(', ', array_slice((array) (($plan->offerings ?? [])['sell'] ?? []), 0, 12));
        $desc = mb_substr((string) $plan->business_description, 0, 500);

        if (! $llm->isAvailable()) {
            // Fail closed: keep only the top-40 by volume rather than tag thousands
            // of unvetted (often off-topic) keywords as gap.
            return array_fill_keys(array_slice(array_keys($candidates), 0, 40), true);
        }

        // hash keyed by keyword text for reverse lookup after the LLM returns text.
        $hashByKeyword = [];
        foreach ($candidates as $hash => $c) {
            $hashByKeyword[mb_strtolower(trim($c['keyword']))] = $hash;
        }

        $keep = [];
        foreach (array_chunk(array_values($candidates), self::CHUNK) as $chunk) {
            $list = implode("\n", array_map(static fn ($c) => $c['keyword'], $chunk));
            try {
                $response = $llm->completeJson([
                    ['role' => 'system', 'content' => 'You filter SEO keyword lists for topical relevance. Respond with valid JSON only.'],
                    ['role' => 'user', 'content' => <<<PROMPT
                    Business offerings: {$offer}
                    About: {$desc}

                    From the keywords below, return ONLY those genuinely relevant to THIS
                    business — the ones its articles should target. DROP anything off-topic:
                    unrelated tools, industries, or languages, even when they share a common
                    word (like "generator" or "name"). Keep the exact original text.

                    {$list}

                    Return JSON: {"relevant": ["...", "..."]}
                    PROMPT],
                ], ['temperature' => 0.1, 'max_tokens' => 4000, 'timeout' => 60, 'model' => $model['model'] ?: null, '__source' => 'content_autopilot.bulk_relevance']);

                foreach ((array) ($response['relevant'] ?? []) as $kw) {
                    $hash = $hashByKeyword[mb_strtolower(trim((string) $kw))] ?? null;
                    if ($hash !== null) {
                        $keep[$hash] = true;
                    }
                }
            } catch (\Throwable) {
                // Skip this chunk; other chunks still classify.
                continue;
            }
        }

        return $keep;
    }

    private function row(string $planId, string $hash, string $keyword, string $type, ?int $volume, string $country, $facts, $now): array
    {
        return [
            'plan_id' => $planId,
            'keyword_hash' => $hash,
            'keyword' => mb_substr($keyword, 0, 255),
            'type' => $type,
            'country' => $country,
            'search_volume' => $volume,
            'competition' => $facts->competition ?? null,
            'keyword_difficulty' => $facts->keyword_difficulty ?? null,
            'search_intent' => $facts->search_intent ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
