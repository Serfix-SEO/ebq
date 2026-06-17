<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Models\Concerns\UsesTenantConnection;

/**
 * One keyword in a gap analysis, bucketed and enriched.
 *
 * @property int $keyword_gap_analysis_id
 * @property string $keyword
 * @property string $keyword_hash
 * @property string $bucket
 * @property ?int $search_volume
 * @property ?float $competition
 * @property ?float $cpc
 * @property ?float $our_position
 * @property ?array $competitor_domains
 * @property ?int $opportunity_score
 * @property ?array $score_components
 */
class KeywordGapRow extends Model
{
    use HasUlids;
    use UsesTenantConnection;
    protected $fillable = [
        'keyword_gap_analysis_id',
        'keyword',
        'keyword_hash',
        'bucket',
        'search_volume',
        'competition',
        'cpc',
        'our_position',
        'competitor_position',
        'competitor_domains',
        'opportunity_score',
        'score_components',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'search_volume' => 'integer',
            'competition' => 'float',
            'cpc' => 'float',
            'our_position' => 'float',
            'competitor_position' => 'integer',
            'competitor_domains' => 'array',
            'opportunity_score' => 'integer',
            'score_components' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(KeywordGapAnalysis::class, 'keyword_gap_analysis_id');
    }
}
