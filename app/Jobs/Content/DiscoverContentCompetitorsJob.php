<?php

namespace App\Jobs\Content;

use App\Models\ContentPlan;
use App\Models\Website;
use App\Services\Llm\LlmClientFactory;
use App\Services\Reports\ReportEnrichmentService;
use App\Support\ContentAutopilotConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * SERP fallback competitor discovery for the Content Autopilot wizard: when a
 * site has no backlink footprint (fresh/low-authority), its backlink report
 * finds no competitors. This job takes the content plan's TOP TARGET KEYWORDS —
 * genuine searches the site wants to rank for — and asks
 * {@see ReportEnrichmentService::discoverCompetitorsFor()} who currently ranks
 * for them. Those are the real competitors.
 *
 * Runs ASYNC so it never blocks the wizard render / re-bills on the 5s poll.
 * Result is cached under `content:serp-competitors:<websiteId>` (read by
 * {@see \App\Services\Content\ContentSetupInsights::build()}); the
 * `content:serp-comp:<websiteId>` flag (set by ensureGenerating) is cleared here
 * so the wizard stops showing the "generating" state.
 */
class DiscoverContentCompetitorsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public string $websiteId)
    {
        $this->onQueue('content');
    }

    public function handle(ReportEnrichmentService $enrichment): void
    {
        $flag = 'content:serp-comp:'.$this->websiteId;

        try {
            $website = Website::query()->find($this->websiteId);
            if ($website === null) {
                return;
            }
            $domain = $website->normalized_domain ?: $website->domain;
            $plan = ContentPlan::query()->where('website_id', $this->websiteId)->first();
            if ($plan === null || ! $domain) {
                return;
            }

            $queries = $this->longTailQueries($website, $plan, (string) $domain);
            if ($queries === []) {
                return;
            }
            $keywords = array_map(static fn ($k) => ['keyword' => $k], $queries);

            // Non-admin sites bill real SERP spend (same policy as the report);
            // admins sandbox. billedUserId scopes the metered usage.
            $billedUserId = $website->user?->is_admin ? null : $website->user_id;

            $result = $enrichment->discoverCompetitorsFor((string) $domain, $keywords, $billedUserId);
            $competitors = array_values(array_filter((array) ($result['competitors'] ?? []), 'is_array'));

            if ($competitors !== []) {
                Cache::put('content:serp-competitors:'.$this->websiteId, $competitors, now()->addDays(30));
            }
        } catch (\Throwable) {
            // Fail soft — the step falls back to its empty state.
        } finally {
            // Let the wizard re-read (build() picks up the new competitors) and
            // stop polling.
            Cache::forget('content:setup-insights:v1:'.$this->websiteId);
            Cache::forget($flag);
        }
    }

    /**
     * Build LONG-TAIL SERP queries for competitor discovery. Head terms
     * ("gold price") return generic, marketplace-dominated SERPs; long-tail
     * buyer-intent queries surface the actual small competitors. LLM-suggested
     * queries come first (best fit for the business), topped up with the plan's
     * own multi-word target keywords. Never uses 1–2 word head terms.
     *
     * @return list<string>
     */
    private function longTailQueries(Website $website, ContentPlan $plan, string $domain): array
    {
        // Plan target keywords, long-tail only (≥3 words).
        $planKw = $plan->topics()
            ->whereNotNull('target_keyword')->where('target_keyword', '!=', '')
            ->orderByDesc('keyword_volume')
            ->limit(40)
            ->pluck('target_keyword')
            ->map(static fn ($k) => trim((string) $k))
            ->filter(static fn ($k) => str_word_count($k) >= 3)
            ->values()
            ->all();

        $queries = array_merge($this->llmSuggestedQueries($plan, $domain), $planKw);

        // Dedupe case-insensitively, keep order (LLM suggestions first).
        $seen = [];
        $out = [];
        foreach ($queries as $q) {
            $key = mb_strtolower(trim($q));
            if ($key === '' || isset($seen[$key]) || str_word_count($key) < 3) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $q;
        }

        return array_slice($out, 0, 12);
    }

    /**
     * Ask the LLM for long-tail, buyer-intent search queries for this business.
     *
     * @return list<string>
     */
    private function llmSuggestedQueries(ContentPlan $plan, string $domain): array
    {
        try {
            $model = ContentAutopilotConfig::modelFor('ideate');
            $llm = LlmClientFactory::make($model['provider']);
            if (! $llm->isAvailable()) {
                return [];
            }

            $offer = implode(', ', array_slice((array) ($plan->offerings['sell'] ?? []), 0, 10));
            $desc = mb_substr((string) $plan->business_description, 0, 600);

            $response = $llm->completeJson([
                ['role' => 'system', 'content' => 'You are an SEO analyst. Respond with valid JSON only.'],
                ['role' => 'user', 'content' => <<<PROMPT
                Business domain: {$domain}
                What it offers: {$offer}
                About: {$desc}

                List 10 LONG-TAIL, buyer-intent Google search queries (each 4–8 words) a
                real customer would type to find and choose a business like this one.
                Include location/qualifier modifiers where natural. Do NOT return short
                generic head terms (e.g. "gold price", "sell gold").

                Return JSON: {"queries": ["...", "..."]}
                PROMPT],
            ], [
                'temperature' => 0.4,
                'max_tokens' => 500,
                'timeout' => 30,
                '__source' => 'content_autopilot.competitor_queries',
            ]);

            $list = is_array($response['queries'] ?? null) ? $response['queries'] : [];

            return array_values(array_filter(
                array_map(static fn ($q) => trim((string) $q), $list),
                static fn ($q) => str_word_count($q) >= 3,
            ));
        } catch (\Throwable) {
            return [];
        }
    }
}
