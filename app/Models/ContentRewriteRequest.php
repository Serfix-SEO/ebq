<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One client-requested article rewrite (credit-gated). Deliberately no DB
 * foreign keys — the row is the refund/audit linkage and must survive
 * topic/website deletion. `error` is internal vocabulary; never render it
 * to clients.
 */
class ContentRewriteRequest extends Model
{
    use HasUlids;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['client_seen_at' => 'datetime'];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(ContentTopic::class, 'topic_id');
    }
}
