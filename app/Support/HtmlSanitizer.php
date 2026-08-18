<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Whitelist sanitizer for user-authored rich text (support-ticket messages).
 * Anything outside the small tag set survives as its plain text; every
 * attribute is dropped except http(s) hrefs on <a>. Output is safe to render
 * with {!! !!} and to embed in HTML email.
 */
class HtmlSanitizer
{
    private const ALLOWED = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 'ul', 'ol', 'li', 'a', 'blockquote'];

    /**
     * Wider set for rendering a generated ARTICLE (headings, images, tables).
     * Still an allow-list: the client can edit article HTML in the TipTap
     * editor, and `ArticleReview::sanitize()` is only a blocklist (regex over
     * script/style/iframe + on* attributes), which leaks — an unclosed
     * `<iframe src=…>` or an unquoted `onclick=…` walks straight through it.
     * Fine for showing a client their own content; NOT fine for rendering that
     * content inside an admin session, so the admin view goes through here.
     */
    private const ALLOWED_ARTICLE = [
        'p', 'br', 'b', 'strong', 'i', 'em', 'u', 'ul', 'ol', 'li', 'a', 'blockquote',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'figure', 'figcaption', 'img',
        'table', 'thead', 'tbody', 'tr', 'th', 'td', 'hr', 'code', 'pre', 'sup', 'sub',
    ];

    /** @var list<string> swapped in for the duration of an article clean */
    private static array $allowed = self::ALLOWED;

    /** Article-grade clean: headings, images and tables survive; scripts do not. */
    public static function article(string $html): string
    {
        self::$allowed = self::ALLOWED_ARTICLE;
        try {
            return self::clean($html);
        } finally {
            self::$allowed = self::ALLOWED;   // never leak the wider set
        }
    }

    public static function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $encoded = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, ~0], 'UTF-8');
        $dom->loadHTML('<div>'.$encoded.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementsByTagName('div')->item(0);
        if ($root === null) {
            return '';
        }

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= self::render($child);
        }

        return trim($out);
    }

    /** Plain text with tags stripped — for length validation and excerpts. */
    public static function text(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private static function render(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return htmlspecialchars($node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (! $node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);
        $inner = '';
        foreach (iterator_to_array($node->childNodes) as $child) {
            $inner .= self::render($child);
        }

        if ($tag === 'div') {
            // contenteditable browsers wrap lines in <div> — treat as a
            // paragraph so line structure survives sanitizing.
            return $inner === '' ? '' : "<p>$inner</p>";
        }

        if (! in_array($tag, self::$allowed, true)) {
            // Unknown element (span/script/style…): keep only its text
            // content — already escaped by the recursion above. Script/style
            // bodies are dropped entirely.
            return in_array($tag, ['script', 'style'], true) ? '' : $inner;
        }

        if ($tag === 'br' || $tag === 'hr') {
            return '<'.$tag.'>';
        }

        if ($tag === 'img') {
            // Only our own generated/stored images, never a remote or data:
            // URL pulled in by an edit.
            $src = trim($node->getAttribute('src'));
            if (preg_match('#^https?://#i', $src) !== 1) {
                return '';
            }

            return '<img src="'.htmlspecialchars($src, ENT_QUOTES | ENT_HTML5, 'UTF-8').'"'
                .' alt="'.htmlspecialchars(trim($node->getAttribute('alt')), ENT_QUOTES | ENT_HTML5, 'UTF-8').'"'
                .' loading="lazy">';
        }

        if ($tag === 'a') {
            $href = trim($node->getAttribute('href'));
            if (preg_match('#^https?://#i', $href) !== 1) {
                return $inner; // javascript:/data:/relative — link stripped, text kept
            }

            return '<a href="'.htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8').'" rel="noopener nofollow" target="_blank">'.$inner.'</a>';
        }

        return "<$tag>$inner</$tag>";
    }
}
