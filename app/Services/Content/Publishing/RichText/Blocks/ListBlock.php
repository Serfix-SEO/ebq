<?php

namespace App\Services\Content\Publishing\RichText\Blocks;

use App\Services\Content\Publishing\RichText\Inline;

final class ListBlock extends Block
{
    /** @param  list<list<Inline>>  $items one inline run list per <li>; nested lists are flattened into extra items */
    public function __construct(
        public readonly bool $ordered,
        public readonly array $items,
    ) {}
}
