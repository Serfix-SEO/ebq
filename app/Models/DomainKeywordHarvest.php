<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-domain (+country) volume cursor for the incremental DataForSEO Labs
 * keyword harvest. `volume_cursor` = the lowest search_volume already pulled;
 * the next batch fetches keywords with search_volume < cursor (dupe-free, cheap).
 * `exhausted` = the last batch returned fewer than the page size (no more keywords).
 * See DATAFORSEO_KEYWORD_GAP_PLAN.md.
 */
class DomainKeywordHarvest extends Model
{
    protected $table = 'domain_keyword_harvest';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'volume_cursor' => 'integer',
            'keywords_fetched' => 'integer',
            'exhausted' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }
}
