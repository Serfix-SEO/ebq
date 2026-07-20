<?php

namespace Tests\Feature\Content;

use App\Jobs\Content\ClassifyPlanKeywordsJob;
use App\Models\ContentPlan;
use App\Models\ContentPlanKeyword;
use App\Models\DomainKeywordRanking;
use App\Models\KeywordMetric;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentSetupInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ClassifyPlanKeywordsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_tags_own_and_gap_and_stamps_classified(): void
    {
        // No LLM configured in tests → bulk relevance fails closed to top-by-volume,
        // so both competitor keywords are kept as gap.
        $user = User::factory()->create(['is_admin' => false]);
        $website = Website::factory()->for($user)->create(['normalized_domain' => 'me.test']);
        $own = strtolower(preg_replace('/^www\./', '', (string) ($website->normalized_domain ?: $website->domain)));
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id, 'country' => 'US', 'status' => ContentPlan::STATUS_DRAFT,
            'offerings' => ['sell' => ['stylish name generator']],
            'business_description' => 'Stylish name generator.',
        ]);

        Cache::put('content:setup-insights:v1:'.$website->id, [
            'my_referring_domains' => 10, 'my_authority' => null,
            'competitors' => [['domain' => 'rival.com']],
            'median' => null, 'gap' => null, 'behind' => false,
        ], now()->addDay());

        // client already ranks for one (→ own, and excluded from gap)
        $this->ranking($own, 'my brand name', 500);
        // competitor keywords (→ gap)
        $this->ranking('rival.com', 'stylish name generator', 9000);
        $this->ranking('rival.com', 'cool nickname maker', 4000);
        // client also ranks for this exact one → must NOT be a gap
        $this->ranking($own, 'stylish name generator', 8000);

        (new ClassifyPlanKeywordsJob($plan->id))->handle(app(ContentSetupInsights::class));

        $own = ContentPlanKeyword::where('plan_id', $plan->id)->where('type', 'own')->pluck('keyword')->all();
        $gap = ContentPlanKeyword::where('plan_id', $plan->id)->where('type', 'gap')->pluck('keyword')->all();

        $this->assertContains('my brand name', $own);
        $this->assertContains('stylish name generator', $own);          // client ranks → own
        $this->assertContains('cool nickname maker', $gap);
        $this->assertNotContains('stylish name generator', $gap);       // excluded from gap (client ranks)
        $this->assertNotNull($plan->fresh()->keywords_classified_at);
    }

    public function test_second_run_appends_new_lower_volume_band(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id, 'country' => 'US', 'status' => ContentPlan::STATUS_DRAFT,
            'offerings' => ['sell' => ['name generator']], 'business_description' => 'Name generator.',
        ]);
        Cache::put('content:setup-insights:v1:'.$website->id, [
            'my_referring_domains' => 10, 'my_authority' => null,
            'competitors' => [['domain' => 'rival.com']], 'median' => null, 'gap' => null, 'behind' => false,
        ], now()->addDay());

        $this->ranking('rival.com', 'high volume name', 5000);
        (new ClassifyPlanKeywordsJob($plan->id))->handle(app(ContentSetupInsights::class));

        $this->assertSame(1, ContentPlanKeyword::where('plan_id', $plan->id)->where('type', 'gap')->count());
        $this->assertSame(5000, $plan->fresh()->keywords_classify_cursor);

        // A new, lower-volume keyword arrives next month (below the cursor).
        $this->ranking('rival.com', 'low volume name', 500);
        (new ClassifyPlanKeywordsJob($plan->id))->handle(app(ContentSetupInsights::class));

        $gap = ContentPlanKeyword::where('plan_id', $plan->id)->where('type', 'gap')->pluck('keyword')->all();
        $this->assertCount(2, $gap);                              // appended, not replaced
        $this->assertContains('low volume name', $gap);
        $this->assertContains('high volume name', $gap);
        $this->assertSame(500, $plan->fresh()->keywords_classify_cursor); // cursor advanced down
    }

    private function ranking(string $domain, string $keyword, int $volume): void
    {
        DomainKeywordRanking::query()->create([
            'domain' => $domain, 'keyword' => $keyword, 'country' => 'us',
            'keyword_hash' => KeywordMetric::hashKeyword($keyword),
            'search_volume' => $volume, 'rank_absolute' => 5,
        ]);
    }
}
