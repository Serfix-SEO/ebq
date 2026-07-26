<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Client verdict on a generated article ("Do you like this article?").
 * One current row per (topic, user); the client can change their mind
 * (updateOrCreate). Monitored by admins at admin.content-feedback.
 */
class ContentArticleFeedback extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'content_article_feedback';

    protected $guarded = [];

    public const RATING_LOVE = 'love';

    public const RATING_REWRITES = 'rewrites';

    public const RATING_WRONG = 'wrong';

    public const RATINGS = [self::RATING_LOVE, self::RATING_REWRITES, self::RATING_WRONG];

    /** Client-safe label for a rating code. */
    public static function label(string $rating): string
    {
        return match ($rating) {
            self::RATING_LOVE => 'Loved it',
            self::RATING_REWRITES => 'Needs small rewrites',
            self::RATING_WRONG => 'Fundamentally wrong',
            default => $rating,
        };
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(ContentTopic::class, 'topic_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(ContentArticle::class, 'article_id');
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
