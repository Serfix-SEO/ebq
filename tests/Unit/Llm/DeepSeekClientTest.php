<?php

namespace Tests\Unit\Llm;

use App\Services\Llm\DeepSeekClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeepSeekClientTest extends TestCase
{
    private function fakeCompletion(string $content = 'hello', string $model = 'deepseek-chat'): array
    {
        return [
            'model' => $model,
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => $content]],
            ],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];
    }

    public function test_complete_happy_path_extracts_content_model_and_usage(): void
    {
        Http::fake(['api.deepseek.com/*' => Http::response($this->fakeCompletion())]);

        $client = new DeepSeekClient('sk-test');
        $out = $client->complete([['role' => 'user', 'content' => 'hi']]);

        $this->assertTrue($out['ok']);
        $this->assertSame('hello', $out['content']);
        $this->assertSame('deepseek-chat', $out['model']);
        $this->assertSame(15, $out['usage']['total']);
        // Undocumented-but-relied-on keys for completeWithTools.
        $this->assertArrayHasKey('tool_calls', $out);
        $this->assertArrayHasKey('message', $out);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.deepseek.com/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer sk-test'));
    }

    public function test_missing_key_returns_deepseek_prefixed_error(): void
    {
        Http::fake();

        $out = (new DeepSeekClient(''))->complete([['role' => 'user', 'content' => 'hi']]);

        $this->assertFalse($out['ok']);
        $this->assertSame('deepseek_api_key_missing', $out['error']);
        Http::assertNothingSent();
    }

    public function test_http_429_maps_to_deepseek_http_429(): void
    {
        Http::fake(['api.deepseek.com/*' => Http::response(['error' => 'rate limited'], 429)]);

        $out = (new DeepSeekClient('sk-test'))->complete([['role' => 'user', 'content' => 'hi']]);

        $this->assertFalse($out['ok']);
        $this->assertSame('deepseek_http_429', $out['error']);
    }

    public function test_connection_failure_maps_to_deepseek_network_error(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('timeout');
        });

        $out = (new DeepSeekClient('sk-test'))->complete([['role' => 'user', 'content' => 'hi']]);

        $this->assertFalse($out['ok']);
        $this->assertSame('deepseek_network_error', $out['error']);
    }

    public function test_max_tokens_clamped_to_32k_and_writer_scale_passes_through(): void
    {
        Http::fake(['api.deepseek.com/*' => Http::response($this->fakeCompletion())]);
        $client = new DeepSeekClient('sk-test');

        // AI Writer's 16k request must NOT be clamped (V4 accepts it —
        // the old 8192 clamp silently truncated article generation).
        $client->complete(
            [['role' => 'user', 'content' => 'write a long article about json output']],
            ['max_tokens' => 16000],
        );
        Http::assertSent(fn ($request) => $request['max_tokens'] === 16000);

        Http::fake(['api.deepseek.com/*' => Http::response($this->fakeCompletion())]);

        $client->complete(
            [['role' => 'user', 'content' => 'write a long article about json output']],
            ['max_tokens' => 64000],
        );
        Http::assertSent(fn ($request) => $request['max_tokens'] === 32768);
    }

    public function test_json_mode_appends_json_nudge_only_when_prompt_lacks_json(): void
    {
        Http::fake(['api.deepseek.com/*' => Http::response($this->fakeCompletion('{"a":1}'))]);
        $client = new DeepSeekClient('sk-test');

        // Prompt without the word "json" → nudge appended.
        $client->completeJson([['role' => 'user', 'content' => 'list three colours']]);
        Http::assertSent(function ($request) {
            $messages = $request['messages'];
            $last = end($messages);
            return ($request['response_format']['type'] ?? '') === 'json_object'
                && $last['role'] === 'system'
                && str_contains($last['content'], 'JSON');
        });

        Http::fake(['api.deepseek.com/*' => Http::response($this->fakeCompletion('{"a":1}'))]);

        // Prompt already mentions JSON → body unchanged.
        $client->completeJson([['role' => 'user', 'content' => 'Return a JSON object with three colours']]);
        Http::assertSent(fn ($request) => count($request['messages']) === 1);
    }

    public function test_reasoning_option_enables_thinking_mode(): void
    {
        Http::fake(['api.deepseek.com/*' => Http::response($this->fakeCompletion())]);

        (new DeepSeekClient('sk-test'))->complete(
            [['role' => 'user', 'content' => 'hi']],
            ['reasoning' => true],
        );

        // budget_tokens must ride along — reasoning shares max_tokens
        // with the answer and would starve it unbounded.
        Http::assertSent(fn ($request) => ($request['thinking']['type'] ?? '') === 'enabled'
            && ($request['thinking']['budget_tokens'] ?? 0) > 0);
    }

    public function test_thinking_is_explicitly_disabled_by_default(): void
    {
        // V4 models think BY DEFAULT when the flag is omitted — the client
        // must actively disable it on non-reasoning calls or small
        // max_tokens budgets get eaten by reasoning_content (live incident
        // 2026-07-11: empty-content briefs in the blog-post wizard).
        Http::fake(['api.deepseek.com/*' => Http::response($this->fakeCompletion())]);

        (new DeepSeekClient('sk-test'))->complete([['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(fn ($request) => ($request['thinking']['type'] ?? '') === 'disabled');
    }
}
