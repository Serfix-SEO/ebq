# Serfix Content AI for Laravel

Receive articles published by [Serfix](https://serfix.io) Content Autopilot into your
Laravel app, store them, and serve them at a URL you choose — with SEO metas,
`BlogPosting` schema and **self-hosted images**.

This is the Laravel counterpart to the WordPress plugin. Serfix pushes each article to a
signed webhook; this package verifies, imports and renders it.

```bash
composer require serfix/content-ai-laravel
php artisan content-ai:install
php artisan migrate
php artisan storage:link   # only if you keep the default `public` image disk
```

`content-ai:install` prints a generated signing secret. Put it in `.env`:

```dotenv
CONTENT_AI_WEBHOOK_SECRET=<the generated value>
```

Then in **Content Autopilot → Connect publishing**, pick **Webhook** and enter:

| Field | Value |
|---|---|
| Endpoint URL | `https://your-app.test/serfix/content-ai/webhook` |
| Signing secret | the same value as `CONTENT_AI_WEBHOOK_SECRET` |

Confirm the plumbing without waiting for a real publish:

```bash
php artisan content-ai:verify
```

## The blog URL

`/{prefix}/{slug}` — set the prefix and you're done:

```dotenv
CONTENT_AI_ROUTE_PREFIX=insights   # → /insights/your-article-link
```

Everything the package generates follows it: article URLs, breadcrumbs, the sitemap, and
cross-article links inside the HTML. Registered routes:

| Route | Name |
|---|---|
| `GET /{prefix}` | `content-ai.index` |
| `GET /{prefix}/{slug}` | `content-ai.show` |
| `GET /{prefix}/feed` | `content-ai.feed` |
| `GET /{prefix}/sitemap.xml` | `content-ai.sitemap` |

Rendering articles inside your own app instead? Set `CONTENT_AI_ROUTES_ENABLED=false` —
the webhook keeps receiving, and you query the model yourself:

```php
use Serfix\ContentAi\Models\Article;

$articles = Article::published()->latest('published_at')->paginate();
```

## Keep your own design

Three globals are available in **every** Blade view. Drop them into your existing
layout and build whatever you like around them:

```blade
<!DOCTYPE html>
<html>
<head>
    {!! $serfix_head !!}          {{-- title, description, canonical, robots, OG, Twitter, JSON-LD --}}
</head>
<body>
    <x-my-navbar />

    <article class="prose">
        {!! $serfix_body !!}      {{-- the article HTML, images already on your disk --}}
    </article>

    {!! $serfix_body_below !!}    {{-- related articles, and anything that belongs after --}}

    <x-my-footer />
</body>
</html>
```

Same thing as directives or helpers, whichever suits your codebase:

```blade
@serfixHead   @serfixBody   @serfixBodyBelow
```
```php
serfix_head();  serfix_body();  serfix_body_below();  serfix_article();
```

They render to an empty string on any page that isn't showing an article, so they're
safe in a layout shared by your whole site — nothing is computed until echoed.

Rendering articles from **your own** controller? Tell the package which one is current
and the globals work there too:

```php
use Serfix\ContentAi\Rendering\Renderer;

public function show(string $slug)
{
    $article = \Serfix\ContentAi\Models\Article::where('slug', $slug)->firstOrFail();

    app(Renderer::class)->use($article);

    return view('my-own-blog-page');   // your view, your design
}
```

`content-ai.render.schema_in` moves the JSON-LD to `body_below` if you prefer it at the
end of the document; `content-ai.render.related` sets how many related links to show
(`0` to omit). `render.globals => false` stops sharing the variables entirely — the
helpers keep working.

## SEO

The globals already include everything below; these components exist for finer control.

```blade
<head>
    <x-content-ai::meta :article="$article" />
    <x-content-ai::schema :article="$article" />
</head>
```

`meta` emits title, description, canonical, robots, Open Graph and Twitter card.
`schema` emits `BlogPosting` + `BreadcrumbList` JSON-LD — plus an `FAQPage` node lifted
from the FAQ block Content AI already writes, which is rich-result eligible at no extra
authoring cost.

Using your own SEO package? Take the array instead:

```php
app(\Serfix\ContentAi\Services\MetaBuilder::class)->for($article);
app(\Serfix\ContentAi\Services\SchemaBuilder::class)->for($article);   // array
```

Drafts and unpublished articles are automatically `noindex, nofollow`.

## Images are copied to your disk

Articles arrive with `<img>` pointing at Serfix storage. Every one is downloaded, stored
on **your** disk and the `src` rewritten — published pages never hotlink someone else's
bucket, and they keep working if the original is moved or expired.

```dotenv
CONTENT_AI_IMAGE_DISK=s3          # any Laravel disk; default `public`
CONTENT_AI_LOCALIZE_IMAGES=true
```

A download that fails leaves the original `src` in place, so a flaky fetch degrades to a
hotlinked image rather than a broken one. Only `https` hosts on
`config('content-ai.images.allowed_hosts')` are ever fetched — that allow-list is an SSRF
guard, so widen it deliberately.

## Drafts and review

```dotenv
CONTENT_AI_AUTO_PUBLISH=false
```

Every delivery is parked as a draft. Drafts 404 publicly and open only through a signed,
expiring preview link:

```php
app(\Serfix\ContentAi\Http\Controllers\ArticleController::class)->previewUrl($article);
```

Set `CONTENT_AI_PRESERVE_LOCAL_EDITS=true` and a row you edited by hand will not be
overwritten by a later `article.updated` delivery.

## Events

```php
use Serfix\ContentAi\Events\{ArticleReceived, ArticlePublished, ArticleUpdated};
```

Hook these to purge a CDN, reindex a search engine, or notify a channel.

## Customising

```bash
php artisan vendor:publish --tag=content-ai-config
php artisan vendor:publish --tag=content-ai-views       # unstyled on purpose
php artisan vendor:publish --tag=content-ai-migrations
```

Point `content-ai.views.layout` at your own layout, or swap the model/controller entirely
via `content-ai.models.*` and your own routes.

## The wire format

`POST` with `X-Serfix-Signature: sha256=<hmac_sha256(raw_body, secret)>`.

```jsonc
{
  "event": "article.published",     // or article.updated / article.unpublished / verify
  "external_id": null,              // the id WE returned previously; null on first publish
  "article": {
    "h1": "…", "slug": "…", "html": "…", "markdown": "…",
    "meta_title": "…", "meta_description": "…",
    "word_count": 1200, "language": "en",
    "target_keyword": "…", "secondary_keywords": ["…"]
  },
  "sent_at": "2026-07-21T06:00:00+00:00"
}
```

We answer `{"ok":true,"id":"…","url":"…","status":"published"}`. **The `url` matters**:
Serfix stores it as the published location, so without it there is no record of where your
article actually went.

### Security

- **Sanitised by default.** Article HTML is run through an allow-list sanitizer
  before it is stored — no `<script>`, no `on*` handlers, no `javascript:`/`data:`
  URLs, no `<iframe>`/`<form>`/`style=`. The signature is the only barrier between
  an attacker and markup on your page, so a leaked signing secret must not become
  stored XSS. Dependency-free (`DOMDocument`). Disable with
  `CONTENT_AI_SANITIZE_HTML=false` only if you fully trust the publisher.
- **https only.** Serfix refuses to send to an `http://` endpoint: the HMAC stops
  forgery, not eavesdropping.
- **Strong secrets.** Serfix generates a 48-character secret for you; anything
  under 32 characters is rejected. Never reuse one across sites — one secret is
  one site's entire identity.

Three properties this receiver guarantees:

- **Authenticity** — HMAC over the *raw* body (re-encoding JSON does not round-trip
  byte-for-byte), compared with `hash_equals`, plus a timestamp window so a captured
  request cannot be replayed later.
- **Idempotency** — Serfix retries on any non-2xx. A repeat delivery matches on
  `external_id`, then on `slug`, and updates instead of creating. A lost `200` can never
  duplicate a post.
- **Honest failures** — a broken payload answers 4xx/5xx so the publisher retries, rather
  than a cheerful `200` that silently drops the article.

## Testing

```bash
composer test
```

## Requirements

PHP 8.2+ · Laravel 11 or 12

## License

MIT
