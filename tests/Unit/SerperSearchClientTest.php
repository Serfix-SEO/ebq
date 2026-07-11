<?php

namespace Tests\Unit;

use App\Services\SerperSearchClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerperSearchClientTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Http::fake();

        parent::tearDown();
    }

    public function test_returns_null_when_api_key_missing(): void
    {
        Config::set('services.serper.key', '');
        Config::set('services.serper.base_url', 'https://serper-stub.test');

        $out = app(SerperSearchClient::class)->search('hello world');

        $this->assertNull($out);
        Http::assertNothingSent();
    }

    public function test_posts_json_and_returns_decoded_array_on_success(): void
    {
        Config::set('services.serper.key', 'secret-key');
        Config::set('services.serper.base_url', 'https://serper-stub.test');

        Http::fake([
            'https://serper-stub.test/search' => Http::response([
                'organic' => [
                    ['link' => 'https://example.com/a', 'title' => 'A', 'position' => 1],
                ],
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        $out = app(SerperSearchClient::class)->search('hello', 5);

        $this->assertIsArray($out);
        $this->assertSame('https://example.com/a', $out['organic'][0]['link']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://serper-stub.test/search'
                && $request->hasHeader('X-API-KEY', 'secret-key')
                && $request['q'] === 'hello'
                && (int) $request['num'] === 5
                && ! array_key_exists('gl', $request->data())
                && ! array_key_exists('hl', $request->data());
        });
    }

    public function test_posts_gl_and_hl_when_valid(): void
    {
        Config::set('services.serper.key', 'secret-key');
        Config::set('services.serper.base_url', 'https://serper-stub.test');

        Http::fake([
            'https://serper-stub.test/search' => Http::response(['organic' => []], 200),
        ]);

        $out = app(SerperSearchClient::class)->search('hello', 5, 'de', 'de');
        $this->assertIsArray($out);

        Http::assertSent(function ($request) {
            $d = $request->data();

            return $d['q'] === 'hello'
                && $d['gl'] === 'de'
                && $d['hl'] === 'de';
        });
    }

    public function test_returns_null_on_non_success_status(): void
    {
        Config::set('services.serper.key', 'k');
        Config::set('services.serper.base_url', 'https://serper-stub.test');

        Http::fake([
            'https://serper-stub.test/search' => Http::response(['error' => 'bad'], 500),
        ]);

        $this->assertNull(app(SerperSearchClient::class)->search('q'));
    }

    public function test_returns_null_when_body_is_not_json_object(): void
    {
        Config::set('services.serper.key', 'k');
        Config::set('services.serper.base_url', 'https://serper-stub.test');

        Http::fake([
            'https://serper-stub.test/search' => Http::response('not-json', 200, ['Content-Type' => 'text/plain']),
        ]);

        $this->assertNull(app(SerperSearchClient::class)->search('q'));
    }
}
