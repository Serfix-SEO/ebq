<?php

namespace Tests\Feature;

use App\Models\CrawlFinding;
use App\Models\User;
use App\Models\Website;
use App\Services\ReportCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Resolving a false-positive finding is only half the job: Site Health and the
 * action queue read 24h caches keyed by the website's data version, so a run
 * that doesn't bump that version leaves the client staring at issues we already
 * cleared.
 *
 * The first version of the command bumped nothing — it read `website_id` off
 * the finding, and findings belong to the shared CRAWL SITE with that column
 * null on all 68,962 rows (prod, 2026-08-16). These tests pin the crawl-site →
 * subscribers resolution so the no-op can't come back.
 */
class RecheckBrokenLinksCacheTest extends TestCase
{
    use RefreshDatabase;

    private function findingFor(Website $website, string $url, string $status = 'open'): CrawlFinding
    {
        return CrawlFinding::create([
            'crawl_site_id' => $website->crawl_site_id,
            'website_id' => null, // exactly how the detector writes them
            'category' => 'broken_link',
            'type' => 'broken_external',
            'severity' => 'medium',
            'impact' => 0,
            'affected_url' => $url,
            'affected_url_hash' => CrawlFinding::hashUrl($url),
            'detail' => ['http_status' => 404],
            'status' => $status,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'resolved_at' => $status === 'resolved' ? now() : null,
        ]);
    }

    public function test_resolving_a_false_positive_bumps_every_subscriber_cache(): void
    {
        $website = Website::factory()->create(['user_id' => User::factory()->create()->id]);
        // A second account sharing the same crawl — it reads the same findings
        // and must be invalidated too.
        $sharer = Website::factory()->create([
            'user_id' => User::factory()->create()->id,
            'domain' => 'sharer.test',
        ]);
        $sharer->forceFill(['crawl_site_id' => $website->crawl_site_id])->save();

        $url = 'https://support.example.com/answer/1';
        $this->findingFor($website, $url);

        $before = [
            (string) $website->id => ReportCache::version((string) $website->id),
            (string) $sharer->id => ReportCache::version((string) $sharer->id),
        ];

        // HEAD 404 then GET 200 — the live-page pattern.
        Http::fake([$url => Http::sequence()->push('', 404)->push('ok', 200)]);

        $this->artisan('ebq:recheck-broken-links')->assertSuccessful();

        $this->assertSame('resolved', CrawlFinding::first()->status);
        foreach ($before as $websiteId => $version) {
            $this->assertGreaterThan(
                $version,
                ReportCache::version($websiteId),
                "website {$websiteId} must have its cached Site Health invalidated"
            );
        }
    }

    public function test_flush_only_mode_repairs_previously_resolved_findings(): void
    {
        $website = Website::factory()->create(['user_id' => User::factory()->create()->id]);
        $this->findingFor($website, 'https://support.example.com/answer/2', 'resolved');

        $before = ReportCache::version((string) $website->id);

        // No HTTP allowed: this path must not re-check anything.
        Http::preventStrayRequests();
        $this->artisan('ebq:recheck-broken-links', ['--flush-resolved-since' => now()->toDateString()])
            ->assertSuccessful();

        $this->assertGreaterThan($before, ReportCache::version((string) $website->id));
    }

    public function test_a_still_broken_link_keeps_its_finding(): void
    {
        $website = Website::factory()->create(['user_id' => User::factory()->create()->id]);
        $url = 'https://example.org/really-gone';
        $this->findingFor($website, $url);

        Http::fake([$url => Http::response('', 404)]);

        $this->artisan('ebq:recheck-broken-links')->assertSuccessful();

        $this->assertSame('open', CrawlFinding::first()->status);
    }
}
