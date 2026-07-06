<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * Branding overlay applied to outbound report emails + PDFs when the
 * tenant's plan has `report_whitelabel` enabled.
 *
 * Two ownership modes per row, enforced by uniqueness:
 *   - user-default:  user_id set,    website_id null
 *   - website-override: user_id null, website_id set
 *
 * Resolution order lives in {@see \App\Services\Reports\ReportBrandingResolver}.
 */
class ReportBranding extends Model
{
    use HasUlids;
    protected $fillable = [
        'user_id',
        'website_id',
        'company_name',
        'logo_path',
        'accent_color',
        'footer_text',
        'contact_email',
        'contact_phone',
        'contact_address',
        'reply_to_email',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * Public URL for the uploaded logo, or null when no logo is set.
     * Uses the `public` disk so the URL is permanent and CDN-cacheable.
     */
    public function logoUrl(): ?string
    {
        if ($this->logo_path === null || $this->logo_path === '') {
            return null;
        }
        return Storage::disk('public')->url($this->logo_path);
    }

    /**
     * The in-memory "fall back to EBQ" branding object returned when the
     * tenant's plan disables `report_whitelabel` or no row exists. Kept
     * here next to the real model so the template only ever has to deal
     * with one shape.
     *
     * Not persisted; `id` is null. Callers must not save() this row.
     */
    public static function ebqDefault(): self
    {
        $branding = new self([
            'company_name'    => 'Serfix',
            'logo_path'       => null,
            'accent_color'    => '#F26419',
            'footer_text'     => 'Sent by Serfix on behalf of your workspace. Manage report settings in Serfix → Settings.',
            'contact_email'   => null,
            'contact_phone'   => null,
            'contact_address' => null,
            'reply_to_email'  => null,
        ]);
        $branding->exists = false;

        return $branding;
    }

    /**
     * True when this row is the in-memory EBQ default (not persisted).
     * Templates use this to decide whether to render the contact block.
     */
    public function isDefault(): bool
    {
        return ! $this->exists;
    }
}
