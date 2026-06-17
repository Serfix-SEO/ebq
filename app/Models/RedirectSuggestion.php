<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Models\Concerns\UsesTenantConnection;

/**
 * @property string $id
 * @property string $website_id
 * @property string $source_path
 * @property string $source_path_hash
 * @property string $suggested_destination
 * @property int $confidence
 * @property string $status
 * @property string|null $rationale
 * @property int $hits_30d
 * @property \Illuminate\Support\Carbon|null $first_seen_at
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 * @property \Illuminate\Support\Carbon|null $matched_at
 * @property \Illuminate\Support\Carbon|null $applied_at
 */
class RedirectSuggestion extends Model
{
    use HasUlids;
    use UsesTenantConnection;
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_STALE = 'stale';

    protected $fillable = [
        'website_id',
        'source_path',
        'source_path_hash',
        'suggested_destination',
        'confidence',
        'status',
        'rationale',
        'hits_30d',
        'first_seen_at',
        'last_seen_at',
        'matched_at',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'matched_at' => 'datetime',
            'applied_at' => 'datetime',
            'confidence' => 'integer',
            'hits_30d' => 'integer',
        ];
    }

    public static function hashPath(string $path): string
    {
        return hash('sha256', $path);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
