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

    protected $guarded = [];

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
