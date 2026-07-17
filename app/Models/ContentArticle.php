<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One draft VERSION of a topic's article. The revision loop appends new
 * versions (is_current moves forward) so score history is auditable.
 */
class ContentArticle extends Model
{
    use HasFactory;
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'outline' => 'array',
            'seo_issues' => 'array',
            'style_issues' => 'array',
            'generation_meta' => 'array',
            'is_current' => 'boolean',
        ];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(ContentTopic::class, 'topic_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ContentImage::class, 'article_id');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(ContentPublication::class, 'article_id');
    }

    /** Persist as the new current version for the topic. */
    public static function storeVersion(ContentTopic $topic, array $attributes): self
    {
        $latest = (int) $topic->articles()->max('version');

        $topic->articles()->where('is_current', true)->update(['is_current' => false]);

        return $topic->articles()->create($attributes + [
            'version' => $latest + 1,
            'is_current' => true,
        ]);
    }
}
