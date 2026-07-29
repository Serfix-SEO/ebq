<?php

namespace App\Models;

use App\Services\Content\ContentRankHistoryService;
use App\Services\Content\ContentSerpChecker;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recorded live-Google rank check for a tracked keyword. `position` null
 * means "checked, not in the top 100" — distinct from no row at all (never
 * checked that day), which is why the chart draws gaps rather than zeros.
 *
 * @see ContentSerpChecker  writes one row per check
 * @see ContentRankHistoryService  reads the series
 */
class ContentKeywordRankHistory extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'content_keyword_rank_history';

    protected $guarded = [];

    public const SOURCE_SERP = 'serp';

    protected function casts(): array
    {
        return [
            'checked_on' => 'date',
            'position' => 'int',
        ];
    }

    /**
     * Store the check day as a bare Y-m-d. Laravel's `date` cast writes
     * "Y-m-d 00:00:00", which MySQL silently truncates for a DATE column but
     * SQLite keeps verbatim — so the day-keyed updateOrCreate lookup (and every
     * whereBetween on a date string) missed and inserted a duplicate.
     */
    public function setCheckedOnAttribute(mixed $value): void
    {
        $this->attributes['checked_on'] = $value === null
            ? null
            : Carbon::parse($value)->toDateString();
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function trackedKeyword(): BelongsTo
    {
        return $this->belongsTo(ContentTrackedKeyword::class, 'tracked_keyword_id');
    }
}
