<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Models\Concerns\UsesTenantConnection;

/**
 * One auto-discovered competitor domain for a website, ranked by `score`.
 * Produced + pruned by {@see \App\Services\Competitive\CompetitorDiscoveryService}.
 *
 * @property string $website_id
 * @property string $competitor_domain
 * @property int $appearances
 * @property int $keywords_sampled
 * @property ?float $avg_position
 * @property ?int $best_position
 * @property float $score
 * @property ?int $domain_authority
 * @property ?array $sample_keywords
 * @property string $run_id
 */
class DiscoveredCompetitor extends Model
{
    use HasUlids;
    use UsesTenantConnection;
    protected $fillable = [
        'website_id',
        'competitor_domain',
        'appearances',
        'keywords_sampled',
        'avg_position',
        'best_position',
        'score',
        'domain_authority',
        'sample_keywords',
        'run_id',
        'last_refreshed_at',
    ];

    protected function casts(): array
    {
        return [
            'appearances' => 'integer',
            'keywords_sampled' => 'integer',
            'avg_position' => 'float',
            'best_position' => 'integer',
            'score' => 'float',
            'domain_authority' => 'integer',
            'sample_keywords' => 'array',
            'last_refreshed_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function scopeForWebsite(Builder $q, string $websiteId): Builder
    {
        return $q->where('website_id', $websiteId);
    }
}
