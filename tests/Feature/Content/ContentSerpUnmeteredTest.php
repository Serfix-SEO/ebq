<?php

namespace Tests\Feature\Content;

use App\Exceptions\QuotaExceededException;
use App\Models\ClientActivity;
use App\Models\Plan;
use App\Models\User;
use App\Models\Website;
use App\Services\SerperSearchClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Product isolation: Content Autopilot's SERP calls (article research, wizard
 * PAA, keyword tracker) carry `__unmetered` and must neither consume nor be
 * blocked by the SEO plan's serp_api cap. Regression for prod 2026-08-10:
 * ProduceContentArticleJob died with "used all 100 keyword-tracking lookups"
 * because the research SERP hit the dashboard meter.
 */
class ContentSerpUnmeteredTest extends TestCase
{
    use RefreshDatabase;

    /** A user whose SEO serp_api cap is fully exhausted (cap of 0 calls). */
    private function cappedOwnerWithSite(): Website
    {
        Plan::create([
            'slug' => 'capped', 'name' => 'Capped', 'is_active' => true,
            'api_limits' => ['serper' => ['monthly_calls' => 0]],
        ]);
        $user = User::factory()->create(['current_plan_slug' => 'capped']);

        return Website::factory()->create(['user_id' => $user->id]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.serper.key' => 'test-key']);
        Http::fake(['google.serper.dev/*' => Http::response(['organic' => [['title' => 'A', 'link' => 'https://a.test']]], 200)]);
    }

    public function test_unmetered_call_bypasses_the_exhausted_seo_cap_and_logs_separately(): void
    {
        $site = $this->cappedOwnerWithSite();

        $json = app(SerperSearchClient::class)->query([
            'q' => 'best widgets',
            '__website_id' => $site->id,
            '__owner_user_id' => $site->user_id,
            '__source' => 'content_autopilot.brief',
            '__unmetered' => true,
        ]);

        $this->assertIsArray($json);
        $this->assertSame(1, ClientActivity::query()->where('provider', 'serp_api:unmetered')->count());
        // Nothing lands under the pooled provider, so the dashboard meter is untouched.
        $this->assertSame(0, ClientActivity::query()->where('provider', 'serp_api')->count());
    }

    public function test_metered_call_still_enforces_the_cap(): void
    {
        $site = $this->cappedOwnerWithSite();

        $this->expectException(QuotaExceededException::class);

        app(SerperSearchClient::class)->query([
            'q' => 'best widgets',
            '__website_id' => $site->id,
            '__owner_user_id' => $site->user_id,
            '__source' => 'plugin_writer',
        ]);
    }
}
