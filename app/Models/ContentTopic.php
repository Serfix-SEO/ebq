<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A calendar cell: one planned article moving through the pipeline.
 *
 * Status flow:
 * suggested → approved → researching → writing → scoring → revising →
 * ready → scheduled → publishing → published | failed | skipped
 */
class ContentTopic extends Model
{
    use HasFactory;
    use HasUlids;

    public const STATUS_SUGGESTED = 'suggested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_RESEARCHING = 'researching';

    public const STATUS_WRITING = 'writing';

    public const STATUS_SCORING = 'scoring';

    public const STATUS_REVISING = 'revising';

    public const STATUS_READY = 'ready';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PUBLISHING = 'publishing';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    /** Statuses the reaper watches — a row stuck here >30min is failed. */
    public const IN_FLIGHT = [
        self::STATUS_RESEARCHING,
        self::STATUS_WRITING,
        self::STATUS_SCORING,
        self::STATUS_REVISING,
        self::STATUS_PUBLISHING,
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'secondary_keywords' => 'array',
            'brief' => 'array',
            'meta' => 'array',
            'scheduled_for' => 'date',
            'stage_started_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ContentPlan::class, 'plan_id');
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(ContentArticle::class, 'topic_id');
    }

    public function currentArticle(): HasOne
    {
        return $this->hasOne(ContentArticle::class, 'topic_id')->where('is_current', true);
    }

    /** Move to a pipeline stage, stamping the watchdog anchor. */
    public function enterStage(string $status): void
    {
        $this->forceFill(['status' => $status, 'stage_started_at' => now()])->save();
    }

    public function fail(string $error): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'last_error' => mb_substr($error, 0, 2000),
        ])->save();
    }
}
