<?php

namespace App\Services\Content\Publishing\RichText;

/**
 * One styled text run inside a block: a stretch of text with a uniform
 * bold/italic/link state. Adjacent runs with identical state are merged by
 * the parser so adapters never emit redundant sibling spans.
 */
final readonly class Inline
{
    public function __construct(
        public string $text,
        public bool $bold = false,
        public bool $italic = false,
        public ?string $href = null,
    ) {}

    public function sameStyle(Inline $other): bool
    {
        return $this->bold === $other->bold
            && $this->italic === $other->italic
            && $this->href === $other->href;
    }

    public function withText(string $text): self
    {
        return new self($text, $this->bold, $this->italic, $this->href);
    }
}
