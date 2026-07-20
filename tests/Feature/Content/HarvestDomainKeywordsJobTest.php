<?php

namespace Tests\Feature\Content;

use App\Jobs\Content\HarvestDomainKeywordsJob;
use App\Models\DomainKeywordHarvest;
use App\Models\DomainKeywordRanking;
use App\Models\KeywordMetric;
use App\Services\DataForSeoBacklinkClient;
use App\Services\Reports\DataForSeoSpendMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HarvestDomainKeywordsJobTest extends TestCase
{
    use RefreshDatabase;

    private function configureDfs(): void
    {
        config([
            'services.dataforseo.login' => 'x', 'services.dataforseo.password' => 'y',
            'services.dataforseo.force_sandbox' => false, 'services.dataforseo.monthly_cap_usd' => null,
        ]);
    }

    private function fakeRanked(array $items): void
    {
        Http::fake([
            'api.dataforseo.com/*' => Http::response([
                'tasks' => [[
                    'cost' => 0.011, 'status_code' => 20000,
                    'result' => [['items' => $items]],
                ]],
            ], 200),
        ]);
    }

    private function item(string $kw, int $vol, int $rank): array
    {
        return [
            'keyword_data' => [
                'keyword' => $kw,
                'keyword_info' => ['search_volume' => $vol, 'cpc' => 0.5, 'competition' => 0.2],
                'keyword_properties' => ['keyword_difficulty' => 34],
                'search_intent_info' => ['main_intent' => 'informational'],
            ],
            'ranked_serp_element' => ['serp_item' => [
                'rank_absolute' => $rank, 'type' => 'organic', 'url' => "https://rival.com/{$rank}",
                'etv' => 1200.5, 'rank_changes' => ['previous_rank_absolute' => $rank + 2, 'is_up' => true],
            ]],
        ];
    }

    private function harvest(string $domain, string $country = 'us'): void
    {
        (new HarvestDomainKeywordsJob($domain, $country))->handle(
            app(DataForSeoBacklinkClient::class), app(DataForSeoSpendMeter::class)
        );
    }

    public function test_it_harvests_into_keyword_metrics_and_rankings_and_sets_cursor(): void
    {
        $this->configureDfs();
        $this->fakeRanked([$this->item('stylish name', 5000, 3), $this->item('cool nickname', 800, 7)]);

        $this->harvest('https://www.rival.com/');

        $this->assertSame(2, KeywordMetric::where('data_source', 'dfs_labs')->count());
        $km = KeywordMetric::where('keyword', 'stylish name')->first();
        $this->assertSame(5000, $km->search_volume);
        $this->assertSame(34, $km->keyword_difficulty);
        $this->assertSame('informational', $km->search_intent);

        $this->assertSame(2, DomainKeywordRanking::where('domain', 'rival.com')->count());
        $dkr = DomainKeywordRanking::where('domain', 'rival.com')->where('keyword', 'stylish name')->first();
        $this->assertSame(3, $dkr->rank_absolute);
        $this->assertSame('https://rival.com/3', $dkr->page_url);

        $h = DomainKeywordHarvest::where('domain', 'rival.com')->where('country', 'us')->first();
        $this->assertSame(800, $h->volume_cursor);       // lowest volume in the batch
        $this->assertSame(2, $h->keywords_fetched);
        $this->assertTrue($h->exhausted);                // 2 < limit(1000) → no more
    }

    public function test_empty_response_does_not_mark_exhausted(): void
    {
        // A transient API blip returns no rows — the domain must stay harvestable
        // (marking it exhausted permanently killed a live domain on prod).
        $this->configureDfs();
        $this->fakeRanked([]);

        $this->harvest('rival.com');

        $h = DomainKeywordHarvest::where('domain', 'rival.com')->where('country', 'us')->first();
        $this->assertNotNull($h);
        $this->assertFalse($h->exhausted);
        $this->assertNotNull($h->last_run_at);
    }

    public function test_second_run_skips_when_exhausted(): void
    {
        $this->configureDfs();
        DomainKeywordHarvest::query()->create([
            'domain' => 'rival.com', 'country' => 'us', 'exhausted' => true, 'keywords_fetched' => 10,
        ]);
        Http::fake();

        $this->harvest('rival.com');

        Http::assertNothingSent();
    }
}
