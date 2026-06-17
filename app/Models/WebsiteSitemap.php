<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Models\Concerns\UsesTenantConnection;

class WebsiteSitemap extends Model
{
    use HasUlids;
    use UsesTenantConnection;
    public const SOURCE_GSC = 'gsc';
    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'website_id',
        'path',
        'source',
        'type',
        'is_pending',
        'is_sitemaps_index',
        'errors',
        'warnings',
        'submitted_urls',
        'indexed_urls',
        'last_submitted_at',
        'last_downloaded_at',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_pending' => 'boolean',
            'is_sitemaps_index' => 'boolean',
            'errors' => 'integer',
            'warnings' => 'integer',
            'submitted_urls' => 'integer',
            'indexed_urls' => 'integer',
            'last_submitted_at' => 'datetime',
            'last_downloaded_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function isFromGsc(): bool
    {
        return $this->source === self::SOURCE_GSC;
    }
}
