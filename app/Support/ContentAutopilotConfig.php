<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Admin-tunable Content Autopilot knobs, stored in the `settings` table
 * (live-flippable from /admin/settings, no deploy). Everything here is
 * ADMIN-ONLY — none of these values or their vocabulary may leak into
 * client-facing copy.
 *
 * Per-stage model overrides resolve as:
 *   Setting content.model.{stage}  →  ['provider' => ..., 'model' => ...]
 * falling back to the platform default provider/model (LlmProviderConfig /
 * AiModelConfig) when unset — so a fresh install behaves like the rest of
 * the AI suite until an admin tunes it.
 */
class ContentAutopilotConfig
{
    public const STAGES = ['ideate', 'write', 'revise', 'image_prompts'];

    /**
     * Setting::get that fails safe to the default when the settings table is
     * unreachable (fresh deploy pre-migrate, DB-less unit tests) — same
     * philosophy as LocaleConfig::multilingualEnabled().
     */
    private static function setting(string $key, mixed $default = null): mixed
    {
        try {
            return Setting::get($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    /** @return array{provider:?string, model:?string} */
    public static function modelFor(string $stage): array
    {
        $value = self::setting('content.model.'.$stage);

        if (is_array($value) && isset($value['provider'], $value['model'])) {
            return ['provider' => (string) $value['provider'], 'model' => (string) $value['model']];
        }

        // Unset => platform default provider + its current model. The write
        // stage prefers DeepSeek when configured (quality per $; thinking mode).
        if (in_array($stage, ['write', 'revise'], true)
            && LlmProviderConfig::isConfigured(LlmProviderConfig::PROVIDER_DEEPSEEK)) {
            return [
                'provider' => LlmProviderConfig::PROVIDER_DEEPSEEK,
                'model' => AiModelConfig::currentModel(LlmProviderConfig::PROVIDER_DEEPSEEK),
            ];
        }

        return ['provider' => null, 'model' => null]; // factory default resolution
    }

    public static function setModelFor(string $stage, ?string $provider, ?string $model): void
    {
        if ($provider === null || $model === null || $model === '') {
            Setting::set('content.model.'.$stage, null);

            return;
        }
        Setting::set('content.model.'.$stage, ['provider' => $provider, 'model' => $model]);
    }

    // ── Image generation ────────────────────────────────────────────────

    public static function imagesEnabled(): bool
    {
        return (bool) self::setting('content.images.enabled', true);
    }

    public static function featuredImageEnabled(): bool
    {
        return (bool) self::setting('content.images.featured_enabled', true);
    }

    /** Max INLINE images per article (featured not counted). 0–4. */
    public static function maxInlineImages(): int
    {
        return max(0, min(4, (int) self::setting('content.images.max_inline', 2)));
    }

    public static function renderingSpeed(): string
    {
        $speed = strtoupper((string) self::setting('content.images.rendering_speed', 'TURBO'));

        return in_array($speed, ['FLASH', 'TURBO', 'DEFAULT', 'QUALITY'], true) ? $speed : 'TURBO';
    }

    public static function styleType(): string
    {
        $style = strtoupper((string) self::setting('content.images.style_type', 'AUTO'));

        return in_array($style, ['AUTO', 'GENERAL', 'REALISTIC', 'DESIGN'], true) ? $style : 'AUTO';
    }

    // ── Quality / revision loop ─────────────────────────────────────────

    /** Score at which an article stops revising and becomes ready. */
    public static function targetScore(): int
    {
        return max(50, min(100, (int) self::setting('content.revise.target_score', 85)));
    }

    public static function maxRevisions(): int
    {
        return max(0, min(6, (int) self::setting('content.revise.max_iterations', 3)));
    }

    /** Below this final score the topic fails instead of becoming ready. */
    public static function publishFloor(): int
    {
        return max(0, min(100, (int) self::setting('content.revise.publish_floor', 60)));
    }

    // ── Humanizer ───────────────────────────────────────────────────────

    /** @return list<string> lowercase banned phrases (admin-extendable). */
    public static function bannedPhrases(): array
    {
        $stored = self::setting('content.humanizer.banned_phrases');
        if (is_array($stored) && $stored !== []) {
            return array_values(array_filter(array_map(
                static fn ($p) => mb_strtolower(trim((string) $p)),
                $stored
            )));
        }

        return self::DEFAULT_BANNED_PHRASES;
    }

    /**
     * Seed list of AI-tell phrases the writer must never use and the lint
     * flags. Lowercase; matched case-insensitively on word boundaries.
     *
     * @var list<string>
     */
    public const DEFAULT_BANNED_PHRASES = [
        'delve', 'delving', 'tapestry', 'leverage', 'leveraging', 'unlock the',
        'unleash', 'elevate your', 'embark', 'realm', 'in the realm of',
        'landscape of', 'navigating the', 'game-changer', 'game changer',
        'in conclusion', 'moreover', 'furthermore', 'additionally,',
        'it is important to note', "it's important to note", 'it is worth noting',
        'in today\'s fast-paced', 'in this digital age', 'digital landscape',
        'ever-evolving', 'seamlessly', 'harness the power', 'a testament to',
        'dive into', 'diving into', 'deep dive', 'let\'s explore',
        'look no further', 'in summary', 'to summarize', 'ultimately,',
        'robust', 'holistic', 'synergy', 'paradigm', 'cutting-edge',
        'revolutionize', 'supercharge', 'skyrocket', 'boast', 'boasts',
        'whether you\'re a', 'we\'ve got you covered', 'without further ado',
        'at the end of the day', 'needless to say', 'when it comes to',
        'the world of', 'not only', 'but also', 'crucial role',
        'vital role', 'comprehensive guide', 'ultimate guide to success',
        'stay ahead of the curve', 'take your .* to the next level',
        'in a nutshell', 'first and foremost', 'last but not least',
    ];
}
