<?php

namespace Serfix\ContentAi\Services;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Allow-list sanitizer for inbound article HTML.
 *
 * Why this exists: the package injects article HTML straight into the host's
 * page. The signature check is the only thing standing between an attacker and
 * markup on someone else's site, so a single leaked signing secret would mean
 * stored XSS delivered by us. Defence in depth — strip anything that can
 * execute, regardless of who signed it.
 *
 * Deliberately dependency-free (DOMDocument, which ships with PHP): a security
 * package that drags in a large HTML library is a bigger attack surface than the
 * problem it solves, and hosts should not have to audit a transitive tree to
 * install this.
 *
 * Allow-list, never deny-list: unknown tags are unwrapped (children kept),
 * unknown attributes dropped. A deny-list is guaranteed to miss the next trick.
 */
class HtmlSanitizer
{
    /** Tags Content AI actually emits, plus common semantic markup. */
    private const ALLOWED_TAGS = [
        'p', 'br', 'hr', 'div', 'span', 'section', 'article', 'aside',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'a', 'img', 'figure', 'figcaption', 'picture', 'source',
        'strong', 'b', 'em', 'i', 'u', 's', 'mark', 'small', 'sub', 'sup',
        'blockquote', 'q', 'cite', 'code', 'pre', 'kbd', 'samp', 'var',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
        'nav', 'time', 'abbr', 'address', 'details', 'summary',
    ];

    /**
     * Attributes allowed on any element, plus per-tag extras.
     *
     * `style` is NOT allowed anywhere: it carries `expression()`, `behavior`,
     * `url(javascript:)` and a long tail of engine-specific escapes.
     */
    private const GLOBAL_ATTRS = ['id', 'class', 'title', 'dir', 'lang', 'role'];

    private const TAG_ATTRS = [
        'a' => ['href', 'target', 'rel', 'download'],
        'img' => ['src', 'alt', 'width', 'height', 'loading', 'decoding', 'srcset', 'sizes'],
        'source' => ['src', 'srcset', 'sizes', 'type', 'media'],
        'time' => ['datetime'],
        'td' => ['colspan', 'rowspan', 'headers', 'scope'],
        'th' => ['colspan', 'rowspan', 'headers', 'scope', 'abbr'],
        'ol' => ['start', 'reversed', 'type'],
        'li' => ['value'],
        'blockquote' => ['cite'],
        'q' => ['cite'],
        'abbr' => ['title'],
        'details' => ['open'],
        'col' => ['span'],
        'colgroup' => ['span'],
    ];

    /** Dropped WITH their contents — the content is the payload. */
    private const STRIP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'applet', 'frame', 'frameset',
        'form', 'input', 'button', 'select', 'option', 'textarea', 'template',
        'noscript', 'base', 'link', 'meta', 'svg', 'math', 'portal',
    ];

    /** Schemes an href/src may use. Everything else (javascript:, data:, vbscript:) goes. */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $dom = new DOMDocument;

        // Suppress HTML5-tag warnings from libxml's HTML4 parser; we validate
        // structure ourselves below.
        $previous = libxml_use_internal_errors(true);
        // The meta charset + wrapper keep UTF-8 intact and stop libxml adding
        // <html><body> we would then have to unwrap.
        $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="content-ai-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('content-ai-root');
        if ($root === null) {
            return ''; // unparseable → emit nothing rather than something unchecked
        }

        $this->stripDangerousSubtrees($dom);
        $this->clean($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }

    /** Remove nodes whose *contents* are the danger (script bodies, form fields…). */
    private function stripDangerousSubtrees(DOMDocument $dom): void
    {
        $xpath = new DOMXPath($dom);
        $query = implode('|', array_map(
            static fn (string $t): string => '//'.$t,
            self::STRIP_WITH_CONTENT
        ));

        $nodes = $xpath->query($query);
        if ($nodes === false) {
            return;
        }

        // Snapshot first: removing while iterating a live NodeList skips nodes.
        foreach (iterator_to_array($nodes) as $node) {
            $node->parentNode?->removeChild($node);
        }

        // Comments can hide conditional-comment scripts on legacy engines.
        foreach (iterator_to_array($xpath->query('//comment()') ?: []) as $comment) {
            $comment->parentNode?->removeChild($comment);
        }
    }

    private function clean(DOMNode $node): void
    {
        // Snapshot — clean() mutates the tree as it goes.
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                // Unwrap rather than delete: an unknown wrapper should not cost
                // the reader the paragraph inside it.
                $this->clean($child);
                $this->unwrap($child);

                continue;
            }

            $this->cleanAttributes($child, $tag);
            $this->clean($child);
        }
    }

    private function cleanAttributes(DOMElement $el, string $tag): void
    {
        $allowed = array_merge(self::GLOBAL_ATTRS, self::TAG_ATTRS[$tag] ?? []);

        foreach (iterator_to_array($el->attributes) as $attr) {
            /** @var DOMAttr $attr */
            $name = strtolower($attr->nodeName);

            // Every on* handler, plus anything not explicitly allowed.
            if (str_starts_with($name, 'on') || ! in_array($name, $allowed, true)) {
                $el->removeAttribute($attr->nodeName);

                continue;
            }

            if (in_array($name, ['href', 'src', 'cite', 'srcset', 'download'], true)
                && ! $this->isSafeUrl((string) $attr->nodeValue)) {
                $el->removeAttribute($attr->nodeName);
            }
        }

        // A link that opens a new tab without rel=noopener hands the opener
        // window to the destination — cheap to fix, easy to forget.
        if ($tag === 'a' && $el->getAttribute('target') === '_blank') {
            $el->setAttribute('rel', trim($el->getAttribute('rel').' noopener noreferrer'));
        }
    }

    private function isSafeUrl(string $url): bool
    {
        // Strip the control characters and entities used to smuggle a scheme
        // past naive checks: "java\tscript:", "java&#09;script:".
        $normalized = strtolower(preg_replace('/[\x00-\x20]|&#[xX]?0*(9|10|13|A|D);?/i', '', $url) ?? '');

        if ($normalized === '') {
            return false;
        }
        // Relative URLs and fragments carry no scheme — always fine.
        if (str_starts_with($normalized, '/') || str_starts_with($normalized, '#')
            || str_starts_with($normalized, '?')) {
            return true;
        }
        if (! str_contains($normalized, ':')) {
            return true; // relative path
        }

        $scheme = strstr($normalized, ':', true);

        return in_array((string) $scheme, self::ALLOWED_SCHEMES, true);
    }

    private function unwrap(DOMElement $el): void
    {
        $parent = $el->parentNode;
        if ($parent === null) {
            return;
        }
        while ($el->firstChild !== null) {
            $parent->insertBefore($el->firstChild, $el);
        }
        $parent->removeChild($el);
    }
}
