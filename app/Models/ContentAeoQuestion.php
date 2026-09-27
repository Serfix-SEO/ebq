<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A question we ask the models on a client's behalf — "best oud perfume in
 * Dubai", "how do I choose a facility management company".
 *
 * The row count per website IS the quota, the same design the keyword tracker
 * uses: no Redis meter, nothing to drift out of sync, and a client who wants a
 * new question deletes one.
 */
class ContentAeoQuestion extends Model
{
    use HasUlids;

    protected $guarded = [];

    public const SOURCE_BRAND = 'brand';

    public const SOURCE_ARTICLE = 'article';

    public const SOURCE_GSC = 'gsc';

    public const SOURCE_CLIENT = 'client';

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
            'last_run_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ContentAeoRun::class, 'question_id');
    }

    /** Dedupe key: same question typed two ways is still one question. */
    public static function normalize(string $question): string
    {
        $q = Str::lower(trim($question));
        $q = preg_replace('/[^\p{L}\p{N}\s]/u', '', $q) ?? $q;

        return trim(preg_replace('/\s+/u', ' ', $q) ?? $q);
    }
}
