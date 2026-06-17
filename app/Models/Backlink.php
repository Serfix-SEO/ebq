<?php

namespace App\Models;

use App\Enums\BacklinkType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Models\Concerns\UsesTenantConnection;

class Backlink extends Model
{
    use HasUlids;
    use UsesTenantConnection;
    /** @use HasFactory<\Database\Factories\BacklinkFactory> */
    use HasFactory;

    protected $fillable = [
        'website_id',
        'tracked_date',
        'referring_page_url',
        'target_page_url',
        'domain_authority',
        'spam_score',
        'anchor_text',
        'type',
        'is_dofollow',
        'audit_status',
        'audit_checked_at',
        'audit_result',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tracked_date' => 'date',
            'is_dofollow' => 'boolean',
            'type' => BacklinkType::class,
            'audit_checked_at' => 'datetime',
            'audit_result' => 'array',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
