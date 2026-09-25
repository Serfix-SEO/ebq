<?php

namespace App\Services\Content\Catalog;

use App\Models\ContentProduct;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmClientFactory;
use Illuminate\Support\Facades\Log;

/**
 * Strict Product Mode: drops proposed topics that NAME a product or brand the
 * shop does not carry.
 *
 * Why this is not a word list. `CompetitorMentionGuard::strictBlockedBrands()`
 * is fed by competitor discovery, so it holds rival SHOPS ("v perfumes",
 * "swiss arabian") — nothing stopped blisfragrance.com, a strict client with
 * 524 products, from being handed "Baccarat Rouge 540 Alternatives" and
 * "Armaf Club de Nuit Intense Man" (2026-09-25). Neither brand is in their
 * catalog.
 *
 * Why this is not token matching either. Measured against that real catalog, a
 * stoplist-plus-vocabulary rule flagged "well", "present", "comprehensive" and
 * "gender-neutral" while still missing "baccarat": separating a brand from an
 * ordinary English word needs knowledge a token list does not have. So one
 * cheap LLM call per planning run judges the batch against the shop's own
 * product names.
 *
 * Fails OPEN on purpose: an LLM outage must not stall a client's calendar, so
 * an unusable answer keeps every candidate. The matcher's grounding gate still
 * applies — this only removes titles that name outsiders.
 */
class CatalogBrandValidator
{
    /** Product names shown to the model; enough to infer the brands carried. */
    private const SAMPLE = 150;

    /** Above this share of a batch flagged, treat the answer as broken. */
    private const SANITY_LIMIT = 0.7;

    public function __construct(private ?LlmClient $llm = null) {}

    /**
     * Indices of $titles that name a product or brand absent from the catalog.
     *
     * @param  list<string>  $titles
     * @return list<int>
     */
    public function offCatalogIndexes(string $websiteId, array $titles): array
    {
        $titles = array_values(array_filter(array_map('trim', $titles), fn ($t) => $t !== ''));
        if ($titles === []) {
            return [];
        }

        $names = ContentProduct::query()
            ->where('website_id', $websiteId)
            ->usable()
            ->inRandomOrder()
            ->limit(self::SAMPLE)
            ->pluck('name')
            ->all();
        if ($names === []) {
            return [];   // no catalog to judge against — the planner gate covers this
        }

        $numbered = [];
        foreach ($titles as $i => $title) {
            $numbered[] = $i.'. '.mb_substr($title, 0, 200);
        }

        $prompt = "A shop sells ONLY these products:\n".implode("\n", array_map(
            fn ($n) => '- '.mb_substr((string) $n, 0, 120), $names
        ))."\n\n"
            .'Below are proposed article titles for this shop. Flag a title ONLY if it names a specific '
            .'product, product line or brand that this shop clearly does NOT sell (for example a rival '
            ."brand's fragrance when no such product appears above).\n\n"
            .'Do NOT flag: generic wording, categories, occasions, materials, places, or brands that DO '
            ."appear in the list above.\n\n"
            ."Titles:\n".implode("\n", $numbered)."\n\n"
            .'Reply with JSON only: {"off_catalog": [<indexes>]}';

        try {
            $llm = $this->llm ?? LlmClientFactory::make();
            $json = $llm->completeJson([
                ['role' => 'system', 'content' => 'You check whether article titles name products a shop does not sell. Reply with JSON only.'],
                ['role' => 'user', 'content' => $prompt],
            ], ['max_tokens' => 400, 'temperature' => 0]);
        } catch (\Throwable $e) {
            Log::warning('content_autopilot.brand_validator_failed', ['error' => $e->getMessage()]);

            return [];
        }

        if (! is_array($json) || ! isset($json['off_catalog']) || ! is_array($json['off_catalog'])) {
            return [];
        }

        $flagged = [];
        foreach ($json['off_catalog'] as $index) {
            if (is_numeric($index) && isset($titles[(int) $index])) {
                $flagged[] = (int) $index;
            }
        }
        $flagged = array_values(array_unique($flagged));

        // A model that flags nearly everything is malfunctioning, and dropping
        // the batch would stall the calendar. Keep them and say so.
        if (count($flagged) > (int) ceil(count($titles) * self::SANITY_LIMIT)) {
            Log::warning('content_autopilot.brand_validator_over_flagged', [
                'website_id' => $websiteId, 'flagged' => count($flagged), 'titles' => count($titles),
            ]);

            return [];
        }

        return $flagged;
    }
}
