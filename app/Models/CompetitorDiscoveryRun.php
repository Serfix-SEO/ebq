<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Models\Concerns\UsesTenantConnection;

/**
 * Lifecycle + cost ledger for one competitor auto-discovery run.
 * Mirrors the queued → running → completed/failed shape of the other async
 * trackers in the app ({@see KeywordApiRequest}, {@see GuestRankCheck}).
 *
 * @property string $run_id
 * @property string $website_id
 * @property ?string $user_id
 * @property string $status
 * @property int $keywords_planned
 * @property int $serp_calls_made
 * @property ?string $seed_source
 * @property ?string $error
 */
class CompetitorDiscoveryRun extends Model
{
    use HasUlids;
    use UsesTenantConnection;
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const SEED_GSC = 'gsc';
    public const SEED_MANUAL = 'manual';

    protected $fillable = [
        'run_id',
        'website_id',
        'user_id',
        'status',
        'keywords_planned',
        'serp_calls_made',
        'seed_source',
        'error',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'keywords_planned' => 'integer',
            'serp_calls_made' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'run_id';
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function markRunning(): void
    {
        $this->forceFill(['status' => self::STATUS_RUNNING, 'started_at' => now()])->save();
    }

    public function markCompleted(): void
    {
        $this->forceFill(['status' => self::STATUS_COMPLETED, 'completed_at' => now()])->save();
    }

    public function markFailed(string $message): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error' => mb_substr($message, 0, 250),
            'completed_at' => now(),
        ])->save();
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }
}
