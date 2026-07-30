<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A connected social account for auto-sharing published articles, one row per
 * website+provider. `credentials` is an ENCRYPTED array cast — tokens never
 * touch the DB in plaintext:
 *   facebook → {page_id, page_token, page_name}   (page tokens don't expire)
 *   x        → {access_token, refresh_token, expires_at, username}
 *
 * `display_name` duplicates the non-secret label (page name / @handle) so
 * listings never need to decrypt credentials.
 */
class ContentSocialAccount extends Model
{
    use HasUlids;

    public const PROVIDER_FACEBOOK = 'facebook';

    public const PROVIDER_X = 'x';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_ERROR = 'error';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'credentials' => AsEncryptedArrayObject::class,
            'share_enabled' => 'boolean',
            'last_posted_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function providerLabel(): string
    {
        return match ($this->provider) {
            self::PROVIDER_FACEBOOK => 'Facebook',
            self::PROVIDER_X => 'X',
            default => ucfirst((string) $this->provider),
        };
    }
}
