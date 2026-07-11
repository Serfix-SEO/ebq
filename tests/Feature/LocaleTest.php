<?php

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
use App\Models\User;
use App\Support\LocaleConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * i18n infra (2026-07-07): locale resolution (user column > cookie > sniff >
 * default), the popup-visibility flag, admin's hard exclusion, and dir=rtl
 * rendering on the shared layouts. Since 2026-07-09 all of it sits behind the
 * admin multilingual kill switch (default OFF) — most tests enable it first.
 */
class LocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        LocaleConfig::setMultilingualEnabled(true);
    }

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

    public function test_multilingual_off_forces_english_and_hides_picker(): void
    {
        LocaleConfig::setMultilingualEnabled(false);

        // Even a saved ar preference (user column AND cookie) renders LTR
        // English, and the first-visit picker never shows.
        $user = User::factory()->create(['locale' => 'ar']);

        $this->withCookie(SetLocale::COOKIE, 'ar')
            ->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertDontSee('Choose your language');

        // Fresh visitor: no picker either.
        $this->flushSession();
        $this->get('/')
            ->assertOk()
            ->assertDontSee('Choose your language');
    }

    public function test_multilingual_off_blocks_locale_switch_but_keeps_saved_choice(): void
    {
        LocaleConfig::setMultilingualEnabled(false);

        $user = User::factory()->create(['locale' => 'ar']);

        $this->actingAs($user)->get('/locale/ar')->assertNotFound();

        // The stored preference is untouched — re-enabling restores it.
        $this->assertSame('ar', $user->fresh()->locale);
    }

    public function test_multilingual_off_still_fully_active_for_admin(): void
    {
        LocaleConfig::setMultilingualEnabled(false);

        // Admin's saved ar preference renders RTL Arabic on the customer
        // site even while the platform is English-only for everyone else,
        // and they can still use the /locale switcher to flip languages.
        $admin = User::factory()->create([
            'is_admin' => true,
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/')
            ->assertOk()
            ->assertSee('dir="rtl"', false);

        $this->actingAs($admin)->get('/locale/en')->assertRedirect();
        $this->assertSame('en', $admin->fresh()->locale);

        // The admin panel itself stays English/LTR regardless. Admin routes
        // skip SetLocale entirely, so reset the app locale the earlier
        // in-process requests set — real HTTP requests each start fresh.
        // Literal 'en', NOT config('app.locale'): app()->setLocale() writes
        // back into config, so after the ar request the config value IS 'ar'.
        app()->setLocale('en');

        $this->actingAs($admin)
            ->get('/admin/settings')
            ->assertOk()
            ->assertDontSee('dir="rtl"', false);
    }

    public function test_multilingual_off_clamps_mailable_locale_to_english(): void
    {
        LocaleConfig::setMultilingualEnabled(false);

        $this->assertSame('en', LocaleConfig::resolve('ar'));
        $this->assertSame('en', LocaleConfig::resolve(null));

        LocaleConfig::setMultilingualEnabled(true);

        $this->assertSame('ar', LocaleConfig::resolve('ar'));
    }
}
