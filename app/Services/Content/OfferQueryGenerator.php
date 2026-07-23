<?php

namespace App\Services\Content;

use App\Models\ContentPlan;
use App\Support\ContentAutopilotConfig;
use App\Support\ContentSiteTypeProfiles;
use App\Support\KeywordFinderLocations;
use App\Services\Llm\LlmClientFactory;
use Illuminate\Support\Facades\Cache;

/**
 * Turns the plan's confirmed offers into buyer-shaped candidate queries —
 * the root of the offer spine. Each candidate carries its LINEAGE (which
 * offer produced it), so keyword research and the wizard UI can show "because
 * you sell: X" instead of context-free volume lists.
 *
 * One flash LLM call per plan, cached 30 days; the deterministic fallback
 * (site-type query shapes mechanically filled with offer heads) means this
 * NEVER returns empty for a plan with offerings — an LLM outage degrades
 * quality, not behavior.
 */
class OfferQueryGenerator
{
    private const CACHE_DAYS = 30;

    private const MAX_CANDIDATES = 30;

    /** @return list<array{query:string, offer:string, intent:string}> */
    public function candidates(ContentPlan $plan): array
    {
        $sell = $this->sellOffers($plan);
        if ($sell === []) {
            return [];
        }

        return Cache::remember(
            $this->cacheKey($plan),
            now()->addDays(self::CACHE_DAYS),
            function () use ($plan, $sell): array {
                $llm = $this->llmCandidates($plan, $sell);

                return $llm !== [] ? $llm : $this->mechanicalCandidates($plan, $sell);
            }
        );
    }

    public function forget(ContentPlan $plan): void
    {
        Cache::forget($this->cacheKey($plan));
    }

    private function cacheKey(ContentPlan $plan): string
    {
        // Offer edits must invalidate naturally — hash the inputs, not just the plan.
        $sig = md5(json_encode([
            $plan->site_type,
            $plan->audience,
            $plan->country,
            (array) (($plan->offerings ?? [])['sell'] ?? []),
        ]));

        return 'content:offer-queries:v1:'.$plan->id.':'.$sig;
    }

    /** @return list<string> */
    private function sellOffers(ContentPlan $plan): array
    {
        return array_values(array_filter(array_map(
            static fn ($v) => trim((string) $v),
            (array) (($plan->offerings ?? [])['sell'] ?? [])
        ), static fn ($v) => $v !== '' && ! self::isPromoOffer($v)));
    }

    /** @return list<array{query:string, offer:string, intent:string}> */
    private function llmCandidates(ContentPlan $plan, array $sell): array
    {
        try {
            $llm = LlmClientFactory::make(ContentAutopilotConfig::modelFor('ideate')['provider']);
            if (! $llm->isAvailable()) {
                return [];
            }

            $profile = ContentSiteTypeProfiles::profile($plan->site_type);
            $shapes = implode('; ', $profile['query_shapes']);
            $offers = implode("\n", array_map(static fn ($o) => '- '.$o, array_slice($sell, 0, 12)));
            $audience = trim((string) $plan->audience) ?: 'not specified';
            $country = $plan->country && $plan->country !== 'global'
                ? (KeywordFinderLocations::COUNTRIES[$plan->country] ?? $plan->country)
                : 'worldwide';
            $typeLabel = ContentSiteTypeProfiles::label($plan->site_type ?? ContentSiteTypeProfiles::OTHER);

            $response = $llm->completeJson([
                ['role' => 'system', 'content' => 'You generate realistic Google search queries for a content-marketing tool. Respond with valid JSON only.'],
                ['role' => 'user', 'content' => <<<PROMPT
                Business type: {$typeLabel}. Market: {$country}. Audience: {$audience}.
                Description: {$plan->business_description}

                OFFERS (what the site sells / covers):
                {$offers}

                Write up to 24 search queries a REAL potential customer or reader would type into Google
                before choosing something this business offers. Query styles that fit this business:
                {$shapes}

                Rules:
                - Each query ties to exactly ONE offer from the list (copy the offer text verbatim into "offer").
                - 3-6 words, natural search phrasing, lowercase, no brand names, no quotes.
                - Prefer specific long-tail phrasings a smaller site can rank for over generic head terms.
                - intent: informational | commercial | transactional.

                Return JSON: {"queries": [{"query": "...", "offer": "...", "intent": "..."}]}
                PROMPT],
            ], [
                'temperature' => 0.4,
                'max_tokens' => 1600,
                'timeout' => 40,
                '__source' => 'content_autopilot.offer_queries',
                '__unmetered' => true,
            ]);
            app(ContentLlmSpendMeter::class)->add(ContentLlmSpendMeter::EST_IDEATE_USD);

            if (! is_array($response)) {
                return [];
            }

            $out = [];
            $seen = [];
            foreach ((array) ($response['queries'] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $query = mb_strtolower(trim((string) ($row['query'] ?? '')));
                if ($query === '' || mb_strlen($query) > 80 || isset($seen[$query])) {
                    continue;
                }
                $seen[$query] = true;
                $offer = trim((string) ($row['offer'] ?? ''));
                $intent = in_array($row['intent'] ?? null, ['informational', 'commercial', 'transactional'], true)
                    ? $row['intent'] : 'informational';
                // The model must not invent offers — snap to the closest real one.
                $out[] = ['query' => $query, 'offer' => $this->snapToOffer($offer, $sell), 'intent' => $intent];
                if (count($out) >= self::MAX_CANDIDATES) {
                    break;
                }
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * LLM-free fallback: fill the site type's query shapes with offer heads.
     * Crude but honest — and it keeps lineage intact.
     *
     * @return list<array{query:string, offer:string, intent:string}>
     */
    private function mechanicalCandidates(ContentPlan $plan, array $sell): array
    {
        $profile = ContentSiteTypeProfiles::profile($plan->site_type);
        $audience = trim((string) $plan->audience);
        $audienceHead = $audience !== ''
            ? implode(' ', array_slice(preg_split('/\s+/', mb_strtolower($audience), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 3))
            : 'beginners';

        $out = [];
        $seen = [];
        foreach ($sell as $offer) {
            $head = $this->offerHead($offer);
            if ($head === '') {
                continue;
            }
            foreach ($profile['query_shapes'] as $shape) {
                $query = str_replace(['{offer}', '{audience}'], [$head, $audienceHead], $shape);
                $query = mb_strtolower(trim(preg_replace('/\s+/', ' ', $query)));
                if ($query === '' || mb_strlen($query) > 80 || isset($seen[$query])) {
                    continue;
                }
                $seen[$query] = true;
                $out[] = ['query' => $query, 'offer' => $offer, 'intent' => $this->shapeIntent($shape)];
                if (count($out) >= self::MAX_CANDIDATES) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /**
     * Promo mechanics are not products: "Exclusive offers", "Complimentary
     * samples with orders" describe HOW the shop sells, not WHAT — seeding
     * queries from them yields "sign up for exclusive offers" / cross-brand
     * drift (live kayali round #3). Extraction can produce them; the query
     * pipeline must never seed from them. Public: the DFS suggestions pass
     * applies the same rule.
     */
    public static function isPromoOffer(string $offer): bool
    {
        return (bool) preg_match(
            '/\b(offers?|discounts?|deals?|coupons?|promo(tion)?s?|samples?|shipping|returns?|sign.?ups?|newsletters?|loyalty|rewards?)\b/iu',
            $offer
        );
    }

    /** Same head-shortening rule the keyword seeds always used (≤5 words). Public: the DFS suggestions pass seeds from the same heads. */
    public function offerHead(string $offer): string
    {
        $offer = mb_strtolower(trim(preg_replace('/\s*\(.*?\)\s*/', ' ', $offer) ?? $offer));
        $words = preg_split('/\s+/', $offer, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_slice($words, 0, 5));
    }

    private function shapeIntent(string $shape): string
    {
        return match (true) {
            str_contains($shape, 'best') || str_contains($shape, 'vs')
                || str_contains($shape, 'review') || str_contains($shape, 'compar') => 'commercial',
            str_contains($shape, 'cost') || str_contains($shape, 'buy') => 'transactional',
            default => 'informational',
        };
    }

    /** Match the model's echoed offer back to a real offering (fuzzy, safe). */
    private function snapToOffer(string $offer, array $sell): string
    {
        if ($offer === '') {
            return $sell[0];
        }
        foreach ($sell as $real) {
            if (strcasecmp($real, $offer) === 0) {
                return $real;
            }
        }
        // Highest token overlap wins; ties → first (most important) offer.
        $offerTokens = $this->tokens($offer);
        $best = $sell[0];
        $bestScore = 0;
        foreach ($sell as $real) {
            $overlap = count(array_intersect($offerTokens, $this->tokens($real)));
            if ($overlap > $bestScore) {
                $bestScore = $overlap;
                $best = $real;
            }
        }

        return $best;
    }

    /**
     * Attribute arbitrary (server-expanded) keywords back to offers by token
     * overlap with the candidate set — powers the "because you sell: X"
     * lineage on keywords the LLM never saw. Returns keyword => offer for
     * confident matches only.
     *
     * @param  list<string>  $keywords
     * @param  list<array{query:string, offer:string, intent:string}>  $candidates
     * @return array<string, string>
     */
    public function attribute(array $keywords, array $candidates, array $sell): array
    {
        $offerTokens = [];
        foreach ($sell as $offer) {
            $offerTokens[$offer] = $this->tokens($offer);
        }
        foreach ($candidates as $c) {
            // Candidate queries extend their offer's token set — "layering"
            // links back to the perfume offer that spawned the query.
            $offerTokens[$c['offer']] = array_values(array_unique(array_merge(
                $offerTokens[$c['offer']] ?? [], $this->tokens($c['query'])
            )));
        }

        $map = [];
        foreach ($keywords as $kw) {
            $kwTokens = $this->tokens($kw);
            if ($kwTokens === []) {
                continue;
            }
            $best = null;
            $bestScore = 0.0;
            foreach ($offerTokens as $offer => $tokens) {
                if ($tokens === []) {
                    continue;
                }
                $score = count(array_intersect($kwTokens, $tokens)) / count($kwTokens);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $offer;
                }
            }
            // Confident = at least half the keyword's tokens belong to the offer.
            if ($best !== null && $bestScore >= 0.5) {
                $map[$kw] = (string) $best;
            }
        }

        return $map;
    }

    /** @return list<string> */
    private function tokens(string $s): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($words, static fn ($w) => mb_strlen($w) >= 3));
    }
}
