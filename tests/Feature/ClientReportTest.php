<?php

namespace Tests\Feature;

use App\Jobs\GenerateWebsiteReport;
use App\Models\ReportShare;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteReportSnapshot;
use App\Services\DataForSeoBacklinkClient;
use App\Services\MozLinksClient;
use App\Services\ReportFreshnessGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ClientReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_analyze_requires_signup_and_makes_no_api_call(): void
    {
        Bus::fake();
        Http::fake();

        $response = $this->postJson('/analyze', ['url' => 'example.com']);

        $response->assertStatus(202)->assertJsonStructure(['results_url']);
        $this->assertEquals('example.com', session('analyze_domain'));
        Bus::assertNotDispatched(GenerateWebsiteReport::class);
        Http::assertNothingSent();
    }

    public function test_authenticated_analyze_dispatches_generation(): void
    {
        Bus::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/analyze', ['url' => 'https://example.com/page']);

        $response->assertStatus(202)->assertJsonStructure(['results_url']);
        Bus::assertDispatched(GenerateWebsiteReport::class, fn ($job) => $job->domain === 'example.com');
    }

    public function test_guest_report_view_shows_blurred_teaser_and_no_api_call(): void
    {
        Bus::fake();
        Http::fake();

        $response = $this->get('/report/view?url=example.com');

        $response->assertOk()
            ->assertSee('example.com')
            ->assertSee('Create free account & view report')
            ->assertSee('blur-', false);
        Bus::assertNotDispatched(GenerateWebsiteReport::class);
        Http::assertNothingSent();
    }

    public function test_public_tools_require_signup_for_guests(): void
    {
        Bus::fake();

        // SEO Audit tool — anonymous submit runs nothing (no job) and returns a
        // results_url pointing at the blurred teaser preview.
        $res = $this->postJson('/audit', ['url' => 'example.com', 'keyword' => 'seo'])
            ->assertStatus(202)
            ->assertJsonStructure(['results_url']);
        $this->assertStringContainsString('/tools/preview/audit', $res->json('results_url'));

        Bus::assertNotDispatched(\App\Jobs\RunGuestPageAudit::class);
    }

    public function test_site_explorer_lookup_limit_per_plan(): void
    {
        Bus::fake();
        \App\Models\Plan::create(['slug' => 'trial', 'name' => 'Trial', 'site_explorer_limit' => 2, 'site_explorer_window_hours' => 24]);
        $user = User::factory()->create(['current_plan_slug' => 'trial']);

        $this->actingAs($user)->postJson('/analyze', ['url' => 'one.com'])->assertStatus(202);
        $this->actingAs($user)->postJson('/analyze', ['url' => 'two.com'])->assertStatus(202);
        // 3rd lookup exceeds the trial limit of 2.
        $this->actingAs($user)->postJson('/analyze', ['url' => 'three.com'])->assertStatus(429);
    }

    public function test_public_report_token_renders_and_bad_tokens_404(): void
    {
        $owner = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $owner->id, 'domain' => 'example.com']);

        WebsiteReportSnapshot::create([
            'normalized_domain' => 'example.com',
            'domain_authority' => 41,
            'payload' => ['domain' => 'example.com', 'gauges' => ['domain_authority' => 41], 'totals' => [], 'ratios' => []],
            'status' => 'ready',
            'fetched_at' => now(),
        ]);

        $share = ReportShare::create([
            'website_id' => $website->id,
            'created_by' => $owner->id,
            'token' => ReportShare::newToken(),
        ]);

        $this->get('/r/'.$share->token)->assertOk()->assertSee('example.com');

        $this->get('/r/does-not-exist-token')->assertNotFound();

        $share->update(['revoked_at' => now()]);
        $this->get('/r/'.$share->token)->assertNotFound();
    }

    public function test_assemble_emits_trust_and_citation_scores(): void
    {
        $payload = app(\App\Services\Reports\ClientReportService::class)->assemble('example.com', [
            'summary' => [
                'backlinks' => 24318,
                'referring_domains' => 1842,
                'referring_ips' => 1650,
                'referring_subnets' => 1400,
                'rank' => 470,
                'backlinks_spam_score' => 3,
                'referring_links_attributes' => ['nofollow' => 9240],
            ],
            'history' => [],
            'referring_domains' => [
                ['domain' => 'en.wikipedia.org', 'rank' => 890, 'backlinks' => 1, 'first_seen' => '2024-03-01 00:00:00'],
                ['domain' => 'smallblog.net', 'rank' => 120, 'backlinks' => 5, 'first_seen' => '2025-01-01 00:00:00'],
                ['domain' => 'spamdir.xyz', 'rank' => 10, 'backlinks' => 44, 'first_seen' => '2025-06-01 00:00:00'],
            ],
            'anchors' => [],
            'domain_pages' => [],
            'competitors' => [],
            'backlinks' => [],
            'moz' => ['domain_authority' => 41, 'page_authority' => 38, 'spam_score' => 3],
            'opr' => ['example.com' => ['score' => 4.7, 'rank' => 1639043, 'history' => []]],
        ]);

        $this->assertIsInt($payload['scores']['citation']);
        $this->assertIsInt($payload['scores']['trust']);
        $this->assertSame(\App\Services\Reports\AuthorityScoreCalculator::VERSION, $payload['scores']['version']);
        // Per-row Citation Score lands on every referring-domain row.
        $this->assertIsInt($payload['top_referring_domains'][0]['cs']);
        // Trust can exceed Citation only by the plausibility margin.
        $this->assertLessThanOrEqual($payload['scores']['citation'] + 10, $payload['scores']['trust']);
    }

    public function test_topicsignal_refreshes_on_read_even_when_scores_are_current(): void
    {
        // Regression (2026-07-16): a payload whose scores were already
        // current-version short-circuited BEFORE the calculator ran, so the
        // topical section that landed AFTER the score stamp never produced a
        // TopicSignal — the gauge showed "—" forever.
        $svc = app(\App\Services\Reports\ClientReportService::class);
        $payload = [
            'domain' => 'topical-done.test',
            'scores' => ['trust' => 15, 'citation' => 5, 'version' => \App\Services\Reports\AuthorityScoreCalculator::VERSION],
            'topical_trust' => ['sample' => 33, 'total' => 33, 'relevant_pct' => 30,
                'topics' => [['topic' => 'Business & Industry', 'count' => 12]]],
            'meta' => ['schema' => 2],
        ];

        $out = $svc->withTraffic($payload, null);

        // 15 * (0.4 + 0.6*0.30) = 8.7 → 9
        $this->assertSame(9, $out['scores']['topical']);
    }

    public function test_registration_phone_is_optional(): void
    {
        $this->post('/register', [
            'name' => 'Grace Hopper',
            'email' => 'grace@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ]);

        $this->assertDatabaseHas('users', ['email' => 'grace@example.com', 'phone' => null]);
    }

    public function test_registration_combines_dial_code_with_phone(): void
    {
        $this->post('/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'dial_code' => '+44',
            'phone' => '555 123 4567',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ]);

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com', 'phone' => '+44 555 123 4567']);
    }

    public function test_freshness_gate_default_ttl(): void
    {
        $gate = app(ReportFreshnessGate::class);

        WebsiteReportSnapshot::create([
            'normalized_domain' => 'fresh.com',
            'payload' => ['domain' => 'fresh.com'],
            'status' => 'ready',
            'fetched_at' => now()->subDays(60),
        ]);
        WebsiteReportSnapshot::create([
            'normalized_domain' => 'stale.com',
            'payload' => ['domain' => 'stale.com'],
            'status' => 'ready',
            'fetched_at' => now()->subDays(120),
        ]);

        // No paid owner → 90-day default: 60d fresh, 120d stale.
        $this->assertTrue($gate->isFresh('fresh.com'));
        $this->assertFalse($gate->isFresh('stale.com'));
    }

    public function test_freshness_gate_paid_owner_shortens_ttl_to_30_days(): void
    {
        WebsiteReportSnapshot::create([
            'normalized_domain' => 'paid.com',
            'payload' => ['domain' => 'paid.com'],
            'status' => 'ready',
            'fetched_at' => now()->subDays(60),
        ]);

        // Partial-mock isPaidOwned so we test the tier logic without full plan setup.
        $gate = Mockery::mock(ReportFreshnessGate::class)->makePartial();
        $gate->shouldReceive('isPaidOwned')->andReturn(true);

        // 60 days old, paid TTL is 30 → stale.
        $this->assertEquals(30, $gate->ttlDaysFor('paid.com'));
        $this->assertFalse($gate->isFresh('paid.com'));
    }

    public function test_generate_job_upserts_snapshot_from_mocked_providers(): void
    {
        config(['services.dataforseo.login' => 'x', 'services.dataforseo.password' => 'y']);

        $dfs = Mockery::mock(DataForSeoBacklinkClient::class);
        $dfs->shouldReceive('isConfigured')->andReturn(true);
        $dfs->shouldReceive('useSandbox')->andReturnSelf();
        $dfs->shouldReceive('summary')->andReturn([
            'backlinks' => 24318,
            'referring_domains' => 1842,
            'referring_ips' => 1203,
            'referring_subnets' => 890,
            'rank' => 470,
            'referring_links_attributes' => ['nofollow' => 18970],
        ]);
        $dfs->shouldReceive('history')->andReturn([]);
        $dfs->shouldReceive('referringDomains')->andReturn([]);
        $dfs->shouldReceive('anchors')->andReturn([]);
        $dfs->shouldReceive('domainPages')->andReturn([]);
        $dfs->shouldReceive('competitors')->andReturn([]);
        $dfs->shouldReceive('labsCompetitors')->andReturn([]);
        $dfs->shouldReceive('backlinksSample')->andReturn([]);
        $dfs->shouldReceive('totalCost')->andReturn(0.4524);

        $moz = Mockery::mock(MozLinksClient::class);
        $moz->shouldReceive('isConfigured')->andReturn(true);
        $moz->shouldReceive('urlMetrics')->andReturn(['domain_authority' => 41, 'page_authority' => 38, 'spam_score' => 3, 'linking_root_domains' => 1842]);

        $opr = Mockery::mock(\App\Services\OpenPageRankClient::class);
        $opr->shouldReceive('metricsFor')->andReturn([
            'example.com' => ['rank' => 1639043, 'score' => 4.7, 'referring_domains' => 120, 'history' => []],
        ]);

        $this->app->instance(DataForSeoBacklinkClient::class, $dfs);
        $this->app->instance(MozLinksClient::class, $moz);
        $this->app->instance(\App\Services\OpenPageRankClient::class, $opr);

        (new GenerateWebsiteReport('example.com'))->handle(
            $dfs, $moz, $opr, app(ReportFreshnessGate::class), app(\App\Services\Reports\ClientReportService::class),
            app(\App\Services\ClientActivityLogger::class)
        );

        $snapshot = WebsiteReportSnapshot::forDomain('example.com');
        $this->assertNotNull($snapshot);
        $this->assertEquals(41, $snapshot->domain_authority);
        $this->assertEquals(24318, $snapshot->backlinks_total);
        $this->assertEquals(41, $snapshot->payload['gauges']['domain_authority']);
        $this->assertEquals(47, $snapshot->payload['gauges']['authority_score']);
        $this->assertEquals(1639043, $snapshot->payload['popularity']['rank']);
        $this->assertEquals(4.7, $snapshot->payload['popularity']['score']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
