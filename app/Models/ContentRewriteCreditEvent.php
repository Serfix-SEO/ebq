<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable rewrite-credit ledger (ContentGeneration pattern): the row sums
 * ARE the balance — no cached counters to drift. No FKs; survives deletions.
 */
class ContentRewriteCreditEvent extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    public const KIND_PURCHASE = 'purchase';

    public const KIND_SPEND = 'spend';

    public const KIND_REFUND = 'refund';

    public const KIND_ADMIN_GRANT = 'admin_grant';

    public const SOURCE_FREE = 'free';

    public const SOURCE_PURCHASED = 'purchased';

    protected $guarded = [];
}
