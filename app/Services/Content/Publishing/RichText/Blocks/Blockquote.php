<?php

namespace App\Services\Content\Publishing\RichText\Blocks;

use App\Services\Content\Publishing\RichText\Inline;

final class Blockquote extends Block
{
    /** @param  list<Inline>  $inlines */
    public function __construct(public readonly array $inlines) {}
}
