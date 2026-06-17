<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Models\Concerns\UsesTenantConnection;

class PageAuditReport extends Model
{
    use HasUlids;
    use UsesTenantConnection;
    protected $fillable = [
        'website_id',
        'page',
        'page_hash',
        'primary_keyword',
        'primary_keyword_source',
        'status',
        'audited_at',
        'http_status',
        'response_time_ms',
        'page_size_bytes',
        'error_message',
        'result',
    ];

    protected function casts(): array
    {
        return [
            'audited_at' => 'datetime',
            'result' => 'array',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * @return HasMany<CustomPageAudit, $this>
     */
    public function customPageAudits(): HasMany
    {
        return $this->hasMany(CustomPageAudit::class);
    }
}
