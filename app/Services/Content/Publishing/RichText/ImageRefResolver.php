<?php

namespace App\Services\Content\Publishing\RichText;

/**
 * Maps a local image URL (as it appears in the article HTML) to the id of a
 * copy already uploaded to the destination platform. Returning null means the
 * image could not be re-hosted — adapters then drop the node and emit the alt
 * text as an italic paragraph so no content silently vanishes.
 */
interface ImageRefResolver
{
    public function resolve(string $src): ?ImageRef;
}
