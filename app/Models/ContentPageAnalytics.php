<?php

namespace App\Models;

use App\Jobs\SyncContentPageAnalytics;
use App\Services\Content\ContentPerformanceService;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-page daily GA4 traffic for Content Autopilot article reporting (pageviews
 * / sessions / users). GSC per-page/query metrics come from search_console_data;
 * this fills the page-level gap GA's date+source-only sync (analytics_data) can't.
 *
 * @see SyncContentPageAnalytics
 * @see ContentPerformanceService
 */
class ContentPageAnalytics extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'content_page_analytics';

    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
        'pageviews' => 'int',
        'sessions' => 'int',
        'users' => 'int',
        'engagement_rate' => 'float',
    ];

    /**
     * Store `date` as a plain Y-m-d string. The default 'date' cast writes
     * 'Y-m-d H:i:s'; MySQL's DATE column truncates it, but sqlite (the test DB)
     * keeps the time, so string-compared window queries miss the rows. Mirrors
     * SearchConsoleData::setDateAttribute — the daily job's raw upsert already
     * writes plain Y-m-d, so this keeps Eloquent writes consistent with it.
     */
    public function setDateAttribute(mixed $value): void
    {
        $this->attributes['date'] = $value === null
            ? null
            : Carbon::parse($value)->toDateString();
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
