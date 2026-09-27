<?php

namespace App\Services\Content\Aeo;

use App\Models\ContentAeoQuestion;
use App\Models\ContentAeoRun;
use App\Models\ContentPlan;
use App\Models\Website;
use App\Services\Content\ContentLlmSpendMeter;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmClientFactory;
use App\Support\ContentAutopilotConfig;
use Illuminate\Support\Facades\Log;

/**
 * Asks a model a buyer's question and records who it names.
 *
 * **What this measures, precisely.** The model answers from its own memory,
 * with no browsing. That is not "citation share in ChatGPT" — nobody can
 * measure that without the vendor's API, and this product will not pretend
 * otherwise. It is whether the model *knows* the client, which is what decides
 * the answer for every question asked without retrieval, and it is the thing
 * that moves when a brand starts being written about.
 *
 * **The model never decides whether the client was mentioned.** It returns a
 * plain list of businesses in the order it would recommend them; deterministic
 * PHP then matches that list against the brand's own names and domain. Asking
 * an LLM "were you mentioned?" invites it to be agreeable, and an agreeable
 * answer here is a lie that flatters the client and the product at once.
 *
 * Cost: one small completion per question per engine — roughly $0.0002 on
 * DeepSeek, charged through {@see ContentLlmSpendMeter} like every other
 * content call, and `__unmetered` so it never eats the dashboard's token cap.
 */
class BrandRecallProbe
{
    /** Deliberately generous: a short list is what a person would be told. */
    private const MAX_BRANDS = 8;

    /**
     * $llm is injected when the container has one bound (tests, and any caller
     * that wants a specific client); otherwise each engine is resolved through
     * the factory. Same shape as CatalogBrandValidator, for the same reason:
     * a service that can only build its own dependency cannot be tested.
     */
    public function __construct(
        private readonly ?ContentLlmSpendMeter $meter = null,
        private readonly ?LlmClient $llm = null,
    ) {}

    /**
     * Run one question against one engine and store the result.
     *
     * Always writes a row — a failed probe is recorded as a failure, never
     * left to look like "you were not mentioned".
     */
    public function run(ContentAeoQuestion $question, Website $website, string $engine = ContentAeoRun::ENGINE_DEEPSEEK): ContentAeoRun
    {
        $plan = ContentPlan::query()->where('website_id', $website->id)->first();
        $answer = $this->ask((string) $question->question, $plan, $engine);

        if ($answer === null) {
            return $this->store($question, $website, $engine, [
                'ok' => false,
                'error' => 'The model did not return a usable answer.',
                // Written explicitly rather than left to the column default:
                // a failed probe must not carry an ambiguous "mentioned" that
                // a later read could mistake for a measured miss.
                'mentioned' => false,
                'mention_rank' => null,
                'competitors' => [],
            ]);
        }

        $brands = $this->brands($answer);
        $rank = $this->rankOf($brands, $website, $plan);

        return $this->store($question, $website, $engine, [
            'ok' => true,
            'mentioned' => $rank !== null,
            'mention_rank' => $rank,
            // Everyone else it named, in order — the part a client acts on.
            'competitors' => array_values(array_slice(array_filter(
                $brands,
                fn (string $b): bool => ! $this->isOurs($b, $website, $plan)
            ), 0, self::MAX_BRANDS)),
            'excerpt' => mb_substr(trim((string) ($answer['summary'] ?? '')), 0, 1000),
        ]);
    }

    /**
     * One completion, no browsing, strict JSON.
     *
     * @return array{brands?: list<string>, summary?: string}|null
     */
    private function ask(string $question, ?ContentPlan $plan, string $engine): ?array
    {
        $llm = $this->llm
            ?? LlmClientFactory::make($engine === ContentAeoRun::ENGINE_MISTRAL ? 'mistral' : 'deepseek');
        if (! $llm->isAvailable()) {
            return null;
        }

        $market = filled($plan?->country) && $plan->country !== 'global'
            ? ' The person asking is in '.strtoupper((string) $plan->country).'.'
            : '';

        $user = <<<PROMPT
        Someone asks you: "{$question}"{$market}

        Answer from your own knowledge. Do not browse, and do not say you cannot browse.
        If you genuinely do not know any specific businesses, return an empty list — a guess is worse than nothing.

        List the businesses, brands or products you would actually name in your answer, in the order you
        would name them. Use the names people know them by.

        Return JSON only: {"brands": ["...", "..."], "summary": "one sentence of what you would say"}
        PROMPT;

        $options = [
            'temperature' => 0,   // the same question should give the same answer
            'max_tokens' => 400,
            'timeout' => 45,
            '__source' => 'content_autopilot.aeo_probe',
            '__unmetered' => true,
        ];

        try {
            $response = $llm->completeJson([
                ['role' => 'system', 'content' => 'You answer from memory and reply with valid JSON only.'],
                ['role' => 'user', 'content' => $user],
            ], $options);
        } catch (\Throwable $e) {
            Log::warning('aeo.probe_failed', ['engine' => $engine, 'error' => mb_substr($e->getMessage(), 0, 160)]);

            return null;
        }

        ($this->meter ?? app(ContentLlmSpendMeter::class))->add(ContentAutopilotConfig::aeoProbeCostUsd());

        return is_array($response) ? $response : null;
    }

    /** @return list<string> */
    private function brands(array $answer): array
    {
        $brands = $answer['brands'] ?? [];

        return array_values(array_filter(array_map(
            static fn ($b): string => trim((string) $b),
            is_array($brands) ? $brands : []
        )));
    }

    /**
     * Where the client appears in the model's list, 1-based, or null.
     *
     * Null means "not named", which is a different fact from "named last" —
     * the score treats them differently and so does the client.
     */
    private function rankOf(array $brands, Website $website, ?ContentPlan $plan): ?int
    {
        foreach ($brands as $i => $brand) {
            if ($this->isOurs($brand, $website, $plan)) {
                return $i + 1;
            }
        }

        return null;
    }

    /**
     * Is this one of the client's own names?
     *
     * Matches the domain's brand token, the registered name, and the domain
     * itself. Deliberately strict on short names: a two-letter brand would
     * match half the alphabet soup a model returns.
     */
    private function isOurs(string $brand, Website $website, ?ContentPlan $plan): bool
    {
        $needle = $this->fold($brand);
        if ($needle === '') {
            return false;
        }

        foreach ($this->ourNames($website, $plan) as $name) {
            if ($name === '' || mb_strlen($name) < 4) {
                continue;
            }
            if ($needle === $name || str_contains($needle, $name) || str_contains($name, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function ourNames(Website $website, ?ContentPlan $plan): array
    {
        $host = strtolower((string) ($website->normalized_domain ?: $website->domain));
        $names = [$host];
        // The brand token: "bellavest" from "bellavest.ae".
        if (($dot = strpos($host, '.')) !== false) {
            $names[] = substr($host, 0, $dot);
        }
        if (filled($plan?->org_legal_name)) {
            $names[] = (string) $plan->org_legal_name;
        }

        return array_values(array_unique(array_filter(array_map(fn (string $n): string => $this->fold($n), $names))));
    }

    private function fold(string $value): string
    {
        $value = mb_strtolower(trim($value));
        // Drop the noise that makes two spellings of one brand look different:
        // "Bellavest Trading L.L.C." and "bellavest" are the same business.
        $value = preg_replace('/\b(llc|l\.l\.c|ltd|limited|inc|co|company|trading|fz|fze|dmcc)\b/u', '', $value) ?? $value;

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? $value);
    }

    private function store(ContentAeoQuestion $question, Website $website, string $engine, array $attributes): ContentAeoRun
    {
        $run = ContentAeoRun::query()->updateOrCreate(
            [
                'question_id' => $question->id,
                'engine' => $engine,
                'ran_on' => now()->toDateString(),
            ],
            $attributes + ['website_id' => $website->id]
        );

        $question->forceFill(['last_run_at' => now()])->save();

        return $run;
    }
}
