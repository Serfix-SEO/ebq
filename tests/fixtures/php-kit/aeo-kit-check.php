<?php
/**
 * Behaviour check for the PHP kit's AI Visibility code
 * (resources/snippets/php/serfix/lib.php).
 *
 * The kit runs on somebody else's shared host with no framework, no database
 * and PHP 7.4, so it cannot be exercised by the Laravel suite. This drives the
 * REAL kit file against a throwaway install and asserts what it recorded.
 *
 *   php tests/fixtures/php-kit/aeo-kit-check.php
 *
 * Exits non-zero on the first failed expectation.
 */
$root = sys_get_temp_dir().'/serfix-kit-sim-'.getmypid();
@mkdir($root.'/serfix/data/posts', 0755, true);
@mkdir($root.'/serfix/media', 0755, true);
@mkdir($root.'/articles', 0755, true);
copy('/var/www/ebq/resources/snippets/php/serfix/lib.php', $root.'/serfix/lib.php');
file_put_contents($root.'/serfix/config.php', '<?php return '.var_export([
    'secret' => str_repeat('s', 40),
    'integration_id' => '01TESTINTEGRATION',
    'ai_report_url' => 'https://example.test/api/v1/aeo/kit/bot-hits',
    'site_url' => 'https://shop.test',
    'site_name' => 'Shop',
    'blog_path' => 'articles',
    'pretty_urls' => false,
    'image_hosts' => [],
    'tolerance_seconds' => 300,
], true).';');

require $root.'/serfix/lib.php';

// index with two published articles + a draft
serfix_write_json_atomic(serfix_data_dir().'/index.json', [
    'a1' => ['slug' => 'oud-guide', 'status' => 'published', 'title' => 'Oud Guide', 'excerpt' => 'How to pick oud.', 'published_at' => '2026-09-20T10:00:00Z'],
    'a2' => ['slug' => 'summer', 'status' => 'published', 'title' => 'Summer Scents', 'excerpt' => '', 'published_at' => '2026-09-25T10:00:00Z'],
    'a3' => ['slug' => 'draft-one', 'status' => 'draft', 'title' => 'Draft', 'excerpt' => '', 'published_at' => null],
]);

$visit = function (string $ua, string $path) {
    $_SERVER['HTTP_USER_AGENT'] = $ua;
    serfix_note_ai_visit($path);
};

$visit('Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)', '/articles/oud-guide');
$visit('Mozilla/5.0 (compatible; GPTBot/1.2)', '/articles/summer');
$visit('Mozilla/5.0 (compatible; GPTBot/1.2)', '/articles/summer');           // repeat path
$visit('Mozilla/5.0 (Macintosh) AppleWebKit/537.36 Chrome/124 Safari/537.36', '/articles/oud-guide'); // human
$visit('Mozilla/5.0 (compatible; Claude-SearchBot/1.0)', '/articles/oud-guide');

$buffer = serfix_read_json(serfix_data_dir().'/ai-hits.json');
$day = gmdate('Y-m-d');
$fail = [];
$expect = function ($label, $actual, $wanted) use (&$fail) {
    $ok = $actual === $wanted;
    printf("%s  %s: %s\n", $ok ? 'ok  ' : 'FAIL', $label, json_encode($actual));
    if (! $ok) { $fail[] = $label.' — wanted '.json_encode($wanted); }
};

$expect('only AI visits counted', count($buffer), 2);
$expect('gptbot hits', (int) $buffer[$day.'|gptbot']['hits'], 3);
$expect('gptbot distinct pages', count($buffer[$day.'|gptbot']['paths']), 2);
$expect('longest UA match wins', isset($buffer[$day.'|claude-searchbot']), true);
$expect('human traffic is invisible', isset($buffer[$day.'|chrome']), false);

// llms.txt
$expect('llms.txt written', serfix_write_llms_txt(), true);
$llms = file_get_contents($root.'/llms.txt');
$expect('llms.txt lists published articles', substr_count($llms, '- ['), 3); // 2 articles + the index link
$expect('llms.txt keeps drafts out', strpos($llms, 'draft-one') === false, true);
$expect('llms.txt carries the excerpt', strpos($llms, 'How to pick oud.') !== false, true);
$expect('llms.txt is newest first', strpos($llms, 'Summer Scents') < strpos($llms, 'Oud Guide'), true);

// the six-hour report throttle
file_put_contents(serfix_data_dir().'/.ai-report-at', (string) time());
serfix_report_ai_hits();  // must be a no-op, buffer intact
$expect('report throttled within 6h', count(serfix_read_json(serfix_data_dir().'/ai-hits.json')), 2);

echo "\n".$llms."\n";
exec('rm -rf '.escapeshellarg($root));
if ($fail) { fwrite(STDERR, "\n".count($fail)." failed:\n  ".implode("\n  ", $fail)."\n"); exit(1); }
echo "All kit checks passed.\n";
