<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Publish credentials per website+platform. `credentials` is an ENCRYPTED
 * array cast — plaintext never touches the DB (WP publish secrets, Shopify
 * Admin API tokens, webhook signing secrets, app passwords).
 */
class ContentIntegration extends Model
{
    use HasFactory;
    use HasUlids;

    public const PLATFORM_WORDPRESS = 'wordpress';

    public const PLATFORM_WORDPRESS_APP_PASSWORD = 'wordpress_app_password';

    public const PLATFORM_SHOPIFY = 'shopify';

    public const PLATFORM_WEBHOOK = 'webhook';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_ERROR = 'error';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'credentials' => AsEncryptedArrayObject::class,
            'config' => 'array',
            'last_verified_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }

    /** Client-facing platform name (both WordPress connection types read "WordPress"). */
    public function platformLabel(): string
    {
        return match ($this->platform) {
            self::PLATFORM_WORDPRESS, self::PLATFORM_WORDPRESS_APP_PASSWORD => 'WordPress',
            self::PLATFORM_SHOPIFY => 'Shopify',
            self::PLATFORM_WEBHOOK => __('Custom integration'),
            default => ucfirst((string) $this->platform),
        };
    }
}
