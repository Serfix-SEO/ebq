<?php

namespace Tests\Feature;

use App\Jobs\RunGuestPageSpeedStrategy;
use App\Models\GuestPageSpeed;
use App\Support\Audit\SafeHttpGuard;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class GuestPageSpeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        config([
            'services.recaptcha.site_key' => '', 'services.recaptcha.secret_key' => '',
            'services.lighthouse.url' => 'http://lh.test', 'services.lighthouse.key' => 'k',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }


    public function test_tool_pages_load(): void
    {
        $this->get(route('tools.pagespeed'))->assertOk()->assertSee('PageSpeed', false);
        $this->get(route('tools.audit'))->assertOk()->assertSee('SEO audit', false);
    }

    public function test_url_is_required(): void
    {
        // Signed-in user — anonymous submits short-circuit to the signup
        // teaser (202, no validation, no job) before any of this logic runs.
        $this->actingAs(\App\Models\User::factory()->create());
        $this->postJson(route('guest-pagespeed.store'), ['url' => ''])->assertStatus(422);
    }

    public function test_first_test_is_free_shown_on_screen_and_queued(): void
    {
        // Signed-in user — anonymous submits short-circuit to the signup
        // teaser (202, no validation, no job) before any of this logic runs.
        $this->actingAs(\App\Models\User::factory()->create());
        Queue::fake();
        $guard = Mockery::mock(SafeHttpGuard::class);
        $guard->shouldReceive('check')->andReturn(['ok' => true]);
        $this->app->instance(SafeHttpGuard::class, $guard);

        // No prior cookie → first test runs free and is shown on screen.
        $r = $this->postJson(route('guest-pagespeed.store'), ['url' => 'a.com/p']);
        $r->assertStatus(202)->assertJsonPath('emailed', false)->assertJsonStructure(['results_url', 'status_url', 'token']);

        $this->assertDatabaseHas('guest_page_speeds', ['url' => 'https://a.com/p', 'email' => null]);
        // One job per strategy (mobile + desktop).
        Queue::assertPushed(RunGuestPageSpeedStrategy::class, 2);
    }

    public function test_unconfigured_lighthouse_is_handled(): void
    {
        // Signed-in user — anonymous submits short-circuit to the signup
        // teaser (202, no validation, no job) before any of this logic runs.
        $this->actingAs(\App\Models\User::factory()->create());
        config(['services.lighthouse.url' => '', 'services.lighthouse.key' => '']);
        $this->postJson(route('guest-pagespeed.store'), ['url' => 'https://a.com/p'])->assertStatus(503);
    }

    /**
     * The old email-gated 2nd run (and its Lead::capture) was replaced by the
     * account gate: anonymous submits get the signup teaser (202, no row, no
     * job); signed-in users run every test frictionless — no email step, no
     * lead rows.
     */
    public function test_account_gate_anonymous_teaser_and_authed_frictionless(): void
    {
        Queue::fake();
        $guard = Mockery::mock(SafeHttpGuard::class);
        $guard->shouldReceive('check')->andReturn(['ok' => true]);
        $this->app->instance(SafeHttpGuard::class, $guard);

        // Anonymous → teaser only: no row, no jobs.
        $this->postJson(route('guest-pagespeed.store'), ['url' => 'a.com/p'])
            ->assertStatus(202)
            ->assertJsonMissingPath('token');
        $this->assertDatabaseCount('guest_page_speeds', 0);
        Queue::assertNothingPushed();

        // Signed-in → consecutive runs both free + on screen, no email gate.
        $this->actingAs(\App\Models\User::factory()->create());
        foreach (['a.com/p', 'a.com/p2'] as $url) {
            $this->postJson(route('guest-pagespeed.store'), ['url' => $url])
                ->assertStatus(202)
                ->assertJsonPath('emailed', false);
        }
        Queue::assertPushed(RunGuestPageSpeedStrategy::class, 4); // 2 runs × (mobile+desktop)
        $this->assertDatabaseCount('leads', 0);
    }

    // NOTE: the per-browser progressive gate (1st free → 2nd email → 3rd signup)
    // is a verbatim adaptation of GuestAuditController, whose identical
    // cookie-counting flow is covered by GuestPageAuditTest. The signed-cookie
    // round-trip is not re-asserted here because it's flaky in the test harness
    // (the same call behaves inconsistently for the audit controller too).

    public function test_two_strategy_jobs_coordinate_to_finalize_the_report(): void
    {
        $strat = [
            'strategy' => 'mobile', 'lighthouse_version' => '12',
            'scores' => ['performance' => 80, 'accessibility' => 95, 'best_practices' => 92, 'seo' => 90],
            'metrics' => [], 'opportunities' => [], 'diagnostics' => [],
            'failed_audits' => ['accessibility' => [], 'best_practices' => [], 'seo' => []], 'screenshot' => null,
        ];
        $fake = new class($strat) extends \App\Services\LighthouseClient
        {
            public function __construct(private array $strat) {}

            public function isConfigured(): bool
            {
                return true;
            }

            public function fetchStrategyReport(string $url, string $strategy, ?int $maxSeconds = null): ?array
            {
                return ['strategy' => $strategy] + $this->strat;
            }
        };
        $this->app->instance(\App\Services\LighthouseClient::class, $fake);

        $row = GuestPageSpeed::start('https://example.com');

        // First strategy lands → still running (waiting on the other).
        \Illuminate\Support\Facades\Bus::dispatchSync(new RunGuestPageSpeedStrategy($row->id, 'mobile'));
        $this->assertSame(GuestPageSpeed::STATUS_RUNNING, $row->fresh()->status);

        // Second strategy lands → finalized with both.
        \Illuminate\Support\Facades\Bus::dispatchSync(new RunGuestPageSpeedStrategy($row->id, 'desktop'));
        $fresh = $row->fresh();
        $this->assertSame(GuestPageSpeed::STATUS_COMPLETED, $fresh->status);
        $this->assertNotNull($fresh->result['mobile']);
        $this->assertNotNull($fresh->result['desktop']);
    }

    public function test_results_page_renders_completed_report(): void
    {
        $strat = [
            'strategy' => 'mobile', 'lighthouse_version' => '12',
            'scores' => ['performance' => 80, 'accessibility' => 95, 'best_practices' => 92, 'seo' => 90],
            'metrics' => [['key' => 'lcp', 'label' => 'Largest Contentful Paint', 'display' => '2 s', 'rating' => 'good']],
            'opportunities' => [], 'diagnostics' => [],
            'failed_audits' => ['accessibility' => [], 'best_practices' => [], 'seo' => []], 'screenshot' => null,
        ];
        $row = GuestPageSpeed::create([
            'token' => (string) Str::uuid(),
            'url' => 'https://example.com',
            'status' => GuestPageSpeed::STATUS_COMPLETED,
            'result' => ['mobile' => $strat, 'desktop' => $strat, 'fetched_at' => now()->toIso8601String(), 'lighthouse_version' => '12'],
        ]);

        $this->get(route('guest-pagespeed.show', $row))
            ->assertOk()
            ->assertSee('Performance', false)
            ->assertSee('Largest Contentful Paint', false)
            ->assertSee('Start free', false);
    }
}
