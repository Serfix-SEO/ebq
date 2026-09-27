<?php

namespace Tests\Feature;

use App\Console\Commands\SendFailedJobsAlert;
use App\Mail\FailedJobsDigestMail;
use App\Models\PluginAuthFailure;
use App\Models\User;
use App\Models\Website;
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

        $row = PluginAuthFailure::where('host', 'example.com')->first();
        $this->assertNotNull($row, 'the 401 should be attributed to the site named in the UA');
        $this->assertSame($website->id, $row->website_id);
        $this->assertSame(PluginAuthFailure::REASON_TOKEN_REJECTED, $row->reason);
        $this->assertSame('2.1.0', $row->plugin_version);
        $this->assertSame(1, $row->failures);
    }

    public function test_an_install_whose_website_was_deleted_is_still_recorded(): void
    {
        // The shape the first version of this alarm was blind to, and the one
        // production actually has: gbwhatsapp.app posts every half hour on
        // plugin 1.0.5 against an account that no longer exists.
        $this->withHeaders(['User-Agent' => 'EBQ-SEO-WP/1.0.5; https://gbwhatsapp.app'])
            ->withToken('long-dead')
            ->getJson('/api/v1/website-features')
            ->assertUnauthorized();

        $row = PluginAuthFailure::where('host', 'gbwhatsapp.app')->first();
        $this->assertNotNull($row, 'an install with no website of ours is exactly what we want to hear about');
        $this->assertNull($row->website_id);
        $this->assertTrue($row->isOrphan());
        $this->assertSame('1.0.5', $row->plugin_version);
    }

    public function test_a_surviving_token_whose_website_is_gone_is_recorded_too(): void
    {
        // Sanctum resolves a tokenable of null, so this answers 403 rather than
        // 401 — equally broken (simcardairportbali.com, six days of it).
        $website = $this->site('gone.test');
        $token = $website->createToken('test', ['read:insights'])->plainTextToken;
        $website->forceDelete();

        $this->withHeaders(['User-Agent' => 'Serfix-SEO-WP/2.1.0; https://gone.test'])
            ->withToken($token)
            ->getJson('/api/v1/website-features')
            ->assertForbidden();

        $row = PluginAuthFailure::where('host', 'gone.test')->first();
        $this->assertNotNull($row);
        $this->assertSame(PluginAuthFailure::REASON_WEBSITE_MISSING, $row->reason);
    }

    public function test_repeated_failures_keep_the_original_start_time(): void
    {
        $website = $this->site();
        $started = now()->subDays(9);
        PluginAuthFailure::create([
            'host' => 'example.com', 'website_id' => $website->id,
            'first_seen_at' => $started, 'last_seen_at' => $started, 'failures' => 40,
        ]);

        $this->unthrottle();
        $this->withHeaders(['User-Agent' => self::PLUGIN_UA])
            ->withToken('still-dead')
            ->getJson('/api/v1/website-features')
            ->assertUnauthorized();

        $row = PluginAuthFailure::where('host', 'example.com')->first();
        // "Failing for 9 days" is the whole point — a per-request reset would
        // make a two-month outage look like it started a minute ago.
        $this->assertSame($started->toDateString(), $row->first_seen_at->toDateString());
        $this->assertTrue($row->last_seen_at->isToday());
        $this->assertSame(41, $row->failures);
        $this->assertSame(9, $row->failingDays());
    }

    public function test_a_successful_call_clears_the_failure(): void
    {
        $website = $this->site();
        PluginAuthFailure::create([
            'host' => 'example.com', 'website_id' => $website->id,
            'first_seen_at' => now()->subDays(30), 'last_seen_at' => now()->subHour(), 'failures' => 700,
        ]);
        $token = $website->createToken('test', ['read:insights'])->plainTextToken;

        $this->unthrottle();
        $this->withHeaders(['User-Agent' => self::PLUGIN_UA])
            ->withToken($token)
            ->getJson('/api/v1/website-features')
            ->assertOk();

        $this->assertSame(0, PluginAuthFailure::count(), 'a reconnect ends the run');
    }

    public function test_a_stranger_cannot_flag_someone_elses_site(): void
    {
        $this->site('example.com');

        // Not our plugin: no recording, whatever the URL claims.
        $this->withHeaders(['User-Agent' => 'curl/8.5.0 https://example.com'])
            ->withToken('nope')
            ->getJson('/api/v1/website-features')
            ->assertUnauthorized();

        $this->assertSame(0, PluginAuthFailure::count());

        // Our plugin's UA, but a site we have never heard of.
        $this->unthrottle();
        $this->withHeaders(['User-Agent' => 'Serfix-SEO-WP/2.1.0; https://not-a-customer.test'])
            ->withToken('nope')
            ->getJson('/api/v1/website-features')
            ->assertUnauthorized();

        $this->assertSame(1, PluginAuthFailure::count(), 'an unknown site is recorded, just without a website link');
        $this->assertTrue(PluginAuthFailure::first()->isOrphan());
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
        PluginAuthFailure::create([
            'host' => 'example.com',
            'first_seen_at' => now()->subHours(2),   // mid-reconnect
            'last_seen_at' => now(), 'failures' => 2,
        ]);

        $this->assertCount(0, PluginAuthHealth::failingInstalls());
    }

    public function test_a_site_that_stopped_calling_is_not_chased(): void
    {
        PluginAuthFailure::create([
            'host' => 'quiet.test',
            'first_seen_at' => now()->subDays(40),
            'last_seen_at' => now()->subDays(10),   // plugin removed
            'failures' => 300,
        ]);

        $this->assertCount(0, PluginAuthHealth::failingInstalls(), 'nothing to chase if it stopped trying');
    }

    public function test_installs_that_gave_up_a_month_ago_are_pruned(): void
    {
        PluginAuthFailure::create([
            'host' => 'ancient.test',
            'first_seen_at' => now()->subDays(90), 'last_seen_at' => now()->subDays(45), 'failures' => 9,
        ]);
        PluginAuthFailure::create([
            'host' => 'current.test',
            'first_seen_at' => now()->subDays(5), 'last_seen_at' => now(), 'failures' => 9,
        ]);

        $this->assertSame(1, PluginAuthHealth::prune());
        $this->assertSame(['current.test'], PluginAuthFailure::pluck('host')->all());
    }

    public function test_the_digest_reports_it_once_with_how_long_it_has_been_broken(): void
    {
        Mail::fake();
        User::factory()->create(['is_admin' => true]);
        $website = $this->site('pubgnamegenerator.net');
        PluginAuthFailure::create([
            'host' => 'pubgnamegenerator.net', 'website_id' => $website->id,
            'first_seen_at' => now()->subDays(63), 'last_seen_at' => now()->subMinutes(20),
            'failures' => 1500, 'plugin_version' => '2.0.22',
        ]);
        PluginAuthFailure::create([
            'host' => 'gbwhatsapp.app', 'website_id' => null,
            'first_seen_at' => now()->subDays(14), 'last_seen_at' => now()->subMinutes(5),
            'failures' => 268, 'plugin_version' => '1.0.5',
        ]);

        $this->artisan(SendFailedJobsAlert::class)->assertSuccessful();

        Mail::assertSent(FailedJobsDigestMail::class, fn (FailedJobsDigestMail $mail) => str_contains($mail->body, 'PLUGIN AUTH')
            && str_contains($mail->body, 'pubgnamegenerator.net')
            && str_contains($mail->body, '63d')
            && str_contains($mail->body, 'reconnect')
            && str_contains($mail->body, 'gbwhatsapp.app')
            && str_contains($mail->body, 'no longer exists')
            && str_contains($mail->body, 'plugin 1.0.5'));

        // Second run in the same week says nothing — an unreconnected client
        // must not re-report every fifteen minutes.
        Mail::fake();
        $this->artisan(SendFailedJobsAlert::class)->assertSuccessful();
        Mail::assertNothingSent();
    }
}
