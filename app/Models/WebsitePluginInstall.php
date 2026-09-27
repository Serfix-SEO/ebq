<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsitePluginInstall extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'website_id',
        'channel',
        'installed_version',
        'site_url',
        'last_seen_at',
        'auth_failing_since',
        'last_auth_failure_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'auth_failing_since' => 'datetime',
            'last_auth_failure_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
