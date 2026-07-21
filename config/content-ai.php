<?php

use Serfix\ContentAi\Models\Article;
use Serfix\ContentAi\Models\ArticleImage;

return [

    /*
    |--------------------------------------------------------------------------
    | Webhook
    |--------------------------------------------------------------------------
    |
    | Serfix POSTs articles to /{webhook.path} signed with `X-Serfix-Signature:
    | sha256=<hmac_sha256(raw_body, secret)>`. Paste the SAME secret you entered
    | in Content Autopilot → Connect publishing.
    |
    | `tolerance` rejects deliveries whose `sent_at` is older than N seconds, so
    | a captured request cannot be replayed later. Set 0 to disable (not advised).
    |
    */

    'webhook' => [
        'enabled' => env('CONTENT_AI_WEBHOOK_ENABLED', true),
        'path' => env('CONTENT_AI_WEBHOOK_PATH', 'serfix/content-ai/webhook'),
        'secret' => env('CONTENT_AI_WEBHOOK_SECRET'),
        'tolerance' => (int) env('CONTENT_AI_WEBHOOK_TOLERANCE', 300),
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Blog routes
    |--------------------------------------------------------------------------
    |
    | `prefix` is the {blogs} segment: 'blog' gives /blog/your-article-slug.
    | Set `enabled => false` to register no routes at all and drive the Article
    | model from your own controllers.
    |
    */

    'route' => [
        'enabled' => env('CONTENT_AI_ROUTES_ENABLED', true),
        'prefix' => env('CONTENT_AI_ROUTE_PREFIX', 'blog'),
        'name_prefix' => 'content-ai.',
        'domain' => env('CONTENT_AI_ROUTE_DOMAIN'),
        'middleware' => ['web'],
        'per_page' => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | Publishing behaviour
    |--------------------------------------------------------------------------
    |
    | auto_publish=false parks every delivery as a draft for a human to release;
    | drafts are reachable only through a signed preview URL.
    |
    | preserve_local_edits keeps a row you edited by hand from being overwritten
    | by a later article.updated delivery (compares `locally_edited_at`).
    |
    */

    'publishing' => [
        'auto_publish' => env('CONTENT_AI_AUTO_PUBLISH', true),
        'preserve_local_edits' => env('CONTENT_AI_PRESERVE_LOCAL_EDITS', false),
        'preview_ttl' => 60 * 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Images
    |--------------------------------------------------------------------------
    |
    | Articles arrive with <img> pointing at Serfix storage. We download every
    | one, store it on YOUR disk and rewrite the src — so published pages never
    | hotlink someone else's bucket and survive it going away.
    |
    | `allowed_hosts` is an SSRF guard: only these hosts are ever fetched. Empty
    | array = allow any https host (not advised).
    |
    */

    'images' => [
        'localize' => env('CONTENT_AI_LOCALIZE_IMAGES', true),
        'disk' => env('CONTENT_AI_IMAGE_DISK', 'public'),
        'path' => 'content-ai/images',
        'max_bytes' => 12 * 1024 * 1024,
        'timeout' => 30,
        'allowed_hosts' => [
            'nbg1.your-objectstorage.com',
            'serfix.io',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SEO
    |--------------------------------------------------------------------------
    */

    'seo' => [
        'site_name' => 'Serfix',
        'title_suffix' => null,
        'canonical_base' => env('CONTENT_AI_CANONICAL_BASE'),
        'twitter_site' => env('CONTENT_AI_TWITTER_SITE'),
        'default_og_image' => null,
        'robots_drafts' => 'noindex, nofollow',
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema.org (JSON-LD)
    |--------------------------------------------------------------------------
    |
    | `faq` lifts the FAQ block Content AI already writes into a FAQPage node,
    | which is rich-result eligible at no extra authoring cost.
    |
    */

    'schema' => [
        'enabled' => true,
        'type' => 'BlogPosting',
        'breadcrumbs' => true,
        'faq' => true,
        'publisher' => [
            'name' => 'Serfix',
            'logo' => '/serfix-logo.png',
        ],
        'author' => [
            'type' => 'Organization',
            'name' => 'Serfix',
            'url' => 'https://serfix.io',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Content handling
    |--------------------------------------------------------------------------
    |
    | rewrite_internal_links points cross-article links at YOUR blog prefix.
    | sanitize_html is off by default (the HTML is ours and already clean); turn
    | it on if you would rather not trust inbound markup implicitly.
    |
    */

    'content' => [
        'rewrite_internal_links' => true,
        'sanitize_html' => false,
        'reading_words_per_minute' => 220,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rendering — global Blade variables
    |--------------------------------------------------------------------------
    |
    | Keep your own design and drop our output into it:
    |
    |   <head> {!! $serfix_head !!}
    |   <body> {!! $serfix_body !!}
    |          {!! $serfix_body_below !!}
    |
    | Equivalent directives: @serfixHead / @serfixBody / @serfixBodyBelow, and
    | helpers serfix_head() / serfix_body() / serfix_body_below() / serfix_article().
    |
    | They render to '' on any page that is not showing an article, so they are
    | safe in a layout shared by your whole site. Set `globals => false` to stop
    | sharing them entirely (the helpers keep working).
    |
    | `schema_in` moves the JSON-LD between the head and the end of the body;
    | `related` is how many related links $serfix_body_below renders (0 = none).
    |
    */

    'render' => [
        'globals' => true,
        'schema_in' => 'head',
        'related' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Models & views — override to take full control
    |--------------------------------------------------------------------------
    */

    'models' => [
        'article' => Article::class,
        'image' => ArticleImage::class,
    ],

    // Host views: our own Blade files wrapping <x-marketing.page>, so the blog
    // uses the real Serfix layout, nav and footer. The package ships unstyled
    // views on purpose — this is the intended override point.
    'views' => [
        'index' => 'blog.index',
        'show' => 'blog.show',
        'layout' => null,
    ],

    'table_prefix' => 'content_ai_',
];
