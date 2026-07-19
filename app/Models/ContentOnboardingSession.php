<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Anonymous content-onboarding run: a provisional Website (owned by the system
 * content-leads user) + wizard progress, keyed by a token held in the visitor's
 * session. Re-parented to a real user on signup; GC'd if never converted.
 */
class ContentOnboardingSession extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['converted_at' => 'datetime'];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
