<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One engine's answer to one question on one day.
 *
 * `ok` and `error` are stored rather than dropped: a probe that failed must
 * never look like a probe that ran and found nothing. Telling a client "you
 * were not mentioned" when in truth we never asked is the failure mode this
 * column exists to prevent.
 */
class ContentAeoRun extends Model
{
    use HasUlids;

    protected $guarded = [];

    public const ENGINE_DEEPSEEK = 'deepseek';

    public const ENGINE_MISTRAL = 'mistral';

    public const ENGINE_GOOGLE_AIO = 'google_aio';

    protected function casts(): array
    {
        return [
            // 'date' alone serialises as a datetime, so updateOrCreate looks up
            // '2026-09-27' against a stored '2026-09-27 00:00:00', misses its own
            // row and then trips the unique key.
            'ran_on' => 'date:Y-m-d',
            'mentioned' => 'bool',
            'mention_rank' => 'int',
            'competitors' => 'array',
            'cited_urls' => 'array',
            'ok' => 'bool',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ContentAeoQuestion::class, 'question_id');
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
