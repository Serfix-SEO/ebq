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
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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

    'competitor_backlinks' => [
        'limit_per_competitor' => (int) env('COMPETITOR_BACKLINKS_LIMIT', 50),
        'fresh_days' => (int) env('COMPETITOR_BACKLINKS_FRESH_DAYS', 30),
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

];
