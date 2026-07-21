<?php

namespace Serfix\ContentAi\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Serfix\ContentAi\Models\Article;

/** Hook point for the host app (cache purge, search reindex, notifications). */
class ArticleUpdated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Article $article) {}
}
