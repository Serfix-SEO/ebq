<?php
/**
 * Serfix publishing kit — shared helpers for receiver.php and articles/index.php.
 *
 * Runs on the customer's own web host, which we do not control: plain PHP 7.4+,
 * no framework, no database, no Composer. Articles are stored as JSON files in
 * serfix/data/ (denied to browsers by its .htaccess) and images in
 * serfix/media/ (public). Every function is prefixed serfix_ so the kit can be
 * dropped into any existing site without colliding with its own code.
 *
 * You should not need to edit this file. Settings live in serfix/config.php.
 */

if (defined('SERFIX_KIT_VERSION')) {
    return;
}
define('SERFIX_KIT_VERSION', '1.0.0');

/** Slug reserved for the pretty-URL self-check; no article may use it. */
define('SERFIX_REWRITE_CHECK_SLUG', 'serfix-rewrite-check');

/** @return array<string, mixed> */
function serfix_config()
{
    static $config = null;
    if ($config === null) {
        $loaded = @include __DIR__.'/config.php';
        $config = is_array($loaded) ? $loaded : array();
    }

    return $config;
}

/** @param mixed $default */
function serfix_cfg($key, $default = null)
{
    $config = serfix_config();

    return array_key_exists($key, $config) ? $config[$key] : $default;
}

function serfix_data_dir()
{
    return __DIR__.'/data';
}

function serfix_media_dir()
{
    return __DIR__.'/media';
}

/** Public web root of the site, without a trailing slash. */
function serfix_site_url()
{
    return rtrim((string) serfix_cfg('site_url', ''), '/');
}

function serfix_blog_path()
{
    $path = trim((string) serfix_cfg('blog_path', 'articles'), '/');

    return $path === '' ? 'articles' : $path;
}

function serfix_blog_url()
{
    return serfix_site_url().'/'.serfix_blog_path().'/';
}

function serfix_media_url()
{
    return serfix_site_url().'/serfix/media';
}

/**
 * Article URLs are pretty (/articles/my-post) only once we have PROVEN the
 * host honours the rewrite rules in articles/.htaccess — see
 * serfix_detect_pretty_urls(). A guessed pretty URL that 404s would be handed
 * back to Serfix, submitted to Google and shared publicly, so the safe default
 * is the query form (/articles/?post=my-post), which works on every host.
 */
function serfix_pretty_urls()
{
    $forced = serfix_cfg('pretty_urls', 'auto');
    if ($forced === true || $forced === false) {
        return $forced;
    }
    $settings = serfix_read_json(serfix_data_dir().'/settings.json');

    return is_array($settings) && ! empty($settings['pretty_urls']);
}

function serfix_post_url($slug)
{
    return serfix_pretty_urls()
        ? serfix_site_url().'/'.serfix_blog_path().'/'.rawurlencode($slug)
        : serfix_blog_url().'?post='.rawurlencode($slug);
}

/**
 * Ask our own site for the reserved check slug through the pretty URL. Only a
 * working rewrite rule reaches index.php with it, so a positive answer is proof,
 * not a guess. Loopback is blocked on some hosts — then we simply keep query
 * URLs, which is correct, not an error.
 */
function serfix_detect_pretty_urls()
{
    $forced = serfix_cfg('pretty_urls', 'auto');
    if ($forced === true || $forced === false) {
        return $forced;
    }
    $url = serfix_site_url().'/'.serfix_blog_path().'/'.SERFIX_REWRITE_CHECK_SLUG;
    $body = serfix_http_get($url, 4, 4096);
    $ok = is_string($body) && strpos($body, 'serfix-rewrite-ok') !== false;
    serfix_write_json_atomic(serfix_data_dir().'/settings.json', array(
        'pretty_urls' => $ok,
        'checked_at' => gmdate('c'),
    ));

    return $ok;
}

// ── JSON storage ───────────────────────────────────────────────────────

/** @return mixed|null */
function serfix_read_json($path)
{
    if (! is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);

    return $data === null && json_last_error() !== JSON_ERROR_NONE ? null : $data;
}

/**
 * Write-then-rename, so a reader never sees half a file and a crash mid-write
 * never corrupts the previous copy.
 */
function serfix_write_json_atomic($path, $data)
{
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }
    $tmp = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    if (! @rename($tmp, $path)) {
        @unlink($tmp);

        return false;
    }

    return true;
}

/**
 * Can this host actually store articles? Checked on every verify and test
 * delivery, because an unwritable folder is the most likely thing to go wrong
 * on shared hosting — and without this check it would only surface as a
 * failed first article.
 */
function serfix_storage_ready()
{
    foreach (array(serfix_data_dir(), serfix_data_dir().'/posts', serfix_media_dir()) as $dir) {
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true)) {
            return false;
        }
        $probe = $dir.'/.write-test-'.bin2hex(random_bytes(4));
        if (@file_put_contents($probe, 'ok') === false) {
            return false;
        }
        @unlink($probe);
    }

    return true;
}

/** Serialise index read-modify-write across concurrent deliveries. */
function serfix_lock()
{
    $handle = @fopen(serfix_data_dir().'/.lock', 'c');
    if ($handle !== false) {
        @flock($handle, LOCK_EX);
    }

    return $handle;
}

function serfix_unlock($handle)
{
    if (is_resource($handle)) {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}

/** @return array<string, array<string, mixed>> id => summary */
function serfix_index()
{
    $index = serfix_read_json(serfix_data_dir().'/index.json');

    return is_array($index) ? $index : array();
}

function serfix_post_path($id)
{
    return serfix_data_dir().'/posts/'.$id.'.json';
}

/** @return array<string, mixed>|null */
function serfix_load_post($id)
{
    if (! serfix_id_ok($id)) {
        return null;
    }
    $post = serfix_read_json(serfix_post_path($id));

    return is_array($post) ? $post : null;
}

/**
 * Find a post by its current slug, or by a slug it used to have.
 *
 * @return array{0: string|null, 1: bool} [id, isAlias]
 */
function serfix_find_by_slug($slug)
{
    foreach (serfix_index() as $id => $summary) {
        if (($summary['slug'] ?? '') === $slug) {
            return array($id, false);
        }
    }
    foreach (serfix_index() as $id => $summary) {
        if (in_array($slug, (array) ($summary['aliases'] ?? array()), true)) {
            return array($id, true);
        }
    }

    return array(null, false);
}

// ── validation ─────────────────────────────────────────────────────────

/**
 * The slug becomes part of a URL and is looked up against files on disk, so
 * it is whitelisted outright: lowercase letters, digits and single hyphens.
 * Anything else — dots, slashes, "..", encoded characters — is refused, which
 * makes path traversal impossible rather than merely filtered.
 */
function serfix_slug_ok($slug)
{
    return is_string($slug)
        && strlen($slug) <= 190
        && $slug !== SERFIX_REWRITE_CHECK_SLUG
        && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1;
}

function serfix_id_ok($id)
{
    return is_string($id) && preg_match('/^[a-f0-9]{16}$/', $id) === 1;
}

// ── HTML ───────────────────────────────────────────────────────────────

/**
 * A second line of defence behind the signature: articles are written by
 * Serfix and signed, but this HTML is served on the customer's own domain, so
 * nothing executable survives even if a delivery were ever forged.
 */
function serfix_clean_html($html)
{
    $html = (string) $html;
    $html = preg_replace('#<(script|style|iframe|object|embed|form)\b[^>]*>.*?</\1\s*>#is', '', $html);
    $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button|meta|link|base)\b[^>]*/?>#is', '', $html);
    $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    $html = preg_replace('/(href|src)\s*=\s*(["\'])\s*(javascript|vbscript|data):[^"\']*\2/i', '$1="#"', $html);

    return (string) $html;
}

function serfix_excerpt($html, $length = 170)
{
    $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $html), ENT_QUOTES, 'UTF-8')));
    if (function_exists('mb_strlen') && mb_strlen($text) > $length) {
        return rtrim(mb_substr($text, 0, $length - 1)).'…';
    }
    if (strlen($text) > $length) {
        return rtrim(substr($text, 0, $length - 1)).'…';
    }

    return $text;
}

function serfix_e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ── images ─────────────────────────────────────────────────────────────

/**
 * Only fetch images from the hosts Serfix serves them from. Without this a
 * forged payload could make the customer's server request internal addresses
 * (169.254.169.254, localhost, the router) on an attacker's behalf.
 */
function serfix_image_fetchable($src)
{
    $parts = @parse_url((string) $src);
    if (! is_array($parts) || empty($parts['host'])) {
        return false;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if ($scheme !== 'https' && ! ($scheme === 'http' && serfix_cfg('image_allow_http', false) === true)) {
        return false;
    }
    $host = strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    foreach ((array) serfix_cfg('image_hosts', array()) as $allowed) {
        $allowed = strtolower(trim((string) $allowed));
        if ($allowed === '') {
            continue;
        }
        if ($host === $allowed || substr($host, -strlen('.'.$allowed)) === '.'.$allowed) {
            return true;
        }
    }

    return false;
}

/** @return string|null the body, or null on any failure */
function serfix_http_get($url, $timeout, $maxBytes)
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $body = '';
        curl_setopt_array($ch, array(
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout * 3,
            CURLOPT_USERAGENT => 'SerfixKit/'.SERFIX_KIT_VERSION,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, $maxBytes) {
                $body .= $chunk;

                return strlen($body) > $maxBytes ? 0 : strlen($chunk);
            },
        ));
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($ok === false || $status < 200 || $status >= 300 || strlen($body) > $maxBytes) {
            return null;
        }

        return $body;
    }
    if (! ini_get('allow_url_fopen')) {
        return null;
    }
    $context = stream_context_create(array('http' => array(
        'timeout' => $timeout * 3,
        'follow_location' => 0,
        'ignore_errors' => false,
        'user_agent' => 'SerfixKit/'.SERFIX_KIT_VERSION,
    )));
    $body = @file_get_contents($url, false, $context, 0, $maxBytes + 1);
    if ($body === false || strlen($body) > $maxBytes) {
        return null;
    }

    return $body;
}

/**
 * Copy one image onto this host and return its public URL, or null to keep
 * the original. Failure is always soft: a missing image beats a broken article.
 */
function serfix_store_image($src, $postId)
{
    if (! serfix_image_fetchable($src)) {
        return null;
    }
    $dir = serfix_media_dir().'/'.$postId;
    $name = substr(sha1($src), 0, 20);

    // A re-delivery of the same article: reuse what we already have.
    foreach (array('jpg', 'png', 'webp', 'gif') as $ext) {
        if (is_file($dir.'/'.$name.'.'.$ext)) {
            return serfix_media_url().'/'.$postId.'/'.$name.'.'.$ext;
        }
    }

    $bytes = serfix_http_get($src, 8, 12 * 1024 * 1024);
    if ($bytes === null || $bytes === '') {
        return null;
    }
    // Trust the bytes, not the URL: only real raster images get written.
    $info = function_exists('getimagesizefromstring') ? @getimagesizefromstring($bytes) : false;
    $types = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif');
    if (! is_array($info) || ! isset($types[$info['mime']])) {
        return null;
    }
    $ext = $types[$info['mime']];
    if (! is_dir($dir) && ! @mkdir($dir, 0755, true)) {
        return null;
    }
    if (@file_put_contents($dir.'/'.$name.'.'.$ext, $bytes, LOCK_EX) === false) {
        return null;
    }

    return serfix_media_url().'/'.$postId.'/'.$name.'.'.$ext;
}

/** @return array{0: string, 1: string|null} [html with local srcs, first image url] */
function serfix_localize_images($html, $postId)
{
    $first = null;
    if (! preg_match_all('/<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', $html, $matches)) {
        return array($html, null);
    }
    foreach (array_unique($matches[1]) as $src) {
        $local = serfix_store_image($src, $postId);
        $url = $local !== null ? $local : $src;
        if ($local !== null) {
            $html = str_replace($src, $local, $html);
        }
        if ($first === null) {
            $first = $url;
        }
    }

    return array($html, $first);
}
