<?php

namespace App\Services\Content\Publishing\RichText\Blocks;

/**
 * Marker base for the bounded block model produced by HtmlBlockParser.
 * Adapters (Ricos, Portable Text) switch on the concrete class.
 */
abstract class Block {}
