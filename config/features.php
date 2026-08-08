<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Global feature kill-switches
    |--------------------------------------------------------------------------
    |
    | These gate whole product areas ON/OFF for EVERYONE, independent of the
    | per-team `TeamPermissions` access model. A `false` here wins over any
    | team permission: the route group 404s (via the `feature.enabled`
    | middleware), the sidebar entry is hidden, and the area is never used as
    | a post-login landing route.
    |
    */

    // AI Studio (beta). Disabled by default — flip FEATURE_AI_STUDIO=true in
    // .env (per box) to bring the beta back. Kept out of prod while in beta.
    'ai_studio' => (bool) env('FEATURE_AI_STUDIO', false),

    // SEO platform UI (2026-08-08). false = the whole SEO product disappears
    // from the UI and the app becomes Content-AI-focused: `/` serves the
    // Content Autopilot landing, SEO marketing pages 302 away, authed SEO
    // surfaces redirect to /content (admins exempt), the sidebar shows only
    // content groups, /billing lists Content AI only. Backend untouched —
    // crawls, jobs, APIs, the WP plugin and admin pages keep running.
    //
    // Checked at RUNTIME (config(...), never Route::has): every route name
    // stays registered in both states, because names like 'pricing' and
    // 'landing' are referenced across dozens of blades and in middleware
    // allowlists, and because box A's working tree is the prod docroot — code
    // goes live before deploy decisions, so the env flag, not code presence,
    // decides visibility. Default true = deploying this code changes nothing.
    'seo_platform_ui' => (bool) env('SEO_PLATFORM_UI', true),
];
