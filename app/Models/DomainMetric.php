<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Accumulating per-domain intelligence (see the domain_metrics migration).
 * Rows are NEVER deleted on client churn — the asset compounds.
 */
class DomainMetric extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_seed' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'opr_refreshed_at' => 'datetime',
            'cc_refreshed_at' => 'datetime',
            'topic_classified_at' => 'datetime',
            'moz_refreshed_at' => 'datetime',
            'opr_score' => 'float',
        ];
    }

    public function history(): HasMany
    {
        return $this->hasMany(DomainMetricHistory::class);
    }
}
