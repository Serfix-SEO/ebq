<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One lifecycle (onboarding-funnel) email: user × segment × stage. The unique
 * (user_id, segment, stage) DB key makes a double-send structurally impossible;
 * senders use updateOrCreate on that natural key so a `failed` row is retried
 * on the next run. `converted_at` = the user left the emailed segment on a
 * later run (the report's "it worked" signal).
 */
class LifecycleEmailSend extends Model
{
    use HasUlids;

    public const STAGE_INITIAL = 'initial';
    public const STAGE_FOLLOWUP = 'followup';

    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    /** segment number => short admin label */
    public const SEGMENTS = [
        1 => 'Articles flowing',
        2 => 'No website yet',
        3 => 'Strategy unfinished',
        4 => 'Not connected',
    ];

    protected $fillable = [
        'user_id',
        'website_id',
        'segment',
        'stage',
        'to_email',
        'subject',
        'status',
        'converted_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'segment' => 'integer',
            'converted_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
