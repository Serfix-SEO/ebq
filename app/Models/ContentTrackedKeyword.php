<?php

namespace App\Models;

use App\Services\Content\ContentKeywordTracker;
use App\Services\Content\ContentPerformanceService;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A keyword a client is tracking for Content Autopilot performance. The row
 * count per website IS the quota (KeywordTrackerQuota). Auto-added on publish
 * (source=auto) or added from the article detail page (source=manual).
 *
 * @see ContentKeywordTracker
 * @see ContentPerformanceService
 */
class ContentTrackedKeyword extends Model
{
    use HasFactory;
    use HasUlids;

    protected $guarded = [];

    protected $casts = [
        'is_primary' => 'bool',
        'serp_position' => 'int',
        'serp_checked_at' => 'datetime',
    ];

    /** Weekly SERP refresh cadence (user decision 2026-07-26). */
    public const SERP_STALE_DAYS = 7;

    public const SOURCE_AUTO = 'auto';

    public const SOURCE_MANUAL = 'manual';

    /** Canonical form used for dedupe + matching GSC query rows. */
    public static function normalize(string $keyword): string
    {
        return Str::lower(trim(preg_replace('/\s+/u', ' ', $keyword) ?? ''));
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(ContentTopic::class, 'topic_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(ContentArticle::class, 'article_id');
    }

    public function addedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }
}
