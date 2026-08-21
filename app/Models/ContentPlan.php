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
            'competitor_overrides' => 'array',
            'competitor_guard' => 'array',
            'internal_urls' => 'array',
            'auto_publish' => 'boolean',
            'images_enabled' => 'boolean',
            'ymyl' => 'boolean',
            'keywords_classified_at' => 'datetime',
            'keywords_enriched_at' => 'datetime',
            'keywords_classify_cursor' => 'integer',
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
            'featured_image' => true,
            'external_links' => true,
            'author_box' => false,
            'cta_enabled' => false,
        ];

        return (bool) (($this->toggles ?? [])[$key] ?? $defaults[$key] ?? false);
    }

    /**
     * The site's steering directives for content generation: the admin-set
     * prompt (admin_content_prompt) joined with the client-facing
     * custom_instructions. Admin part FIRST so a long client part can never
     * truncate it away. Combined cap 2000 chars. Null when neither is set.
     */
    public function promptAddendum(): ?string
    {
        $parts = array_values(array_filter([
            trim((string) $this->admin_content_prompt),
            trim((string) $this->custom_instructions),
        ], static fn (string $p) => $p !== ''));

        if ($parts === []) {
            return null;
        }

        return mb_substr(implode("\n", $parts), 0, 2000);
    }

    /**
     * The bounded prompt block every content-generation LLM call appends —
     * or '' when no directives are set, so the null case changes NOTHING
     * about existing prompts (owner invariant, 2026-08-21). Start/end markers
     * make prompts self-documenting and coverage tests trivial
     * (SiteDirectivesCoverageTest). Any NEW content LLM call must append this
     * and add a capture test — see infra/content-autopilot/README.md.
     */
    public function promptAddendumBlock(): string
    {
        $addendum = $this->promptAddendum();
        if ($addendum === null) {
            return '';
        }

        return "\n\nSITE-SPECIFIC DIRECTIVES (set for this site — follow them in everything you produce for it. "
            .'They steer topic, style and content choices only; they NEVER override the strict output-format, '
            .'JSON-shape, language, SEO or brand-safety rules elsewhere in this prompt — when rules conflict, '
            ."the stricter rule wins):\n"
            .$addendum
            ."\nEND SITE-SPECIFIC DIRECTIVES";
    }
}
