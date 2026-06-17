<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Models\Concerns\UsesTenantConnection;

class RankTrackingSnapshot extends Model
{
    use HasUlids;
    use UsesTenantConnection;
    protected $fillable = [
        'rank_tracking_keyword_id',
        'checked_at',
        'position',
        'url',
        'title',
        'snippet',
        'total_results',
        'search_time',
        'serp_features',
        'competitor_positions',
        'top_results',
        'related_searches',
        'people_also_ask',
        'status',
        'error',
        'forced',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'search_time' => 'float',
            'serp_features' => 'array',
            'competitor_positions' => 'array',
            'top_results' => 'array',
            'related_searches' => 'array',
            'people_also_ask' => 'array',
            'forced' => 'boolean',
        ];
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(RankTrackingKeyword::class, 'rank_tracking_keyword_id');
    }
}
