<?php

namespace App\Services\Content;

use App\Models\User;

/**
 * Thin seam around Stripe Checkout-session retrieval so purchase
 * verification is testable without the network (bind a fake in tests).
 */
class StripeSessionReader
{
    /** @return object|null the Stripe session, null when unavailable */
    public function retrieve(User $user, string $sessionId): ?object
    {
        try {
            return $user->stripe()->checkout->sessions->retrieve($sessionId);
        } catch (\Throwable) {
            return null;
        }
    }
}
