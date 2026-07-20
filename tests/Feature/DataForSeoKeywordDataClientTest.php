<?php

namespace Tests\Feature;

use App\Services\DataForSeoKeywordDataClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DataForSeoKeywordDataClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.dataforseo.login', 'u');
        config()->set('services.dataforseo.password', 'p');
        config()->set('services.dataforseo.force_sandbox', false);
    }

    /** Clickstream endpoint wraps rows in result[0].items — must not return 0. */
    public function test_parses_clickstream_items_wrapper(): void
    {
        Http::fake(['*' => Http::response([
            'tasks' => [[
                'cost' => 0.05,
                'status_code' => 20000,
                'result' => [[
                    'items' => [
                        ['keyword' => 'pubg name generator', 'search_volume' => 90500, 'competition' => 0.2],
                        ['keyword' => 'best pubg names', 'search_volume' => 12100, 'competition' => 0.8],
                    ],
                ]],
            ]],
        ])]);

        $out = app(DataForSeoKeywordDataClient::class)->searchVolume(['pubg name generator', 'best pubg names']);

        $this->assertSame(90500, $out['pubg name generator']['search_volume']);
        $this->assertSame(12100, $out['best pubg names']['search_volume']);
        $this->assertSame('high', $out['best pubg names']['competition'] > 0.67 ? 'high' : 'low'); // sanity
    }

    /** google_ads-style flat result[] rows still parse. */
    public function test_parses_flat_result_rows(): void
    {
        Http::fake(['*' => Http::response([
            'tasks' => [[
                'cost' => 0.05,
                'status_code' => 20000,
                'result' => [
                    ['keyword' => 'seo tools', 'search_volume' => 40000],
                ],
            ]],
        ])]);

        $out = app(DataForSeoKeywordDataClient::class)->searchVolume(['seo tools']);
        $this->assertSame(40000, $out['seo tools']['search_volume']);
    }
}
