<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentImage extends Model
{
    use HasFactory;
    use HasUlids;

    public const ROLE_FEATURED = 'featured';

    public const ROLE_INLINE = 'inline';

    public const STATUS_PENDING = 'pending';

    public const STATUS_GENERATED = 'generated';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REJECTED = 'rejected';

    protected $guarded = [];

    /** Public-disk URL for the stored image, or null. */
    public function url(): ?string
    {
        return $this->disk_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->disk_path) : null;
    }

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'cost_usd' => 'float',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(ContentArticle::class, 'article_id');
    }
}
