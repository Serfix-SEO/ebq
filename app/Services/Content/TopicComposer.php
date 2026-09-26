<?php

namespace App\Services\Content;

use App\Jobs\Content\RefineTopicSecondaryKeywordsJob;
use App\Models\ContentPlan;
use App\Models\ContentPlanKeyword;
use App\Models\ContentProduct;
use App\Models\ContentTopic;
use App\Models\KeywordMetric;
use App\Models\Website;
use App\Services\Content\Catalog\CatalogBrandValidator;
use App\Services\Content\Catalog\TopicProductMatcher;
use App\Services\KeywordMetricsService;
use App\Services\KeywordResearch\KeywordIntentClassifier;
use App\Services\Llm\LlmClient;
use App\Support\ContentAutopilotConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Write about this instead" — turns a client's plain-words idea into a real,
 * SEO-shaped topic, and lets them swap it in for one we planned.
 *
 * The client describes what they want; we come back with a few properly-formed
 * titles carrying a target keyword, intent, secondary keywords and whatever
 * search volume we know, ranked by what this particular site can realistically
 * win. They pick one. Nothing reaches the calendar that the planner itself
 * would not have been allowed to plan.
 *
 * That last point is the whole design constraint. A client-authored topic runs
 * the SAME gates as an auto-planned one — rival-brand drop, off-catalog drop,
 * product grounding for strict plans, keyword and title de-duplication — and
 * takes its date from the planner's own availability rules, which is what
 * enforces publish days, one article per day and the monthly cap. Otherwise
 * this box becomes the way around every promise the product makes.
 *
 * Cost: one ideation-class LLM call per suggestion request (charged to
 * {@see ContentLlmSpendMeter} exactly as the planner charges its own), plus a
 * free keyword-metrics lookup. Creating the chosen topic costs nothing extra —
 * the writer builds its brief at write time on a keyword-keyed cache either way.
 */
class TopicComposer
{
    /** How many options a client gets to choose between. */
    public const SUGGESTIONS = 3;

    /** Marks a topic the client asked for, so the UI can say so. */
    public const SOURCE = 'client';

    /** Titles this similar to something already planned are a duplicate. */
    private const SIMILAR = 0.75;

    /** Suggestion requests allowed per plan per hour (each is an LLM call). */
    private const PER_HOUR = 20;

    public function __construct(
        private readonly ContentTopicPlanner $planner,
        private readonly LlmClient $llm,
    ) {}

    /**
     * Options for the client to choose from, best first.
     *
     * @return array{ok: bool, reason: ?string, suggestions: list<array<string, mixed>>}
     */
    public function suggest(ContentPlan $plan, string $idea): array
    {
        $idea = trim(preg_replace('/\s+/u', ' ', $idea) ?? '');
        $website = $plan->website;

        if (mb_strlen($idea) < 3 || $website === null) {
            return $this->nothing('say_more');
        }
        if (! RateLimiter::attempt('topic-suggest:'.$plan->id, self::PER_HOUR, static fn () => true, 3600)) {
            return $this->nothing('slow_down');
        }

        $candidates = $this->ask($plan, $website, $idea);
        if ($candidates === []) {
            return $this->nothing('no_ideas');
        }

        $suggestions = $this->keepAllowed($plan, $website, $candidates);
        if ($suggestions === []) {
            // Everything was refused. For a strict plan that is usually "your
            // catalog can't support this idea", which is worth saying plainly
            // rather than returning an empty list that reads as a glitch.
            return $this->nothing($plan->product_mode === ContentPlan::PRODUCT_MODE_STRICT ? 'not_in_catalog' : 'too_similar');
        }

        return ['ok' => true, 'reason' => null, 'suggestions' => array_slice($suggestions, 0, self::SUGGESTIONS)];
    }

    /**
     * Put a chosen suggestion on the calendar.
     *
     * $choice arrives from the browser, so every gate runs AGAIN here: a
     * hand-crafted payload must not be able to plant a rival-brand or
     * off-catalog topic that suggest() would have refused.
     *
     * Replacing inherits the old topic's slot and marks it skipped, which is
     * what makes a swap cost nothing against the monthly allowance. Adding
     * takes a date from the planner's availability, which enforces the cap.
     */
    public function create(ContentPlan $plan, array $choice, ?ContentTopic $replacing = null, ?string $date = null): ?ContentTopic
    {
        $website = $plan->website;
        if ($website === null) {
            return null;
        }

        // Re-run the gates and keep THEIR output, not the browser's: the
        // validated row carries the enriched secondaries, the resolved intent
        // and the volume, so a tampered payload cannot smuggle any of them in.
        $allowed = $this->keepAllowed($plan, $website, [[
            'title' => $choice['title'] ?? '',
            'target_keyword' => $choice['target_keyword'] ?? '',
            'intent' => $choice['intent'] ?? null,
            'secondary_keywords' => $choice['secondary_keywords'] ?? [],
            'product_urls' => $choice['product_urls'] ?? [],
        ]], $replacing, deepCheck: false);
        if ($allowed === []) {
            return null;
        }
        $row = $allowed[0];

        [$scheduledFor, $position] = $this->slot($plan, $replacing, $date);
        if ($scheduledFor === null) {
            return null;   // month is full and nothing was being replaced
        }

        $topic = $plan->topics()->create([
            'website_id' => $website->id,
            'title' => $row['title'],
            'target_keyword' => $row['target_keyword'],
            'secondary_keywords' => $row['secondary_keywords'],
            'intent' => $row['intent'],
            'keyword_volume' => $row['volume'],
            'source' => self::SOURCE,
            'status' => ContentTopic::STATUS_APPROVED,
            'scheduled_for' => $scheduledFor,
            'position' => $position,
        ]);

        $this->attachProducts($plan, $website, $topic, $row['product_urls']);

        if ($replacing !== null) {
            $replacing->forceFill([
                'status' => ContentTopic::STATUS_SKIPPED,
                'meta' => array_merge((array) $replacing->meta, ['replaced_by' => $topic->id]),
            ])->save();
        }

        // Re-rank the secondary keywords against the plan's own library once
        // the topic exists. Fails open; the topic is already usable without it.
        RefineTopicSecondaryKeywordsJob::dispatch($topic->id);

        Log::info('content_autopilot.client_topic_created', [
            'plan_id' => $plan->id, 'topic_id' => $topic->id,
            'replaced' => $replacing?->id,
        ]);

        return $topic;
    }

    /** Dates a client may pick for a new topic (empty once the month is full). */
    public function availableDates(ContentPlan $plan): array
    {
        return $this->planner->availableDates($plan);
    }

    // ── internals ───────────────────────────────────────────────────────

    /** @return array{ok: bool, reason: string, suggestions: list<array<string, mixed>>} */
    private function nothing(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason, 'suggestions' => []];
    }

    /**
     * One LLM call, reasoning from the SAME context the planner ideates with —
     * including, for a strict plan, the catalog and its absolute brand rule.
     *
     * @return list<array<string, mixed>>
     */
    private function ask(ContentPlan $plan, Website $website, string $idea): array
    {
        $ctx = $this->planner->promptContext($plan, $website, [], $this->planner->existingPageTitles($website));
        $model = ContentAutopilotConfig::modelFor('ideate');
        $count = self::SUGGESTIONS;

        $user = <<<PROMPT
        The client who owns {$ctx['domain']} wants an article about this, in their words:

        "{$idea}"

        Turn that into {$count} DIFFERENT article topics they could publish. Same idea, different angles —
        a how-to, a comparison, a buying guide — whichever genuinely suit it.

        BUSINESS:
        {$plan->business_description}
        They offer: {$ctx['sell']}
        They do NOT offer (never write about these as if they do): {$ctx['dontSell']}
        {$ctx['marketBlock']}{$ctx['typeBlock']}{$ctx['catalogBlock']}{$ctx['directivesBlock']}

        EXISTING PAGES (do NOT duplicate these topics):
        {$ctx['titlesBlock']}

        Rules:
        - Stay on the client's idea. If it is vague, make it specific in the way their customers would search.
        - One clear target keyword per topic — what a person would actually type, not a slogan.
        - Natural article titles, not clickbait. No year numbers unless essential; if a year is needed it MUST be {$ctx['currentYear']}.
        - 3-6 secondary keywords per topic.
        - Write titles in language "{$ctx['language']}".
        - Never propose a topic about something they do not offer.

        Return JSON: {"topics": [{"title": "...", "target_keyword": "...", "secondary_keywords": ["..."], "intent": "informational|commercial|transactional|navigational"{$ctx['productContractField']}}]}
        PROMPT;

        $options = [
            'temperature' => 0.4,
            'max_tokens' => 1200,
            'timeout' => 60,
            '__source' => 'content_autopilot.topic_compose',
            '__unmetered' => true,
        ];
        if (! empty($model['model'])) {
            $options['model'] = $model['model'];
        }

        $response = $this->llm->completeJson([
            ['role' => 'system', 'content' => 'You are an SEO content strategist. Respond with valid JSON only.'],
            ['role' => 'user', 'content' => $user],
        ], $options);
        app(ContentLlmSpendMeter::class)->add(ContentLlmSpendMeter::EST_IDEATE_USD);

        $topics = is_array($response) ? ($response['topics'] ?? null) : null;

        return is_array($topics) ? array_values(array_filter($topics, 'is_array')) : [];
    }

    /**
     * Every gate the planner applies to its own ideas, applied to the client's.
     *
     * $deepCheck adds the LLM catalog brand check (suggestion time only).
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function keepAllowed(ContentPlan $plan, Website $website, array $candidates, ?ContentTopic $ignore = null, bool $deepCheck = true): array
    {
        $strict = $plan->product_mode === ContentPlan::PRODUCT_MODE_STRICT;

        // Normalize and drop the unusable FIRST, so the titles handed to the
        // brand validator line up with this list: it returns indexes into the
        // array it was given, after filtering empties out of it.
        $usable = [];
        foreach ($candidates as $candidate) {
            $title = trim((string) ($candidate['title'] ?? ''));
            $keyword = mb_strtolower(trim((string) ($candidate['target_keyword'] ?? '')));
            if ($title !== '' && $keyword !== '') {
                $usable[] = ['title' => $title, 'keyword' => $keyword, 'raw' => $candidate];
            }
        }
        if ($usable === []) {
            return [];
        }

        $blockedBrands = app(CompetitorMentionGuard::class)->strictBlockedBrands($plan);
        $matcher = app(TopicProductMatcher::class);
        // The catalog brand check is an LLM judgement, so it runs when the
        // options are proposed, not again when one is picked: re-asking would
        // pay for a second call and could flip its mind on a title the client
        // was just offered. The deterministic gates below always run.
        $offCatalog = $strict && $deepCheck
            ? array_flip(app(CatalogBrandValidator::class)->offCatalogIndexes(
                (string) $website->id, array_column($usable, 'title')
            ))
            : [];

        // Everything already on this plan, so we neither repeat a keyword nor
        // re-word a topic that is already waiting to be written.
        $taken = ContentTopic::query()
            ->where('plan_id', $plan->id)
            ->where('status', '!=', ContentTopic::STATUS_SKIPPED)
            ->when($ignore !== null, fn ($q) => $q->whereKeyNot($ignore->id))
            ->get(['id', 'title', 'target_keyword']);
        $takenKeywords = $taken->pluck('target_keyword')->filter()->map(
            static fn ($k) => mb_strtolower(trim((string) $k))
        )->flip();
        $takenTitles = array_merge(
            $taken->pluck('title')->filter()->all(),
            $this->planner->existingPageTitles($website),
        );

        $kept = [];
        foreach ($usable as $i => $row) {
            [$title, $keyword, $candidate] = [$row['title'], $row['keyword'], $row['raw']];

            if (isset($takenKeywords[$keyword]) || isset($offCatalog[$i])) {
                continue;
            }
            foreach ($blockedBrands as $brand) {
                if (preg_match('/\b'.preg_quote((string) $brand, '/').'\b/ui', $title.' '.$keyword) === 1) {
                    continue 2;
                }
            }
            foreach ($takenTitles as $existing) {
                if ($this->planner->similarity($title, (string) $existing) >= self::SIMILAR) {
                    continue 2;
                }
            }

            $products = $strict ? $matcher->match((string) $website->id, $title.' '.$keyword, 5) : collect();
            if ($strict && $products->isEmpty()) {
                continue;   // ungroundable: strict promises articles about THEIR products
            }

            $metrics = $this->metrics($plan, $website, $keyword);
            $kept[] = [
                'title' => mb_substr($title, 0, 300),
                'target_keyword' => mb_substr($keyword, 0, 200),
                'intent' => $this->intent($candidate['intent'] ?? null, $keyword),
                'secondary_keywords' => $this->secondaries($plan, $candidate, $keyword),
                'product_urls' => array_slice(array_values(array_filter(array_map(
                    static fn ($u): string => trim((string) $u),
                    (array) ($candidate['product_urls'] ?? [])
                ))), 0, 3),
                'products' => $products->pluck('name')->take(3)->values()->all(),
                'volume' => $metrics['volume'],
                'winnability' => $metrics['winnability'],
            ];
        }

        // Winnability first, volume only as a tie-break: a small site gains
        // nothing from a head term it cannot rank for (KeywordWinnability).
        usort($kept, static fn ($a, $b) => [$b['winnability'], $b['volume'] ?? -1] <=> [$a['winnability'], $a['volume'] ?? -1]);

        return $kept;
    }

    /**
     * The LLM's secondary keywords, topped up from the plan's own keyword
     * library by token overlap — real phrases the site was already researched
     * for beat invented ones.
     *
     * @return list<string>
     */
    private function secondaries(ContentPlan $plan, array $candidate, string $keyword): array
    {
        $given = array_values(array_unique(array_filter(array_map(
            static fn ($k): string => trim((string) $k),
            (array) ($candidate['secondary_keywords'] ?? [])
        ))));
        if (count($given) >= 4) {
            return array_slice($given, 0, 8);
        }

        $tokens = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($keyword)) ?: [],
            static fn ($t) => mb_strlen((string) $t) > 3
        ));
        if ($tokens === []) {
            return $given;
        }

        $related = ContentPlanKeyword::query()
            ->where('plan_id', $plan->id)
            ->where(function ($q) use ($tokens) {
                foreach ($tokens as $token) {
                    $q->orWhere('keyword', 'like', '%'.$token.'%');
                }
            })
            ->orderByDesc('search_volume')
            ->limit(20)
            ->pluck('keyword');

        $lower = array_map('mb_strtolower', $given);
        foreach ($related as $phrase) {
            $phrase = trim((string) $phrase);
            if ($phrase === '' || mb_strtolower($phrase) === $keyword || in_array(mb_strtolower($phrase), $lower, true)) {
                continue;
            }
            $given[] = $phrase;
            $lower[] = mb_strtolower($phrase);
            if (count($given) >= 6) {
                break;
            }
        }

        return array_slice($given, 0, 8);
    }

    /**
     * What we know about a phrase right now. A keyword nobody has looked up
     * yet comes back with a null volume and a background fetch queued — the UI
     * says so rather than inventing a number.
     *
     * @return array{volume: ?int, winnability: float}
     */
    private function metrics(ContentPlan $plan, Website $website, string $keyword): array
    {
        $row = null;
        try {
            $row = app(KeywordMetricsService::class)->metricsOrQueue(
                [$keyword], (string) ($plan->country ?: 'global'), (string) $website->id, (string) $website->user_id
            )[KeywordMetric::hashKeyword($keyword)] ?? null;
        } catch (\Throwable $e) {
            Log::debug('content_autopilot.compose_metrics_failed', ['error' => mb_substr($e->getMessage(), 0, 120)]);
        }

        $volume = $row?->search_volume ?? $this->volume($plan, $keyword);
        $competition = match (true) {
            ($row?->competition ?? null) === null => 'unknown',
            $row->competition >= 0.66 => 'high',
            $row->competition >= 0.33 => 'medium',
            default => 'low',
        };

        return [
            'volume' => $volume,
            'winnability' => KeywordWinnability::score(
                $row?->keyword_difficulty,
                $competition,
                KeywordWinnability::ownAuthority($website),
            ),
        ];
    }

    /** Volume from the plan's own keyword library, when we have it. */
    private function volume(ContentPlan $plan, string $keyword): ?int
    {
        $stored = ContentPlanKeyword::query()
            ->where('plan_id', $plan->id)
            ->whereRaw('LOWER(keyword) = ?', [$keyword])
            ->value('search_volume');

        return $stored !== null ? (int) $stored : null;
    }

    /** The topic column takes four values; the classifier's 'other' is not one. */
    private function intent(?string $given, string $keyword): string
    {
        $allowed = ['informational', 'commercial', 'transactional', 'navigational'];
        $given = mb_strtolower(trim((string) $given));
        if (in_array($given, $allowed, true)) {
            return $given;
        }
        $classified = KeywordIntentClassifier::classify($keyword);

        return in_array($classified, $allowed, true) ? $classified : 'informational';
    }

    /**
     * The slot the new topic occupies.
     *
     * A replacement inherits the old topic's date and position — the mechanism
     * `materializeConfirmedTopics()` already uses to swap a client's choice in
     * for a filler, and the reason a swap adds nothing to the month's count.
     *
     * For a new topic the date must be one `availableDates()` offers (it
     * returns Y-m-d strings): that is what enforces publish days, one article
     * per day and the monthly cap, and it is re-checked here because the
     * browser chose it.
     *
     * @return array{0: ContentTopic|string|null, 1: int}
     */
    private function slot(ContentPlan $plan, ?ContentTopic $replacing, ?string $date): array
    {
        if ($replacing !== null) {
            return [$replacing->scheduled_for, (int) $replacing->position];
        }

        $wanted = $date !== null ? trim($date) : null;
        foreach ($this->planner->availableDates($plan) as $candidate) {
            if ($wanted === null || $candidate === $wanted) {
                return [$candidate, $plan->topics()->count()];
            }
        }

        return [null, 0];
    }

    /** Strict plans ground every topic in real catalog rows. */
    private function attachProducts(ContentPlan $plan, Website $website, ContentTopic $topic, array $citedUrls): void
    {
        if ($plan->product_mode !== ContentPlan::PRODUCT_MODE_STRICT) {
            return;
        }

        $attach = [];
        if ($citedUrls !== []) {
            foreach (ContentProduct::query()->where('website_id', $website->id)->usable()
                ->whereIn('url', array_map('trim', $citedUrls))->pluck('id') as $id) {
                $attach[$id] = ['role' => 'featured'];
            }
        }
        foreach (app(TopicProductMatcher::class)
            ->match((string) $website->id, $topic->title.' '.$topic->target_keyword, 5)->pluck('id') as $id) {
            $attach[$id] ??= ['role' => 'mentioned'];
        }

        if ($attach !== []) {
            $topic->products()->syncWithoutDetaching($attach);
        }
    }
}
