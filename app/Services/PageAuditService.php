<?php

namespace App\Services;

use App\Models\PageAuditReport;
use App\Models\SearchConsoleData;
use App\Models\Website;
use App\Support\Audit\HtmlAuditor;
use App\Support\Audit\KeywordStrategyAnalyzer;
use App\Support\Audit\PageLocaleResolver;
use App\Services\LighthouseClient;
use App\Support\Audit\RecommendationEngine;
use App\Services\Crawler\ProxyPool;
use App\Support\Audit\SafeHttpGuard;
use App\Support\Audit\SerpGlCountryPrompt;
use App\Support\Audit\SerpLocaleDefaults;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PageAuditService
{
    private const MAX_LINKS_CHECKED = 100;

    private const LINK_TIMEOUT = 8;

    private const LINK_POOL_CONCURRENCY = 10;

    /** Statuses that can be a block/rate-limit false positive rather than a real dead link. */
    private const LINK_FALLBACK_STATUSES = [403, 405, 429, 501];

    private const LINK_CHECK_UA = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Googlebot/2.1; +http://www.google.com/bot.html) Chrome/124.0.6367.207 Safari/537.36';

    /** Social hosts whose WAF/anti-bot layer routinely 403s non-browser UAs even for live
     *  pages. A 401/403/429/999 here isn't trustworthy evidence of a dead link. Mirrors
     *  SiteIssueDetector::KNOWN_ANTIBOT_HOSTS. Confirmed 2026-06-25 (x.com/WesleyLawn38331). */
    private const KNOWN_ANTIBOT_HOSTS = ['x.com', 'twitter.com', 'linkedin.com', 'facebook.com', 'instagram.com'];

    private const ANTIBOT_BLOCK_STATUSES = [401, 403, 429, 999];

    /** Cap the HTML body we parse to protect libxml / memory. */
    private const MAX_BODY_BYTES = 5_000_000;

    public function __construct(
        private readonly SafeHttpGuard $guard,
        private readonly ProxyPool $proxies,
    ) {}

    /**
     * `$lite = true` skips the two slowest optional stages (link checking
     * across up to 100 outbound URLs, and the 3-competitor Serper benchmark
     * with full HTML fetches). Use it when the audit is triggered from a
     * UX path where wall-time matters more than completeness — e.g. the
     * editor's live-score endpoint, where users are watching a spinner.
     * Typical wall-time: full ≈ 60–120s; lite ≈ 15–30s.
     */
    public function audit(string $websiteId, string $pageUrl, ?string $serpTargetKeyword = null, bool $enforceUrlBelongsToWebsite = false, ?string $serpGlUserOverride = null, bool $lite = false): PageAuditReport
    {
        $startedAt = microtime(true);

        $serpKeywordTrimmed = is_string($serpTargetKeyword) ? trim($serpTargetKeyword) : '';
        $serpKeywordArg = $serpKeywordTrimmed !== '' ? $serpKeywordTrimmed : null;
        $failurePrimaryKeyword = $serpKeywordArg;
        $failurePrimarySource = $serpKeywordArg !== null ? 'custom_audit' : null;

        $guardCheck = $this->guard->check($pageUrl);
        if (! $guardCheck['ok']) {
            return $this->persistFailure(
                $websiteId,
                $pageUrl,
                'Audit target rejected: '.($guardCheck['reason'] ?? 'unsafe_url'),
                null,
                null,
                $failurePrimaryKeyword,
                $failurePrimarySource,
            );
        }

        if ($enforceUrlBelongsToWebsite) {
            $websiteForDomain = Website::query()->find($websiteId);
            if (! $websiteForDomain instanceof Website) {
                return $this->persistFailure($websiteId, $pageUrl, 'Website not found.', null, null, $failurePrimaryKeyword, $failurePrimarySource);
            }
            if (! $websiteForDomain->isAuditUrlForThisSite($pageUrl)) {
                return $this->persistFailure(
                    $websiteId,
                    $pageUrl,
                    'The URL must use your website domain (or a subdomain of it).',
                    null,
                    null,
                    $failurePrimaryKeyword,
                    $failurePrimarySource,
                );
            }
        }

        try {
            $website = Website::query()->find($websiteId);
            if (! $website instanceof Website) {
                return $this->persistFailure(
                    $websiteId,
                    $pageUrl,
                    'Website not found.',
                    null,
                    null,
                    $failurePrimaryKeyword,
                    $failurePrimarySource,
                );
            }

            // Live-score (lite) skips the Serper benchmark; the full flow runs it.
            $built = $this->buildAuditResult($pageUrl, $website, $serpKeywordArg, $serpGlUserOverride, $lite, $lite, false);

            if (! ($built['ok'] ?? false)) {
                return $this->persistFailure(
                    $websiteId,
                    $pageUrl,
                    $built['error'] ?? 'Failed to fetch page',
                    $built['http_status'] ?? null,
                    $built['ttfb_ms'] ?? null,
                    $failurePrimaryKeyword,
                    $failurePrimarySource,
                );
            }

            return PageAuditReport::updateOrCreate(
                ['website_id' => $websiteId, 'page_hash' => hash('sha256', $pageUrl)],
                [
                    'page' => mb_substr($pageUrl, 0, 700),
                    'status' => 'completed',
                    'audited_at' => now(),
                    'http_status' => $built['http_status'],
                    'response_time_ms' => $built['ttfb_ms'],
                    'page_size_bytes' => $built['page_size_bytes'],
                    'error_message' => null,
                    'primary_keyword' => $built['primary_keyword'],
                    'primary_keyword_source' => $built['primary_keyword_source'],
                    'result' => $built['result'],
                    'content_hash' => $built['content_hash'] ?? null,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning("PageAuditService failed for website {$websiteId} page {$pageUrl}: {$e->getMessage()}");

            return $this->persistFailure(
                $websiteId,
                $pageUrl,
                $e->getMessage(),
                null,
                (int) round((microtime(true) - $startedAt) * 1000),
                $failurePrimaryKeyword,
                $failurePrimarySource,
            );
        }
    }

    /**
     * Run a free, no-signup landing-page audit from a user-supplied URL + keyword.
     *
     * No Website / GSC data is involved — keyword analysis runs purely off the
     * manual keyword (see {@see KeywordStrategyAnalyzer::analyze()} with empty
     * targets). Lite (skips slow link checking) and runs the Serper SERP
     * benchmark — competitors + the page's SERP position for the keyword — but
     * skips the Lighthouse CWV stage. SSRF is enforced by {@see SafeHttpGuard}
     * inside fetch.
     *
     * @return array{status: string, result: ?array<string, mixed>, http_status: ?int, response_time_ms: ?int, primary_keyword: ?string, primary_keyword_source: ?string, error_message: ?string}
     */
    /**
     * Independently fetch a URL and hash its extracted body text — used by
     * `ebq:recheck-audit-content` to detect real content drift without
     * trusting the WP plugin's self-reported `modified` timestamp (see
     * `content_hash` re-audit gate, infra/audits/page-audit.md §Gotchas).
     * Returns null on any fetch/SSRF-guard failure (caller should skip,
     * not treat as "changed").
     */
    public function currentContentHash(string $pageUrl): ?string
    {
        $guardCheck = $this->guard->check($pageUrl);
        if (! $guardCheck['ok']) {
            return null;
        }

        $fetch = $this->fetch($pageUrl);
        if (! isset($fetch['body'])) {
            return null;
        }

        $bodyText = (new HtmlAuditor($fetch['body'], $pageUrl))->content()['body_text'] ?? '';

        return hash('sha256', $bodyText);
    }

    public function auditGuest(string $pageUrl, string $keyword, ?string $serpGl = null): array
    {
        $kw = trim($keyword);
        $serpKeywordArg = $kw !== '' ? $kw : null;

        try {
            // $serpGl is the visitor-chosen SERP country; buildAuditResult validates
            // it and falls back to the page-detected gl when null/invalid.
            $built = $this->buildAuditResult($pageUrl, null, $serpKeywordArg, $serpGl, true, false, true);
        } catch (\Throwable $e) {
            Log::warning("PageAuditService::auditGuest failed for {$pageUrl}: {$e->getMessage()}");

            return [
                'status' => 'failed',
                'result' => null,
                'http_status' => null,
                'response_time_ms' => null,
                'primary_keyword' => $serpKeywordArg,
                'primary_keyword_source' => $serpKeywordArg !== null ? 'custom_audit' : null,
                'error_message' => 'We couldn’t analyze that page. Please check the URL and try again.',
            ];
        }

        if (! ($built['ok'] ?? false)) {
            return [
                'status' => 'failed',
                'result' => null,
                'http_status' => $built['http_status'] ?? null,
                'response_time_ms' => $built['ttfb_ms'] ?? null,
                'primary_keyword' => $serpKeywordArg,
                'primary_keyword_source' => $serpKeywordArg !== null ? 'custom_audit' : null,
                'error_message' => $this->friendlyGuestError($built['error'] ?? null),
            ];
        }

        return [
            'status' => 'completed',
            'result' => $built['result'],
            'http_status' => $built['http_status'],
            'response_time_ms' => $built['ttfb_ms'],
            'primary_keyword' => $built['primary_keyword'],
            'primary_keyword_source' => $built['primary_keyword_source'],
            'error_message' => null,
        ];
    }

    /**
     * Translate an internal fetch/guard error into copy safe to show a stranger.
     */
    private function friendlyGuestError(?string $raw): string
    {
        $raw = (string) $raw;
        if (str_contains($raw, 'rejected') || str_contains($raw, 'blocked') || str_contains($raw, 'unsafe')) {
            return 'That URL can’t be audited. Enter a public website address (https://…).';
        }

        return 'We couldn’t fetch that page. Check the URL is reachable and try again.';
    }

    /**
     * Shared analysis pipeline used by both the authenticated {@see audit()} flow
     * (which persists a {@see PageAuditReport}) and the anonymous {@see auditGuest()}
     * flow. When {@see $website} is null there is no GSC keyword source — the
     * keyword block is built from {@see $serpKeywordArg} alone. {@see $skipSerp} /
     * {@see $skipCwv} short-circuit the two paid external stages.
     *
     * @return array{ok: bool, error?: string, http_status: ?int, ttfb_ms: ?int, page_size_bytes?: ?int, result?: array<string, mixed>, primary_keyword?: ?string, primary_keyword_source?: ?string}
     */
    private function buildAuditResult(string $pageUrl, ?Website $website, ?string $serpKeywordArg, ?string $serpGlUserOverride, bool $lite, bool $skipSerp, bool $skipCwv): array
    {
        $fetch = $this->fetch($pageUrl);

        if (! isset($fetch['body'])) {
            return [
                'ok' => false,
                'error' => $fetch['error'] ?? 'Failed to fetch page',
                'http_status' => $fetch['http_status'] ?? null,
                'ttfb_ms' => $fetch['ttfb_ms'] ?? null,
            ];
        }

        $html = $fetch['body'];
        $auditor = new HtmlAuditor($html, $pageUrl);

        $metadata = $auditor->metadata();
        $localeSignals = $auditor->localeSignals();
        $pageLocaleResolved = PageLocaleResolver::resolve($localeSignals, $pageUrl);

        $userSerpGl = null;
        if ($serpGlUserOverride !== null && SerpLocaleDefaults::isValidSerperGl($serpGlUserOverride)) {
            $userSerpGl = strtolower(trim($serpGlUserOverride));
        }
        $serpGlForBenchmark = $userSerpGl ?? $pageLocaleResolved['gl'];

        $headings = $auditor->headings();
        $content = $auditor->content();
        $bodyText = $content['body_text'];
        // Hash of the extracted body text (not raw HTML — markup/whitespace
        // churn shouldn't count as a content change) so a later independent
        // fetch can detect real content drift without trusting WordPress's
        // self-reported `modified` timestamp. See `content_hash` re-audit gate.
        $contentHash = hash('sha256', $bodyText);
        unset($content['body_text']);
        // Persist a truncated excerpt so downstream consumers (e.g. the
        // striking-distance AI snippet rewriter) have page copy to work from
        // without re-fetching the HTML. Additive to the stored result JSON.
        $content['body_excerpt'] = mb_substr($bodyText, 0, 4000);
        $images = $auditor->images();
        $links = $auditor->links();
        $schema = $auditor->schema();
        $favicon = $auditor->favicon();
        $readability = $auditor->readability($bodyText);

        // Link checking can take up to ~80s for a page with many
        // outbound links. Skip it in lite mode — the live-score caller
        // doesn't surface broken-link counts in its factor anyway.
        if ($lite) {
            $links['broken'] = [];
            $links['links_checked'] = 0;
            $links['links_skipped'] = count($links['internal']) + count($links['external']);
        } else {
            $allLinks = array_merge($links['internal'], $links['external']);
            $linkCheck = $this->checkLinks($allLinks);
            $links['broken'] = $linkCheck['broken'];
            $links['links_checked'] = $linkCheck['checked'];
            $links['links_skipped'] = $linkCheck['skipped'];
        }

        $technical = [
            'http_status' => $fetch['http_status'],
            'ttfb_ms' => $fetch['ttfb_ms'],
            'page_size_bytes' => $fetch['size'],
            'compression' => $fetch['compression'],
            'is_https' => str_starts_with(strtolower($pageUrl), 'https://'),
            'stack' => $auditor->technology($fetch['headers'] ?? []),
        ];

        $pageLocaleBlock = [
            'gl' => $pageLocaleResolved['gl'],
            'hl' => $pageLocaleResolved['hl'],
            'source' => $pageLocaleResolved['source'],
            'bcp47' => $pageLocaleResolved['bcp47'],
            'hreflang_matched' => $pageLocaleResolved['hreflang_matched'],
            'signals' => $pageLocaleResolved['signals'],
        ];
        if ($userSerpGl !== null) {
            $pageLocaleBlock['serp_gl_user_chosen'] = $userSerpGl;
        }

        $serpEffectivePreview = SerpLocaleDefaults::forSerperRequest(
            $serpGlForBenchmark,
            $pageLocaleResolved['hl'],
            $pageLocaleResolved['bcp47'],
        );
        $serpEffGl = $serpEffectivePreview['gl'] ?? null;
        if (SerpLocaleDefaults::isValidSerperGl($serpEffGl)) {
            $pageLocaleBlock['serp_gl_effective'] = strtolower((string) $serpEffGl);
        }

        $result = [
            'metadata' => $metadata,
            'page_locale' => $pageLocaleBlock,
            'content' => $content + ['headings' => $headings['headings'], 'h1_count' => $headings['h1_count'], 'heading_order_ok' => $headings['heading_order_ok']],
            'images' => $images,
            'links' => $links,
            'technical' => $technical,
            'advanced' => [
                'schema_blocks' => $schema['count'],
                'schema_blocks_detail' => $schema['blocks'],
                'readability' => $readability,
                'has_favicon' => $favicon['present'],
                'favicon_href' => $favicon['href'],
            ],
        ];

        $targetKeywords = $this->fetchTargetKeywords($website, $pageUrl);
        $h1Texts = array_values(array_filter(array_map(
            fn ($h) => $h['level'] === 1 ? ($h['text'] ?? '') : null,
            $headings['headings'] ?? []
        )));
        $allHeadingsText = implode(' ', array_map(fn ($h) => $h['text'] ?? '', $headings['headings'] ?? []));

        $result['keywords'] = app(KeywordStrategyAnalyzer::class)->analyze($targetKeywords, [
            'title' => $metadata['title'] ?? '',
            'meta_description' => $metadata['meta_description'] ?? '',
            'h1_text' => implode(' ', $h1Texts),
            'all_headings_text' => $allHeadingsText,
            'body_text' => $bodyText,
            'keyword_density' => $content['keyword_density'] ?? [],
        ], $serpKeywordArg);

        if ($website instanceof Website && is_array($result['keywords'])) {
            $result['keywords']['gsc_lookback_days'] = $website->effectiveGscKeywordLookbackDays();
        }

        // Serper benchmark fetches 3 competitor HTML pages + audits them —
        // adds 20–40s to wall-time. Gated solely by $skipSerp so a caller can
        // stay lite (skip link checking) yet still benchmark — e.g. guest
        // audits. The authenticated live-score path passes skipSerp=$lite.
        $benchmark = $skipSerp ? null : $this->buildSerperReadabilityBenchmark(
            $pageUrl,
            $result['keywords'],
            is_numeric($readability['flesch'] ?? null) ? (float) $readability['flesch'] : null,
            (int) ($content['word_count'] ?? 0),
            (int) ($images['total'] ?? 0),
            $technical['stack'] ?? null,
            $serpKeywordArg,
            $serpGlForBenchmark,
            $pageLocaleResolved['hl'],
            $pageLocaleResolved['bcp47'],
            $website?->id,
            $website->user_id ?? null,
        );
        if ($benchmark !== null) {
            if (isset($benchmark['your_serp']) && is_array($benchmark['your_serp'])) {
                $benchmark['your_serp'] = $this->mergeAuditedPageSerpPreviewIntoYourSerp(
                    $benchmark['your_serp'],
                    $pageUrl,
                    $metadata,
                );
            }
            $result['benchmark'] = $benchmark;
        }

        // Core Web Vitals via the standalone ebq-intelegence Lighthouse service.
        // Runs here (not in the Job) so the sync "Audit this page" flow on
        // PageDetail gets CWV too. Client returns null silently if the
        // service is down — audit still completes without CWV. Guest audits
        // skip it (skipCwv) to keep the free run fast + credit-free.
        if (! $skipCwv) {
            try {
                $cwv = app(LighthouseClient::class)->fetchMobileAndDesktop($pageUrl);
                if (is_array($cwv)) {
                    $result['core_web_vitals'] = $cwv;
                }
            } catch (\Throwable $e) {
                Log::warning('PageAuditService: CWV enrichment failed', [
                    'url' => $pageUrl,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $result['recommendations'] = app(RecommendationEngine::class)->analyze($result);

        $kwBlock = $result['keywords'] ?? [];
        $storedPrimaryKeyword = null;
        $storedPrimarySource = null;
        if (($kwBlock['available'] ?? false) === true) {
            $pq = isset($kwBlock['primary']['query']) ? trim((string) $kwBlock['primary']['query']) : '';
            $storedPrimaryKeyword = $pq !== '' ? mb_substr($pq, 0, 200) : null;
            $src = $kwBlock['primary_source'] ?? null;
            $storedPrimarySource = is_string($src) && $src !== '' ? mb_substr($src, 0, 32) : null;
        }

        return [
            'ok' => true,
            'http_status' => $fetch['http_status'],
            'ttfb_ms' => $fetch['ttfb_ms'],
            'page_size_bytes' => $fetch['size'],
            'result' => $result,
            'primary_keyword' => $storedPrimaryKeyword,
            'primary_keyword_source' => $storedPrimarySource,
            'content_hash' => $contentHash,
        ];
    }

    /**
     * One HTTP fetch to resolve page locale and recommend a Serper {@code gl} before the interactive audit flow.
     *
     * @return array{ok: bool, recommended_gl?: string, recommendation_hint?: string, detected_hl?: ?string, detected_html_gl?: ?string, error?: string}
     */
    public function peekSerpCountryChoiceNeeded(string $websiteId, string $pageUrl, bool $enforceUrlBelongsToWebsite = false): array
    {
        $guardCheck = $this->guard->check($pageUrl);
        if (! $guardCheck['ok']) {
            return ['ok' => false, 'error' => 'Audit target rejected: '.($guardCheck['reason'] ?? 'unsafe_url')];
        }

        if ($enforceUrlBelongsToWebsite) {
            $websiteForDomain = Website::query()->find($websiteId);
            if (! $websiteForDomain instanceof Website) {
                return ['ok' => false, 'error' => 'Website not found.'];
            }
            if (! $websiteForDomain->isAuditUrlForThisSite($pageUrl)) {
                return ['ok' => false, 'error' => 'The URL must use your website domain (or a subdomain of it).'];
            }
        }

        $fetch = $this->fetch($pageUrl);
        if (! isset($fetch['body'])) {
            return ['ok' => false, 'error' => (string) ($fetch['error'] ?? 'Failed to fetch page')];
        }

        $signals = (new HtmlAuditor($fetch['body'], $pageUrl))->localeSignals();
        $resolved = PageLocaleResolver::resolve($signals, $pageUrl);
        $hl = $resolved['hl'] ?? null;
        $htmlGl = $resolved['gl'] ?? null;
        $bcp47 = $resolved['bcp47'] ?? null;
        $recommended = SerpGlCountryPrompt::recommendedGl($htmlGl, $hl, $bcp47);

        return [
            'ok' => true,
            'recommended_gl' => $recommended,
            'recommendation_hint' => SerpGlCountryPrompt::recommendationHint($recommended, $htmlGl, $hl, $bcp47),
            'detected_hl' => $hl,
            'detected_html_gl' => $htmlGl,
        ];
    }

    /**
     * Optional SERP readability benchmark via Serper (fail-closed: never throws).
     *
     * @param  array<string, mixed>  $keywordsPayload
     * @return array<string, mixed>|null null when Serper is not configured
     */
    private function buildSerperReadabilityBenchmark(string $pageUrl, array $keywordsPayload, ?float $yourFlesch, int $yourWordCount, int $yourImageCount, ?array $yourStack = null, ?string $serpKeywordOverride = null, ?string $serpGl = null, ?string $serpHl = null, ?string $serpBcp47 = null, ?string $websiteId = null, ?string $ownerUserId = null): ?array
    {
        $apiKey = config('services.serper.key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            return null;
        }

        $effective = SerpLocaleDefaults::forSerperRequest($serpGl, $serpHl, $serpBcp47);
        $effectiveGl = $effective['gl'];
        $effectiveHl = $effective['hl'];

        $keywordContext = null;

        try {
            $serpLocaleOut = $this->compactSerpLocale($effectiveGl, $effectiveHl);
            $override = $serpKeywordOverride !== null ? trim($serpKeywordOverride) : '';
            $useManualSerpKeyword = $override !== '';
            $keywordSource = null;

            if (! $useManualSerpKeyword) {
                if (empty($keywordsPayload['available']) || empty($keywordsPayload['primary']['query'])) {
                    return [
                        'keyword' => null,
                        'keyword_source' => null,
                        'source' => 'serper',
                        'your_flesch' => $yourFlesch,
                        'competitors' => [],
                        'skipped_reason' => 'no_primary_keyword',
                        'serp_locale' => $serpLocaleOut,
                    ];
                }
                $keyword = trim((string) $keywordsPayload['primary']['query']);
                $keywordSource = 'gsc_primary';
            } else {
                $keyword = $override;
                $keywordSource = 'manual';
            }

            $keywordContext = $keyword !== '' ? $keyword : null;
            if ($keyword === '') {
                return [
                    'keyword' => null,
                    'keyword_source' => null,
                    'source' => 'serper',
                    'your_flesch' => $yourFlesch,
                    'competitors' => [],
                    'skipped_reason' => 'no_primary_keyword',
                    'serp_locale' => $serpLocaleOut,
                ];
            }

            $payload = app(SerperSearchClient::class)->search(
                $keyword,
                20,
                $effectiveGl,
                $effectiveHl,
                websiteId: $websiteId,
                ownerUserId: $ownerUserId,
                source: 'audit',
            );
            if ($payload === null) {
                return [
                    'keyword' => $keyword,
                    'keyword_source' => $keywordSource,
                    'source' => 'serper',
                    'your_flesch' => $yourFlesch,
                    'competitors' => [],
                    'skipped_reason' => 'serper_request_failed',
                    'your_serp' => $this->emptyYourSerpSnapshot(),
                    'serp_locale' => $serpLocaleOut,
                ];
            }

            $organic = $payload['organic'] ?? [];
            if (! is_array($organic) || $organic === []) {
                return [
                    'keyword' => $keyword,
                    'keyword_source' => $keywordSource,
                    'source' => 'serper',
                    'your_flesch' => $yourFlesch,
                    'competitors' => [],
                    'skipped_reason' => 'no_organic_results',
                    'your_serp' => $this->emptyYourSerpSnapshot(),
                    'serp_locale' => $serpLocaleOut,
                ];
            }

            $yourSerp = $this->resolveYourSerpPosition($pageUrl, $organic);

            $candidates = [];
            foreach ($organic as $row) {
                $link = $this->organicRowHttpUrl(is_array($row) ? $row : null);
                if ($link === null) {
                    continue;
                }
                if ($this->organicHostMatchesAuditedSite($pageUrl, $link)) {
                    continue;
                }
                if (! $this->guard->check($link)['ok']) {
                    continue;
                }
                $candidates[] = [
                    'url' => $link,
                    'title' => is_string($row['title'] ?? null) ? $row['title'] : '',
                    'position' => is_numeric($row['position'] ?? null) ? (int) $row['position'] : null,
                ];
                if (count($candidates) >= 3) {
                    break;
                }
            }

            $competitors = [];
            foreach ($candidates as $entry) {
                $fetch = $this->fetch($entry['url']);
                if (! isset($fetch['body'])) {
                    continue;
                }
                try {
                    $compAuditor = new HtmlAuditor($fetch['body'], $entry['url']);
                    $compContent = $compAuditor->content();
                    $compBody = $compContent['body_text'] ?? '';
                    $compRead = $compAuditor->readability($compBody);
                    $compImages = $compAuditor->images();
                    $compStack = $compAuditor->technology($fetch['headers'] ?? []);
                    $competitors[] = [
                        'url' => $entry['url'],
                        'title' => $entry['title'] !== '' ? $entry['title'] : '',
                        'position' => $entry['position'],
                        'word_count' => isset($compContent['word_count']) ? max(0, (int) $compContent['word_count']) : null,
                        'image_count' => max(0, (int) ($compImages['total'] ?? 0)),
                        'flesch' => is_numeric($compRead['flesch'] ?? null) ? (float) $compRead['flesch'] : null,
                        'grade' => $compRead['grade'] ?? null,
                        'stack_label' => $compStack['label'] ?? 'Unknown',
                        'stack_type' => $compStack['type'] ?? 'unknown',
                    ];
                } catch (\Throwable) {
                    continue;
                }
            }

            $gapTable = $this->buildBenchmarkGapTable($yourWordCount, $yourFlesch, $yourImageCount, $competitors, $yourStack);

            return [
                'keyword' => $keyword,
                'keyword_source' => $keywordSource,
                'source' => 'serper',
                'your_flesch' => $yourFlesch,
                'your_word_count' => $yourWordCount,
                'your_image_count' => $yourImageCount,
                'competitors' => $competitors,
                'skipped_reason' => $competitors === [] ? 'no_competitor_pages_fetched' : null,
                'your_serp' => $yourSerp,
                'gap_table' => $gapTable,
                'serp_locale' => $serpLocaleOut,
            ];
        } catch (\Throwable $e) {
            Log::warning("PageAuditService: Serper benchmark failed for {$pageUrl}: {$e->getMessage()}");

            return [
                'keyword' => $keywordContext,
                'keyword_source' => null,
                'source' => 'serper',
                'your_flesch' => $yourFlesch,
                'competitors' => [],
                'skipped_reason' => 'benchmark_error',
                'serp_locale' => $this->compactSerpLocale($effectiveGl, $effectiveHl),
            ];
        }
    }

    /**
     * @return array{gl?: string, hl?: string}
     */
    private function compactSerpLocale(?string $gl, ?string $hl): array
    {
        $out = [];
        $g = is_string($gl) ? strtolower(trim($gl)) : '';
        if ($g !== '' && strlen($g) === 2 && ctype_alpha($g)) {
            $out['gl'] = $g;
        }
        $h = is_string($hl) ? strtolower(trim($hl)) : '';
        if ($h !== '' && preg_match('/^[a-z]{2}(-[a-z0-9]{2,8})?$/', $h) === 1) {
            $out['hl'] = $h;
        }

        return $out;
    }

    /**
     * Compare audited page to averages from fetched competitor HTML (word count, Flesch, image count).
     *
     * @param  list<array<string, mixed>>  $competitors
     * @return array{rows: list<array<string, mixed>>}|null
     */
    private function buildBenchmarkGapTable(int $yourWords, ?float $yourFlesch, int $yourImages, array $competitors, ?array $yourStack = null): ?array
    {
        if ($competitors === []) {
            return null;
        }

        $rows = [];

        $wcVals = [];
        foreach ($competitors as $c) {
            if (is_array($c) && isset($c['word_count']) && is_numeric($c['word_count'])) {
                $wcVals[] = (float) $c['word_count'];
            }
        }
        if ($wcVals !== []) {
            $avg = array_sum($wcVals) / count($wcVals);
            $delta = $yourWords - $avg;
            $rows[] = [
                'key' => 'word_count',
                'metric' => 'Word count',
                'yours' => $yourWords,
                'market_avg' => round($avg, 1),
                'delta' => round($delta, 1),
                'status' => $delta < -1 ? 'Add content' : ($delta > 1 ? 'Above sample' : 'Aligned'),
            ];
        }

        if (is_numeric($yourFlesch)) {
            $fRawCount = 0;
            $fVals = [];
            foreach ($competitors as $c) {
                if (is_array($c) && isset($c['flesch']) && is_numeric($c['flesch'])) {
                    $fRawCount++;
                    $v = (float) $c['flesch'];
                    // Flesch is only meaningful for real prose. <10 or >95 almost always
                    // comes from non-article competitor pages (product shells, SPA chrome,
                    // heavy-JS categories) and would skew the market average wildly.
                    if ($v >= 10.0 && $v <= 95.0) {
                        $fVals[] = $v;
                    }
                }
            }

            if ($fVals !== []) {
                $avg = array_sum($fVals) / count($fVals);
                $delta = $yourFlesch - $avg;
                $row = [
                    'key' => 'flesch',
                    'metric' => 'Readability (Flesch)',
                    'yours' => round($yourFlesch, 1),
                    'market_avg' => round($avg, 1),
                    'delta' => round($delta, 1),
                    'status' => $this->fleschStatus($yourFlesch, $delta),
                ];
                if ($fRawCount > count($fVals)) {
                    $row['sample_note'] = 'flesch_out_of_range';
                }
                $rows[] = $row;
            } elseif ($fRawCount > 0) {
                $rows[] = [
                    'key' => 'flesch',
                    'metric' => 'Readability (Flesch)',
                    'yours' => round($yourFlesch, 1),
                    'market_avg' => null,
                    'delta' => null,
                    'status' => 'No comparable sample',
                    'sample_note' => 'flesch_out_of_range',
                ];
            }
        }

        $imgVals = [];
        foreach ($competitors as $c) {
            if (is_array($c) && array_key_exists('image_count', $c) && is_numeric($c['image_count'])) {
                $imgVals[] = (float) $c['image_count'];
            }
        }
        if ($imgVals !== []) {
            $avg = array_sum($imgVals) / count($imgVals);
            $delta = $yourImages - $avg;
            $rows[] = [
                'key' => 'images',
                'metric' => 'Images',
                'yours' => $yourImages,
                'market_avg' => round($avg, 1),
                'delta' => round($delta, 1),
                'status' => $delta < -0.5 ? 'Add visuals' : ($delta > 0.5 ? 'Above sample' : 'Aligned'),
            ];
        }

        $stackRow = $this->buildStackGapRow($yourStack, $competitors);
        if ($stackRow !== null) {
            $rows[] = $stackRow;
        }

        return $rows === [] ? null : ['rows' => $rows];
    }

    /**
     * @param  array{label: string, type: string}|null  $yourStack
     * @param  list<array<string, mixed>>  $competitors
     * @return array<string, mixed>|null
     */
    /**
     * Zone-aware label for the Flesch gap-table row. A page already in the "accessible"
     * band (≥60) should never be flagged "tighten copy" just because a competitor
     * sample happens to skew lower; conversely, a page in the "hard-to-read" band (<30)
     * should be flagged regardless of the delta.
     */
    private function fleschStatus(float $yours, ?float $delta): string
    {
        if ($yours >= 60.0) {
            if ($delta !== null && $delta > 5.0) {
                return 'Accessible · above sample';
            }

            return 'Accessible';
        }
        if ($yours < 30.0) {
            return 'Tighten copy';
        }
        if ($delta !== null && $delta > 5.0) {
            return 'Better UX';
        }
        if ($delta !== null && $delta < -5.0) {
            return 'Tighten copy';
        }

        return 'Similar';
    }

    private function buildStackGapRow(?array $yourStack, array $competitors): ?array
    {
        $yourType = is_array($yourStack) ? (string) ($yourStack['type'] ?? 'unknown') : 'unknown';
        $yourLabel = is_array($yourStack) ? (string) ($yourStack['label'] ?? 'Unknown') : 'Unknown';
        if ($yourType === 'unknown' || $yourLabel === 'Unknown') {
            return null;
        }

        $compTypes = [];
        $compLabels = [];
        foreach ($competitors as $c) {
            $ct = (string) ($c['stack_type'] ?? 'unknown');
            $cl = (string) ($c['stack_label'] ?? 'Unknown');
            if ($ct === 'unknown' || $cl === 'Unknown') {
                continue;
            }
            $compTypes[] = $ct;
            $compLabels[] = $cl;
        }
        if (count($compTypes) < 2) {
            return null;
        }

        $labelCounts = array_count_values($compLabels);
        arsort($labelCounts);
        $topLabel = array_key_first($labelCounts);
        $topCount = $labelCounts[$topLabel];
        $summary = $topCount > 1 ? "{$topLabel} ×{$topCount}" : $topLabel;

        $typeCounts = array_count_values($compTypes);
        arsort($typeCounts);
        $dominantType = array_key_first($typeCounts);

        $deltaKind = 'parity';
        if ($yourType === 'modern' && $dominantType === 'cms') {
            $deltaKind = 'moat';
        } elseif ($yourType === 'cms' && $dominantType === 'modern') {
            $deltaKind = 'disadvantage';
        }

        return [
            'key' => 'stack',
            'metric' => 'Tech stack',
            'yours' => $yourLabel,
            'market_avg' => $summary,
            'delta' => null,
            'delta_kind' => $deltaKind,
            'status' => match ($deltaKind) {
                'moat' => 'Competitive moat',
                'disadvantage' => 'Stack gap',
                default => 'Parity',
            },
        ];
    }

    /**
     * Adds title / meta description / URL line from the audited page HTML so the report can compare with the Serper organic row.
     *
     * @param  array<string, mixed>  $yourSerp
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function mergeAuditedPageSerpPreviewIntoYourSerp(array $yourSerp, string $pageUrl, array $metadata): array
    {
        $title = isset($metadata['title']) && is_string($metadata['title']) ? trim($metadata['title']) : '';
        $desc = isset($metadata['meta_description']) && is_string($metadata['meta_description']) ? trim($metadata['meta_description']) : '';

        $yourSerp['audited_page_url'] = $pageUrl;
        $yourSerp['audited_page_title'] = $title !== '' ? mb_substr($title, 0, 200) : null;
        $yourSerp['audited_page_snippet'] = $desc !== '' ? mb_substr($desc, 0, 500) : null;
        $yourSerp['audited_page_display'] = $this->breadcrumbStyleUrlDisplay($pageUrl);

        return $yourSerp;
    }

    /**
     * @return array{found: bool, position: int|null, on_first_page: bool|null, organic_sample_size: int, matched_listing_url: string|null, matched_listing_title: string|null, matched_listing_snippet: string|null, matched_listing_display: string|null}
     */
    private function emptyYourSerpSnapshot(): array
    {
        return [
            'found' => false,
            'position' => null,
            'on_first_page' => null,
            'organic_sample_size' => 0,
            'matched_listing_url' => null,
            'matched_listing_title' => null,
            'matched_listing_snippet' => null,
            'matched_listing_display' => null,
        ];
    }

    /**
     * Match audited site against Serper organic rows by **host** (www-normalized), not full URL path,
     * so e.g. an audited deep URL still matches when the sample lists the homepage or another path on the same domain.
     * Picks the best (lowest) reported position among matching rows and returns that row’s listing URL/title.
     *
     * @param  list<array<string, mixed>>  $organic
     * @return array{found: bool, position: int|null, on_first_page: bool|null, organic_sample_size: int, matched_listing_url: string|null, matched_listing_title: string|null, matched_listing_snippet: string|null, matched_listing_display: string|null}
     */
    private function resolveYourSerpPosition(string $pageUrl, array $organic): array
    {
        $totalValid = 0;
        foreach ($organic as $row) {
            if ($this->organicRowHttpUrl($row) !== null) {
                $totalValid++;
            }
        }

        $bestPos = null;
        $bestLink = null;
        $bestTitle = null;
        $bestSnippet = null;
        $bestDisplay = null;
        $ordinal = 0;
        foreach ($organic as $row) {
            $link = $this->organicRowHttpUrl($row);
            if ($link === null) {
                continue;
            }
            $ordinal++;
            if (! $this->organicHostMatchesAuditedSite($pageUrl, $link)) {
                continue;
            }
            if (! is_array($row)) {
                continue;
            }
            $pos = is_numeric($row['position'] ?? null) ? (int) $row['position'] : $ordinal;
            if ($bestPos === null || $pos < $bestPos) {
                $bestPos = $pos;
                $bestLink = $link;
                $t = $row['title'] ?? '';
                $bestTitle = is_string($t) && trim($t) !== '' ? trim($t) : null;
                $bestSnippet = $this->organicRowSnippet($row);
                $bestDisplay = $this->organicSerpDisplayLine($row, $link);
            }
        }

        if ($bestPos !== null && $bestLink !== null) {
            return [
                'found' => true,
                'position' => $bestPos,
                'on_first_page' => $bestPos <= 10,
                'organic_sample_size' => $totalValid,
                'matched_listing_url' => $bestLink,
                'matched_listing_title' => $bestTitle,
                'matched_listing_snippet' => $bestSnippet,
                'matched_listing_display' => $bestDisplay,
            ];
        }

        return [
            'found' => false,
            'position' => null,
            'on_first_page' => null,
            'organic_sample_size' => $totalValid,
            'matched_listing_url' => null,
            'matched_listing_title' => null,
            'matched_listing_snippet' => null,
            'matched_listing_display' => null,
        ];
    }

    /**
     * True when the organic result is on the same host or a parent/child subdomain of the audited URL’s host
     * (e.g. audited https://blog.example.com/a and organic https://example.com/ → match).
     */
    private function organicHostMatchesAuditedSite(string $auditedPageUrl, string $organicUrl): bool
    {
        $auditedHost = $this->normalizeHostForBenchmark($auditedPageUrl);
        $organicHost = $this->normalizeHostForBenchmark($organicUrl);
        if ($auditedHost === null || $organicHost === null) {
            return false;
        }
        if ($auditedHost === $organicHost) {
            return true;
        }

        return str_ends_with($auditedHost, '.'.$organicHost)
            || str_ends_with($organicHost, '.'.$auditedHost);
    }

    /**
     * @param  array<string, mixed>|null  $row
     */
    private function organicRowHttpUrl(?array $row): ?string
    {
        if (! is_array($row)) {
            return null;
        }
        $link = $row['link'] ?? '';
        if (! is_string($link) || $link === '') {
            return null;
        }
        $linkLower = strtolower($link);
        if (! str_starts_with($linkLower, 'http://') && ! str_starts_with($linkLower, 'https://')) {
            return null;
        }

        return $link;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function organicRowSnippet(array $row): ?string
    {
        foreach (['snippet', 'description'] as $key) {
            $v = $row[$key] ?? null;
            if (is_string($v)) {
                $s = trim($v);
                if ($s !== '') {
                    return mb_substr($s, 0, 320);
                }
            }
        }

        return null;
    }

    /**
     * Green-line style URL as shown in Google (Serper: displayLink, etc.; else breadcrumb from link).
     *
     * @param  array<string, mixed>  $row
     */
    private function organicSerpDisplayLine(array $row, string $link): string
    {
        foreach (['displayLink', 'displayedLink', 'displayUrl'] as $key) {
            $v = $row[$key] ?? null;
            if (is_string($v)) {
                $s = trim($v);
                if ($s !== '') {
                    return mb_substr($s, 0, 200);
                }
            }
        }

        return $this->breadcrumbStyleUrlDisplay($link);
    }

    private function breadcrumbStyleUrlDisplay(string $url): string
    {
        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');
        $path = (string) ($parts['path'] ?? '');
        $trimmedPath = trim($path, '/');

        return $trimmedPath !== ''
            ? $host.' › '.str_replace('/', ' › ', $trimmedPath)
            : $host;
    }

    private function normalizeHostForBenchmark(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }
        $host = strtolower($host);

        return preg_replace('/^www\./', '', $host) ?: null;
    }

    private function fetchTargetKeywords(?Website $website, string $pageUrl): array
    {
        // Guest / no-website audits have no GSC data — keyword analysis runs
        // off the manual keyword override instead.
        if (! $website instanceof Website) {
            return [];
        }

        $from = $website->gscKeywordWindowStartDate();

        return SearchConsoleData::query()
            ->select('query', DB::raw('SUM(clicks) as clicks'), DB::raw('SUM(impressions) as impressions'), DB::raw('AVG(position) as position'))
            ->where('website_id', $website->id)
            ->where('page', $pageUrl)
            ->whereDate('date', '>=', $from)
            ->where('query', '!=', '')
            ->groupBy('query')
            ->orderByDesc('impressions')
            ->limit(50)
            ->get()
            ->map(fn ($row) => [
                'query' => (string) $row->query,
                'clicks' => (int) $row->clicks,
                'impressions' => (int) $row->impressions,
                'position' => round((float) $row->position, 1),
            ])
            ->all();
    }

    private function fetch(string $url): array
    {
        $startedAt = microtime(true);

        $guardCheck = $this->guard->check($url);
        if (! $guardCheck['ok']) {
            return [
                'error' => 'blocked: '.($guardCheck['reason'] ?? 'unsafe_url'),
                'http_status' => null,
                'ttfb_ms' => 0,
            ];
        }

        try {
            $response = Http::timeout(20)
                ->connectTimeout(10)
                ->withUserAgent('Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Googlebot/2.1; +http://www.google.com/bot.html) Chrome/124.0.6367.207 Safari/537.36')
                ->withHeaders(['Accept' => 'text/html,application/xhtml+xml'])
                ->withOptions([
                    // Manual redirect handling: follow up to 5 hops, but re-guard each.
                    'allow_redirects' => [
                        'max' => 5,
                        'strict' => true,
                        'referer' => false,
                        'protocols' => ['http', 'https'],
                        'track_redirects' => true,
                        'on_redirect' => function ($request, $response, $uri) {
                            $check = $this->guard->check((string) $uri);
                            if (! $check['ok']) {
                                throw new \RuntimeException('blocked redirect: '.($check['reason'] ?? 'unsafe_url'));
                            }
                        },
                    ],
                ])
                ->get($url);
            $ttfb = (int) round((microtime(true) - $startedAt) * 1000);
            $fullBody = (string) $response->body();
            $fullSize = strlen($fullBody);
            $body = $fullSize > self::MAX_BODY_BYTES ? substr($fullBody, 0, self::MAX_BODY_BYTES) : $fullBody;
            if ($fullSize > self::MAX_BODY_BYTES) {
                Log::info("PageAuditService: truncated oversized response body for {$url} ({$fullSize} -> ".self::MAX_BODY_BYTES.' bytes)');
            }
            $encoding = strtolower((string) $response->header('Content-Encoding'));

            $stackHeaders = [];
            foreach (['server', 'x-powered-by', 'x-generator', 'via', 'cf-cache-status'] as $hName) {
                $val = (string) $response->header($hName);
                if ($val !== '') {
                    $stackHeaders[$hName] = $val;
                }
            }

            return [
                'body' => $body,
                'http_status' => $response->status(),
                'ttfb_ms' => $ttfb,
                'size' => $fullSize,
                'compression' => $encoding !== '' ? $encoding : null,
                'headers' => $stackHeaders,
            ];
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
                'http_status' => null,
                'ttfb_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        }
    }

    private function checkLinks(array $links): array
    {
        if (empty($links)) {
            return ['broken' => [], 'checked' => 0, 'skipped' => 0];
        }

        $unique = [];
        foreach ($links as $l) {
            if (count($unique) >= self::MAX_LINKS_CHECKED) {
                break;
            }
            $unique[$l['href']] = $l;
        }
        $allCandidates = array_values($unique);
        $skipped = max(0, count($links) - count($allCandidates));

        $broken = [];
        $toCheck = [];
        foreach ($allCandidates as $link) {
            $check = $this->guard->check((string) ($link['href'] ?? ''));
            if (! $check['ok']) {
                $broken[] = [
                    'href' => $link['href'] ?? '',
                    'anchor' => $link['anchor'] ?? '',
                    'status' => 'blocked',
                    'error' => $check['reason'] ?? 'unsafe_url',
                ];

                continue;
            }
            $toCheck[] = $link;
        }

        foreach (array_chunk($toCheck, self::LINK_POOL_CONCURRENCY) as $batch) {
            $responses = Http::pool(function (Pool $pool) use ($batch) {
                $calls = [];
                foreach ($batch as $i => $link) {
                    $calls[] = $pool->as((string) $i)
                        ->timeout(self::LINK_TIMEOUT)
                        ->connectTimeout(self::LINK_TIMEOUT)
                        ->withUserAgent(self::LINK_CHECK_UA)
                        ->withOptions([
                            'allow_redirects' => [
                                'max' => 3,
                                'strict' => true,
                                'referer' => false,
                                'protocols' => ['http', 'https'],
                                'on_redirect' => function ($request, $response, $uri) {
                                    $check = $this->guard->check((string) $uri);
                                    if (! $check['ok']) {
                                        throw new \RuntimeException('blocked redirect: '.($check['reason'] ?? 'unsafe_url'));
                                    }
                                },
                            ],
                        ])
                        ->head($link['href']);
                }

                return $calls;
            });

            foreach ($batch as $i => $link) {
                $resp = $responses[(string) $i] ?? null;
                $status = null;
                $error = null;

                if ($resp instanceof Response) {
                    $status = $resp->status();
                    if (in_array($status, self::LINK_FALLBACK_STATUSES, true)) {
                        $status = $this->getFallback($link['href']) ?? $status;
                    }
                } else {
                    $error = $resp instanceof \Throwable ? $resp->getMessage() : 'unknown';
                }

                $isUntrustedAntibotBlock = $status !== null
                    && in_array($status, self::ANTIBOT_BLOCK_STATUSES, true)
                    && $this->isKnownAntibotHost($link['href']);
                if ($isUntrustedAntibotBlock) {
                    Log::info('page_audit.broken_link.antibot_skip', ['href' => $link['href'], 'status' => $status]);
                }
                if (($status === null || $status >= 400) && ! $isUntrustedAntibotBlock) {
                    $broken[] = [
                        'href' => $link['href'],
                        'anchor' => $link['anchor'] ?? '',
                        'status' => $status,
                        'error' => $error,
                    ];
                }
            }
        }

        return ['broken' => $broken, 'checked' => count($toCheck), 'skipped' => $skipped];
    }

    private function isKnownAntibotHost(string $href): bool
    {
        $host = strtolower((string) parse_url($href, PHP_URL_HOST));
        foreach (self::KNOWN_ANTIBOT_HOSTS as $d) {
            if ($host === $d || str_ends_with($host, '.'.$d)) {
                return true;
            }
        }

        return false;
    }

    private function getFallback(string $url): ?int
    {
        $check = $this->guard->check($url);
        if (! $check['ok']) {
            return null;
        }

        $status = $this->fetchGetStatus($url, null);
        if ($status !== null && $status < 400) {
            return $status;
        }

        // Direct GET still looks dead — could be a real 404, or the host
        // blocking our IP/UA (anti-bot, rate-limit). Retry once via the
        // crawler's proxy pool before trusting the direct result.
        if ($this->proxies->enabled()) {
            $proxy = $this->proxies->pick();
            if ($proxy !== null) {
                $proxied = $this->fetchGetStatus($url, $proxy);
                if ($proxied !== null && $proxied < 400) {
                    $this->proxies->markSuccess($proxy);

                    return $proxied;
                }
                $this->proxies->markFailure($proxy);
            }
        }

        return $status;
    }

    private function fetchGetStatus(string $url, ?string $proxy): ?int
    {
        try {
            $resp = Http::timeout(self::LINK_TIMEOUT)
                ->connectTimeout(self::LINK_TIMEOUT)
                ->withUserAgent(self::LINK_CHECK_UA)
                ->withOptions(array_filter([
                    'proxy' => $proxy,
                    'allow_redirects' => [
                        'max' => 3,
                        'strict' => true,
                        'referer' => false,
                        'protocols' => ['http', 'https'],
                        'on_redirect' => function ($request, $response, $uri) {
                            $check = $this->guard->check((string) $uri);
                            if (! $check['ok']) {
                                throw new \RuntimeException('blocked redirect: '.($check['reason'] ?? 'unsafe_url'));
                            }
                        },
                    ],
                ], static fn ($v) => $v !== null))
                ->get($url);

            return $resp->status();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function persistFailure(
        string $websiteId,
        string $pageUrl,
        string $error,
        ?int $httpStatus,
        ?int $ttfb,
        ?string $primaryKeyword = null,
        ?string $primaryKeywordSource = null,
    ): PageAuditReport {
        $pk = $primaryKeyword !== null && trim($primaryKeyword) !== '' ? mb_substr(trim($primaryKeyword), 0, 200) : null;
        $pks = $primaryKeywordSource !== null && trim($primaryKeywordSource) !== '' ? mb_substr(trim($primaryKeywordSource), 0, 32) : null;
        if ($pk === null) {
            $pks = null;
        }

        return PageAuditReport::updateOrCreate(
            ['website_id' => $websiteId, 'page_hash' => hash('sha256', $pageUrl)],
            [
                'page' => mb_substr($pageUrl, 0, 700),
                'status' => 'failed',
                'audited_at' => now(),
                'http_status' => $httpStatus,
                'response_time_ms' => $ttfb,
                'page_size_bytes' => null,
                'error_message' => mb_substr($error, 0, 1000),
                'primary_keyword' => $pk,
                'primary_keyword_source' => $pks,
                'result' => null,
            ]
        );
    }
}
