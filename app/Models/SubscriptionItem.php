<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Laravel\Cashier\SubscriptionItem as CashierSubscriptionItem;

/**
 * `subscription_items` also uses a ULID primary key — same reason as
 * {@see Subscription}. Registered via Cashier::useSubscriptionItemModel().
 */
class SubscriptionItem extends CashierSubscriptionItem
{
    use HasUlids;
}
