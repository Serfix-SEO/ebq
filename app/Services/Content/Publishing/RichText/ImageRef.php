<?php

namespace App\Services\Content\Publishing\RichText;

/**
 * A platform-hosted replacement for one of our local article images:
 * Wix media id or Sanity asset _id, plus optional dimensions.
 */
final readonly class ImageRef
{
    public function __construct(
        public string $ref,
        public ?int $width = null,
        public ?int $height = null,
    ) {}
}
