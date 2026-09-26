<?php
/**
 * Serfix publishing kit — your articles, rendered on your own domain.
 *
 *   /articles/                 the article list
 *   /articles/?post=my-post    one article (or /articles/my-post where the
 *                              server supports the rewrite rules in .htaccess)
 *   /articles/?sitemap=1       an XML sitemap of your articles
 *
 * Pages are plain server-rendered HTML with their title, description,
 * canonical link, social tags and article schema in the <head> — which is what
 * search engines read.
 *
 * To make these pages wear your site's own design, create
 * serfix/header.php and serfix/footer.php. If they exist they are used instead
 * of the simple built-in page. Your header receives $serfix_head: print it
 * inside <head> so the SEO tags come along.
 */

require dirname(__DIR__).'/serfix/lib.php';

$serfix_slug = isset($_GET['post']) ? strtolower(trim((string) $_GET['post'])) : '';

// The pretty-URL self-check (see serfix_detect_pretty_urls). Reaching this
// branch through /articles/serfix-rewrite-check proves the rewrite works.
if ($serfix_slug === SERFIX_REWRITE_CHECK_SLUG) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo 'serfix-rewrite-ok';
    exit;
}

// ── sitemap ────────────────────────────────────────────────────────────
if (isset($_GET['sitemap'])) {
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
    foreach (serfix_index() as $summary) {
        if (($summary['status'] ?? '') !== 'published') {
            continue;
        }
        echo '  <url><loc>'.serfix_e(serfix_post_url($summary['slug'])).'</loc>'
            .'<lastmod>'.serfix_e(substr((string) ($summary['updated_at'] ?? ''), 0, 10)).'</lastmod></url>'."\n";
    }
    echo '</urlset>'."\n";
    exit;
}

$serfix_site_name = (string) serfix_cfg('site_name', parse_url(serfix_site_url(), PHP_URL_HOST));
$serfix_post = null;

if ($serfix_slug !== '') {
    list($serfix_id, $serfix_is_alias) = serfix_slug_ok($serfix_slug) ? serfix_find_by_slug($serfix_slug) : array(null, false);
    $serfix_post = $serfix_id !== null ? serfix_load_post($serfix_id) : null;

    // A renamed article: send visitors and search engines to its new address.
    if ($serfix_post !== null && $serfix_is_alias && ($serfix_post['status'] ?? '') === 'published') {
        header('Location: '.serfix_post_url($serfix_post['slug']), true, 301);
        exit;
    }
    // Drafts are not public.
    if ($serfix_post !== null && ($serfix_post['status'] ?? '') !== 'published') {
        $serfix_post = null;
    }
    if ($serfix_post === null) {
        http_response_code(404);
    }
}

// AI Visibility: count answer-engine crawlers and report the daily totals to
// Serfix after this page has been delivered. Human traffic returns instantly
// and nothing about a visitor is stored — see serfix_note_ai_visit().
serfix_note_ai_visit(isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '');
serfix_report_ai_hits_after_response();

// ── <head> tags ────────────────────────────────────────────────────────
function serfix_head_tags(array $tags)
{
    $out = '';
    foreach ($tags as $tag) {
        list($kind, $name, $value) = $tag;
        if ($value === null || $value === '') {
            continue;
        }
        if ($kind === 'title') {
            $out .= '<title>'.serfix_e($value).'</title>'."\n";
        } elseif ($kind === 'link') {
            $out .= '<link rel="'.serfix_e($name).'" href="'.serfix_e($value).'">'."\n";
        } elseif ($kind === 'property') {
            $out .= '<meta property="'.serfix_e($name).'" content="'.serfix_e($value).'">'."\n";
        } elseif ($kind === 'json') {
            // JSON-LD must not contain a literal "</script>".
            $out .= '<script type="application/ld+json">'.str_replace('</', '<\/', $value).'</script>'."\n";
        } else {
            $out .= '<meta name="'.serfix_e($name).'" content="'.serfix_e($value).'">'."\n";
        }
    }

    return $out;
}

if ($serfix_post !== null) {
    $serfix_url = serfix_post_url($serfix_post['slug']);
    $serfix_canonical = $serfix_post['canonical_url'] !== '' ? $serfix_post['canonical_url'] : $serfix_url;
    $serfix_title = $serfix_post['meta_title'] !== '' ? $serfix_post['meta_title'] : $serfix_post['title'];
    $serfix_description = $serfix_post['meta_description'] !== '' ? $serfix_post['meta_description'] : serfix_excerpt($serfix_post['html']);
    $serfix_robots = ($serfix_post['robots_noindex'] ? 'noindex' : 'index').', '.($serfix_post['robots_nofollow'] ? 'nofollow' : 'follow');

    $serfix_schema = array(
        '@context' => 'https://schema.org',
        '@graph' => array(
            array_filter(array(
                '@type' => 'BlogPosting',
                'headline' => $serfix_title,
                'description' => $serfix_description,
                'inLanguage' => $serfix_post['language'],
                'wordCount' => $serfix_post['word_count'] ?: null,
                'mainEntityOfPage' => array('@type' => 'WebPage', '@id' => $serfix_canonical),
                'url' => $serfix_canonical,
                'image' => $serfix_post['image'] ? array('@type' => 'ImageObject', 'url' => $serfix_post['image']) : null,
                'datePublished' => $serfix_post['published_at'],
                'dateModified' => $serfix_post['updated_at'],
                'author' => array('@type' => 'Organization', 'name' => $serfix_site_name, 'url' => serfix_site_url()),
                'publisher' => array('@type' => 'Organization', 'name' => $serfix_site_name, 'url' => serfix_site_url()),
                'keywords' => implode(', ', array_filter(array_merge(array($serfix_post['target_keyword']), $serfix_post['secondary_keywords']))) ?: null,
            )),
            array(
                '@type' => 'BreadcrumbList',
                'itemListElement' => array(
                    array('@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => serfix_site_url().'/'),
                    array('@type' => 'ListItem', 'position' => 2, 'name' => 'Articles', 'item' => serfix_blog_url()),
                    array('@type' => 'ListItem', 'position' => 3, 'name' => $serfix_post['title'], 'item' => $serfix_canonical),
                ),
            ),
        ),
    );

    $serfix_head = serfix_head_tags(array(
        array('title', null, $serfix_title.' | '.$serfix_site_name),
        array('name', 'description', $serfix_description),
        array('name', 'robots', $serfix_robots),
        array('link', 'canonical', $serfix_canonical),
        array('property', 'og:type', 'article'),
        array('property', 'og:title', $serfix_post['og_title'] !== '' ? $serfix_post['og_title'] : $serfix_title),
        array('property', 'og:description', $serfix_post['og_description'] !== '' ? $serfix_post['og_description'] : $serfix_description),
        array('property', 'og:url', $serfix_canonical),
        array('property', 'og:site_name', $serfix_site_name),
        array('property', 'og:image', $serfix_post['image']),
        array('property', 'article:published_time', $serfix_post['published_at']),
        array('property', 'article:modified_time', $serfix_post['updated_at']),
        array('name', 'twitter:card', $serfix_post['twitter_card'] !== '' ? $serfix_post['twitter_card'] : ($serfix_post['image'] ? 'summary_large_image' : 'summary')),
        array('name', 'twitter:title', $serfix_post['twitter_title'] !== '' ? $serfix_post['twitter_title'] : $serfix_title),
        array('name', 'twitter:description', $serfix_post['twitter_description'] !== '' ? $serfix_post['twitter_description'] : $serfix_description),
        array('name', 'twitter:image', $serfix_post['image']),
        array('json', null, json_encode($serfix_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
    ));
    $serfix_lang = $serfix_post['language'] ?: 'en';
} elseif ($serfix_slug !== '') {
    $serfix_head = serfix_head_tags(array(
        array('title', null, 'Article not found | '.$serfix_site_name),
        array('name', 'robots', 'noindex, follow'),
    ));
    $serfix_lang = 'en';
} else {
    $serfix_page = max(1, (int) ($_GET['page'] ?? 1));
    $serfix_all = array_values(array_filter(serfix_index(), function ($summary) {
        return ($summary['status'] ?? '') === 'published';
    }));
    usort($serfix_all, function ($a, $b) {
        return strcmp((string) $b['published_at'], (string) $a['published_at']);
    });
    $serfix_per_page = 12;
    $serfix_pages = max(1, (int) ceil(count($serfix_all) / $serfix_per_page));
    $serfix_page = min($serfix_page, $serfix_pages);
    $serfix_list = array_slice($serfix_all, ($serfix_page - 1) * $serfix_per_page, $serfix_per_page);
    $serfix_list_url = serfix_blog_url().($serfix_page > 1 ? '?page='.$serfix_page : '');

    $serfix_head = serfix_head_tags(array(
        array('title', null, 'Articles | '.$serfix_site_name),
        array('name', 'description', 'Guides and articles from '.$serfix_site_name.'.'),
        array('link', 'canonical', $serfix_list_url),
        array('property', 'og:type', 'website'),
        array('property', 'og:title', 'Articles | '.$serfix_site_name),
        array('property', 'og:url', $serfix_list_url),
    ));
    $serfix_lang = 'en';
}

header('Content-Type: text/html; charset=utf-8');

$serfix_css = <<<'CSS'
.serfix-blog{max-width:760px;margin:0 auto;padding:32px 20px 64px;color:#1f2937;font:inherit;line-height:1.7}
.serfix-blog *{box-sizing:border-box}
.serfix-blog a{color:inherit}
.serfix-back{display:inline-block;margin-bottom:24px;font-size:.9em;color:#6b7280;text-decoration:none}
.serfix-back:hover{text-decoration:underline}
.serfix-blog h1{font-size:2.1em;line-height:1.2;margin:0 0 12px}
.serfix-meta{color:#6b7280;font-size:.9em;margin-bottom:28px}
.serfix-body img{max-width:100%;height:auto;border-radius:10px}
.serfix-body h2{font-size:1.5em;line-height:1.3;margin:1.8em 0 .6em}
.serfix-body h3{font-size:1.2em;margin:1.5em 0 .5em}
.serfix-body table{width:100%;border-collapse:collapse;display:block;overflow-x:auto}
.serfix-body th,.serfix-body td{border:1px solid #e5e7eb;padding:8px 10px;text-align:left}
.serfix-body figure{margin:1.6em 0}
.serfix-body figcaption{font-size:.85em;color:#6b7280;margin-top:6px}
.serfix-grid{display:grid;gap:24px;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));margin-top:24px}
.serfix-card{display:block;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;text-decoration:none;transition:box-shadow .15s}
.serfix-card:hover{box-shadow:0 6px 20px rgba(0,0,0,.08)}
.serfix-card img{display:block;width:100%;aspect-ratio:16/9;object-fit:cover;background:#f3f4f6}
.serfix-card div{padding:14px 16px 18px}
.serfix-card h2{font-size:1.05em;line-height:1.35;margin:0 0 6px}
.serfix-card p{font-size:.9em;color:#6b7280;margin:0}
.serfix-pages{display:flex;gap:12px;justify-content:center;margin-top:36px}
.serfix-empty{color:#6b7280;margin-top:24px}
CSS;

$serfix_header = __DIR__.'/../serfix/header.php';
$serfix_footer = __DIR__.'/../serfix/footer.php';

if (is_file($serfix_header)) {
    include $serfix_header;
} else {
    echo '<!doctype html>'."\n".'<html lang="'.serfix_e($serfix_lang).'">'."\n".'<head>'."\n"
        .'<meta charset="utf-8">'."\n".'<meta name="viewport" content="width=device-width, initial-scale=1">'."\n"
        .$serfix_head
        .'<style>body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#fff}</style>'."\n"
        .'</head>'."\n".'<body>'."\n";
}

echo '<style>'.$serfix_css.'</style>'."\n";
echo '<main class="serfix-blog">'."\n";

if ($serfix_post !== null) {
    echo '<a class="serfix-back" href="'.serfix_e(serfix_blog_url()).'">&larr; All articles</a>'."\n";
    echo '<article>'."\n";
    echo '<h1>'.serfix_e($serfix_post['title']).'</h1>'."\n";
    echo '<p class="serfix-meta">'.serfix_e(date('j F Y', strtotime($serfix_post['published_at']))).'</p>'."\n";
    // Stored HTML was cleaned on receipt (serfix_clean_html) and arrives
    // signed from Serfix; it is the article body, so it is printed as HTML.
    echo '<div class="serfix-body">'.$serfix_post['html'].'</div>'."\n";
    echo '</article>'."\n";
} elseif ($serfix_slug !== '') {
    echo '<a class="serfix-back" href="'.serfix_e(serfix_blog_url()).'">&larr; All articles</a>'."\n";
    echo '<h1>Article not found</h1>'."\n";
    echo '<p class="serfix-empty">This article may have moved. Browse all articles instead.</p>'."\n";
} else {
    echo '<a class="serfix-back" href="'.serfix_e(serfix_site_url().'/').'">&larr; '.serfix_e($serfix_site_name).'</a>'."\n";
    echo '<h1>Articles</h1>'."\n";
    if ($serfix_list === array()) {
        echo '<p class="serfix-empty">New articles will appear here soon.</p>'."\n";
    } else {
        echo '<div class="serfix-grid">'."\n";
        foreach ($serfix_list as $summary) {
            echo '<a class="serfix-card" href="'.serfix_e(serfix_post_url($summary['slug'])).'">';
            if (! empty($summary['image'])) {
                echo '<img src="'.serfix_e($summary['image']).'" alt="" loading="lazy">';
            }
            echo '<div><h2>'.serfix_e($summary['title']).'</h2><p>'.serfix_e($summary['excerpt'] ?? '').'</p></div></a>'."\n";
        }
        echo '</div>'."\n";
        if ($serfix_pages > 1) {
            echo '<nav class="serfix-pages">';
            if ($serfix_page > 1) {
                echo '<a href="'.serfix_e(serfix_blog_url().($serfix_page > 2 ? '?page='.($serfix_page - 1) : '')).'">&larr; Newer</a>';
            }
            if ($serfix_page < $serfix_pages) {
                echo '<a href="'.serfix_e(serfix_blog_url().'?page='.($serfix_page + 1)).'">Older &rarr;</a>';
            }
            echo '</nav>'."\n";
        }
    }
}

echo '</main>'."\n";

if (is_file($serfix_footer)) {
    include $serfix_footer;
} else {
    echo '</body>'."\n".'</html>'."\n";
}
