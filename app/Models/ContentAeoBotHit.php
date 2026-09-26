<?php

namespace App\Models;

use App\Support\Aeo\AiAgents;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "GPTBot fetched 42 of your pages last Tuesday" — one row per site, bot and
 * day, reported by code running on the client's own site.
 *
 * This is the only first-party signal in the whole AI-visibility feature: it is
 * not inferred, sampled or modelled, it is the client's own server saying what
 * asked for a page. It exists only for sites running our WordPress plugin or
 * our PHP kit; everywhere else the absence of rows means "not instrumented",
 * never "no AI crawlers came".
 */
class ContentAeoBotHit extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $casts = [
        'hit_on' => 'date',
        'hits' => 'int',
        'pages' => 'int',
    ];

    public const REPORTER_PLUGIN = 'plugin';

    public const REPORTER_KIT = 'kit';

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function botLabel(): string
    {
        return AiAgents::label($this->bot);
    }
}
