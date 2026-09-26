<?php

namespace App\Support\Aeo;

/**
 * Every AI agent we care about, in one place: what it calls itself in a
 * robots.txt group, what it calls itself in a User-Agent header, what a visit
 * it sent looks like in GA4, and — the part clients always ask about — what
 * it actually DOES with the page.
 *
 * Three kinds, and the difference matters when we tell someone "you blocked
 * this":
 *   - training   — reads pages to train a model. Blocking it costs you nothing
 *                  today and keeps you out of tomorrow's model.
 *   - search     — builds the index an answer engine cites from. Blocking it
 *                  removes you from that engine's answers.
 *   - user       — fetches a page because a person just asked about it. This
 *                  is the one that turns into a citation and a click.
 *
 * Two entries never make a request at all: Google-Extended and
 * Applebot-Extended are POLICY TOKENS — they exist only as robots.txt names
 * that opt you out of Gemini and Apple Intelligence training. They will never
 * appear in a hit log, so `crawls` is false and the UI must not show them as
 * "never seen"; that would read as a problem when it is simply how they work.
 */
class AiAgents
{
    public const TRAINING = 'training';

    public const SEARCH = 'search';

    public const USER = 'user';

    /**
     * Keyed by our stable internal id (also the value stored in
     * content_ai_crawler_hits.bot).
     *
     * token   — the lowercase robots.txt product token
     * ua      — lowercase needles matched against the User-Agent header
     * crawls  — false for policy-only tokens that never send a request
     *
     * @return array<string, array{label: string, engine: string, kind: string, token: string, ua: list<string>, crawls: bool}>
     */
    public static function all(): array
    {
        return [
            'gptbot' => [
                'label' => 'GPTBot', 'engine' => 'ChatGPT', 'kind' => self::TRAINING,
                'token' => 'gptbot', 'ua' => ['gptbot'], 'crawls' => true,
            ],
            'oai_searchbot' => [
                'label' => 'OAI-SearchBot', 'engine' => 'ChatGPT', 'kind' => self::SEARCH,
                'token' => 'oai-searchbot', 'ua' => ['oai-searchbot'], 'crawls' => true,
            ],
            'chatgpt_user' => [
                'label' => 'ChatGPT-User', 'engine' => 'ChatGPT', 'kind' => self::USER,
                'token' => 'chatgpt-user', 'ua' => ['chatgpt-user'], 'crawls' => true,
            ],
            'perplexitybot' => [
                'label' => 'PerplexityBot', 'engine' => 'Perplexity', 'kind' => self::SEARCH,
                'token' => 'perplexitybot', 'ua' => ['perplexitybot'], 'crawls' => true,
            ],
            'perplexity_user' => [
                'label' => 'Perplexity-User', 'engine' => 'Perplexity', 'kind' => self::USER,
                'token' => 'perplexity-user', 'ua' => ['perplexity-user'], 'crawls' => true,
            ],
            'claudebot' => [
                'label' => 'ClaudeBot', 'engine' => 'Claude', 'kind' => self::TRAINING,
                'token' => 'claudebot', 'ua' => ['claudebot', 'anthropic-ai'], 'crawls' => true,
            ],
            'claude_searchbot' => [
                'label' => 'Claude-SearchBot', 'engine' => 'Claude', 'kind' => self::SEARCH,
                'token' => 'claude-searchbot', 'ua' => ['claude-searchbot'], 'crawls' => true,
            ],
            'claude_user' => [
                'label' => 'Claude-User', 'engine' => 'Claude', 'kind' => self::USER,
                'token' => 'claude-user', 'ua' => ['claude-user'], 'crawls' => true,
            ],
            'google_extended' => [
                'label' => 'Google-Extended', 'engine' => 'Gemini', 'kind' => self::TRAINING,
                'token' => 'google-extended', 'ua' => [], 'crawls' => false,
            ],
            'applebot_extended' => [
                'label' => 'Applebot-Extended', 'engine' => 'Apple Intelligence', 'kind' => self::TRAINING,
                'token' => 'applebot-extended', 'ua' => [], 'crawls' => false,
            ],
            'ccbot' => [
                'label' => 'CCBot', 'engine' => 'Common Crawl', 'kind' => self::TRAINING,
                'token' => 'ccbot', 'ua' => ['ccbot'], 'crawls' => true,
            ],
            'bytespider' => [
                'label' => 'Bytespider', 'engine' => 'ByteDance', 'kind' => self::TRAINING,
                'token' => 'bytespider', 'ua' => ['bytespider'], 'crawls' => true,
            ],
            'meta_externalagent' => [
                'label' => 'meta-externalagent', 'engine' => 'Meta AI', 'kind' => self::TRAINING,
                'token' => 'meta-externalagent', 'ua' => ['meta-externalagent'], 'crawls' => true,
            ],
            'amazonbot' => [
                'label' => 'Amazonbot', 'engine' => 'Alexa / Rufus', 'kind' => self::TRAINING,
                'token' => 'amazonbot', 'ua' => ['amazonbot'], 'crawls' => true,
            ],
            'mistralai_user' => [
                'label' => 'MistralAI-User', 'engine' => 'Le Chat', 'kind' => self::USER,
                'token' => 'mistralai-user', 'ua' => ['mistralai-user'], 'crawls' => true,
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> only the agents that really fetch pages */
    public static function crawlers(): array
    {
        return array_filter(self::all(), static fn (array $a): bool => $a['crawls']);
    }

    /** @return list<string> robots.txt tokens, for RobotsTxtParser::isBlocked() */
    public static function tokens(): array
    {
        return array_values(array_map(static fn (array $a): string => $a['token'], self::all()));
    }

    public static function label(string $id): string
    {
        return self::all()[$id]['label'] ?? $id;
    }

    /** @return array<string, mixed>|null */
    public static function find(string $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    /**
     * Which agent a User-Agent header belongs to, or null.
     *
     * Longest needle first so "claude-searchbot" is not swallowed by a shorter
     * sibling, and so a header naming two products resolves to the specific one.
     */
    public static function matchUserAgent(string $userAgent): ?string
    {
        $ua = mb_strtolower($userAgent);
        if ($ua === '') {
            return null;
        }

        $best = null;
        $bestLen = 0;
        foreach (self::all() as $id => $agent) {
            foreach ($agent['ua'] as $needle) {
                if (strlen($needle) > $bestLen && str_contains($ua, $needle)) {
                    $best = $id;
                    $bestLen = strlen($needle);
                }
            }
        }

        return $best;
    }

    /**
     * GA4 `sessionSource` values that mean "a person came here from an AI
     * answer". Matched case-insensitively against the stored source string.
     *
     * @return array<string, string> source value => engine label
     */
    public static function referralSources(): array
    {
        return [
            'chatgpt.com' => 'ChatGPT',
            'chat.openai.com' => 'ChatGPT',
            'openai.com' => 'ChatGPT',
            'perplexity.ai' => 'Perplexity',
            'www.perplexity.ai' => 'Perplexity',
            'gemini.google.com' => 'Gemini',
            'bard.google.com' => 'Gemini',
            'copilot.microsoft.com' => 'Copilot',
            'claude.ai' => 'Claude',
            'you.com' => 'You.com',
            'poe.com' => 'Poe',
            'chat.mistral.ai' => 'Le Chat',
        ];
    }

    /** @return list<string> the raw source values, for a whereIn() */
    public static function referralSourceValues(): array
    {
        return array_keys(self::referralSources());
    }
}
