<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_shows_forgot_password_link(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee(__('Forgot your password?'))
            ->assertSee(route('password.request'));
    }

    public function test_forgot_password_screen_renders(): void
    {
        $this->get(route('password.request'))->assertOk();
    }

    /**
     * The tool-gate / report-teaser modal is a full login surface, so it needs
     * the recovery path too — it shipped without one until 2026-07-29.
     */
    public function test_auth_modal_signin_offers_the_reset_link(): void
    {
        // Rendered through a real request: the modal's partials rely on the
        // session-shared $errors bag that a bare view()->render() lacks.
        $this->get(route('tools.audit'))
            ->assertOk()
            ->assertSee(route('password.request'))
            ->assertSee('Forgot your password?');
    }

    public function test_reset_link_can_be_requested(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->get(route('password.reset', $notification->token))->assertOk();

            $response = $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'NewStr0ngPass!23',
                'password_confirmation' => 'NewStr0ngPass!23',
            ]);

            $response->assertRedirect(route('login'))->assertSessionHasNoErrors();
            $this->assertTrue(Hash::check('NewStr0ngPass!23', $user->fresh()->password));

            return true;
        });
    }
}
