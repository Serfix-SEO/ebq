<?php

namespace App\Support;

/**
 * Turn bare URLs in already-safe HTML into clickable links.
 *
 * Support messages are written in a small rich editor whose link button
 * produces a proper `<a>`. Nobody uses it: people paste a URL and send. Those
 * arrived as plain text — visible, unclickable, and the client had to select
 * and copy them by hand (reported 2026-08-18).
 *
 * Runs on the OUTPUT of {@see HtmlSanitizer} (or of `nl2br(e())`), never on raw
 * user input, so everything here is already escaped and the tag set is known.
 * Text inside an existing `<a>` is left alone — re-linking it would nest
 * anchors and produce invalid markup.
 */
final class Autolink
{
    /**
     * Anchors (whole element), then any other tag. Matching whole `<a>` blocks
     * FIRST is what keeps link text from being linkified again.
     */
    private const SKIP = '#(<a\b[^>]*>.*?</a>|<[^>]+>)#is';

    /**
     * Trailing punctuation almost always belongs to the sentence, not the URL:
     * "see https://x.test/page." should not link the full stop.
     */
    private const TRIM_TRAILING = ".,;:!?'\"";

    public static function apply(string $html): string
    {
        if ($html === '' || ! preg_match('#https?://|www\.#i', $html)) {
            return $html;
        }

        $parts = preg_split(self::SKIP, $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $html;
        }

        $out = '';
        foreach ($parts as $i => $part) {
            // Odd indices are the captured tags/anchors — pass them through.
            $out .= ($i % 2 === 1) ? $part : self::linkifyText($part);
        }

        return $out;
    }

    private static function linkifyText(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        return (string) preg_replace_callback(
            '#(?<![\w@/])((?:https?://|www\.)[^\s<>"\']+)#i',
            static function (array $m): string {
                $match = $m[1];

                // Give the trailing punctuation back to the sentence, and drop a
                // closing bracket that has no opener inside the URL.
                $trailing = '';
                while ($match !== '' && str_contains(self::TRIM_TRAILING, substr($match, -1))) {
                    $trailing = substr($match, -1).$trailing;
                    $match = substr($match, 0, -1);
                }
                if (str_ends_with($match, ')') && substr_count($match, '(') < substr_count($match, ')')) {
                    $match = substr($match, 0, -1);
                    $trailing = ')'.$trailing;
                }
                if ($match === '') {
                    return $m[1];
                }

                // The text is already HTML-escaped; the href needs the real URL.
                $raw = html_entity_decode($match, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $href = str_starts_with(strtolower($raw), 'www.') ? 'https://'.$raw : $raw;

                // Anything that is not plain http(s) after decoding is left as
                // text — the sanitizer's rule, applied here too.
                if (preg_match('#^https?://#i', $href) !== 1) {
                    return $m[1];
                }

                return '<a href="'.htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8').'"'
                    .' rel="noopener nofollow" target="_blank">'.$match.'</a>'.$trailing;
            },
            $text,
        );
    }
}
