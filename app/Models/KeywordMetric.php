<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * @property string $keyword
 * @property string $keyword_hash
 * @property string $country
 * @property string $data_source
 * @property ?int $search_volume
 * @property ?float $cpc
 * @property ?float $low_top_of_page_bid
 * @property ?float $high_top_of_page_bid
 * @property ?string $currency
 * @property ?float $competition
 * @property ?array $trend_12m
 * @property \Illuminate\Support\Carbon $fetched_at
 * @property \Illuminate\Support\Carbon $expires_at
 */
class KeywordMetric extends Model
{
    use HasUlids;
    protected $fillable = [
        'keyword',
        'keyword_hash',
        'country',
        'data_source',
        'search_volume',
        'cpc',
        'low_top_of_page_bid',
        'high_top_of_page_bid',
        'currency',
        'competition',
        'keyword_difficulty',
        'search_intent',
        'trend_12m',
        'fetched_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'search_volume' => 'integer',
            'cpc' => 'float',
            'low_top_of_page_bid' => 'float',
            'high_top_of_page_bid' => 'float',
            'competition' => 'float',
            'keyword_difficulty' => 'integer',
            'trend_12m' => 'array',
            'fetched_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public static function hashKeyword(string $keyword): string
    {
        return hash('sha256', mb_strtolower(trim($keyword)));
    }

    public function scopeFresh(Builder $q): Builder
    {
        return $q->where('expires_at', '>', now());
    }

    public function scopeStale(Builder $q): Builder
    {
        return $q->where('expires_at', '<=', now());
    }

    public function isFresh(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isFuture();
    }

    /**
     * Computed trend classification. Cached by Eloquent's accessor cache so
     * calling it repeatedly on the same row is essentially free.
     */
    public function getTrendClassAttribute(): string
    {
        return \App\Services\KeywordValueCalculator::trendClassify($this->trend_12m);
    }

    public function getNextPeakMonthAttribute(): ?int
    {
        return \App\Services\KeywordValueCalculator::nextPeakMonth($this->trend_12m);
    }
}
