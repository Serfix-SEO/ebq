<?php

namespace App\Services\Content;

use App\Models\Website;
use App\Support\ContentAutopilotConfig;
use App\Services\Llm\LlmClientFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Auto-detects the Content Autopilot business profile from data the crawler
 * already collected: ONE cheap LLM call over the homepage + top pages turns
 * crawl signals into {description, sell[], dont_sell[]} for the setup wizard.
 * The client edits the result — this is a pre-fill, not a lock-in.
 *
 * Cached 7 days per website (repeat wizard visits must not re-bill).
 * Fails soft: no crawl data / no LLM => nulls, wizard falls back to the
 * homepage meta description and manual entry.
 */
class SiteProfileExtractor
{
    /** @return array{description:?string, sell:list<string>, dont_sell:list<string>} */
    public function extract(Website $website): array
    {
        $empty = ['description' => null, 'sell' => [], 'dont_sell' => []];

        return Cache::remember(
            'content:site-profile:v1:'.$website->id,
            now()->addDays(7),
            function () use ($website, $empty): array {
                $signals = $this->crawlSignals($website);
                if ($signals === []) {
                    return $empty;
                }

                $llm = LlmClientFactory::make(ContentAutopilotConfig::modelFor('ideate')['provider']);
                if (! $llm->isAvailable()) {
                    return $empty;
                }

                $pagesBlock = implode("\n", array_map(
                    static fn ($p) => '- '.$p['title'].($p['meta'] !== '' ? ' — '.$p['meta'] : ''),
                    $signals
                ));

                $response = $llm->completeJson([
                    ['role' => 'system', 'content' => 'You analyze websites for a content-marketing tool. Respond with valid JSON only.'],
                    ['role' => 'user', 'content' => <<<PROMPT
                    Based ONLY on these real pages from {$website->domain}, describe the business.

                    PAGES (title — meta description):
                    {$pagesBlock}

                    Return JSON:
                    {
                      "description": "2-3 sentences, plain language, what the site offers and for whom",
                      "sell": ["4-8 concrete products/services/tools the site clearly offers, most important first"],
                      "dont_sell": ["2-5 closely related things the site does NOT appear to offer (so articles never promise them)"]
                    }
                    Be specific to THIS site. Never invent offerings the pages don't support.
                    PROMPT],
                ], [
                    'temperature' => 0.3,
                    'max_tokens' => 900,
                    'timeout' => 45,
                    '__source' => 'content_autopilot.site_profile',
                ]);
                app(ContentLlmSpendMeter::class)->add(ContentLlmSpendMeter::EST_IDEATE_USD);

                if (! is_array($response)) {
                    return $empty;
                }

                $clean = static fn ($list) => array_slice(array_values(array_filter(array_map(
                    static fn ($v) => trim((string) $v),
                    is_array($list) ? $list : []
                ))), 0, 8);

                return [
                    'description' => is_string($response['description'] ?? null)
                        ? mb_substr(trim($response['description']), 0, 1000) : null,
                    'sell' => $clean($response['sell'] ?? []),
                    'dont_sell' => $clean($response['dont_sell'] ?? []),
                ];
            }
        );
    }

    /** @return list<array{title:string, meta:string}> top crawled pages */
    private function crawlSignals(Website $website): array
    {
        try {
            if (! $website->crawl_site_id) {
                return [];
            }

            return DB::table('website_pages')
                ->where('crawl_site_id', $website->crawl_site_id)
                ->where('http_status', 200)
                ->whereNotNull('title')
                ->where('title', '!=', '')
                ->orderByDesc('inbound_link_count')
                ->limit(20)
                ->get(['title', 'meta_description'])
                ->map(fn ($p) => [
                    'title' => mb_substr((string) $p->title, 0, 120),
                    'meta' => mb_substr((string) ($p->meta_description ?? ''), 0, 200),
                ])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
