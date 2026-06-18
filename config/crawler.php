<?php

return [
    // Pages per CrawlPageBatchJob chunk.
    'batch_size' => (int) env('CRAWLER_BATCH_SIZE', 25),

    // Significant-term extraction (language-agnostic; feeds the internal-link
    // suggester instead of raw body_text). prune_body_text trims body_text to an
    // excerpt AFTER analysis to reclaim storage — OFF by default because it's
    // irreversible (no DB backups); enable once terms are validated in production.
    'terms_df_sample' => (int) env('CRAWLER_TERMS_DF_SAMPLE', 3000),
    'prune_body_text' => (bool) env('CRAWLER_PRUNE_BODY_TEXT', false),
    'body_excerpt_chars' => (int) env('CRAWLER_BODY_EXCERPT_CHARS', 500),

    // Multi-pass crawling. A run crawls due pages, then re-selects pages newly
    // discovered mid-run (e.g. category pages linked from the homepage that began
    // as stubs) and crawls those too, repeating until no new pages are found or
    // these bounds are hit. Without this the homepage's nav targets stay uncrawled
    // and the internal-link graph is disconnected (orphan/click-depth false flags).
    'max_passes' => (int) env('CRAWLER_MAX_PASSES', 6), // deprecated: superseded by pages_per_pass (CrawlPassJob now derives its own runaway ceiling)
    'max_pages_per_run' => (int) env('CRAWLER_MAX_PAGES_PER_RUN', 200000),

    // Fairness: max pages a single pass enqueues before yielding the crawl queue to
    // other sites. Small enough that one large site can't monopolise the 5 shared
    // crawl workers; large enough to keep per-pass overhead low. The multi-pass loop
    // keeps going (next pass to the back of the queue) until the site is exhausted or
    // the cap is hit, so this does NOT cap total pages — only the burst per pass.
    'pages_per_pass' => (int) env('CRAWLER_PAGES_PER_PASS', 1000),

    // Watchdog (ebq:crawl-supervisor): a `running` run whose row hasn't been touched
    // in this many minutes is treated as wedged (dead multi-pass chain) and resumed
    // or finalized. Generous so a genuinely-slow batch isn't mistaken for a stall.
    'stall_minutes' => (int) env('CRAWLER_STALL_MINUTES', 10),
    // Hard cap on a single run's wall-clock before the supervisor stops resurrecting
    // it and finalizes with whatever has been crawled.
    'max_run_hours' => (int) env('CRAWLER_MAX_RUN_HOURS', 6),

    // Politeness delay between same-host fetches inside a batch (milliseconds).
    'delay_ms' => (int) env('CRAWLER_DELAY_MS', 250),

    // Fleet-wide per-domain rate limiter (DomainRateLimiter): max ms a worker will
    // wait for a domain token before proceeding fail-open. The rate itself
    // (req/sec/domain) is the admin-editable autoscaler.per_domain_rate setting.
    'rate_max_wait_ms' => (int) env('CRAWLER_RATE_MAX_WAIT_MS', 5000),

    // Per-page fetch timeout (seconds).
    'timeout' => (int) env('CRAWLER_TIMEOUT', 20),

    // Per-job PHP memory ceiling (ini_set), applied at the top of the heavy crawl
    // jobs. Horizon workers inherit PHP's CLI default (128M); the pre-Horizon raw
    // workers ran `php -d memory_limit=2048M`, which the Horizon migration dropped —
    // so HtmlAuditor (large pages) and the link-graph analysis OOM'd at 128M. Setting
    // it in code makes the ceiling travel with the deploy to EVERY box (the pinned
    // box AND every autoscaled ephemeral crawl box, whose crawl workers also parse
    // large HTML) without depending on the worker snapshot / container php.ini.
    // Ceilings, not reservations — keep within the worker box RAM (~3.7GB).
    'batch_memory_limit' => env('CRAWLER_BATCH_MEMORY_LIMIT', '512M'),     // CrawlPageBatchJob (crawl pool, incl. ephemeral boxes)
    'analyze_memory_limit' => env('CRAWLER_ANALYZE_MEMORY_LIMIT', '1024M'), // AnalyzeSiteJob (finalize, pinned box only)

    // Proxy policy (PageCrawlProcessor). When true (default) the crawler uses a pool
    // proxy FIRST whenever one is available — so the worker box's own public IP is never
    // exposed (a blocked server IP makes the box useless; pool IPs are disposable). It
    // only fetches DIRECT from the box IP when no proxy is available. Set false to force
    // direct-only (e.g. if the proxy pool is unhealthy).
    'use_proxies' => (bool) env('CRAWLER_USE_PROXIES', true),

    // On a block, the domain's adaptive rate resets to the floor and won't ramp again for
    // this cooldown (DomainRateLimiter::recordBlock).
    'block_cooldown_s' => (int) env('CRAWLER_BLOCK_COOLDOWN_S', 600),

    // Smart per-domain adaptive crawl rate (AIMD — ebq:ramp-crawl-rates every minute). The
    // rate starts at the admin autoscaler.per_domain_rate (floor) and ramps by rate_step each
    // clean minute up to rate_max. If a domain's recent fetch latency rises above
    // baseline × rate_degrade_factor it backs off (halves) BEFORE getting blocked.
    // rate_min_samples = fetches needed before the latency signal is trusted.
    'rate_step' => (int) env('CRAWLER_RATE_STEP', 10),
    'rate_max' => (int) env('CRAWLER_RATE_MAX', 100),
    'rate_degrade_factor' => (float) env('CRAWLER_RATE_DEGRADE_FACTOR', 1.5),
    'rate_min_samples' => (int) env('CRAWLER_RATE_MIN_SAMPLES', 8),

    // Incremental crawling — adaptive recrawl interval (days). A page's interval
    // backs off geometrically from min→max while it keeps coming back unchanged,
    // and snaps back to min the moment it significantly changes.
    'recrawl_min_days' => (int) env('CRAWLER_RECRAWL_MIN_DAYS', 3),
    'recrawl_base_days' => (int) env('CRAWLER_RECRAWL_BASE_DAYS', 7),
    'recrawl_max_days' => (int) env('CRAWLER_RECRAWL_MAX_DAYS', 30),

    // SimHash Hamming-distance above which a content change counts as "significant"
    // (lower = stricter; higher tolerates more ad/timestamp noise). 0–64.
    'simhash_threshold' => (int) env('CRAWLER_SIMHASH_THRESHOLD', 3),

    // Sitemap <lastmod> trust: only treat a lastmod bump as a recrawl trigger once
    // we have at least N observations for the site AND the share of bumps that led
    // to a real change is at least the ratio (else the sitemap is "always-bumping").
    'sitemap_trust_min_sample' => (int) env('CRAWLER_SITEMAP_TRUST_MIN_SAMPLE', 20),
    'sitemap_trust_ratio' => (float) env('CRAWLER_SITEMAP_TRUST_RATIO', 0.3),

    // Cap external links checked per site per run (broken-external pass).
    'max_external_checks' => (int) env('CRAWLER_MAX_EXTERNAL_CHECKS', 500),

    // Click-depth at/above which an indexable page is flagged "deep".
    'deep_page_threshold' => (int) env('CRAWLER_DEEP_PAGE_THRESHOLD', 3),

    // Min GSC clicks (28d) for a noindex/orphan page to count as "important".
    'important_clicks' => (int) env('CRAWLER_IMPORTANT_CLICKS', 1),

    // Parameter canonicalization. When true, query-string params are stripped
    // from discovered URLs (frontier + internal-link targets) so noisy variants
    // like ?name=…/?nick=…/?utm_… collapse into one clean inventory row instead
    // of thousands. Params in keep_query_params are preserved (e.g. pagination).
    'strip_query_params' => (bool) env('CRAWLER_STRIP_QUERY_PARAMS', true),
    'keep_query_params' => ['page', 'p', 'paged'],

    // Public egress IP + User-Agent of the crawler, shown to clients as the
    // address to allowlist when their firewall/WAF blocks us. Crawls run on the
    // dedicated worker box, so this is that box's public IP.
    'egress_ip' => env('CRAWLER_EGRESS_IP'),

    // Adaptive anti-blocking via a proxy pool. Proxies come from the `proxies`
    // table (admin panel) + proxylist.txt in the project root, merged at runtime.
    'proxy' => [
        'enabled' => (bool) env('CRAWLER_PROXY_ENABLED', false),

        // When a proxy is used:
        //   off       — never (pool disabled)
        //   on_block  — only after a direct fetch is blocked, then retry via proxy
        //               (and preemptively for sites already flagged cloudflare/blocked)
        //   always    — route every fetch through a proxy
        'mode' => env('CRAWLER_PROXY_MODE', 'on_block'),

        // Path to the flat-file proxy list (one proxy per line; see the file).
        'list_file' => env('CRAWLER_PROXY_FILE', base_path('proxylist.txt')),

        // Disable a proxy after this many consecutive failures (0 = never).
        'max_failures' => (int) env('CRAWLER_PROXY_MAX_FAILURES', 5),
    ],
];
