<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per referred account (referred_user_id is UNIQUE — a person can
 * only ever earn their referrer one reward). Deliberately no DB foreign
 * keys: reward/audit history must survive account deletion, so read paths
 * null-check the relations.
 *
 * Lifecycle: pending (signed up through a referral link)
 *   → qualified (their first FULL content base invoice was paid; transient)
 *   → credited  (referrer's Stripe balance credited — terminal)
 *   or credit_failed (Stripe call failed; swept by ebq:grant-referral-rewards).
 */
class Referral extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_QUALIFIED = 'qualified';

    public const STATUS_CREDITED = 'credited';

    public const STATUS_CREDIT_FAILED = 'credit_failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'qualified_at' => 'datetime',
            'credited_at' => 'datetime',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }
}
