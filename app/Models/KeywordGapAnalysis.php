<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Models\Concerns\UsesTenantConnection;

/**
 * Header for one Keyword Gap Analysis run. See the migration for the lifecycle.
 *
 * @property string $website_id
 * @property ?string $user_id
 * @property string $our_url
 * @property ?array $competitor_urls
 * @property string $country
 * @property string $status
 * @property ?array $request_ids
 * @property int $total_requests
 * @property int $completed_requests
 * @property ?array $summary
 * @property ?string $error
 * @property ?\Illuminate\Support\Carbon $expires_at
 * @property ?\Illuminate\Support\Carbon $reprocessed_at
 */
class KeywordGapAnalysis extends Model
{
    use HasUlids;
    use UsesTenantConnection;
    public const STATUS_QUEUED = 'queued';
    public const STATUS_COLLECTING = 'collecting';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const BUCKET_MISSING = 'missing';
    public const BUCKET_WEAK = 'weak';
    public const BUCKET_STRENGTH = 'strength';
    public const BUCKET_SHARED = 'shared';

    public const VERIFY_STATUS_VERIFYING = 'verifying';
    public const VERIFY_STATUS_COMPLETED = 'completed';
    public const VERIFY_STATUS_FAILED = 'failed';

    protected $fillable = [
        'website_id',
        'user_id',
        'our_url',
        'competitor_urls',
        'country',
        'status',
        'request_ids',
        'total_requests',
        'completed_requests',
        'summary',
        'error',
        'expires_at',
        'completed_at',
        'reprocessed_at',
        'verify_status',
        'verify_total',
        'verify_done',
        'verify_error',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'competitor_urls' => 'array',
            'request_ids' => 'array',
            'summary' => 'array',
            'total_requests' => 'integer',
            'completed_requests' => 'integer',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'reprocessed_at' => 'datetime',
            'verify_status' => 'string',
            'verify_total' => 'integer',
            'verify_done' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(KeywordGapRow::class);
    }

    public function isFresh(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isFuture();
    }
}
