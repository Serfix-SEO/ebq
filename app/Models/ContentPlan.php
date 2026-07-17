<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Content Autopilot plan — one per website. Owns cadence, style toggles and
 * the business profile the writer grounds articles in.
 */
class ContentPlan extends Model
{
    use HasFactory;
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    // Wizard in progress: the plan exists so topic ideation can run in the
    // background while the user finishes setup, but no articles are written
    // until the user finishes the wizard and the plan flips to active.
    public const STATUS_DRAFT = 'draft';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'publish_days' => 'array',
            'toggles' => 'array',
            'offerings' => 'array',
            'internal_urls' => 'array',
            'auto_publish' => 'boolean',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function topics(): HasMany
    {
        return $this->hasMany(ContentTopic::class, 'plan_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** A style toggle with its default (all default ON except author_box). */
    public function toggle(string $key): bool
    {
        $defaults = [
            'toc' => true,
            'key_takeaways' => true,
            'faq' => true,
            'external_links' => true,
            'author_box' => false,
            'cta_enabled' => false,
        ];

        return (bool) (($this->toggles ?? [])[$key] ?? $defaults[$key] ?? false);
    }
}
