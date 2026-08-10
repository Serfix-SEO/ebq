<?php

namespace App\Services\Content\Publishing\RichText\Blocks;

final class TableBlock extends Block
{
    /** @param  list<list<string>>  $rows plain-text cells (header row first when the table had one) */
    public function __construct(public readonly array $rows) {}
}
