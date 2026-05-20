<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $external_id
 * @property int $website_id
 * @property int|null $user_id
 * @property string $title
 * @property string $focus_keyword
 * @property list<string>|null $additional_keywords
 * @property string $step
 * @property array<string, mixed>|null $brief
 * @property list<array<string, mixed>>|null $chat_history
 * @property list<array<string, mixed>>|null $images
 * @property string|null $generated_html
 * @property int|null $wp_post_id
 * @property int $credits_used
 */
class WriterProject extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STEP_TOPIC     = 'topic';
    public const STEP_BRIEF     = 'brief';
    public const STEP_STRATEGY  = 'strategy';
    public const STEP_IMAGES    = 'images';
    public const STEP_SUMMARY   = 'summary';
    public const STEP_COMPLETED = 'completed';

    public const STEPS = [
        self::STEP_TOPIC,
        self::STEP_BRIEF,
        self::STEP_STRATEGY,
        self::STEP_IMAGES,
        self::STEP_SUMMARY,
        self::STEP_COMPLETED,
    ];

    protected $fillable = [
        'external_id',
        'website_id',
        'user_id',
        'title',
        'focus_keyword',
        'additional_keywords',
        'lsi_keywords',
        'country',
        'language',
        'tone',
        'audience',
        'custom_prompt',
        'step',
        'brief',
        'chat_history',
        'images',
        'seo_titles',
        'meta_title',
        'meta_description',
        'meta_descriptions',
        'og_title',
        'og_description',
        'faqs',
        'keyword_suggestions',
        'link_suggestions',
        'selected_links',
        'generated_html',
        'wp_post_id',
        'credits_used',
    ];

    protected function casts(): array
    {
        return [
            'additional_keywords' => 'array',
            'lsi_keywords' => 'array',
            'brief' => 'array',
            'chat_history' => 'array',
            'images' => 'array',
            'seo_titles' => 'array',
            'meta_descriptions' => 'array',
            'faqs' => 'array',
            'keyword_suggestions' => 'array',
            'link_suggestions' => 'array',
            'selected_links' => 'array',
            'wp_post_id' => 'integer',
            'credits_used' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WriterProject $project): void {
            if (! $project->external_id) {
                $project->external_id = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'external_id';
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
