<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    /*
     * Stripe — billing infrastructure for Cashier.
     *
     * Cashier itself reads these via `cashier.php` config (published from
     * `php artisan vendor:publish --tag="cashier-config"`). We mirror them
     * here for direct access from controllers and to keep the dotenv
     * surface consolidated. Webhook signing secret is mandatory for
     * production webhook verification.
     */
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook' => [
            'secret' => env('STRIPE_WEBHOOK_SECRET'),
            'tolerance' => env('STRIPE_WEBHOOK_TOLERANCE', 300),
        ],
        // Trial-winback promotion code (duration=once) offered in the h24
        // trial-expiry email, on the billing page for expired users, and
        // auto-applied at their checkout. Must exist and be active in Stripe
        // (coupon TRIAL-WINBACK-30 / promo SAVE30, created 2026-07-07).
        // Empty code disables the offer everywhere. The percent is display
        // copy only — the real discount lives on the Stripe coupon; keep in sync.
        'winback_promo_code' => env('STRIPE_WINBACK_PROMO_CODE', 'SAVE30'),
        'winback_promo_percent' => (int) env('STRIPE_WINBACK_PROMO_PERCENT', 30),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    // WordPress plugin distribution kill switch (2026-07-10): the shipped
    // plugin is outdated, so every "get it" surface (marketing page CTAs,
    // nav badge, settings download/connect panel, /wordpress/plugin.zip,
    // pricing/landing table rows) shows "Coming soon" while this is true.
    // EXISTING installs keep working — the website API, embeds, version
    // endpoint and connect approval flow stay live. Flip to re-enable:
    // WP_PLUGIN_COMING_SOON=false in .env (both boxes) + FPM restart.
    'wordpress_plugin' => [
        'coming_soon' => (bool) env('WP_PLUGIN_COMING_SOON', true),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Google reCAPTCHA v2 (checkbox) — email/password registration and login.
    | Create keys at https://www.google.com/recaptcha/admin — choose v2 "I'm not a robot".
    | Leave both empty to disable (local dev / tests).
    */
    'recaptcha' => [
        'site_key' => env('RECAPTCHA_SITE_KEY', ''),
        'secret_key' => env('RECAPTCHA_SECRET_KEY', ''),
    ],

    /*
     * Microsoft / Outlook OAuth — powers the "send report from Outlook"
     * transport. Requires socialiteproviders/microsoft to be registered
     * via the event subscriber pattern. Tenant: "common" so both work +
     * personal accounts can connect; switch to a specific tenant ID for
     * single-tenant enterprise installs.
     */
    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_REDIRECT_URI'),
        'tenant' => env('MICROSOFT_TENANT', 'common'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        // Cross-Account Protection (CAP / RISC)
        'cap_audience' => env('GOOGLE_CAP_AUDIENCE'),
        'cap_jwks_url' => env('GOOGLE_CAP_JWKS_URL', 'https://www.googleapis.com/oauth2/v3/certs'),
        'cap_issuers' => array_filter(array_map('trim', explode(',', (string) env('GOOGLE_CAP_ISSUERS', 'https://accounts.google.com,accounts.google.com')))),
    ],

    'serper' => [
        'key' => env('SERPER_API_KEY'),
        'search_url' => env('SERPER_SEARCH_URL', 'https://google.serper.dev/search'),
        // Per-call cost (USD) — used by the admin "API usage" dashboard
        // to estimate billable spend per client. Adjust to your contract.
        // Default reflects Serper's published $0.30/1k credits.
        'cost_per_call_usd' => (float) env('SERPER_COST_PER_CALL_USD', 0.0003),
    ],

    'lighthouse' => [
        'url' => env('LIGHTHOUSE_API_URL'),
        'key' => env('LIGHTHOUSE_API_KEY'),
        'timeout' => (int) env('LIGHTHOUSE_TIMEOUT_S', 90),
    ],

    'keywords_everywhere' => [
        'key' => env('KEYWORDS_EVERYWHERE_API_KEY'),
        'base_url' => env('KEYWORDS_EVERYWHERE_BASE_URL', 'https://api.keywordseverywhere.com'),
        'fresh_days' => (int) env('KEYWORDS_EVERYWHERE_FRESH_DAYS', 30),
        // Kill switch for the PAID KE backlink endpoints (50 credits/domain):
        // competitor-backlink refresh + own-backlink sync. OFF by default
        // since 2026-07-14 — domain authority now comes from Open PageRank
        // (free) everywhere; the per-link backlink tables serve whatever is
        // already cached and simply stop refreshing while this is off.
        'backlinks_enabled' => (bool) env('KEYWORDS_EVERYWHERE_BACKLINKS_ENABLED', false),
        // Per-keyword cost (USD) — KE bills 1 credit per keyword. Default
        // reflects the published 100,000-credit pack at $10 = $0.0001/credit.
        'cost_per_keyword_usd' => (float) env('KEYWORDS_EVERYWHERE_COST_PER_KEYWORD_USD', 0.0001),
        // Competitor-backlinks knobs — all env-overridable so the endpoint or
        // defaults can shift without a code change.
        'backlinks_endpoint' => env('KEYWORDS_EVERYWHERE_BACKLINKS_ENDPOINT', '/v1/get_domain_backlinks'),
        'backlinks_country' => env('KEYWORDS_EVERYWHERE_BACKLINKS_COUNTRY', 'us'),
        'backlinks_currency' => env('KEYWORDS_EVERYWHERE_BACKLINKS_CURRENCY', 'USD'),
        'backlinks_data_source' => env('KEYWORDS_EVERYWHERE_BACKLINKS_DATASOURCE', 'g'),
        // Universal freshness window for ALL Keywords Everywhere backlink
        // calls — own-domain syncs, competitor lookups, page-audit triggers,
        // anywhere. If we have records for the domain newer than this, we
        // serve stored data and never re-bill KE. Default 30 days; override
        // with KE_BACKLINKS_TTL_DAYS in .env when you want to tighten or
        // loosen the window (e.g., 7 for a research project, 90 for a stable
        // archive).
        'backlinks_ttl_days' => (int) env('KE_BACKLINKS_TTL_DAYS', 30),
    ],

    // Self-hosted keyword-data API (our own fleet driving Google Keyword
    // Planner). Per-server credentials live in the DB (keyword_api_servers);
    // these are global knobs. The service is asynchronous — see
    // App\Services\KeywordFinder\KeywordFinderPool.
    'keyword_finder' => [
        // Public path the API servers POST results back to. Must match the
        // route in routes/web.php and the CSRF exemption in bootstrap/app.php.
        'webhook_path' => env('KEYWORD_FINDER_WEBHOOK_PATH', '/webhooks/keyword-finder'),
        // Header the server signs the webhook body with (HMAC-SHA256).
        'signature_header' => env('KEYWORD_FINDER_SIGNATURE_HEADER', 'x-webhook-signature'),
        // Freshness window (days) for volume rows written from this provider.
        'fresh_days' => (int) env('KEYWORD_FINDER_FRESH_DAYS', 30),
        // Short timeout for the *ack* POST — the server replies instantly and
        // does the slow work out-of-band, so we never hold a long connection.
        'request_timeout_s' => (int) env('KEYWORD_FINDER_REQUEST_TIMEOUT_S', 15),
        // How long the UI keeps polling a pending request before giving up.
        'poll_ttl_minutes' => (int) env('KEYWORD_FINDER_POLL_TTL_MINUTES', 5),
        'default_location' => env('KEYWORD_FINDER_DEFAULT_LOCATION', 'United States'),
        'default_language' => env('KEYWORD_FINDER_DEFAULT_LANGUAGE', 'English'),
    ],

    'competitor_backlinks' => [
        'limit_per_competitor' => (int) env('COMPETITOR_BACKLINKS_LIMIT', 50),
        'fresh_days' => (int) env('COMPETITOR_BACKLINKS_FRESH_DAYS', 30),
    ],

    // DataForSEO Backlinks API — the primary backlink-profile provider for the
    // customer-facing report (referring domains/IPs/subnets, anchors, dofollow
    // split, active/lost history, competitors, 0-1000 `rank` authority proxy).
    // Pay-as-you-go: $0.024/request + $0.06/1000 rows. Basic-auth (login:pass).
    // CREDENTIALS LIVE IN .env ON BOTH BOXES — never commit them.
    'dataforseo' => [
        'login' => env('DATAFORSEO_LOGIN'),
        'password' => env('DATAFORSEO_PASSWORD'),
        'base_url' => env('DATAFORSEO_BASE_URL', 'https://api.dataforseo.com/v3'),
        // Sandbox returns free mock data. Used automatically when an ADMIN is
        // logged in (so their testing never bills), and forceable via
        // DATAFORSEO_FORCE_SANDBOX for local/dev testing.
        'sandbox_base_url' => env('DATAFORSEO_SANDBOX_BASE_URL', 'https://sandbox.dataforseo.com/v3'),
        'force_sandbox' => (bool) env('DATAFORSEO_FORCE_SANDBOX', false),
        // Bounded top-N cap per row-returning endpoint (referring_domains,
        // anchors, backlinks). Holds per-report cost flat regardless of site
        // size — a 10x bigger site does NOT cost 10x bounded.
        'row_limit' => (int) env('DATAFORSEO_ROW_LIMIT', 1000),
        // 1000 = DataForSEO's hard per-request max (verified live). Raised
        // from 100 (2026-07-13): the $0.024 base request fee is paid either
        // way, and the marginal cost of the extra 900 rows is only
        // $0.000036 each (~$0.03 more per report) — far more data almost
        // free once the request is already being made. We only DISPLAY the
        // top 15/10 (see ClientReportService::topPages/competitorRows), but
        // pulling the full 1000 lets our own explicit sort find the true
        // best rows instead of trusting whatever order the API happened to
        // return within a shallow 100-row window.
        'pages_limit' => (int) env('DATAFORSEO_PAGES_LIMIT', 1000),
        'competitors_limit' => (int) env('DATAFORSEO_COMPETITORS_LIMIT', 1000),
        // Labs rows cost $0.0001 — 10× the Backlinks-API row price — so the
        // Labs organic-competitors call gets its OWN, tighter cap (the 1000-row
        // "extra rows are nearly free" rationale above does NOT hold there).
        // Report value concentrates at the top: rows are sorted by shared
        // keywords, and past ~row 300 it's 15–40 weak-position stragglers
        // nobody acts on (verified on live payloads 2026-07-17). 300 keeps
        // every actionable competitor and cuts the priciest usage line ~60%.
        // Dial back up here anytime without a code change.
        'labs_competitors_limit' => (int) env('DATAFORSEO_LABS_COMPETITORS_LIMIT', 300),
        'history_months' => (int) env('DATAFORSEO_HISTORY_MONTHS', 12),
        'timeout' => (int) env('DATAFORSEO_TIMEOUT_S', 60),
        // Global monthly spend circuit-breaker (USD). When the month's real
        // billed DataForSEO spend reaches this cap: arbitrary-domain lookups
        // degrade to the free-signal partial-report path, TTL refreshes /
        // schema self-heals serve the cached snapshot instead of regenerating,
        // and OWN attached-site first reports still generate (core promise,
        // bounded by signup rate). Strictly ADMIN-ONLY: clients see the normal
        // partial report — no budget/limit copy anywhere. Null/0 = disabled.
        // {@see \App\Services\Reports\DataForSeoSpendMeter}
        'monthly_cap_usd' => env('DATAFORSEO_MONTHLY_CAP_USD') !== null
            ? (float) env('DATAFORSEO_MONTHLY_CAP_USD')
            : null,
    ],

    // Ideogram — AI image generation for Content Autopilot articles
    // (featured + inline images). v3 generate endpoint; generated URLs EXPIRE
    // so images are downloaded immediately in the job. Costs per image:
    // TURBO $0.03 / DEFAULT $0.06 / QUALITY $0.09. Spend is circuit-broken by
    // {@see \App\Services\Content\IdeogramSpendMeter} — admin-only knowledge,
    // over-cap articles simply publish without images. Null/0 cap = disabled.
    'ideogram' => [
        'key' => env('IDEOGRAM_API_KEY'),
        'base_url' => env('IDEOGRAM_BASE_URL', 'https://api.ideogram.ai/v1'),
        'timeout' => (int) env('IDEOGRAM_TIMEOUT_S', 90),
        'monthly_cap_usd' => env('IDEOGRAM_MONTHLY_CAP_USD') !== null
            ? (float) env('IDEOGRAM_MONTHLY_CAP_USD')
            : null,
    ],

    // Content Autopilot LLM spend breaker — caps AUTOPILOT writing spend
    // (estimated from token usage × provider $/token config) separately from
    // interactive AI usage, so a runaway calendar can't drain the LLM budget.
    // {@see \App\Services\Content\ContentLlmSpendMeter}. Null/0 = disabled.
    'content_autopilot' => [
        'llm_monthly_cap_usd' => env('CONTENT_LLM_MONTHLY_CAP_USD') !== null
            ? (float) env('CONTENT_LLM_MONTHLY_CAP_USD')
            : null,
    ],

    // Open PageRank (by Keywords Everywhere) — global popularity rank + 0-10
    // score + monthly history per domain. Bulk endpoint accepts up to 100
    // domains/call; free tier 30k domains/mo. Used for the report's Popularity
    // rank and to enrich competitors + backlink sources. Bearer auth.
    'openpagerank' => [
        'key' => env('OPENPAGERANK_API_KEY'),
        'base_url' => env('OPENPAGERANK_BASE_URL', 'https://openpagerank.keywordseverywhere.com/v1'),
        'timeout' => (int) env('OPENPAGERANK_TIMEOUT_S', 30),
    ],

    // Shared per-domain report cache freshness. A domain's snapshot is served
    // free until it is older than `default_ttl_days`; a paid user owning the
    // domain shortens that to `paid_ttl_days` (monthly refresh). See
    // App\Services\ReportFreshnessGate.
    'report' => [
        'default_ttl_days' => (int) env('REPORT_DEFAULT_TTL_DAYS', 90),
        'paid_ttl_days' => (int) env('REPORT_PAID_TTL_DAYS', 30),
        // Short TTL for partial / no_data / mid-enrichment snapshots so a
        // growing site auto-upgrades to a full report quickly.
        'partial_ttl_days' => (int) env('REPORT_PARTIAL_TTL_DAYS', 10),

        // Topical Trust enrichment: after a full report lands, classify the
        // top referring domains into topics (one LLM call, homepage
        // titles/descriptions fetched free via CrawlFetcher) and patch a
        // "topical_trust" section into the payload. Purely additive — views
        // are fully guarded, so disabling just hides the section.
        'topical_trust' => [
            'enabled' => (bool) env('REPORT_TOPICAL_TRUST_ENABLED', true),
            // Fixed-taxonomy classification with homepage-snippet evidence is
            // flash-class work — the cheap tier matches the premium tier on
            // this task (~10× cheaper; A/B checked 2026-07-16). Empty = the
            // provider's default model.
            'model' => env('REPORT_TOPICAL_TRUST_MODEL', ''),
            // ALL referring domains get classified (user decision 2026-07-16),
            // processed in self-chaining batches so no single queue job runs
            // long (retry_after must stay > job timeout — see infra docs).
            'batch' => (int) env('REPORT_TOPICAL_TRUST_BATCH', 25),
            'total_cap' => (int) env('REPORT_TOPICAL_TRUST_TOTAL_CAP', 1000),
        ],

        // Empty-domain enrichment: when DataForSEO has nothing for a domain,
        // build a partial report from free/cheap signals instead of a dead
        // end (Open PageRank + Moz + self-hosted keyword fleet + LLM
        // junk-check + SERP competitor tally). The kill switch restores the
        // old terminal no_data behavior exactly.
        'enrichment' => [
            'enabled' => (bool) env('REPORT_ENRICHMENT_ENABLED', true),
            // When true, only domains attached as someone's Website enrich;
            // arbitrary Site Explorer lookups stay terminal no_data.
            'attached_only' => (bool) env('REPORT_ENRICHMENT_ATTACHED_ONLY', false),
            // Max keyword rows surfaced per section (site keywords / competitor
            // opportunities / GSC queries). The fleet returns hundreds; tables
            // scroll, so show a generous slice.
            'keyword_rows' => (int) env('REPORT_ENRICHMENT_KEYWORD_ROWS', 100),
            'max_pages' => (int) env('REPORT_ENRICHMENT_MAX_PAGES', 3),
            'serp_query_cap' => (int) env('REPORT_ENRICHMENT_SERP_CAP', 8),
            'llm_max_tokens' => (int) env('REPORT_ENRICHMENT_LLM_MAX_TOKENS', 1200),
            'ideas_timeout_minutes' => (int) env('REPORT_ENRICHMENT_IDEAS_TIMEOUT_MIN', 12),
            'poll_seconds' => (int) env('REPORT_ENRICHMENT_POLL_SECONDS', 30),
        ],
    ],

    // Moz Links API — real Domain Authority + Page Authority + Spam Score for
    // the report's headline gauges. 1 url_metrics call = 1 row = one URL's
    // DA/PA/Spam. Account is on the FREE tier (50 rows/mo) — call ONCE per
    // report on the client's own domain only; DataForSEO `rank` covers all
    // competitor / referring-domain scores. Auth = HTTP Basic of
    // access_id:secret_key. CREDENTIALS LIVE IN .env — never commit them.
    'moz' => [
        // Either supply the base64 `access_id:secret` token directly, OR the
        // two parts. The client prefers `token` when present.
        'token' => env('MOZ_API_TOKEN'),
        'access_id' => env('MOZ_ACCESS_ID'),
        'secret_key' => env('MOZ_SECRET_KEY'),
        'base_url' => env('MOZ_BASE_URL', 'https://lsapi.seomoz.com/v2'),
        'timeout' => (int) env('MOZ_TIMEOUT_S', 30),
    ],

    // Competitive Keyword Intelligence module (gap analysis, competitor
    // auto-discovery, opportunity scoring). All knobs are cost controls for
    // the SERP (Serper) + keyword-finder fan-out — keep the caps conservative.
    'competitive' => [
        // Max keywords whose SERP we scan in one competitor-discovery run.
        'discovery_max_keywords' => (int) env('COMPETITIVE_DISCOVERY_MAX_KEYWORDS', 25),
        // Don't re-run discovery (re-bill SERP) within this window.
        'discovery_refresh_days' => (int) env('COMPETITIVE_DISCOVERY_REFRESH_DAYS', 14),
        // Max live SERP fetches per gap analysis for opportunity scoring.
        'opportunity_live_max' => (int) env('COMPETITIVE_OPPORTUNITY_LIVE_MAX', 20),
        // How many competitor URLs a single gap analysis accepts.
        'gap_max_competitors' => (int) env('COMPETITIVE_GAP_MAX_COMPETITORS', 3),
        // Cap on persisted gap rows (top-by-volume) to bound table growth.
        'gap_row_cap' => (int) env('COMPETITIVE_GAP_ROW_CAP', 1000),
        // How long a gap run may sit in `collecting` before the poller fails it.
        'gap_collect_timeout_minutes' => (int) env('COMPETITIVE_GAP_COLLECT_TIMEOUT_MINUTES', 5),
        // Max keywords verified against the live SERP per gap analysis.
        'gap_verify_max' => (int) env('COMPETITIVE_GAP_VERIFY_MAX', 25),
        // Per-pass cap on FREE cached-SERP verifications (no Serper spend —
        // bounded by job runtime, not billing).
        'gap_verify_cached_max' => (int) env('COMPETITIVE_GAP_VERIFY_CACHED_MAX', 150),
        // Also verify Shared/Weak rows (not just Missing) when true.
        'gap_verify_include_shared' => (bool) env('COMPETITIVE_GAP_VERIFY_INCLUDE_SHARED', false),
        // TTL (days) for the shared, cross-client SERP cache (serp_results).
        // Rankings shift faster than search volume, so shorter than the 30-day
        // keyword cache. One client's lookup is free for every other until this lapses.
        'serp_cache_days' => (int) env('COMPETITIVE_SERP_CACHE_DAYS', 7),
    ],

    'language_detection' => [
        'enabled' => filter_var(env('LANGUAGE_DETECTION_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
     * Public EBQ app URL — used for signed WP plugin deep-links (Reports, etc.).
     * Must match the host that serves /wordpress/embed/* and share APP_KEY with
     * the API the plugin calls.
     */
    'ebq' => [
        'public_url' => rtrim((string) env('EBQ_PUBLIC_URL', env('APP_PUBLIC_URL', 'https://ebq.io')), '/'),
    ],

    'mistral' => [
        'key' => env('MISTRAL_API_KEY'),
        // Default to small-latest (currently Mistral Small 3.2). Per-task
        // overrides happen at the call site via $options['model'].
        'model' => env('MISTRAL_MODEL', 'mistral-small-latest'),
        // Per-1M-token pricing for cost telemetry.
        'cost_per_million_input_usd' => (float) env('MISTRAL_INPUT_USD_PER_M', 0.10),
        'cost_per_million_output_usd' => (float) env('MISTRAL_OUTPUT_USD_PER_M', 0.30),
    ],

    /*
     * DeepSeek — alternative LLM provider (admin-switchable, see
     * App\Support\LlmProviderConfig). OpenAI-compatible API; no vision
     * model.
     */
    'deepseek' => [
        'key' => env('DEEPSEEK_API_KEY'),
        'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
        // Per-1M-token pricing for cost telemetry (deepseek-chat,
        // cache-miss rates — errs high like mistral's).
        'cost_per_million_input_usd' => (float) env('DEEPSEEK_INPUT_USD_PER_M', 0.27),
        'cost_per_million_output_usd' => (float) env('DEEPSEEK_OUTPUT_USD_PER_M', 1.10),
        // Blended per-token rate for the admin Usage page.
        'cost_per_token_usd' => (float) env('DEEPSEEK_COST_PER_TOKEN_USD', 0.0000011),
    ],

    /*
     * Hetzner Cloud — the crawl-worker fleet autoscaler provisions/destroys
     * worker boxes via the API. The token lives ONLY on the web box (the only
     * host that calls the API). network_id/ssh_key_id/firewall_id/location are
     * the fixed infra new boxes attach to; the tunable scaling knobs live in the
     * `Setting` store (see App\Support\AutoscalerConfig), not here.
     */
    'hetzner' => [
        'token' => env('HCLOUD_TOKEN'),
        'location' => env('HCLOUD_LOCATION', 'fsn1'), // must match the private network's zone
        'network_id' => env('HCLOUD_NETWORK_ID'),     // the 10.0.0.0/24 private network
        'ssh_key_id' => env('HCLOUD_SSH_KEY_ID'),     // id_ed25519_worker public key registered in Hetzner
        'firewall_id' => env('HCLOUD_FIREWALL_ID'),   // blocks public 6379/3306, allows the subnet
        'image' => env('HCLOUD_WORKER_IMAGE'),        // fallback snapshot id; overridden by the autoscaler.snapshot_id setting
        'web_box_ip' => env('HCLOUD_WEB_BOX_IP', '10.0.0.2'), // rsync source for boot-time code/.env pull
        'request_timeout_s' => (int) env('HCLOUD_TIMEOUT_S', 30),
        // DB-node fleet (App\Services\Fleet\DbFleetService): a MariaDB-preinstalled
        // snapshot + a firewall that blocks public 3306 and allows the subnet.
        'db_image' => env('HCLOUD_DB_IMAGE'),         // fallback DB snapshot id; overridden by db_fleet.snapshot_id
        'db_firewall_id' => env('HCLOUD_DB_FIREWALL_ID'),
    ],

];
