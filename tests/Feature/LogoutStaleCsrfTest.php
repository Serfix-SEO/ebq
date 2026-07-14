<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A logout POST with a stale/mismatched CSRF token (session left open past
 * SESSION_LIFETIME, or the token rotated in another tab) used to dead-end on
 * the raw 419 page — confusing on the one action a user reaches for
 * specifically because they're done with the session. See bootstrap/app.php's
 * 419 HttpException render callback.
 */
class LogoutStaleCsrfTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_with_a_stale_csrf_token_redirects_instead_of_a_bare_419(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->withSession(['_token' => 'real-token-xyz'])
            ->post(route('logout'), ['_token' => 'wrong-token-abc']);

        $response->assertRedirect();
        $this->assertNotSame(419, $response->getStatusCode());
    }
}
