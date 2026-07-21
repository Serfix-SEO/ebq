<?php

namespace Serfix\ContentAi\Rendering;

use Illuminate\Contracts\Support\Htmlable;
use Stringable;

/**
 * A shared Blade variable that renders only when echoed.
 *
 * The globals are shared with EVERY view at boot, long before a controller has
 * decided which article (if any) is being displayed. Sharing an eagerly-built
 * string would therefore be empty on article pages and wasted work on all the
 * others. Deferring to __toString means the closure runs at echo time, by which
 * point the current article is known — and on a page with no article it costs
 * nothing and prints nothing.
 */
class LazyChunk implements Htmlable, Stringable
{
    /** @param callable(): string $resolver */
    public function __construct(private $resolver) {}

    public function __toString(): string
    {
        return ($this->resolver)();
    }

    /** Lets `{{ $serfix_head }}` behave like `{!! $serfix_head !!}` — never double-escaped. */
    public function toHtml(): string
    {
        return $this->__toString();
    }

    public function isEmpty(): bool
    {
        return trim($this->__toString()) === '';
    }
}
