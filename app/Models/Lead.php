<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * A marketing lead captured when a guest supplies their name + email on the
 * second free landing-page audit. Tagged "converted" once a user account is
 * created with the same email — see {@see markConvertedFor()}, fired from the
 * User model's created event.
 */
class Lead extends Model
{
    use HasUlids;
    public const SOURCE_GUEST_AUDIT = 'guest_audit';

    public const SOURCE_GUEST_RANK = 'guest_rank_tracker';

    public const SOURCE_GUEST_VOLUME = 'guest_keyword_volume';

    public const SOURCE_GUEST_PAGESPEED = 'guest_pagespeed';

    protected $fillable = [
        'email',
        'name',
        'source',
        'guest_page_audit_id',
        'user_id',
        'converted_at',
    ];

    protected $casts = [
        'converted_at' => 'datetime',
    ];

    /**
     * Record (or update) a lead by email. If an account already exists for the
     * email, the lead is marked converted immediately.
     */
    public static function capture(string $email, ?string $name = null, ?string $guestPageAuditId = null, string $source = self::SOURCE_GUEST_AUDIT): self
    {
        $email = Str::lower(trim($email));
        $name = $name !== null && trim($name) !== '' ? mb_substr(trim($name), 0, 255) : null;

        $lead = self::query()->firstOrNew(['email' => $email]);
        $lead->name = $name ?? $lead->name; // never blank out an existing name
        $lead->source = $lead->source ?: $source;
        if ($guestPageAuditId !== null && $lead->guest_page_audit_id === null) {
            $lead->guest_page_audit_id = $guestPageAuditId;
        }

        // Already a customer? Tag converted on capture.
        if ($lead->converted_at === null) {
            $existing = User::query()->where('email', $email)->first();
            if ($existing !== null) {
                $lead->user_id = $existing->id;
                $lead->converted_at = now();
            }
        }

        $lead->save();

        return $lead;
    }

    /**
     * Tag any matching lead as converted when a user signs up. Fired from
     * User::created so it covers both password registration and Google SSO.
     */
    public static function markConvertedFor(User $user): void
    {
        $email = Str::lower(trim((string) $user->email));
        if ($email === '') {
            return;
        }

        self::query()
            ->where('email', $email)
            ->whereNull('converted_at')
            ->update([
                'user_id' => $user->id,
                'converted_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function isConverted(): bool
    {
        return $this->converted_at !== null;
    }

    public function scopeConverted(Builder $q): Builder
    {
        return $q->whereNotNull('converted_at');
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->whereNull('converted_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function guestPageAudit(): BelongsTo
    {
        return $this->belongsTo(GuestPageAudit::class);
    }
}
