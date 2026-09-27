<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A day's AI visibility score, kept so the page can draw a line rather than a number. */
class ContentAeoScore extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            // 'date' alone serialises as a datetime, so updateOrCreate looks up
            // '2026-09-27' against a stored '2026-09-27 00:00:00', misses its own
            // row and then trips the unique key.
            'scored_on' => 'date:Y-m-d',
            'score' => 'int',
            'components' => 'array',
            'signals_used' => 'array',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
