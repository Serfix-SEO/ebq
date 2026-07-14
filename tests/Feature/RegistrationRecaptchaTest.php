<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RegistrationRecaptchaTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_registration_succeeds_without_recaptcha_when_keys_unset(): void
    {
        Config::set('services.recaptcha.site_key', '');
        Config::set('services.recaptcha.secret_key', '');

        $response = $this->post(route('register'), [
            'name' => 'Tester',
            'email' => 'nocaptcha@example.com',
            'dial_code' => '+1',
            'phone' => '5551234567',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect(route('verification.notice'));
        $this->assertDatabaseHas('users', ['email' => 'nocaptcha@example.com']);
    }

    public function test_email_registration_requires_response_when_recaptcha_configured(): void
    {
        Config::set('services.recaptcha.site_key', 'test-site-key');
        Config::set('services.recaptcha.secret_key', 'test-secret-key');

        $response = $this->from(route('register'))->post(route('register'), [
            'name' => 'Tester',
            'email' => 'needs-token@example.com',
            'dial_code' => '+1',
            'phone' => '5551234567',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect(route('register'));
        $response->assertSessionHasErrors('g-recaptcha-response');
        $this->assertDatabaseMissing('users', ['email' => 'needs-token@example.com']);
    }

    public function test_email_registration_succeeds_when_recaptcha_verifies(): void
    {
        Config::set('services.recaptcha.site_key', 'test-site-key');
        Config::set('services.recaptcha.secret_key', 'test-secret-key');

        Http::fake([
            'https://www.google.com/recaptcha/api/siteverify' => Http::response([
                'success' => true,
                'challenge_ts' => now()->toIso8601String(),
                'hostname' => 'localhost',
            ], 200),
        ]);

        $response = $this->post(route('register'), [
            'name' => 'Tester',
            'email' => 'verified-captcha@example.com',
            'dial_code' => '+1',
            'phone' => '5551234567',
            'password' => 'password',
            'password_confirmation' => 'password',
            'g-recaptcha-response' => 'test-token',
        ]);

        $response->assertRedirect(route('verification.notice'));
        $this->assertDatabaseHas('users', ['email' => 'verified-captcha@example.com']);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://www.google.com/recaptcha/api/siteverify'
                && $request['secret'] === 'test-secret-key'
                && $request['response'] === 'test-token';
        });
    }
}
