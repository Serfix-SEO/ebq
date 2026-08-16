<?php

namespace Tests\Feature\Content;

use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentEntitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Site Health inside Content Autopilot (2026-08-16): the SEO platform's
 * crawl-health module (score + priority issue queue) surfaced at
 * /content/site-health, with the old email/dashboard links 301ing to it.
 */
class ContentSiteHealthTest extends TestCase
{
    use RefreshDatabase;

    private function contentUser(): array
    {
        $user = User::factory()->create(['content_comp_sites' => 1]);
        $website = Website::factory()->for($user)->create();
        app(ContentEntitlements::class)->coverWebsite($website);

        return [$user, $website];
    }

    public function test_content_user_can_open_site_health(): void
    {
        config(['features.seo_platform_ui' => false]);
        [$user, $website] = $this->contentUser();

        $this->actingAs($user)->withSession(['current_website_id' => $website->id])
            ->get(route('content.site-health'))
            ->assertOk()
            ->assertSee('Site Health');
    }

    public function test_issue_drilldowns_stay_reachable_for_content_users(): void
    {
        config(['features.seo_platform_ui' => false]);
        [$user, $website] = $this->contentUser();

        // The priority queue links here — it must not bounce to the calendar.
        $this->actingAs($user)->withSession(['current_website_id' => $website->id])
            ->get(route('issues.show', ['key' => 'crawl_broken_links']))
            ->assertOk();
    }

    public function test_crawl_report_email_links_to_the_new_page(): void
    {
        [$user, $website] = $this->contentUser();

        $payload = app(\App\Services\Crawler\CrawlReportService::class)->emailReportPayload($website);
        $this->assertSame(route('content.site-health'), $payload['dashboard_url']);
    }

    /**
     * The header chips and the Priority Action Queue must tell ONE story:
     * both roll severity up per category (actionGroups), so the chip counts
     * always equal the sum of the queue's critical/high card counts.
     */
    public function test_summary_severity_chips_match_the_action_queue_rollup(): void
    {
        [$user, $website] = $this->contentUser();

        $svc = app(\App\Services\Crawler\CrawlReportService::class);
        $summary = $svc->summary($website->id);
        $groups = $svc->actionGroups($website->id);

        $critical = collect($groups)->where('severity', 'critical')->sum('count');
        $high = collect($groups)->where('severity', 'high')->sum('count');
        $total = collect($groups)->sum('count');

        $this->assertSame($critical, $summary['findings']['critical']);
        $this->assertSame($high, $summary['findings']['high']);
        $this->assertSame($total, $summary['findings']['total']);
    }
}
