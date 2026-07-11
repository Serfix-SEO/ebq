<?php

namespace Tests\Unit\Llm;

use App\Services\Llm\MistralClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression guard for the OpenAiCompatibleClient extraction: the Mistral
 * error codes are a de-facto contract (stored in activity rows, mapped to
 * UI copy) and must stay byte-identical to the pre-refactor client.
 */
class MistralClientTest extends TestCase
{
    private function fakeCompletion(array $message, array $usage = []): array
    {
        return [
            'model' => 'mistral-small-latest',
            'choices' => [['message' => $message]],
            'usage' => $usage + ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];
    }

    public function test_error_codes_stay_mistral_prefixed(): void
    {
        $out = (new MistralClient(''))->complete([['role' => 'user', 'content' => 'hi']]);
        $this->assertSame('mistral_api_key_missing', $out['error']);

        Http::fake(['api.mistral.ai/*' => Http::response('nope', 500)]);
        $out = (new MistralClient('sk-test'))->complete([['role' => 'user', 'content' => 'hi']]);
        $this->assertSame('mistral_http_500', $out['error']);

        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('timeout');
        });
        $out = (new MistralClient('sk-test'))->complete([['role' => 'user', 'content' => 'hi']]);
        $this->assertSame('mistral_network_error', $out['error']);
    }

    public function test_complete_posts_to_mistral_endpoint_with_defaults(): void
    {
        Http::fake(['api.mistral.ai/*' => Http::response($this->fakeCompletion(['role' => 'assistant', 'content' => 'hey']))]);

        $out = (new MistralClient('sk-test'))->complete([['role' => 'user', 'content' => 'hi']]);

        $this->assertTrue($out['ok']);
        $this->assertSame('hey', $out['content']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.mistral.ai/v1/chat/completions'
            && $request['model'] === 'mistral-small-latest');
    }

    public function test_complete_with_tools_dispatches_then_returns_final_json(): void
    {
        $toolCallResponse = $this->fakeCompletion([
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [[
                'id' => 'call_1',
                'function' => ['name' => 'lookup', 'arguments' => '{"q":"seo"}'],
            ]],
        ]);
        $finalResponse = $this->fakeCompletion([
            'role' => 'assistant',
            'content' => '```json'."\n".'{"answer": 42,}'."\n".'```',
        ]);

        Http::fake(['api.mistral.ai/*' => Http::sequence()
            ->push($toolCallResponse)
            ->push($finalResponse)]);

        $dispatched = [];
        $out = (new MistralClient('sk-test'))->completeWithTools(
            [['role' => 'user', 'content' => 'question']],
            [['type' => 'function', 'function' => ['name' => 'lookup']]],
            function (string $name, array $args) use (&$dispatched) {
                $dispatched[] = [$name, $args];
                return ['result' => 'data'];
            },
        );

        $this->assertTrue($out['ok']);
        $this->assertSame([['lookup', ['q' => 'seo']]], $dispatched);
        // Tolerant decode handled the fenced JSON with a trailing comma.
        $this->assertSame(['answer' => 42], $out['decoded']);
        $this->assertSame(30, $out['usage']['total']);
    }
}
