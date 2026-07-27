<?php

namespace Tests\Feature\Content;

use App\Models\DomainKeywordHarvest;
use App\Models\DomainKeywordRanking;
use App\Models\KeywordApiRequest;
use App\Models\KeywordApiServer;
use App\Services\KeywordFinder\KeywordFinderPool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The self-hosted keyword server feeds the competitor gap: a TAGGED site-scope
 * harvest's webhook writes the domain's keywords into domain_keyword_rankings so
 * ClassifyPlanKeywordsJob can build the gap without DataForSEO.
 */
class CompetitorGapHarvestTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'webhook-secret';

    private function makeRequest(array $payload): KeywordApiRequest
    {
        $server = KeywordApiServer::create([
            'name' => 'A', 'base_url' => 'http://a.test', 'api_key' => 'k',
            'webhook_secret' => $this->secret, 'is_active' => true,
        ]);

        return KeywordApiRequest::create([
            'request_id' => (string) Str::uuid(),
            'keyword_api_server_id' => $server->id,
            'type' => KeywordApiRequest::TYPE_IDEAS,
            'payload' => $payload,
            'status' => KeywordApiRequest::STATUS_RUNNING,
        ]);
    }

    private function postWebhook(array $body): TestResponse
    {
        $raw = json_encode($body);
        $sig = hash_hmac('sha256', $raw, $this->secret);

        return $this->call('POST', '/webhooks/keyword-finder', [], [], [], [
            'HTTP_X_WEBHOOK_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json',
        ], $raw);
    }

    private function results(): array
    {
        return ['results' => [
            ['keyword' => 'branding agency dubai', 'avgMonthlySearches' => 2400, 'competitionIndex' => 55],
            ['keyword' => 'cinematic video production', 'avgMonthlySearches' => 880, 'competitionIndex' => 40],
        ]];
    }

    public function test_tagged_site_harvest_writes_domain_rankings(): void
    {
        $req = $this->makeRequest([
            'url' => 'https://sensa.digital', 'scope' => 'site',
            'country_key' => 'global', 'harvest_country' => 'global',
            'content_rank_harvest' => true,
        ]);

        $this->postWebhook(['request_id' => $req->request_id, 'result' => $this->results()])->assertOk();

        $rows = DomainKeywordRanking::query()->where('domain', 'sensa.digital')->where('country', 'global')->get();
        $this->assertCount(2, $rows);
        $one = $rows->firstWhere('keyword', 'branding agency dubai');
        $this->assertNotNull($one);
        $this->assertSame(2400, (int) $one->search_volume);
        $this->assertNull($one->rank_absolute); // keyword server has no SERP position

        $harvest = DomainKeywordHarvest::query()->where('domain', 'sensa.digital')->where('country', 'global')->first();
        $this->assertNotNull($harvest);
        $this->assertNotNull($harvest->last_run_at);
        $this->assertTrue((bool) $harvest->exhausted);
    }

    public function test_untagged_site_request_does_not_write_rankings(): void
    {
        // A normal finder/site request (no content_rank_harvest) must NOT pollute
        // domain_keyword_rankings — only keyword_metrics (unchanged behaviour).
        $req = $this->makeRequest([
            'url' => 'https://sensa.digital', 'scope' => 'site', 'country_key' => 'global',
        ]);

        $this->postWebhook(['request_id' => $req->request_id, 'result' => $this->results()])->assertOk();

        $this->assertSame(0, DomainKeywordRanking::query()->where('domain', 'sensa.digital')->count());
        $this->assertNull(DomainKeywordHarvest::query()->where('domain', 'sensa.digital')->first());
    }

    public function test_pool_payload_carries_the_harvest_tag(): void
    {
        [$mode, $payload] = app(KeywordFinderPool::class)->buildIdeasPayload(
            ['url' => 'https://x.test', 'scope' => 'site', 'content_rank_harvest' => true],
            'ae',
        );

        $this->assertSame('website', $mode);
        $this->assertTrue($payload['content_rank_harvest']);
        $this->assertSame('ae', $payload['harvest_country']);
    }
}
