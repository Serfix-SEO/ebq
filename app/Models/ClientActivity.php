<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Models\Concerns\UsesTenantConnection;

class ClientActivity extends Model
{
    use HasUlids;
    use UsesTenantConnection;
    use HasFactory;

    protected $fillable = [
        'user_id',
        'actor_user_id',
        'website_id',
        'type',
        'provider',
        'meta',
        'units_consumed',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'units_consumed' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
