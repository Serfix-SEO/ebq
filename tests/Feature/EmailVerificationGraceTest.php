<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureEmailVerifiedAfterGrace;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class EmailVerificationGraceTest extends TestCase
{
    private function pass(User $user): ?Response
    {
        $request = Request::create('/dashboard');
        $request->setUserResolver(fn () => $user);

        $result = (new EnsureEmailVerifiedAfterGrace)->handle(
            $request,
            fn () => new Response('ok'),
        );

        return $result instanceof Response ? $result : null;
    }

    private function isForcedToVerify(User $user): bool
    {
        $request = Request::create('/dashboard');
        $request->setUserResolver(fn () => $user);

        $result = (new EnsureEmailVerifiedAfterGrace)->handle(
            $request,
            fn () => new Response('ok'),
        );

        return $result instanceof RedirectResponse
            && str_contains($result->getTargetUrl(), 'verify-email');
    }

    public function test_unverified_user_inside_grace_window_passes(): void
    {
        Config::set('auth.verification.grace_days', 3);

        $user = new User;
        $user->created_at = now()->subDay();       // day 1 — within 3-day grace
        $user->email_verified_at = null;

        $this->assertNotNull($this->pass($user));
        $this->assertFalse($this->isForcedToVerify($user));
    }

    public function test_unverified_user_past_grace_window_is_forced_to_verify(): void
    {
        Config::set('auth.verification.grace_days', 3);

        $user = new User;
        $user->created_at = now()->subDays(4);     // past the 3-day grace
        $user->email_verified_at = null;

        $this->assertTrue($this->isForcedToVerify($user));
    }

    public function test_verified_user_always_passes(): void
    {
        Config::set('auth.verification.grace_days', 3);

        $user = new User;
        $user->created_at = now()->subDays(30);
        $user->email_verified_at = now();

        $this->assertFalse($this->isForcedToVerify($user));
        $this->assertNotNull($this->pass($user));
    }

    public function test_zero_grace_days_enforces_immediately(): void
    {
        Config::set('auth.verification.grace_days', 0);

        $user = new User;
        $user->created_at = now();                 // brand new
        $user->email_verified_at = null;

        $this->assertTrue($this->isForcedToVerify($user));
    }
}
