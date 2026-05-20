<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-tenant outbound mail transport.
 *
 * `provider` selects which subset of columns is meaningful:
 *   - 'gmail'   → oauth_account_id points to a `google_accounts` row
 *   - 'outlook' → oauth_account_id points to a `microsoft_accounts` row
 *   - 'smtp'    → smtp_host / smtp_port / smtp_username / smtp_password /
 *                 smtp_encryption are used; oauth_account_id is null
 *
 * Resolution mirrors {@see ReportBranding}: per-website override wins,
 * else the user's default, else null (use the global Laravel mailer).
 */
class MailTransport extends Model
{
    public const PROVIDER_GMAIL = 'gmail';
    public const PROVIDER_OUTLOOK = 'outlook';
    public const PROVIDER_SMTP = 'smtp';

    protected $fillable = [
        'user_id',
        'website_id',
        'provider',
        'display_name',
        'from_address',
        'oauth_account_id',
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'smtp_encryption',
        'last_verified_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            // Decrypted transparently when read; encrypted when written.
            'smtp_password' => 'encrypted',
            'last_verified_at' => 'datetime',
            'smtp_port' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * Load the OAuth account row pointed to by `oauth_account_id`. Not
     * a real Eloquent relation because the target table depends on the
     * `provider` column. Returns null for SMTP transports.
     */
    public function oauthAccount(): GoogleAccount|MicrosoftAccount|null
    {
        if ($this->oauth_account_id === null) {
            return null;
        }
        return match ($this->provider) {
            self::PROVIDER_GMAIL => GoogleAccount::find($this->oauth_account_id),
            self::PROVIDER_OUTLOOK => MicrosoftAccount::find($this->oauth_account_id),
            default => null,
        };
    }

    public function isOAuth(): bool
    {
        return in_array($this->provider, [self::PROVIDER_GMAIL, self::PROVIDER_OUTLOOK], true);
    }
}
