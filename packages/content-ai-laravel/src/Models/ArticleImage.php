<?php

namespace Serfix\ContentAi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A Serfix image copied onto the host application's own disk.
 * Rows exist only for images we successfully stored — a failed download
 * leaves the original src in the HTML rather than a broken local path.
 */
class ArticleImage extends Model
{
    public const ROLE_FEATURED = 'featured';

    public const ROLE_INLINE = 'inline';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['bytes' => 'integer'];
    }

    public function getTable(): string
    {
        return config('content-ai.table_prefix', 'content_ai_').'images';
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(config('content-ai.models.article', Article::class));
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }
}
