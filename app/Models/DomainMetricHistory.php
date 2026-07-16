<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only metric snapshots — one row per (domain, source, day). */
class DomainMetricHistory extends Model
{
    public $timestamps = false;

    protected $table = 'domain_metric_history';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'captured_on' => 'date',
            'value' => 'float',
        ];
    }

    public function domainMetric(): BelongsTo
    {
        return $this->belongsTo(DomainMetric::class);
    }
}
