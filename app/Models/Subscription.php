<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Laravel\Cashier\Subscription as CashierSubscription;

/**
 * The `subscriptions` table uses a ULID primary key (not auto-increment), so the
 * model MUST generate the id on create. Cashier's stock model doesn't, which
 * makes every subscription insert (webhook, syncSubscriptionsFromStripe,
 * newSubscription) fail with "Field 'id' doesn't have a default value".
 * Registered via Cashier::useSubscriptionModel() in AppServiceProvider.
 */
class Subscription extends CashierSubscription
{
    use HasUlids;
}
