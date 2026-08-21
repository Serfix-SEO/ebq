<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable per-user generation ledger — one row per v1 article, written by
 * ContentArticle::storeVersion. Deliberately NO relations/foreign keys: the
 * ledger exists to survive website deletion, which used to cascade away all
 * usage evidence and reset the trial/monthly caps (delete-site + re-add
 * loophole, 2026-08-21). ContentEntitlements counts caps from here.
 */
class ContentGeneration extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = [];
}
