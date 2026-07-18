<?php

namespace Tests\Unit\Content;

use App\Services\Content\IdeogramClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ideogram model routing: the configured model version drives BOTH the
 * endpoint path segment and the prompt field name. v4 (Ideogram 4.0)
 * renamed prompt→text_prompt; everything else is shared. Verified live
 * against api.ideogram.ai on 2026-07-18.
 */
class IdeogramModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ideogram.key' => 'fake-key',
            'services.ideogram.base_url' => 'https://api.ideogram.ai/v1',
        ]);
        Http::fake([
            'api.ideogram.ai/*' => Http::response(['data' => [['url' => 'https://cdn.ideogram.ai/x.png', 'seed' => 1]]], 200),
        ]);
    }

    public function test_v4_hits_v4_path_with_text_prompt_field(): void
    {
        config(['services.ideogram.model' => 'v4']);

        $r = app(IdeogramClient::class)->generate('a blue square', ['rendering_speed' => 'TURBO']);
        $this->assertTrue($r['ok']);

        Http::assertSent(function ($req) {
            return str_contains($req->url(), '/ideogram-v4/generate')
                && ($req->data()['text_prompt'] ?? null) === 'a blue square'
                && ! array_key_exists('prompt', $req->data())
                && ($req->data()['rendering_speed'] ?? null) === 'TURBO';
        });
    }

    public function test_v3_default_hits_v3_path_with_prompt_field(): void
    {
        // no model configured → 'v3' default
        $r = app(IdeogramClient::class)->generate('a red circle');
        $this->assertTrue($r['ok']);

        Http::assertSent(function ($req) {
            return str_contains($req->url(), '/ideogram-v3/generate')
                && ($req->data()['prompt'] ?? null) === 'a red circle'
                && ! array_key_exists('text_prompt', $req->data());
        });
    }

    public function test_turbo_pricing_unchanged(): void
    {
        $this->assertSame(0.03, app(IdeogramClient::class)->costPerImage('TURBO'));
    }
}
