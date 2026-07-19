<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Cashier;
use Tests\TestCase;

class SubscriptionUlidTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_uses_the_ulid_subscription_models(): void
    {
        $this->assertSame(Subscription::class, Cashier::$subscriptionModel);
        $this->assertSame(\App\Models\SubscriptionItem::class, Cashier::$subscriptionItemModel);
    }

    public function test_creating_a_subscription_generates_a_ulid_id(): void
    {
        $user = User::factory()->create(['stripe_id' => 'cus_test']);

        // Mirrors the webhook / syncSubscriptionsFromStripe create path that
        // failed with "Field 'id' doesn't have a default value".
        $sub = $user->subscriptions()->create([
            'type' => 'content',
            'stripe_id' => 'sub_test',
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);

        $this->assertInstanceOf(Subscription::class, $sub);
        $this->assertNotEmpty($sub->id);
        $this->assertFalse($sub->getIncrementing());
        $this->assertSame('string', $sub->getKeyType());
    }
}
