<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AI-readiness check of a website: which AI agents robots.txt lets in,
 * whether an llms.txt is live, what structured data the crawl found, and the
 * score built from those.
 *
 * Kept as history rather than overwritten — a client who unblocks GPTBot should
 * be able to see when that changed, and a run that could not fetch robots.txt
 * must not be able to erase the last answer we trusted.
 */
class ContentAeoAudit extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $casts = [
        'checked_at' => 'datetime',
        'robots_ai' => 'array',
        'robots_fetched' => 'bool',
        'llms_txt_present' => 'bool',
        'schema_coverage' => 'array',
        'readiness_score' => 'int',
        'breakdown' => 'array',
    ];

    /** robots.txt verdicts. UNKNOWN is never presented as ALLOWED. */
    public const ALLOWED = 'allowed';

    public const BLOCKED = 'blocked';

    public const UNKNOWN = 'unknown';

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /** @return list<string> agent keys this site blocks outright */
    public function blockedAgents(): array
    {
        return array_keys(array_filter(
            (array) $this->robots_ai,
            static fn ($verdict): bool => $verdict === self::BLOCKED
        ));
    }
}
