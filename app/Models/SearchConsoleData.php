<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Models\Concerns\UsesTenantConnection;

class SearchConsoleData extends Model
{
    use HasUlids;
    use UsesTenantConnection;
    protected $fillable = [
        'website_id',
        'date',
        'query',
        'page',
        'clicks',
        'impressions',
        'position',
        'country',
        'device',
        'ctr',
        'keyword_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'position' => 'float',
            'ctr' => 'float',
        ];
    }

    /**
     * Store `date` as a plain Y-m-d string. The default 'date' cast writes
     * 'Y-m-d H:i:s'; MySQL's DATE column truncates that server-side, but
     * sqlite (the test DB) keeps the full string, so string-comparison
     * window queries (whereBetween on Y-m-d bounds) silently miss the rows.
     */
    public function setDateAttribute(mixed $value): void
    {
        $this->attributes['date'] = $value === null
            ? null
            : \Illuminate\Support\Carbon::parse($value)->toDateString();
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function scopeForDateRange(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->whereDate('date', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('date', '<=', $to));
    }
}
