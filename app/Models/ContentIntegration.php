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

    public const PLATFORM_WEBFLOW = 'webflow';

    public const PLATFORM_WIX = 'wix';

    public const PLATFORM_SANITY = 'sanity';

    public const PLATFORM_HUBSPOT = 'hubspot';

    public const PLATFORM_WEBHOOK = 'webhook';

    public const PLATFORM_MEDUSA = 'medusa';

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
            self::PLATFORM_WEBFLOW => 'Webflow',
            self::PLATFORM_WIX => 'Wix',
            self::PLATFORM_SANITY => 'Sanity',
            self::PLATFORM_HUBSPOT => 'HubSpot',
            self::PLATFORM_MEDUSA => 'Medusa',
            self::PLATFORM_WEBHOOK => __('Custom integration'),
            default => ucfirst((string) $this->platform),
        };
    }

    /**
     * Where this integration publishes, as label => value pairs safe to render
     * in an admin screen.
     *
     * SECRETS ARE NEVER INCLUDED. `credentials` is an encrypted cast holding
     * tokens, API keys, app passwords and webhook signing secrets; only the
     * addressing parts (site URL, endpoint, store/project/site identifiers)
     * are surfaced, because support needs to answer "where did this article
     * go?" without ever reading a credential. Anything added to a driver's
     * credentials later is opt-in here — do not switch this to a blanket dump.
     *
     * @return array<string, string>
     */
    public function targetSummary(): array
    {
        $creds = $this->credentials;
        $config = (array) ($this->config ?? []);
        $get = fn (string $key) => trim((string) ($creds[$key] ?? ''));
        $cfg = fn (string $key) => trim((string) ($config[$key] ?? ''));

        $out = match ($this->platform) {
            self::PLATFORM_WORDPRESS, self::PLATFORM_WORDPRESS_APP_PASSWORD => [
                __('Site URL') => $get('site_url'),
            ],
            self::PLATFORM_WEBHOOK => [
                __('Endpoint') => $get('endpoint_url'),
                __('Signed') => $get('secret') !== '' ? __('Yes') : __('No'),
            ],
            self::PLATFORM_SHOPIFY => [
                __('Store') => $cfg('shop_url') ?: $get('store_domain'),
                __('Blog') => $cfg('blog_handle'),
            ],
            self::PLATFORM_WEBFLOW => [
                __('Site') => $cfg('site_domain'),
                __('Collection') => $cfg('collection_slug'),
            ],
            self::PLATFORM_WIX => [
                __('Site ID') => $get('site_id'),
                __('Author ID') => $cfg('member_id'),
            ],
            self::PLATFORM_SANITY => [
                __('Project') => $get('project_id'),
                __('Dataset') => $cfg('dataset'),
                __('Document type') => $cfg('doc_type'),
            ],
            self::PLATFORM_HUBSPOT => [
                __('Blog URL') => $cfg('blog_url'),
                __('Blog ID') => $cfg('content_group_id'),
            ],
            self::PLATFORM_MEDUSA => [
                __('Medusa server') => $get('base_url'),
                __('Signed') => $get('secret') !== '' ? __('Yes') : __('No'),
            ],
            default => [],
        };

        if (($status = $cfg('post_status')) !== '') {
            $out[__('Publishes as')] = $status;
        }

        return array_filter($out, fn ($v) => $v !== '');
    }
}
