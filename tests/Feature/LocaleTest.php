<?php

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * i18n infra (2026-07-07): locale resolution (user column > cookie > sniff >
 * default), the popup-visibility flag, admin's hard exclusion, and dir=rtl
 * rendering on the shared layouts.
 */
class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_cookie_sets_locale_and_hides_picker(): void
    {
        $this->withCookie(SetLocale::COOKIE, 'ar')
            ->get('/')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertDontSee('Choose your language');
    }

    public function test_no_cookie_shows_picker_and_defaults_ltr(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('Choose your language');
    }

    public function test_locale_set_route_persists_cookie_and_user_column(): void
    {
        $user = User::factory()->create(['locale' => null]);

        $response = $this->actingAs($user)->get('/locale/ar');
        $response->assertRedirect();
        $response->assertCookie(SetLocale::COOKIE, 'ar');

        $this->assertSame('ar', $user->fresh()->locale);
    }

    public function test_invalid_locale_404s(): void
    {
        $this->get('/locale/fr')->assertNotFound();
    }

    public function test_admin_path_never_gets_rtl_even_with_ar_cookie(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'locale' => 'ar']);

        $this->withCookie(SetLocale::COOKIE, 'ar')
            ->actingAs($admin)
            ->get(route('admin.clients.index'))
            ->assertOk()
            ->assertSee('dir="ltr"', false);
    }

    public function test_authenticated_user_locale_column_wins_over_cookie(): void
    {
        $user = User::factory()->create(['locale' => 'ar']);

        // Stale 'en' cookie from a previous device/browser must lose to the
        // user's saved preference.
        $this->withCookie(SetLocale::COOKIE, 'en')
            ->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertSee('dir="rtl"', false);
    }
}
