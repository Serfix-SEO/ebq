<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A WordPress install that keeps being turned away at the door.
 *
 * Keyed by host rather than website, because the installs worth chasing are
 * usually the ones whose website we no longer have: the plugin keeps calling
 * for months against an account that was deleted. `website_id` is a
 * nice-to-have that nulls out if the site is later removed.
 */
class PluginAuthFailure extends Model
{
    use HasUlids;

    protected $guarded = [];

    /** No such token — usually cascaded away when the website was deleted. */
    public const REASON_TOKEN_REJECTED = 'token_rejected';

    /** The token exists but its website does not (Sanctum's tokenable is gone). */
    public const REASON_WEBSITE_MISSING = 'website_missing';

    protected function casts(): array
    {
        return [
            'failures' => 'int',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /** Whole days this install has been failing — the number that makes it obvious. */
    public function failingDays(): int
    {
        return max(1, (int) $this->first_seen_at->diffInDays(now()));
    }

    /** True when nobody on our side owns this site any more. */
    public function isOrphan(): bool
    {
        return $this->website_id === null;
    }
}
