<?php

namespace App\Services\Content\Publishing\RichText\Blocks;

final class ImageBlock extends Block
{
    public function __construct(
        public readonly string $src,
        public readonly string $alt = '',
    ) {}
}
