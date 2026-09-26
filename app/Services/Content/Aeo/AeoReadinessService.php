<?php

namespace App\Services\Content\Aeo;

use App\Models\ContentAeoAudit;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Services\Crawler\CrawlFetcher;
use App\Support\Aeo\AiAgents;
use App\Support\Crawler\RobotsTxtParser;
use Illuminate\Support\Facades\Log;

/**
 * "Can the AI answer engines read this site at all?"
 *
 * Three questions, answered from the site itself and from our own crawl — no
 * vendor, no LLM, nothing that can be wrong in an interesting way:
 *
 *   1. Does robots.txt let each AI agent in? This is the first caller in the
 *      codebase to use the $userAgentTokens argument {@see RobotsTxtParser}
 *      has always had — every other caller asks about Googlebot only, and an
 *      "Allow: /" for Googlebot says nothing about "Disallow: /" for GPTBot.
 *   2. Is there an llms.txt, the file answer engines increasingly look for?
 *   3. Do the pages carry the structured data an answer engine leans on
 *      (Article/FAQPage/HowTo/Organization)? Read from the crawl we already
 *      run, not a new fetch.
 *
 * A fetch that fails records UNKNOWN. That distinction is the whole point:
 * telling a client "GPTBot is allowed" because we could not reach their
 * robots.txt would be worse than telling them nothing.
 */
class AeoReadinessService
{
    /** Schema types that matter to an answer engine, in priority order. */
    private const ANSWER_TYPES = ['FAQPage', 'HowTo', 'QAPage', 'Article', 'BlogPosting', 'Organization', 'LocalBusiness'];

    /** How many crawled pages to sample for schema coverage. */
    private const SCHEMA_SAMPLE = 500;

    public function __construct(private readonly CrawlFetcher $fetcher) {}

    /**
     * Run the check and store it. Returns the stored audit, or null when the
     * website has no usable domain (nothing to check, nothing to claim).
     */
    public function audit(Website $website): ?ContentAeoAudit
    {
        $origin = $this->origin($website);
        if ($origin === null) {
            return null;
        }

        [$robotsTxt, $robotsFetched] = $this->fetch($origin.'/robots.txt');
        $robotsAi = $this->robotsVerdicts($robotsTxt, $robotsFetched);
        [$llmsBody, $llmsFetched] = $this->fetch($origin.'/llms.txt');
        $llmsPresent = $llmsFetched && $this->looksLikeLlmsTxt($llmsBody);
        $coverage = $this->schemaCoverage($website);

        $breakdown = $this->breakdown($robotsAi, $robotsFetched, $llmsPresent, $coverage);

        return ContentAeoAudit::create([
            'website_id' => $website->id,
            'checked_at' => now(),
            'robots_ai' => $robotsAi,
            'robots_fetched' => $robotsFetched,
            'llms_txt_present' => $llmsPresent,
            'llms_txt_url' => $llmsPresent ? $origin.'/llms.txt' : null,
            'schema_coverage' => $coverage,
            'readiness_score' => $this->score($breakdown),
            'breakdown' => $breakdown,
        ]);
    }

    /**
     * Per-agent robots.txt verdict.
     *
     * A site with no robots.txt at all allows everything — that is the standard,
     * and it is a real answer, so it is recorded as ALLOWED rather than UNKNOWN.
     * Only a fetch we could not complete is UNKNOWN.
     *
     * @return array<string, string>
     */
    public function robotsVerdicts(?string $robotsTxt, bool $fetched): array
    {
        $out = [];
        foreach (AiAgents::all() as $id => $agent) {
            if (! $fetched) {
                $out[$id] = ContentAeoAudit::UNKNOWN;

                continue;
            }
            // '/' is the right probe path: an agent disallowed at the root is
            // shut out of everything an answer engine would want to quote.
            $out[$id] = RobotsTxtParser::isBlocked((string) $robotsTxt, '/', [$agent['token']])
                ? ContentAeoAudit::BLOCKED
                : ContentAeoAudit::ALLOWED;
        }

        return $out;
    }

    /**
     * What structured data our crawl actually found, counted by @type.
     *
     * Reads `website_pages.seo_signals.schema_types`, which the crawler already
     * writes — no extra fetch, and it covers the whole site rather than a
     * sample of one page.
     *
     * @return array{pages: int, with_answer_schema: int, types: array<string, int>}
     */
    public function schemaCoverage(Website $website): array
    {
        $crawlSiteId = $website->crawl_site_id;
        if (! $crawlSiteId) {
            return ['pages' => 0, 'with_answer_schema' => 0, 'types' => []];
        }

        $pages = 0;
        $withAnswerSchema = 0;
        $types = [];

        WebsitePage::query()
            ->where('crawl_site_id', $crawlSiteId)
            ->whereNull('removed_at')
            ->where('http_status', 200)
            ->select('id', 'seo_signals')
            ->orderBy('id')
            ->limit(self::SCHEMA_SAMPLE)
            ->each(function (WebsitePage $page) use (&$pages, &$withAnswerSchema, &$types): void {
                $pages++;
                $found = (array) (($page->seo_signals ?? [])['schema_types'] ?? []);
                $matched = false;
                foreach ($found as $type) {
                    $type = (string) $type;
                    if ($type === '') {
                        continue;
                    }
                    $types[$type] = ($types[$type] ?? 0) + 1;
                    if (in_array($type, self::ANSWER_TYPES, true)) {
                        $matched = true;
                    }
                }
                if ($matched) {
                    $withAnswerSchema++;
                }
            });

        arsort($types);

        return [
            'pages' => $pages,
            'with_answer_schema' => $withAnswerSchema,
            'types' => array_slice($types, 0, 12, true),
        ];
    }

    /**
     * The readiness checks, each with a weight and a client-safe fix line.
     *
     * Weights renormalize over the checks that could actually run (the trick
     * ContentSeoScorer:373 uses), so a site whose robots.txt we could not fetch
     * is scored on what we DO know instead of being punished for our own
     * failed request.
     *
     * @param  array<string, string>  $robotsAi
     * @return list<array{code: string, label: string, passed: bool, weight: int, detail: string}>
     */
    private function breakdown(array $robotsAi, bool $robotsFetched, bool $llmsPresent, array $coverage): array
    {
        $out = [];

        if ($robotsFetched) {
            // Search and user agents are the ones that decide whether you can
            // be cited today; training agents decide whether tomorrow's model
            // knows you at all. Both matter, so both are checked — separately,
            // because blocking training is a defensible choice and blocking
            // retrieval usually is not.
            foreach ([AiAgents::SEARCH => 34, AiAgents::USER => 26, AiAgents::TRAINING => 12] as $kind => $weight) {
                $ids = array_keys(array_filter(
                    AiAgents::all(),
                    static fn (array $a): bool => $a['kind'] === $kind
                ));
                $blocked = array_values(array_filter($ids, static fn (string $id): bool => ($robotsAi[$id] ?? '') === ContentAeoAudit::BLOCKED));
                $out[] = [
                    'code' => 'robots_'.$kind,
                    'label' => match ($kind) {
                        AiAgents::SEARCH => __('AI search crawlers can read your site'),
                        AiAgents::USER => __('AI assistants can open your pages for a user'),
                        default => __('AI training crawlers can read your site'),
                    },
                    'passed' => $blocked === [],
                    'weight' => $weight,
                    'detail' => $blocked === []
                        ? ''
                        : implode(', ', array_map(static fn (string $id): string => AiAgents::label($id), $blocked)),
                ];
            }
        }

        $out[] = [
            'code' => 'llms_txt',
            'label' => __('Your site publishes an llms.txt'),
            'passed' => $llmsPresent,
            'weight' => 10,
            'detail' => '',
        ];

        if (($coverage['pages'] ?? 0) > 0) {
            $share = $coverage['with_answer_schema'] / max(1, $coverage['pages']);
            $out[] = [
                'code' => 'answer_schema',
                'label' => __('Your pages describe themselves in structured data'),
                'passed' => $share >= 0.6,
                'weight' => 18,
                'detail' => __(':n of :total pages checked', [
                    'n' => $coverage['with_answer_schema'], 'total' => $coverage['pages'],
                ]),
            ];
        }

        return $out;
    }

    /** @param list<array{passed: bool, weight: int}> $breakdown */
    private function score(array $breakdown): int
    {
        $total = array_sum(array_column($breakdown, 'weight'));
        if ($total <= 0) {
            return 0;
        }
        $earned = array_sum(array_map(
            static fn (array $c): int => $c['passed'] ? $c['weight'] : 0,
            $breakdown
        ));

        return (int) round($earned / $total * 100);
    }

    /**
     * Is this actually an llms.txt, or a soft 404?
     *
     * Plenty of sites answer 200 with their homepage for any unknown path, so
     * "we got a 200" is not evidence a file exists. Telling a client they have
     * an llms.txt when they do not is the kind of false credit that makes the
     * whole page untrustworthy, so anything that smells like an HTML document
     * is treated as absent.
     */
    private function looksLikeLlmsTxt(?string $body): bool
    {
        $body = trim((string) $body);
        if ($body === '' || mb_strlen($body) < 8) {
            return false;
        }
        $head = mb_strtolower(mb_substr($body, 0, 400));
        foreach (['<!doctype', '<html', '<head', '<body', '<script', '<div'] as $marker) {
            if (str_contains($head, $marker)) {
                return false;
            }
        }

        return true;
    }

    /** @return array{0: ?string, 1: bool} [body, fetched] */
    private function fetch(string $url): array
    {
        try {
            $res = $this->fetcher->fetch($url, [], 10);
        } catch (\Throwable $e) {
            Log::debug('aeo.readiness_fetch_failed', ['url' => $url, 'error' => mb_substr($e->getMessage(), 0, 120)]);

            return [null, false];
        }

        // A 404 is a complete, trustworthy answer ("there is no such file"),
        // unlike a timeout or a 5xx, which tell us nothing.
        if (($res['ok'] ?? false) && (int) ($res['status'] ?? 0) === 200) {
            return [(string) $res['body'], true];
        }
        if ((int) ($res['status'] ?? 0) === 404) {
            return ['', true];
        }

        return [null, false];
    }

    private function origin(Website $website): ?string
    {
        $host = strtolower(trim((string) ($website->normalized_domain ?: $website->domain)));
        $host = preg_replace('#^https?://#', '', $host) ?? $host;
        $host = trim((string) preg_replace('#/.*$#', '', $host));

        return $host === '' ? null : 'https://'.$host;
    }
}
