<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A keyword classified for one content plan as `own` (client already ranks) or
 * `gap` (relevant competitor keyword the client doesn't rank for). Written once
 * by {@see \App\Jobs\Content\ClassifyPlanKeywordsJob}; read by the step-6 gap card
 * and the topic planner (so relevance isn't re-computed). See
 * DATAFORSEO_KEYWORD_GAP_PLAN.md.
 */
class ContentPlanKeyword extends Model
{
    public const TYPE_OWN = 'own';

    public const TYPE_GAP = 'gap';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'search_volume' => 'integer',
            'competition' => 'float',
            'keyword_difficulty' => 'integer',
        ];
    }
}
