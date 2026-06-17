<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Models\Concerns\UsesTenantConnection;

class PageIndexingStatus extends Model
{
    use HasUlids;
    use UsesTenantConnection;
    protected $fillable = [
        'website_id',
        'page',
        'last_reindex_requested_at',
        'last_google_status_checked_at',
        'google_verdict',
        'google_coverage_state',
        'google_indexing_state',
        'google_last_crawl_at',
        'google_status_payload',
    ];

    protected function casts(): array
    {
        return [
            'last_reindex_requested_at' => 'datetime',
            'last_google_status_checked_at' => 'datetime',
            'google_last_crawl_at' => 'datetime',
            'google_status_payload' => 'array',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
