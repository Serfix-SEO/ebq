<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Microsoft OAuth tokens. Mirrors `GoogleAccount` so the resolver and
 * refresh paths can stay symmetric — the only practical difference is
 * the `email` column, cached at connect time so the settings UI doesn't
 * make a Graph call every render.
 */
class MicrosoftAccount extends Model
{
    protected $fillable = [
        'user_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'microsoft_id',
        'email',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
