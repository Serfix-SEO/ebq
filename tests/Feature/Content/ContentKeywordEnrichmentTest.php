<?php

namespace Tests\Feature\Content;

use App\Jobs\Content\EnrichPlanKeywordMetricsJob;
use App\Models\ContentPlan;
use App\Models\ContentPlanKeyword;
use App\Models\KeywordMetric;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentKeywordInsights;
use App\Services\DataForSeoBacklinkClient;
use App\Services\Reports\DataForSeoSpendMeter;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Paid DFS keyword-metric enrichment: every money rail is pinned here with a
 * stub client — NO test may ever reach the real API.
 */
class ContentKeywordEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, list<string>> keyword batches the stub was asked to enrich */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->calls = [];
    }

    private function coveredPlan(bool $covered = true): ContentPlan
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();

        return ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
            'keywords_classified_at' => now()->subDay(),
            'billing_covered_at' => $covered ? now()->subDay() : null,
        ]);
    }

    private function keywordRow(ContentPlan $plan, string $keyword, array $extra = []): ContentPlanKeyword
    {
        return ContentPlanKeyword::create(array_merge([
            'plan_id' => $plan->id,
            'keyword' => $keyword,
            'keyword_hash' => KeywordMetric::hashKeyword($keyword),
            'type' => ContentPlanKeyword::TYPE_GAP,
            // Same derivation the classifier uses when writing library rows.
            'country' => strtolower(trim((string) $plan->country)) ?: 'global',
            'search_volume' => 1000,
        ], $extra));
    }

    /** Stub client: records batches, returns canned rows, bills a fixed cost. */
    private function bindStubClient(array $rowsByKeyword, float $cost = 0.07): void
    {
        $test = $this;
        $stub = new class($rowsByKeyword, $cost, $test) extends DataForSeoBacklinkClient
        {
            public function __construct(private array $rows, private float $costPer, private object $test) {}

            public function isConfigured(): bool
            {
                return true;
            }

            public function resetCost(): static
            {
                return $this;
            }

            public function totalCost(): float
            {
                return $this->costPer;
            }

            public function bulkKeywordMetrics(array $keywords, array $opts = []): array
            {
                $this->test->recordCall($keywords);

                return array_values(array_intersect_key($this->rows, array_flip($keywords)));
            }
        };
        $this->app->instance(DataForSeoBacklinkClient::class, $stub);
    }

    public function recordCall(array $keywords): void
    {
        $this->calls[] = $keywords;
    }

    private function metricRow(string $kw, int $kd, string $intent, int $vol): array
    {
        return ['keyword' => $kw, 'search_volume' => $vol, 'cpc' => 1.5,
            'competition' => 0.42, 'keyword_difficulty' => $kd, 'search_intent' => $intent];
    }

    public function test_enriches_metrics_and_backfills_every_plan_sharing_the_keyword(): void
    {
        $plan = $this->coveredPlan();
        $other = $this->coveredPlan();
        $this->keywordRow($plan, 'best coffee grinder');
        $this->keywordRow($other, 'best coffee grinder'); // shared keyword, other client

        $this->bindStubClient(['best coffee grinder' => $this->metricRow('best coffee grinder', 43, 'commercial', 1900)]);

        (new EnrichPlanKeywordMetricsJob($plan->id))->handle();

        $metric = KeywordMetric::where('keyword_hash', KeywordMetric::hashKeyword('best coffee grinder'))
            ->where('data_source', 'dfs_labs')->first();
        $this->assertNotNull($metric);
        $this->assertSame(43, $metric->keyword_difficulty);
        $this->assertTrue($metric->expires_at->isFuture());

        foreach ([$plan, $other] as $p) {
            $row = ContentPlanKeyword::where('plan_id', $p->id)->first();
            $this->assertSame(43, $row->keyword_difficulty, 'backfilled for plan '.$p->id);
            $this->assertSame('commercial', $row->search_intent);
            $this->assertSame(1900, $row->search_volume, 'precise volume replaces the bucket');
        }
        $this->assertNotNull($plan->fresh()->keywords_enriched_at);
    }

    public function test_uncovered_or_inactive_plan_never_calls_the_api(): void
    {
        $uncovered = $this->coveredPlan(covered: false);
        $this->keywordRow($uncovered, 'some keyword');
        $this->bindStubClient(['some keyword' => $this->metricRow('some keyword', 30, 'informational', 500)]);

        (new EnrichPlanKeywordMetricsJob($uncovered->id))->handle();

        $this->assertSame([], $this->calls);
        $this->assertNull($uncovered->fresh()->keywords_enriched_at);
    }

    public function test_kill_switch_stops_all_spend(): void
    {
        config(['services.content_autopilot.keyword_enrichment' => false]);
        $plan = $this->coveredPlan();
        $this->keywordRow($plan, 'some keyword');
        $this->bindStubClient(['some keyword' => $this->metricRow('some keyword', 30, 'informational', 500)]);

        (new EnrichPlanKeywordMetricsJob($plan->id))->handle();

        $this->assertSame([], $this->calls);
    }

    public function test_exhausted_spend_meter_stops_all_spend(): void
    {
        config(['services.dataforseo.monthly_cap_usd' => 0.01]);
        app(DataForSeoSpendMeter::class)->add(0.02); // over cap
        $plan = $this->coveredPlan();
        $this->keywordRow($plan, 'some keyword');
        $this->bindStubClient(['some keyword' => $this->metricRow('some keyword', 30, 'informational', 500)]);

        try {
            (new EnrichPlanKeywordMetricsJob($plan->id))->handle();
        } finally {
            config(['services.dataforseo.monthly_cap_usd' => null]);
        }

        $this->assertSame([], $this->calls);
    }

    public function test_fresh_metrics_are_never_rebought(): void
    {
        $plan = $this->coveredPlan();
        $this->keywordRow($plan, 'already enriched');
        $this->keywordRow($plan, 'still pending');
        KeywordMetric::create([
            'keyword' => 'already enriched',
            'keyword_hash' => KeywordMetric::hashKeyword('already enriched'),
            'country' => 'us', 'data_source' => 'dfs_labs',
            'keyword_difficulty' => 55, 'fetched_at' => now(), 'expires_at' => now()->addDays(20),
        ]);
        $this->bindStubClient(['still pending' => $this->metricRow('still pending', 20, 'informational', 300)]);

        (new EnrichPlanKeywordMetricsJob($plan->id))->handle();

        $this->assertCount(1, $this->calls);
        $this->assertSame(['still pending'], $this->calls[0], 'the fresh keyword must not be re-bought');
    }

    /**
     * A fresh GKP measurement (our own free Keyword Finder node) satisfies
     * enrichment too — 98% of what DFS bought in Aug 2026 already had one
     * (~$13/mo of duplicate spend). An EXPIRED gkp row does NOT satisfy it,
     * so DFS self-heals coverage if the node stops producing.
     */
    public function test_fresh_gkp_metrics_are_not_bought_from_dfs(): void
    {
        $plan = $this->coveredPlan();
        $this->keywordRow($plan, 'gkp covered');
        $this->keywordRow($plan, 'gkp expired');
        $this->keywordRow($plan, 'never measured');
        KeywordMetric::create([
            'keyword' => 'gkp covered',
            'keyword_hash' => KeywordMetric::hashKeyword('gkp covered'),
            'country' => 'us', 'data_source' => 'gkp',
            'search_volume' => 1000, 'fetched_at' => now(), 'expires_at' => now()->addDays(20),
        ]);
        KeywordMetric::create([
            'keyword' => 'gkp expired',
            'keyword_hash' => KeywordMetric::hashKeyword('gkp expired'),
            'country' => 'us', 'data_source' => 'gkp',
            'search_volume' => 500, 'fetched_at' => now()->subDays(40), 'expires_at' => now()->subDays(10),
        ]);
        $this->bindStubClient([
            'gkp expired' => $this->metricRow('gkp expired', 30, 'commercial', 500),
            'never measured' => $this->metricRow('never measured', 20, 'informational', 300),
        ]);

        (new EnrichPlanKeywordMetricsJob($plan->id))->handle();

        $this->assertCount(1, $this->calls);
        $bought = $this->calls[0];
        sort($bought);
        $this->assertSame(['gkp expired', 'never measured'], $bought,
            'GKP-covered keyword skipped; expired-GKP and unmeasured keywords still bought');
    }

    /**
     * A PAID dfs enrichment stays bought for the re-buy horizon (365d default)
     * even after its 30-day read-freshness expires — without this the whole
     * library was re-bought monthly once the free GKP rows lapsed in lockstep
     * (~$17/mo and growing, found 2026-08-22). Past the horizon it re-buys.
     */
    public function test_paid_enrichment_is_not_rebought_within_the_horizon(): void
    {
        $plan = $this->coveredPlan();
        $this->keywordRow($plan, 'bought recently');
        $this->keywordRow($plan, 'bought long ago');
        KeywordMetric::create([
            'keyword' => 'bought recently',
            'keyword_hash' => KeywordMetric::hashKeyword('bought recently'),
            'country' => 'us', 'data_source' => 'dfs_labs',
            'keyword_difficulty' => 40,
            'fetched_at' => now()->subDays(100), 'expires_at' => now()->subDays(70), // read-stale, spend-covered
        ]);
        KeywordMetric::create([
            'keyword' => 'bought long ago',
            'keyword_hash' => KeywordMetric::hashKeyword('bought long ago'),
            'country' => 'us', 'data_source' => 'dfs_labs',
            'keyword_difficulty' => 40,
            'fetched_at' => now()->subDays(400), 'expires_at' => now()->subDays(370), // past the horizon
        ]);
        $this->bindStubClient(['bought long ago' => $this->metricRow('bought long ago', 25, 'commercial', 900)]);

        (new EnrichPlanKeywordMetricsJob($plan->id))->handle();

        $this->assertCount(1, $this->calls);
        $this->assertSame(['bought long ago'], $this->calls[0],
            'within-horizon keyword skipped despite expired read-TTL; past-horizon keyword re-bought');
    }

    public function test_heartbeat_trigger_respects_monthly_guard(): void
    {
        Queue::fake();
        $insights = app(ContentKeywordInsights::class);

        $due = $this->coveredPlan();
        $due->forceFill(['keywords_enriched_at' => now()->subMonths(2)])->saveQuietly();
        $insights->ensureEnrichment($due->fresh());

        $done = $this->coveredPlan();
        $done->forceFill(['keywords_enriched_at' => now()->startOfMonth()->addDay()])->saveQuietly();
        $insights->ensureEnrichment($done->fresh());

        $uncovered = $this->coveredPlan(covered: false);
        $insights->ensureEnrichment($uncovered);

        Queue::assertPushed(EnrichPlanKeywordMetricsJob::class, 1);
        Queue::assertPushed(EnrichPlanKeywordMetricsJob::class, fn ($job) => $job->planId === $due->id);
    }
}
