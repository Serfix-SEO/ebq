<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Shared many-to-many link: which keywords a domain ranks for (DataForSEO Labs
 * ranked_keywords). One row per (domain, keyword_hash, country). Rows are NEVER
 * deleted on client churn — the asset compounds and is reused across plans/users.
 * See DATAFORSEO_KEYWORD_GAP_PLAN.md.
 */
class DomainKeywordRanking extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rank_absolute' => 'integer',
            'search_volume' => 'integer',
            'previous_rank' => 'integer',
            'etv' => 'float',
            'is_new' => 'boolean',
            'is_up' => 'boolean',
            'is_down' => 'boolean',
            'is_lost' => 'boolean',
            'fetched_at' => 'datetime',
        ];
    }
}
