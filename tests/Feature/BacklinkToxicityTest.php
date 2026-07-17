<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WebsiteReportSnapshot;
use App\Services\Reports\BacklinkToxicityScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BacklinkToxicityTest extends TestCase
{
    use RefreshDatabase;

    private function toxicPayload(): array
    {
        return [
            'domain' => 'victim.test',
            'anchor_types' => ['branded' => 1, 'naked' => 39, 'generic' => 2, 'exact' => 58],
            'anchors' => [
                ['anchor' => 'TG @SEO_LINKK_ORDER – SEO BACKLINKS, HOMEPAGE LINKS, CROSSLINKS', 'backlinks' => 161, 'referring_domains' => 140],
                ['anchor' => 'TELEGRAM @SALESOVEN | ACCESS TO HACKED SITES FOR SEO', 'backlinks' => 11, 'referring_domains' => 9],
                ['anchor' => 'best nickname generator', 'backlinks' => 40, 'referring_domains' => 12],
            ],
            'top_referring_domains' => [
                ['domain' => 'link-legion-23.xyz', 'rank' => 0, 'opr_score' => null, 'cs' => 0, 'ts' => null],
                ['domain' => 'link-legion-94.xyz', 'rank' => 0, 'opr_score' => null, 'cs' => 0, 'ts' => null],
                ['domain' => 'link-legion-137.xyz', 'rank' => 0, 'opr_score' => null, 'cs' => 0, 'ts' => null],
                ['domain' => 'legitblog.com', 'rank' => 400, 'opr_score' => 4.5, 'cs' => 45, 'ts' => 40],
            ],
            'backlinks' => [
                ['url_from' => 'https://link-legion-23.xyz/x', 'anchor' => 'nick tools', 'dofollow' => true],
                ['url_from' => 'https://randomsite.com/post', 'anchor' => 'TELEGRAM @SALESOVEN | ACCESS TO HACKED SITES FOR SEO', 'dofollow' => true],
                ['url_from' => 'https://legitblog.com/review', 'anchor' => 'great nickname tool', 'dofollow' => true],
            ],
        ];
    }

    public function test_flags_toxic_anchors_networks_and_over_optimization(): void
    {
        $out = (new BacklinkToxicityScorer)->analyze($this->toxicPayload());
        $risk = $out['link_risk'];

        $this->assertSame('high', $risk['level']);
        $this->assertTrue($risk['over_optimized']);
        $this->assertSame(58, $risk['exact_pct']);
        $this->assertSame(172, $risk['toxic_anchor_backlinks']); // 161 + 11
        $this->assertContains('link-legion-23.xyz', $risk['toxic_domains']);
        $this->assertContains('randomsite.com', $risk['toxic_domains']);   // hacked-sites anchor source
        $this->assertNotContains('legitblog.com', $risk['toxic_domains']);

        // Row flags: network member + toxic-anchor link are high, clean row untouched.
        $this->assertSame('high', $out['top_referring_domains'][0]['tox']);
        $this->assertArrayNotHasKey('tox', $out['top_referring_domains'][3]);
        $this->assertSame('high', $out['backlinks'][0]['tox']); // domain inherited from network
        $this->assertSame('high', $out['backlinks'][1]['tox']); // toxic anchor
        $this->assertArrayNotHasKey('tox', $out['backlinks'][2]);
        $this->assertSame('high', $out['anchors'][0]['tox']);
        $this->assertArrayNotHasKey('tox', $out['anchors'][2]);
    }

    public function test_clean_profile_has_no_risk(): void
    {
        $out = (new BacklinkToxicityScorer)->analyze([
            'domain' => 'clean.test',
            'anchor_types' => ['branded' => 70, 'naked' => 20, 'generic' => 5, 'exact' => 5],
            'anchors' => [['anchor' => 'clean brand', 'backlinks' => 10, 'referring_domains' => 8]],
            'top_referring_domains' => [
                ['domain' => 'nytimes.com', 'rank' => 900, 'opr_score' => 8.8, 'cs' => 80, 'ts' => 85],
            ],
            'backlinks' => [['url_from' => 'https://nytimes.com/article', 'anchor' => 'clean brand', 'dofollow' => true]],
        ]);

        $this->assertNull($out['link_risk']['level']);
        $this->assertSame([], $out['link_risk']['toxic_domains']);
    }

    public function test_anchor_drilldown_blocked_for_trial_plan(): void
    {
        WebsiteReportSnapshot::create([
            'normalized_domain' => 'victim.test',
            'payload' => array_merge($this->toxicPayload(), ['meta' => ['schema' => 2]]),
            'status' => 'ready',
            'fetched_at' => now(),
        ]);
        \App\Models\Plan::create(['slug' => 'trial', 'name' => 'Trial',
            'api_limits' => ['report' => ['allow_link_drilldown' => 0, 'max_backlink_rows' => 1000]]]);

        $this->actingAs(User::factory()->create(['current_plan_slug' => 'trial']))
            ->get('/report/anchor-links?url=victim.test&anchor=whatever')
            ->assertForbidden()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Upgrade'));
    }

    public function test_anchor_drilldown_persists_rows_into_snapshot(): void
    {
        $snapshot = WebsiteReportSnapshot::create([
            'normalized_domain' => 'victim.test',
            'payload' => array_merge($this->toxicPayload(), ['meta' => ['schema' => 2]]),
            'status' => 'ready',
            'fetched_at' => now(),
        ]);

        $dfs = \Mockery::mock(\App\Services\DataForSeoBacklinkClient::class);
        $dfs->shouldReceive('useSandbox')->andReturnSelf();
        $dfs->shouldReceive('totalCost')->andReturn(0.03); // metered by the spend circuit-breaker
        $dfs->shouldReceive('backlinksForAnchor')->once()->andReturn([
            ['url_from' => 'https://spam-seller.site/p1', 'url_to' => 'https://victim.test/a', 'anchor' => 'TG @SEO_LINKK_ORDER – SEO BACKLINKS, HOMEPAGE LINKS, CROSSLINKS', 'dofollow' => false, 'domain_from_rank' => 3],
        ]);
        $this->app->instance(\App\Services\DataForSeoBacklinkClient::class, $dfs);

        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get('/report/anchor-links?url=victim.test&anchor='.urlencode('TG @SEO_LINKK_ORDER – SEO BACKLINKS, HOMEPAGE LINKS, CROSSLINKS'))
            ->assertOk()
            ->assertJsonPath('rows.0.url_from', 'https://spam-seller.site/p1');

        // Row landed in the stored table, scores stamp dropped for recompute…
        $payload = $snapshot->fresh()->payload;
        $fetched = collect($payload['backlinks'])->firstWhere('via', 'anchor_fetch');
        $this->assertNotNull($fetched);
        $this->assertArrayNotHasKey('scores', $payload);

        // …and the read path immediately flags it toxic with row scores.
        $read = app(\App\Services\Reports\ClientReportService::class)->withTraffic($payload, null);
        $row = collect($read['backlinks'])->firstWhere('via', 'anchor_fetch');
        $this->assertSame('high', $row['tox']);
        $this->assertArrayHasKey('cs', $row);
    }

    public function test_disavow_export_requires_auth_and_lists_toxic_domains(): void
    {
        WebsiteReportSnapshot::create([
            'normalized_domain' => 'victim.test',
            'payload' => array_merge($this->toxicPayload(), ['meta' => ['schema' => 2]]),
            'status' => 'ready',
            'fetched_at' => now(),
        ]);

        $this->get('/report/disavow?url=victim.test')->assertRedirect(route('login'));

        $response = $this->actingAs(User::factory()->create())
            ->get('/report/disavow?url=victim.test')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

        $body = $response->streamedContent ?? $response->getContent();
        $this->assertStringContainsString('domain:link-legion-23.xyz', $body);
        $this->assertStringContainsString('domain:randomsite.com', $body);
        $this->assertStringNotContainsString('legitblog.com', $body);
        $this->assertStringContainsString('# Disavow file for victim.test', $body);
    }
}
