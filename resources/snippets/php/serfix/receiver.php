<?php
/**
 * Serfix publishing kit — the address Serfix sends your articles to.
 *
 * Every delivery is signed with the secret in serfix/config.php
 * (X-Serfix-Signature: sha256=<HMAC-SHA256 of the raw body>). Anything without
 * a valid, recent signature is refused before it can touch your files.
 *
 * You should not need to edit this file.
 */

require __DIR__.'/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

function serfix_reply($status, array $body)
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Opening this address in a browser should reassure, not alarm.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    serfix_reply(200, array(
        'ok' => true,
        'message' => 'The Serfix receiver is installed. This address only accepts deliveries from Serfix.',
        'version' => SERFIX_KIT_VERSION,
    ));
}

$secret = (string) serfix_cfg('secret', '');
if (strlen($secret) < 32) {
    serfix_reply(500, array('error' => 'serfix/config.php has no signing secret. Download the kit again from Serfix.'));
}

// Hash the RAW body. Decoding and re-encoding JSON does not round-trip byte
// for byte, so it would break perfectly valid signatures.
$raw = (string) file_get_contents('php://input', false, null, 0, 8 * 1024 * 1024 + 1);
if (strlen($raw) > 8 * 1024 * 1024) {
    serfix_reply(413, array('error' => 'Delivery too large.'));
}
$provided = (string) ($_SERVER['HTTP_X_SERFIX_SIGNATURE'] ?? '');
$expected = 'sha256='.hash_hmac('sha256', $raw, $secret);
if ($provided === '' || ! hash_equals($expected, $provided)) {
    serfix_reply(401, array('error' => 'Invalid signature.'));
}

$payload = json_decode($raw, true);
if (! is_array($payload)) {
    serfix_reply(400, array('error' => 'Body is not JSON.'));
}

// A captured delivery must not be replayable tomorrow.
$sentAt = isset($payload['sent_at']) ? strtotime((string) $payload['sent_at']) : false;
if ($sentAt !== false && abs(time() - $sentAt) > (int) serfix_cfg('tolerance_seconds', 300)) {
    serfix_reply(401, array('error' => 'Delivery timestamp is outside the accepted window. Check this server\'s clock.'));
}

if (! serfix_storage_ready()) {
    serfix_reply(500, array(
        'error' => 'This website cannot save files in the serfix/data and serfix/media folders. Ask your host to make them writable by PHP.',
    ));
}

$event = (string) ($payload['event'] ?? '');

if ($event === 'verify') {
    serfix_detect_pretty_urls();
    serfix_reply(200, array('ok' => true, 'blog_url' => serfix_blog_url(), 'version' => SERFIX_KIT_VERSION));
}

// The "Send test article" button. Storing proves nothing we have not just
// checked, and a non-developer has no way to delete a stray test post — so
// confirm we COULD store it and point at the blog instead.
if (! empty($payload['test'])) {
    serfix_reply(200, array('ok' => true, 'id' => 'test', 'url' => serfix_blog_url(), 'stored' => false));
}

if ($event === 'article.unpublished') {
    $lock = serfix_lock();
    $id = (string) ($payload['external_id'] ?? '');
    $post = serfix_load_post($id);
    if ($post !== null) {
        $post['status'] = 'draft';
        serfix_write_json_atomic(serfix_post_path($id), $post);
        $index = serfix_index();
        if (isset($index[$id])) {
            $index[$id]['status'] = 'draft';
            serfix_write_json_atomic(serfix_data_dir().'/index.json', $index);
        }
    }
    serfix_unlock($lock);
    serfix_reply(200, array('ok' => true, 'id' => $id));
}

if ($event !== 'article.published' && $event !== 'article.updated') {
    serfix_reply(400, array('error' => 'Unknown event.'));
}

$article = isset($payload['article']) && is_array($payload['article']) ? $payload['article'] : array();
$slug = strtolower(trim((string) ($article['slug'] ?? '')));
if (! serfix_slug_ok($slug)) {
    serfix_reply(422, array('error' => 'Invalid article slug.'));
}

$lock = serfix_lock();

// Match the post we already have: by the id we handed back last time first
// (so a changed slug updates the same post instead of creating a duplicate),
// then by slug for a delivery that lost its id.
$externalId = (string) ($payload['external_id'] ?? '');
$existing = serfix_id_ok($externalId) ? serfix_load_post($externalId) : null;
if ($existing === null) {
    list($foundId) = serfix_find_by_slug($slug);
    $existing = $foundId !== null ? serfix_load_post($foundId) : null;
}
$id = $existing !== null ? (string) $existing['id'] : bin2hex(random_bytes(8));

// Another post already owns this slug: keep both reachable.
list($ownerId, $isAlias) = serfix_find_by_slug($slug);
if ($ownerId !== null && $ownerId !== $id && ! $isAlias) {
    $base = $slug;
    for ($n = 2; $n < 100 && serfix_find_by_slug($slug)[0] !== null; $n++) {
        $slug = $base.'-'.$n;
    }
}

// A renamed post keeps its old address as a redirect, so links and search
// results pointing at the old slug do not break.
$aliases = $existing !== null ? (array) ($existing['aliases'] ?? array()) : array();
if ($existing !== null && $existing['slug'] !== $slug && ! in_array($existing['slug'], $aliases, true)) {
    $aliases[] = $existing['slug'];
}
$aliases = array_values(array_diff($aliases, array($slug)));

list($html, $firstImage) = serfix_localize_images(serfix_clean_html((string) ($article['html'] ?? '')), $id);

$ogImage = trim((string) ($article['og_image'] ?? ''));
if ($ogImage !== '') {
    $localOg = serfix_store_image($ogImage, $id);
    $ogImage = $localOg !== null ? $localOg : $ogImage;
}
$image = $ogImage !== '' ? $ogImage : $firstImage;

$now = gmdate('c');
$status = ($payload['status'] ?? 'published') === 'draft' ? 'draft' : 'published';
$title = trim((string) ($article['h1'] ?? ''));
if ($title === '') {
    $title = ucwords(str_replace('-', ' ', $slug));
}

$post = array(
    'id' => $id,
    'slug' => $slug,
    'aliases' => $aliases,
    'status' => $status,
    'title' => $title,
    'html' => $html,
    'image' => $image,
    'meta_title' => trim((string) ($article['meta_title'] ?? '')),
    'meta_description' => trim((string) ($article['meta_description'] ?? '')),
    'canonical_url' => trim((string) ($article['canonical_url'] ?? '')),
    'robots_noindex' => ! empty($article['robots_noindex']),
    'robots_nofollow' => ! empty($article['robots_nofollow']),
    'og_title' => trim((string) ($article['og_title'] ?? '')),
    'og_description' => trim((string) ($article['og_description'] ?? '')),
    'twitter_title' => trim((string) ($article['twitter_title'] ?? '')),
    'twitter_description' => trim((string) ($article['twitter_description'] ?? '')),
    'twitter_card' => trim((string) ($article['twitter_card'] ?? '')),
    'language' => preg_replace('/[^A-Za-z-]/', '', (string) ($article['language'] ?? 'en')),
    'target_keyword' => trim((string) ($article['target_keyword'] ?? '')),
    'secondary_keywords' => array_values(array_filter(array_map('strval', (array) ($article['secondary_keywords'] ?? array())))),
    'word_count' => (int) ($article['word_count'] ?? 0),
    'published_at' => $existing !== null && ! empty($existing['published_at']) ? $existing['published_at'] : $now,
    'updated_at' => $now,
);

if (! serfix_write_json_atomic(serfix_post_path($id), $post)) {
    serfix_unlock($lock);
    serfix_reply(500, array('error' => 'Could not save the article.'));
}

$index = serfix_index();
$index[$id] = array(
    'slug' => $slug,
    'aliases' => $aliases,
    'status' => $status,
    'title' => $title,
    'excerpt' => $post['meta_description'] !== '' ? $post['meta_description'] : serfix_excerpt($html),
    'image' => $image,
    'published_at' => $post['published_at'],
    'updated_at' => $now,
);
if (! serfix_write_json_atomic(serfix_data_dir().'/index.json', $index)) {
    serfix_unlock($lock);
    serfix_reply(500, array('error' => 'Could not update the article list.'));
}
serfix_unlock($lock);

// Refresh /llms.txt — the plain-text map AI answer engines look for. Best
// effort: on a host where the web root is not writable this simply returns
// false and the client installs the file themselves.
$serfix_llms = serfix_write_llms_txt();

serfix_reply(200, array(
    'ok' => true,
    'id' => $id,
    'url' => serfix_post_url($slug),
    'status' => $status,
    'llms_txt' => $serfix_llms,
));
