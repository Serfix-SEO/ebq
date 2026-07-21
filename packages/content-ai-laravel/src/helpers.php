<?php

use Serfix\ContentAi\Models\Article;
use Serfix\ContentAi\Rendering\Renderer;

if (! function_exists('serfix_article')) {
    /** The article currently being rendered, if any. */
    function serfix_article(): ?Article
    {
        return app(Renderer::class)->current();
    }
}

if (! function_exists('serfix_head')) {
    /** Title, description, canonical, robots, OG, Twitter, JSON-LD. */
    function serfix_head(?Article $article = null): string
    {
        return app(Renderer::class)->head($article);
    }
}

if (! function_exists('serfix_body')) {
    /** The article HTML, images already pointing at your own disk. */
    function serfix_body(?Article $article = null): string
    {
        return app(Renderer::class)->body($article);
    }
}

if (! function_exists('serfix_body_below')) {
    /** Related articles and anything else that belongs after the content. */
    function serfix_body_below(?Article $article = null): string
    {
        return app(Renderer::class)->bodyBelow($article);
    }
}
