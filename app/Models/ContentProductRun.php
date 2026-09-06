<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One catalog-scrape run. The client-facing progress screen polls THIS row —
 * run state lives in the DB, never only in cache (a lost cache key must not
 * strand the screen; ContentSetupInsights 2026-07-24 precedent).
 */
class ContentProductRun extends Model
{
    use HasFactory;
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_DISCOVERING = 'discovering';

    public const STATUS_EXTRACTING = 'extracting';

    public const STATUS_FINALIZING = 'finalizing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    /** Statuses that mean "work is still happening". */
    public const IN_FLIGHT = [
        self::STATUS_PENDING, self::STATUS_DISCOVERING,
        self::STATUS_EXTRACTING, self::STATUS_FINALIZING,
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'heartbeat_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function beat(): void
    {
        $this->forceFill(['heartbeat_at' => now()])->saveQuietly();
    }
}
