<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Models\Concerns\UsesTenantConnection;

/**
 * @property string $id
 * @property string $website_id
 * @property int $samples_count
 * @property array<string, mixed>|null $fingerprint
 * @property string|null $sample_excerpt
 * @property \Illuminate\Support\Carbon|null $last_extracted_at
 */
class BrandVoiceProfile extends Model
{
    use HasUlids;
    use UsesTenantConnection;
    protected $fillable = [
        'website_id',
        'samples_count',
        'fingerprint',
        'sample_excerpt',
        'last_extracted_at',
    ];

    protected function casts(): array
    {
        return [
            'fingerprint' => 'array',
            'last_extracted_at' => 'datetime',
            'samples_count' => 'integer',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
