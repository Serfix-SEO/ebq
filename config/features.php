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
];
