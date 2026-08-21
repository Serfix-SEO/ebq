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
            'robots_noindex' => 'boolean',
            'robots_nofollow' => 'boolean',
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

        $article = $topic->articles()->create($attributes + [
            'version' => $latest + 1,
            'is_current' => true,
        ]);

        // v1 = one consumed generation → durable ledger row (survives website
        // deletion; ContentEntitlements counts trial/monthly caps from it).
        // Best-effort: a ledger hiccup must never lose a produced article.
        if ($latest === 0) {
            try {
                ContentGeneration::create([
                    'user_id' => (string) $topic->website?->user_id,
                    'website_id' => (string) $topic->website_id,
                    'topic_id' => (string) $topic->id,
                    'created_at' => now(),
                ]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('content_generation_ledger_write_failed', [
                    'topic_id' => $topic->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        // Images follow the crown: every consumer (publish drivers' featured
        // image, WP sideload, review page) reads images off the CURRENT
        // version, so leaving the rows on the old one silently strips the
        // featured image from any article that gets a new version after
        // image generation — client edits, revise passes, brand scrubs
        // (cocomii 2026-08-21: Shopify articles published with no main image).
        ContentImage::query()
            ->whereIn('article_id', $topic->articles()->whereKeyNot($article->id)->select('id'))
            ->update(['article_id' => $article->id]);

        return $article;
    }
}
