<?php

namespace App\Services\Content\Catalog;

use App\Models\ContentProduct;
use App\Support\UnicodeText;
use Illuminate\Support\Collection;

/**
 * Deterministic topic↔product matching (Strict Product Mode): folded-token
 * overlap between a topic's title+keyword and each product's precomputed
 * `terms`. UnicodeText folding makes it Arabic-safe (اسماء ≡ أسماء class of
 * problems). No LLM — this is the hard gate the ideation output and the
 * produce-path grounding both rely on.
 */
class TopicProductMatcher
{
    /**
     * Products relevant to the topic text, best first.
     *
     * @return Collection<int, ContentProduct> with a transient `match_score`
     */
    public function match(string $websiteId, string $topicText, int $limit = 8): Collection
    {
        $topicTokens = $this->tokens($topicText);
        if ($topicTokens === []) {
            return collect();
        }

        return ContentProduct::query()
            ->where('website_id', $websiteId)
            ->usable()
            ->get()
            ->map(function (ContentProduct $p) use ($topicTokens) {
                $overlap = count(array_intersect($topicTokens, (array) $p->terms));
                $p->setAttribute('match_score', $overlap);

                return $p;
            })
            ->filter(fn (ContentProduct $p) => $p->match_score >= 1)
            ->sortByDesc('match_score')
            ->take($limit)
            ->values();
    }

    public function matches(string $websiteId, string $topicText): bool
    {
        return $this->match($websiteId, $topicText, 1)->isNotEmpty();
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        $folded = UnicodeText::fold($text);
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($tokens, fn ($t) => mb_strlen($t) >= 2)));
    }
}
