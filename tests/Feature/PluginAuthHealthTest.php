<?php

namespace Tests\Feature;

use App\Console\Commands\SendFailedJobsAlert;
use App\Mail\FailedJobsDigestMail;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsitePluginInstall;
use App\Support\PluginAuthHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A WordPress install whose token has died is the quietest failure we have:
 * the plugin degrades gracefully, the client sees nothing wrong, and every
 * hourly call 401s into an access log nobody reads. pubgnamegenerator.net did
 * it for two months. These tests are the alarm that closes that.
 */
class PluginAuthHealthTest extends TestCase
{
    use RefreshDatabase;

    private const PLUGIN_UA = 'Serfix-SEO-WP/2.1.0; https://example.com';

    private function site(string $domain = 'example.com'): Website
    {
        return Website::factory()->for(User::factory())->create([
            'domain' => $domain, 'normalized_domain' => $domain,
        ]);
    }

    /** The throttle is per host/website, so tests that repeat a call must clear it. */
    private function unthrottle(): void
    {
        Cache::flush();
    }

    public function test_a_dead_token_is_recorded_against_the_site_in_its_user_agent(): void
    {
        $website = $this->site();

        $this->withHeaders(['User-Agent' => self::PLUGIN_UA])
            ->withToken('not-a-real-token')
            ->getJson('/api/v1/website-features')
            ->assertUnauthorized();

        $install = WebsitePluginInstall::where('website_id', $website->id)->first();
        $this->assertNotNull($install, 'the 401 should be attributed to the site named in the UA');
        $this->assertNotNull($install->auth_failing_since);
        $this->assertNotNull($install->last_auth_failure_at);
    }

    public function test_repeated_failures_keep_the_original_start_time(): void
    {
        $website = $this->site();
        $started = now()->subDays(9);
        WebsitePluginInstall::create([
            'website_id' => $website->id,
            'auth_failing_since' => $started,
            'last_auth_failure_at' => $started,
        ]);

        $this->unthrottle();
        $this->withHeaders(['User-Agent' => self::PLUGIN_UA])
            ->withToken('still-dead')
            ->getJson('/api/v1/website-features')
            ->assertUnauthorized();

        $install = WebsitePluginInstall::where('website_id', $website->id)->first();
        // "Failing for 9 days" is the whole point — a per-request reset would
        // make a two-month outage look like it started a minute ago.
        $this->assertSame($started->toDateString(), $install->auth_failing_since->toDateString());
        $this->assertTrue($install->last_auth_failure_at->isToday());
    }

    public function test_a_successful_call_clears_the_failure(): void
    {
        $website = $this->site();
        WebsitePluginInstall::create([
            'website_id' => $website->id,
            'auth_failing_since' => now()->subDays(30),
            'last_auth_failure_at' => now()->subHour(),
        ]);
        $token = $website->createToken('test', ['read:insights'])->plainTextToken;

        $this->unthrottle();
        $this->withHeaders(['User-Agent' => self::PLUGIN_UA])
            ->withToken($token)
            ->getJson('/api/v1/website-features')
            ->assertOk();

        $install = WebsitePluginInstall::where('website_id', $website->id)->first();
        $this->assertNull($install->auth_failing_since);
        $this->assertNull($install->last_auth_failure_at);
    }

    public function test_a_stranger_cannot_flag_someone_elses_site(): void
    {
        $this->site('example.com');

        // Not our plugin: no recording, whatever the URL claims.
        $this->withHeaders(['User-Agent' => 'curl/8.5.0 https://example.com'])
            ->withToken('nope')
            ->getJson('/api/v1/website-features')
            ->assertUnauthorized();

        $this->assertSame(0, WebsitePluginInstall::count());

        // Our plugin's UA, but a site we have never heard of.
        $this->unthrottle();
        $this->withHeaders(['User-Agent' => 'Serfix-SEO-WP/2.1.0; https://not-a-customer.test'])
            ->withToken('nope')
            ->getJson('/api/v1/website-features')
            ->assertUnauthorized();

        $this->assertSame(0, WebsitePluginInstall::count(), 'we only ever match websites we already know');
    }

    public function test_the_user_agent_parser_handles_the_shapes_in_the_wild(): void
    {
        $this->assertSame('example.com', PluginAuthHealth::hostFromUserAgent('Serfix-SEO-WP/2.1.0; https://example.com'));
        $this->assertSame('example.com', PluginAuthHealth::hostFromUserAgent('Serfix-SEO-WP/2.1.0; https://www.example.com/'));
        $this->assertSame('old.test', PluginAuthHealth::hostFromUserAgent('EBQ-SEO-WP/1.9.0; http://old.test'), 'older builds still call in');
        $this->assertNull(PluginAuthHealth::hostFromUserAgent('Mozilla/5.0 (compatible; GPTBot/1.2)'));
        $this->assertNull(PluginAuthHealth::hostFromUserAgent('Serfix-SEO-WP/2.1.0'));
        $this->assertNull(PluginAuthHealth::hostFromUserAgent(null));
    }

    public function test_a_brief_failure_is_not_alarmed(): void
    {
        $website = $this->site();
        WebsitePluginInstall::create([
            'website_id' => $website->id,
            'auth_failing_since' => now()->subHours(2),   // mid-reconnect
            'last_auth_failure_at' => now(),
        ]);

        $this->assertCount(0, PluginAuthHealth::failingInstalls());
    }

    public function test_a_site_that_stopped_calling_is_not_chased(): void
    {
        $website = $this->site();
        WebsitePluginInstall::create([
            'website_id' => $website->id,
            'auth_failing_since' => now()->subDays(40),
            'last_auth_failure_at' => now()->subDays(10),   // plugin removed, site gone
        ]);

        $this->assertCount(0, PluginAuthHealth::failingInstalls(), 'nothing to chase if it stopped trying');
    }

    public function test_the_digest_reports_it_once_with_how_long_it_has_been_broken(): void
    {
        Mail::fake();
        User::factory()->create(['is_admin' => true]);
        $website = $this->site('pubgnamegenerator.net');
        WebsitePluginInstall::create([
            'website_id' => $website->id,
            'auth_failing_since' => now()->subDays(63),
            'last_auth_failure_at' => now()->subMinutes(20),
        ]);

        $this->artisan(SendFailedJobsAlert::class)->assertSuccessful();

        Mail::assertSent(FailedJobsDigestMail::class, fn (FailedJobsDigestMail $mail) => str_contains($mail->body, 'PLUGIN AUTH')
            && str_contains($mail->body, 'pubgnamegenerator.net')
            && str_contains($mail->body, '63d'));

        // Second run in the same week says nothing — an unreconnected client
        // must not re-report every fifteen minutes.
        Mail::fake();
        $this->artisan(SendFailedJobsAlert::class)->assertSuccessful();
        Mail::assertNothingSent();
    }
}
