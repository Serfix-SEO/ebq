<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product from a client's own store (Strict Product Mode). Scraped from
 * their site (JSON-LD first), variant-deduped by URL. `terms` holds
 * UnicodeText-folded tokens precomputed for topic↔product matching.
 */
class ContentProduct extends Model
{
    use HasFactory;
    use HasUlids;

    public const SOURCE_JSONLD = 'jsonld';

    public const SOURCE_OG = 'og';

    public const SOURCE_LLM = 'llm';

    public const SOURCE_SHOPIFY_API = 'shopify_api';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_GONE = 'gone';

    public const AVAILABILITY_IN_STOCK = 'in_stock';

    public const AVAILABILITY_OUT_OF_STOCK = 'out_of_stock';

    public const AVAILABILITY_PREORDER = 'preorder';

    public const AVAILABILITY_UNKNOWN = 'unknown';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'terms' => 'array',
            'is_excluded' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /** Products the writer may feature: active, not client-excluded. */
    public function scopeUsable($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)->where('is_excluded', false);
    }
}
