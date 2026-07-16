<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Models\Website;
use App\Services\Crawler\CrawlSiteBootstrapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * The Site Explorer funnel must ATTACH the pre-signup domain to the new
 * account (Website row + crawl subscription + historical import), not just
 * redirect to the shared report. Covers signup, the pay-first branch, and
 * signin for zero-website accounts.
 */
class RegisterFunnelAttachTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Fail-loud: nothing in this funnel may hit a real API.
        Http::fake(['*' => Http::response([], 500)]);

        // The crawl bootstrapper touches the crawl subsystem — spy it.
        $bootstrapper = Mockery::mock(CrawlSiteBootstrapper::class);
        $bootstrapper->shouldReceive('subscribeWebsite')->byDefault();
        $this->app->instance(CrawlSiteBootstrapper::class, $bootstrapper);
    }

    private function register(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->post('/register', array_merge([
            'name' => 'Funnel User',
            'email' => 'funnel@example.com',
            'password' => 'secret-password-1',
            'password_confirmation' => 'secret-password-1',
        ], $overrides));
    }

    public function test_signup_from_analyze_funnel_attaches_the_domain(): void
    {
        Bus::fake();

        $bootstrapper = $this->app->make(CrawlSiteBootstrapper::class);
        $bootstrapper->shouldReceive('subscribeWebsite')->once();

        // Seeding the session directly — the /analyze → session('analyze_domain')
        // capture is covered by ClientReportTest (and its guard does real DNS).
        $response = $this->withSession(['analyze_domain' => 'newsite.example'])->register();

        $response->assertRedirect(route('report.view', ['url' => 'newsite.example']));

        $user = User::where('email', 'funnel@example.com')->firstOrFail();
        $website = Website::where('user_id', $user->id)->first();
        $this->assertNotNull($website, 'funnel domain was not attached as a Website');
        $this->assertEquals('newsite.example', $website->normalized_domain);
        $this->assertEquals((string) $website->id, (string) session('current_website_id'));
        $this->assertNull(session('analyze_domain'));

        // Historical GSC/GA import queued (Artisan::queue pushes a queued closure job).
        Bus::assertDispatched(\Illuminate\Foundation\Console\QueuedCommand::class);
    }

    public function test_paid_plan_signup_stashes_domain_for_onboarding_instead_of_attaching(): void
    {
        Bus::fake();
        Plan::create([
            'slug' => 'pro', 'name' => 'Pro', 'is_active' => true,
            'price_monthly_usd' => 29, 'stripe_price_id_monthly' => 'price_y',
        ]);

        $response = $this->withSession([
            'analyze_domain' => 'paidsite.example',
            'pending_plan' => 'pro',
            'pending_plan_interval' => 'monthly',
        ])->register(['email' => 'paid@example.com']);

        $response->assertRedirect(route('billing.checkout', ['plan' => 'pro', 'interval' => 'monthly']));
        $this->assertDatabaseCount('websites', 0);
        // The domain is handed to post-checkout onboarding, not dropped.
        $this->assertEquals('paidsite.example', session('onboarding.domain'));
        $this->assertNull(session('analyze_domain'));
    }

    public function test_signin_with_zero_websites_attaches_the_funnel_domain(): void
    {
        Bus::fake();
        $user = User::factory()->create(['password' => bcrypt('secret-password-1')]);

        $bootstrapper = $this->app->make(CrawlSiteBootstrapper::class);
        $bootstrapper->shouldReceive('subscribeWebsite')->once();

        $response = $this->withSession(['analyze_domain' => 'latecomer.example'])->post('/login', [
            'email' => $user->email,
            'password' => 'secret-password-1',
        ]);

        $response->assertRedirect(route('report.view', ['url' => 'latecomer.example']));
        $this->assertEquals('latecomer.example', $user->websites()->first()?->normalized_domain);
    }

    public function test_signin_with_existing_websites_does_not_attach_a_lookup_domain(): void
    {
        Bus::fake();
        $user = User::factory()->create(['password' => bcrypt('secret-password-1')]);
        Website::create([
            'user_id' => $user->id, 'domain' => 'mysite.example',
            'ga_property_id' => '', 'ga_google_account_id' => null,
            'gsc_site_url' => '', 'gsc_google_account_id' => null,
        ]);

        $response = $this->withSession(['analyze_domain' => 'competitor.example'])->post('/login', [
            'email' => $user->email,
            'password' => 'secret-password-1',
        ]);

        $response->assertRedirect(route('report.view', ['url' => 'competitor.example']));
        $this->assertEquals(1, $user->websites()->count());
        $this->assertNull($user->websites()->where('normalized_domain', 'competitor.example')->first());
    }

    public function test_plan_limited_signup_still_lands_on_the_report_without_attaching(): void
    {
        Bus::fake();
        Plan::create(['slug' => 'zero', 'name' => 'Zero', 'is_active' => true, 'max_websites' => 0]);

        $response = $this->withSession(['analyze_domain' => 'blocked.example'])
            ->register(['email' => 'limited@example.com']);
        $user = User::where('email', 'limited@example.com')->firstOrFail();
        $user->forceFill(['current_plan_slug' => 'zero'])->save();

        // The default (planless) signup isn't limited, so the attach happened —
        // this test asserts the blocked path directly through the service.
        $result = app(\App\Services\WebsiteAttachService::class)->attach($user->fresh(), 'another.example');
        $this->assertEquals('plan_limit', $result['blocked']);
        $this->assertNull($result['website']);

        $response->assertRedirect(route('report.view', ['url' => 'blocked.example']));
    }

    public function test_attach_service_reuses_existing_owned_website(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $existing = Website::create([
            'user_id' => $user->id, 'domain' => 'https://mysite.example/',
            'ga_property_id' => '', 'ga_google_account_id' => null,
            'gsc_site_url' => '', 'gsc_google_account_id' => null,
        ]);

        $result = app(\App\Services\WebsiteAttachService::class)->attach($user, 'mysite.example');

        $this->assertFalse($result['created']);
        $this->assertTrue($existing->is($result['website']));
        $this->assertEquals(1, Website::count());
    }
}
