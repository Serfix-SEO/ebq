<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per tenant/crawl-site move between DB shard nodes — live progress
 * written by {@see \App\Services\Sharding\ShardMover} (phase + per-chunk row
 * counts), polled by the fleet page's "Data moves" panel. See the
 * shard_moves migration docblock for why this exists.
 */
class ShardMove extends Model
{
    use HasUlids;

    public const STATUS_COUNTING = 'counting';
    public const STATUS_COPYING = 'copying';
    public const STATUS_VERIFYING = 'verifying';
    public const STATUS_CUTOVER = 'cutover';
    public const STATUS_PURGING = 'purging';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'kind', 'subject_id', 'subject_label', 'source_node_id', 'target_node_id',
        'status', 'current_table', 'tables_total', 'tables_done',
        'rows_total', 'rows_copied', 'table_counts', 'error',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'table_counts' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isRunning(): bool
    {
        return ! in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    /** 0-100, row-based across the copy phase; 100 once completed. */
    public function progressPercent(): int
    {
        if ($this->status === self::STATUS_COMPLETED) {
            return 100;
        }
        if ($this->rows_total <= 0) {
            return $this->status === self::STATUS_COUNTING ? 0 : 95;
        }

        // Copy dominates wall time; verify/cutover/purge get the last 5%.
        $copyShare = (int) floor(min(1, $this->rows_copied / $this->rows_total) * 95);

        return in_array($this->status, [self::STATUS_VERIFYING, self::STATUS_CUTOVER, self::STATUS_PURGING], true)
            ? max($copyShare, 95)
            : $copyShare;
    }
}
