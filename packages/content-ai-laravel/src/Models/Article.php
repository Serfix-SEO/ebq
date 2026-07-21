<?php

namespace Serfix\ContentAi\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An article delivered by Serfix Content AI.
 *
 * `html` is stored post-localisation: image src values already point at YOUR
 * disk, so rendering never reaches out to a third-party bucket.
 *
 * @property string $slug
 * @property string $title
 * @property string $html
 * @property string $status
 * @property ?Carbon $published_at
 */
class Article extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_UNPUBLISHED = 'unpublished';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'secondary_keywords' => 'array',
            'payload' => 'array',
            'published_at' => 'datetime',
            'locally_edited_at' => 'datetime',
            'word_count' => 'integer',
        ];
    }

    public function getTable(): string
    {
        return config('content-ai.table_prefix', 'content_ai_').'articles';
    }

    /** Pretty URLs: /blog/{slug}, never /blog/{id}. */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function images(): HasMany
    {
        return $this->hasMany(config('content-ai.models.image', ArticleImage::class));
    }

    // ── scopes ──────────────────────────────────────────────────────────

    /** Live articles only — excludes drafts AND future-dated ones. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED)
            ->where(function (Builder $q) {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            });
    }

    public function scopeInLanguage(Builder $query, string $language): Builder
    {
        return $query->where('language', $language);
    }

    // ── derived ─────────────────────────────────────────────────────────

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && ($this->published_at === null || $this->published_at->isPast());
    }

    public function url(): string
    {
        if (! config('content-ai.route.enabled', true)) {
            return (string) ($this->canonical_url ?? '');
        }

        return route(config('content-ai.route.name_prefix', 'content-ai.').'show', $this);
    }

    /** Whole minutes, floored at 1 — "0 min read" helps nobody. */
    public function readingMinutes(): int
    {
        $wpm = max(1, (int) config('content-ai.content.reading_words_per_minute', 220));
        $words = $this->word_count > 0
            ? $this->word_count
            : str_word_count(strip_tags((string) $this->html));

        return max(1, (int) ceil($words / $wpm));
    }

    public function featuredImage(): ?ArticleImage
    {
        return $this->images->firstWhere('role', 'featured') ?? $this->images->first();
    }

    /**
     * The excerpt, or a trimmed lead paragraph when Serfix sent none.
     * Meta description is preferred — it is written for exactly this job.
     */
    public function summary(int $chars = 160): string
    {
        $candidate = $this->excerpt ?: $this->meta_description;
        if (! $candidate) {
            $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $this->html)) ?? '');
            $candidate = $text;
        }

        return Str::limit(trim((string) $candidate), $chars);
    }
}
