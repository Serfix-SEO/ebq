<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Models\Concerns\UsesTenantConnection;

class AiInsight extends Model
{
    use HasUlids;
    use UsesTenantConnection;
    protected $fillable = ['website_id', 'date', 'page', 'payload'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'payload' => 'array',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
