<?php

namespace App\Services\Content;

use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\SearchConsoleData;
use App\Models\Website;
use App\Services\Llm\LlmClient;
use App\Support\ContentAutopilotConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Evidence-driven topic ideation for Content Autopilot.
 *
 * Unlike the competitor's typed-lists approach, topics are grounded in the
 * data we already hold for the site:
 *  - GSC striking-distance queries (position 8-30, high impressions) and
 *    high-impression queries with no dedicated page,
 *  - existing crawl page titles (cannibalization guard: never plan a topic
 *    an existing page already covers, never plan two similar topics),
 *  - the plan's business profile (description + sell / don't-sell lists).
 *
 * One LLM call turns the evidence into topic candidates; deduping and date
 * assignment are deterministic PHP. Works without GSC (degrades to the
 * business profile + existing-page context, per the GSC/GA degradation rule).
 */
class ContentTopicPlanner
{
    public function __construct(
        private readonly LlmClient $llm,
    ) {}

    /**
     * Generate and persist up to $count dated topics for the plan.
     * Returns the created ContentTopic models.
     *
     * @return list<ContentTopic>
     */
    public function plan(ContentPlan $plan, int $count = 30): array
    {
        $website = $plan->website;
        if ($website === null || ! $this->llm->isAvailable()) {
            return [];
        }

        // Maintain a fixed pool of at most `cap` (default 30) UNPUBLISHED topics
        // ahead — count the whole active pipeline (planned + in-flight + ready),
        // not just future-dated ones, so we don't pile past 30 while articles are
        // being written. Replenishment happens only as topics leave the pool
        // (published): each publish drops the pool below cap and the dispatcher's
        // top-up adds exactly the shortfall back.
        $cap = \App\Support\ContentAutopilotConfig::monthlyArticlesPerWebsite();
        $existingActive = $plan->topics()
            ->whereNotIn('status', [ContentTopic::STATUS_PUBLISHED, ContentTopic::STATUS_SKIPPED])
            ->count();
        $count = max(0, min($count, $cap) - $existingActive);
        if ($count === 0) {
            return [];
        }

        $existingTitles = $this->existingPageTitles($website);
        $plannedTitles = $plan->topics()
            ->whereNotIn('status', [ContentTopic::STATUS_FAILED, ContentTopic::STATUS_SKIPPED])
            ->pluck('title')->all();
        $gscSignals = $this->gscSignals($website);

        $candidates = $this->ideate($plan, $website, $gscSignals, $existingTitles, $count);
        if ($candidates === []) {
            return [];
        }

        // Relevance gate: never plan an off-topic article. When the plan's keywords
        // have already been bulk-classified (own/gap) we trust that vetting and skip
        // the re-filter — ideation was seeded with the vetted gap keywords. Otherwise
        // vet the candidates now (fails open; never wipes the whole set).
        if ($plan->keywords_classified_at === null) {
            $candidates = $this->filterRelevant($candidates, $plan);
            if ($candidates === []) {
                return [];
            }
        }

        // Cannibalization + internal dedupe (deterministic, after the LLM).
        $taken = array_merge($existingTitles, $plannedTitles);
        $created = [];
        $dates = $this->scheduleDates($plan, $count);

        foreach ($candidates as $candidate) {
            if (count($created) >= $count) {
                break;
            }
            $title = self::freshenYears(trim((string) ($candidate['title'] ?? '')));
            $keyword = self::freshenYears(mb_strtolower(trim((string) ($candidate['target_keyword'] ?? ''))));
            if ($title === '' || $keyword === '') {
                continue;
            }
            foreach ($taken as $existing) {
                if ($this->similarity($title, (string) $existing) >= 0.75) {
                    continue 2;
                }
            }
            $taken[] = $title;

            $created[] = $plan->topics()->create([
                'website_id' => $website->id,
                'title' => mb_substr($title, 0, 300),
                'target_keyword' => mb_substr($keyword, 0, 200),
                'secondary_keywords' => array_slice(array_values(array_filter(array_map(
                    static fn ($k) => trim((string) $k),
                    (array) ($candidate['secondary_keywords'] ?? [])
                ))), 0, 8),
                'intent' => in_array($candidate['intent'] ?? null, ['informational', 'commercial', 'transactional', 'navigational'], true)
                    ? $candidate['intent'] : 'informational',
                'source' => in_array($candidate['source'] ?? null, ['gsc_gap', 'gap', 'keywords', 'competitor', 'llm'], true)
                    ? $candidate['source'] : 'llm',
                'status' => ContentTopic::STATUS_APPROVED,
                'scheduled_for' => $dates[count($created)] ?? null,
                'position' => count($created),
            ]);
        }

        return $created;
    }

    // ── evidence gathering ──────────────────────────────────────────────

    /**
     * Striking-distance + coverage-gap queries from GSC (90 days).
     *
     * @return list<array{query:string, impressions:int, clicks:int, position:float}>
     */
    private function gscSignals(Website $website): array
    {
        try {
            $rows = SearchConsoleData::query()
                ->where('website_id', $website->id)
                ->where('date', '>=', now()->subDays(90)->toDateString())
                ->select('query')
                ->selectRaw('sum(impressions) as impressions, sum(clicks) as clicks, avg(position) as position')
                ->whereNotNull('query')
                ->where('query', '!=', '')
                ->groupBy('query')
                ->havingRaw('sum(impressions) >= 20')
                ->orderByDesc(DB::raw('sum(impressions)'))
                ->limit(120)
                ->get();
        } catch (\Throwable) {
            return [];
        }

        return $rows
            ->filter(fn ($r) => (float) $r->position >= 6.0) // page 1 top spots need no new article
            ->take(40)
            ->map(fn ($r) => [
                'query' => (string) $r->query,
                'impressions' => (int) $r->impressions,
                'clicks' => (int) $r->clicks,
                'position' => round((float) $r->position, 1),
            ])
            ->values()
            ->all();
    }

    /**
     * Existing page titles for the site's own domain — the cannibalization
     * inventory. Direct bounded read of the shared crawl pages for THIS
     * site's crawl_site only (server-side pipeline concern; the client-facing
     * cap-window rule guards UI exposure, not same-site internal planning).
     *
     * @return list<string>
     */
    private function existingPageTitles(Website $website): array
    {
        try {
            $crawlSiteId = $website->crawl_site_id;
            if (! $crawlSiteId) {
                return [];
            }

            return DB::table('website_pages')
                ->where('crawl_site_id', $crawlSiteId)
                ->whereNotNull('title')
                ->where('title', '!=', '')
                ->orderByDesc('inbound_link_count')
                ->limit(500)
                ->pluck('title')
                ->map(fn ($t) => (string) $t)
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    // ── relevance gate ──────────────────────────────────────────────────

    /**
     * Drop ideated candidates whose target keyword isn't genuinely about the
     * client's offerings — so a stray GSC query or LLM drift never becomes a
     * published article. Authoritative when the LLM runs; fails open (keeps all)
     * when unavailable, and never returns empty (the calendar must still fill).
     *
     * @param  list<array<string,mixed>>  $candidates
     * @return list<array<string,mixed>>
     */
    private function filterRelevant(array $candidates, ContentPlan $plan): array
    {
        if (count($candidates) < 2) {
            return $candidates;
        }
        $keywords = array_values(array_unique(array_filter(array_map(
            static fn ($c) => mb_strtolower(trim((string) ($c['target_keyword'] ?? ''))), $candidates
        ))));
        if (count($keywords) < 2) {
            return $candidates;
        }
        $offer = implode(', ', array_slice((array) (($plan->offerings ?? [])['sell'] ?? []), 0, 10));
        $desc = mb_substr((string) $plan->business_description, 0, 400);
        // The don't-sell list used to reach the ideation prompt ONLY, so a
        // candidate that drifted onto an explicit exclusion had no second net.
        $exclude = implode(', ', array_slice((array) (($plan->offerings ?? [])['dont_sell'] ?? []), 0, 10));

        $keep = $this->llmRelevant($keywords, $offer, $desc, $exclude);
        if ($keep === null) {
            return $candidates; // LLM unavailable → fail open
        }
        $keepSet = array_flip($keep);
        $filtered = array_values(array_filter(
            $candidates,
            static fn ($c) => isset($keepSet[mb_strtolower(trim((string) ($c['target_keyword'] ?? '')))])
        ));

        // If the vetting rejected everything, the ideation was already anchored —
        // treat it as an LLM glitch and keep the candidates rather than an empty plan.
        return $filtered === [] ? $candidates : $filtered;
    }

    /**
     * @param  list<string>  $keywords
     * @return list<string>|null  lowercased on-topic keywords; null on LLM unavailable/error
     */
    private function llmRelevant(array $keywords, string $offer, string $desc, string $exclude = ''): ?array
    {
        try {
            if (! $this->llm->isAvailable()) {
                return null;
            }
            $list = implode("\n", array_slice($keywords, 0, 80));
            // Only state the exclusion rule when there IS a list — an empty
            // "never covers:" line reads as a constraint while forbidding
            // nothing, which is the failure mode this whole change is about.
            $excludeBlock = $exclude === ''
                ? ''
                : "\nThis business explicitly does NOT offer, and must never publish about: {$exclude}\n";
            $response = $this->llm->completeJson([
                ['role' => 'system', 'content' => 'You filter SEO topic keywords for topical relevance. Respond with valid JSON only.'],
                ['role' => 'user', 'content' => <<<PROMPT
                Business offerings: {$offer}
                About: {$desc}
                {$excludeBlock}
                From the topic keywords below, return ONLY those genuinely relevant to
                THIS business — topics its articles should actually cover. DROP anything
                off-topic: unrelated tools, industries, or languages, even when they
                share a common word. DROP anything covering what the business does not
                offer. Keep the exact original text.

                {$list}

                Return JSON: {"relevant": ["...", "..."]}
                PROMPT],
            ], ['temperature' => 0.1, 'max_tokens' => 1500, 'timeout' => 40, '__source' => 'content_autopilot.topic_relevance']);

            $rel = is_array($response['relevant'] ?? null) ? $response['relevant'] : [];

            return array_values(array_filter(array_map(
                static fn ($s) => mb_strtolower(trim((string) $s)), $rel
            )));
        } catch (\Throwable) {
            return null;
        }
    }

    // ── LLM ideation ────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function ideate(ContentPlan $plan, Website $website, array $gscSignals, array $existingTitles, int $count): array
    {
        $model = ContentAutopilotConfig::modelFor('ideate');

        $offerings = (array) ($plan->offerings ?? []);
        $sell = implode('; ', array_slice((array) ($offerings['sell'] ?? []), 0, 10));
        $dontSell = implode('; ', array_slice((array) ($offerings['dont_sell'] ?? []), 0, 10));

        $gscBlock = $gscSignals === [] ? '(no search data available)' : implode("\n", array_map(
            static fn ($s) => "- \"{$s['query']}\" (impressions {$s['impressions']}, avg position {$s['position']})",
            array_slice($gscSignals, 0, 40)
        ));
        $titlesBlock = $existingTitles === [] ? '(none known)' : implode("\n", array_map(
            static fn ($t) => '- '.mb_substr($t, 0, 120),
            array_slice($existingTitles, 0, 80)
        ));

        // Pre-vetted GAP keywords (competitors rank, the client doesn't; already
        // classified as topically relevant) — strong, on-topic target candidates.
        $gapKeywords = \App\Models\ContentPlanKeyword::query()
            ->where('plan_id', $plan->id)->where('type', \App\Models\ContentPlanKeyword::TYPE_GAP)
            ->orderByDesc('search_volume')->limit(40)->pluck('keyword')->all();
        $gapBlock = $gapKeywords === [] ? '(none yet)' : implode("\n", array_map(
            static fn ($k) => '- '.$k, $gapKeywords
        ));

        $language = $plan->language ?: 'en';
        $domain = (string) $website->domain;
        $today = now()->toFormattedDateString();
        $currentYear = now()->year;

        $system = 'You are an SEO content strategist. Respond with valid JSON only.';
        $user = <<<PROMPT
        Plan {$count} blog article topics for the website {$domain}.

        BUSINESS:
        {$plan->business_description}
        They offer: {$sell}
        They do NOT offer (never write about these as if they do): {$dontSell}

        REAL SEARCH QUERIES the site already appears for (impressions = demand, position 8-30 = a dedicated article can win the ranking):
        {$gscBlock}

        KEYWORD GAP — competitors rank for these and the site does NOT yet (already vetted as on-topic; prime article targets, use source "gap"):
        {$gapBlock}

        EXISTING PAGES (do NOT duplicate these topics):
        {$titlesBlock}

        Rules:
        - Prefer topics targeting the real queries above (source "gsc_gap") and the vetted keyword gap (source "gap"); fill remaining slots with adjacent topics a customer would search (source "llm").
        - One clear target keyword per topic, natural article title (not clickbait, no year numbers unless essential).
        - TODAY'S DATE is {$today}. If a title genuinely needs a year, it MUST be {$currentYear} — never an earlier year.
        - 3-6 secondary keywords per topic.
        - Write titles in language "{$language}".
        - Never invent topics about things they do not offer.

        Return JSON: {"topics": [{"title": "...", "target_keyword": "...", "secondary_keywords": ["..."], "intent": "informational|commercial|transactional|navigational", "source": "gsc_gap|gap|llm"}]}
        PROMPT;

        $options = [
            'temperature' => 0.5,
            'max_tokens' => 4000,
            'timeout' => 120,
            '__source' => 'content_autopilot.ideate',
        ];
        if (! empty($model['model'])) {
            $options['model'] = $model['model'];
        }

        $response = $this->llm->completeJson([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ], $options);
        app(ContentLlmSpendMeter::class)->add(ContentLlmSpendMeter::EST_IDEATE_USD);

        $topics = is_array($response) ? ($response['topics'] ?? null) : null;

        return is_array($topics) ? array_values(array_filter($topics, 'is_array')) : [];
    }

    // ── scheduling ──────────────────────────────────────────────────────

    /**
     * Future publish dates honoring articles_per_week + publish_days,
     * starting tomorrow.
     *
     * @return list<Carbon>
     */
    private function scheduleDates(ContentPlan $plan, int $count): array
    {
        $perWeek = max(1, min(7, (int) $plan->articles_per_week));
        $days = array_values(array_filter(array_map('intval', (array) ($plan->publish_days ?? []))));
        if ($days === []) {
            // Spread evenly: 1=>Mon; 3=>Mon/Wed/Fri; 5=>weekdays; 7=>daily.
            $days = match (true) {
                $perWeek >= 7 => [1, 2, 3, 4, 5, 6, 7],
                $perWeek === 5 => [1, 2, 3, 4, 5],
                $perWeek === 3 => [1, 3, 5],
                $perWeek === 2 => [2, 4],
                default => [2],
            };
        }
        $days = array_slice($days, 0, $perWeek);

        // Strict one-article-per-day: never reuse a day this plan already has a
        // topic on. Each top-up batch restarts at "tomorrow", so without this the
        // new dates would collide with existing topics and stack 2+ on a day.
        $used = $plan->topics()
            ->whereNotNull('scheduled_for')
            ->pluck('scheduled_for')
            ->map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->toDateString())
            ->flip()->all();

        $dates = [];
        $cursor = now()->addDay()->startOfDay();
        $guard = 0;
        while (count($dates) < $count && $guard++ < 3660) { // ~10y safety valve
            $key = $cursor->toDateString();
            if (in_array($cursor->isoWeekday(), $days, true) && ! isset($used[$key])) {
                $dates[] = $cursor->copy();
                $used[$key] = true;
            }
            $cursor->addDay();
        }

        return $dates;
    }

    /**
     * Replace stale years with the current one. LLMs leak their training
     * cutoff ("Best X in 2024" generated in 2026 — owner QA find). Only
     * recent-past years are rewritten; older ones (2019 and earlier) are
     * treated as deliberate historical references.
     */
    public static function freshenYears(string $text): string
    {
        $current = now()->year;

        return preg_replace_callback('/\b(20[2-9][0-9])\b/', function ($m) use ($current) {
            $year = (int) $m[1];

            return ($year >= 2020 && $year < $current) ? (string) $current : $m[1];
        }, $text) ?? $text;
    }

    /** Token-overlap title similarity (same heuristic as the scorer). */
    private function similarity(string $a, string $b): float
    {
        $tokens = static function (string $s): array {
            $words = preg_split('/[^a-z0-9]+/', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return array_diff($words, ['the', 'a', 'an', 'for', 'to', 'of', 'and', 'in', 'on', 'your', 'how', 'what', 'best', 'guide']);
        };

        $ta = $tokens($a);
        $tb = $tokens($b);
        if ($ta === [] || $tb === []) {
            return 0.0;
        }

        return count(array_intersect($ta, $tb)) / min(count($ta), count($tb));
    }
}
