<?php

namespace App\Services\Content\Publishing\RichText\Blocks;

use App\Services\Content\Publishing\RichText\Inline;

final class Heading extends Block
{
    /** @param  list<Inline>  $inlines */
    public function __construct(
        public readonly int $level, // 2|3
        public readonly array $inlines,
    ) {}
}
