<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\AiModelConfig;
use App\Support\AuditConfig;
use App\Support\KeywordProviderConfig;
use App\Support\LlmProviderConfig;
use App\Support\LocaleConfig;
use App\Support\RankTrackerConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Single consolidated admin settings page. Replaces the previously separate
 * AI model / Rank tracker / Page audit settings pages with one screen:
 *
 *   - Active AI provider (Mistral / DeepSeek) + default model per provider
 *   - Rank tracker default re-check interval
 *   - Keywords Everywhere competitor-data toggle
 *
 * Each control still reads/writes its existing config helper + Setting key,
 * so behaviour is unchanged — only the UI is unified.
 */
class PlatformSettingsController extends Controller
{
    public function edit(): View
    {
        return view('admin.settings.index', [
            'llmProvider'                  => LlmProviderConfig::currentProvider(),
            'llmProviders'                 => LlmProviderConfig::options(),
            'mistralModels'                => AiModelConfig::listAvailableModels(LlmProviderConfig::PROVIDER_MISTRAL),
            'deepseekModels'               => AiModelConfig::listAvailableModels(LlmProviderConfig::PROVIDER_DEEPSEEK),
            'currentMistralModel'          => AiModelConfig::currentModel(LlmProviderConfig::PROVIDER_MISTRAL),
            'currentDeepseekModel'         => AiModelConfig::currentModel(LlmProviderConfig::PROVIDER_DEEPSEEK),
            'checkIntervalHours'           => RankTrackerConfig::checkIntervalHours(),
            'defaultDepth'                 => RankTrackerConfig::DEFAULT_DEPTH,
            'competitorKeywordsEverywhere' => AuditConfig::competitorKeywordsEverywhereEnabled(),
            'keywordProvider'              => KeywordProviderConfig::currentProvider(),
            'keywordProviders'             => KeywordProviderConfig::options(),
            'multilingualEnabled'          => LocaleConfig::multilingualEnabled(),
            'banner' => [
                'enabled'     => ((string) Setting::get('plugin.banner.enabled', '0')) === '1',
                'type'        => (string) Setting::get('plugin.banner.type', 'image'),
                'title'       => (string) Setting::get('plugin.banner.title', ''),
                'image_url'   => (string) Setting::get('plugin.banner.image_url', ''),
                'link_url'    => (string) Setting::get('plugin.banner.link_url', ''),
                'youtube_url' => (string) Setting::get('plugin.banner.youtube_url', ''),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $mistralIds = array_column(AiModelConfig::listAvailableModels(LlmProviderConfig::PROVIDER_MISTRAL), 'id');
        $deepseekIds = array_column(AiModelConfig::listAvailableModels(LlmProviderConfig::PROVIDER_DEEPSEEK), 'id');

        $data = $request->validate([
            'llm_provider' => [
                'required', 'string', Rule::in(LlmProviderConfig::PROVIDERS),
                // Refuse activating a provider whose API key isn't
                // configured — one dropdown save must not take down every
                // AI feature with {provider}_api_key_missing.
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (is_string($value) && in_array($value, LlmProviderConfig::PROVIDERS, true)
                        && ! LlmProviderConfig::isConfigured($value)) {
                        $fail('That AI provider has no API key configured on the server.');
                    }
                },
            ],
            // Field name `model` kept for the Mistral select (form back-compat).
            'model' => ['required', 'string', Rule::in($mistralIds)],
            'deepseek_model' => ['required', 'string', Rule::in($deepseekIds)],
            'default_check_interval_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'competitor_keywords_everywhere' => ['nullable', 'boolean'],
            'multilingual_enabled' => ['nullable', 'boolean'],
            'keyword_volume_provider' => ['required', 'string', Rule::in(KeywordProviderConfig::PROVIDERS)],
            'banner_enabled' => ['nullable', 'boolean'],
            'banner_type' => ['required', 'string', Rule::in(['image', 'youtube'])],
            'banner_title' => ['nullable', 'string', 'max:120'],
            'banner_image_url' => ['nullable', 'url', 'max:2048'],
            'banner_link_url' => ['nullable', 'url', 'max:2048'],
            'banner_youtube_url' => ['nullable', 'url', 'max:2048'],
        ]);

        LlmProviderConfig::setProvider((string) $data['llm_provider']);
        AiModelConfig::setModel((string) $data['model'], LlmProviderConfig::PROVIDER_MISTRAL);
        AiModelConfig::setModel((string) $data['deepseek_model'], LlmProviderConfig::PROVIDER_DEEPSEEK);
        Setting::set(RankTrackerConfig::SETTING_CHECK_INTERVAL, (int) $data['default_check_interval_hours']);
        Setting::set(
            AuditConfig::SETTING_COMPETITOR_KEYWORDS_EVERYWHERE,
            $request->boolean('competitor_keywords_everywhere'),
        );

        KeywordProviderConfig::setProvider((string) $data['keyword_volume_provider']);

        LocaleConfig::setMultilingualEnabled($request->boolean('multilingual_enabled'));

        Setting::set('plugin.banner.enabled', $request->boolean('banner_enabled') ? '1' : '0');
        Setting::set('plugin.banner.type', (string) $data['banner_type']);
        Setting::set('plugin.banner.title', (string) ($data['banner_title'] ?? ''));
        Setting::set('plugin.banner.image_url', (string) ($data['banner_image_url'] ?? ''));
        Setting::set('plugin.banner.link_url', (string) ($data['banner_link_url'] ?? ''));
        Setting::set('plugin.banner.youtube_url', (string) ($data['banner_youtube_url'] ?? ''));

        return redirect()
            ->route('admin.settings')
            ->with('status', 'Settings saved.');
    }

    public function refreshModels(): RedirectResponse
    {
        AiModelConfig::clearModelsCache();

        return redirect()
            ->route('admin.settings')
            ->with('status', 'Model lists refreshed from the providers.');
    }
}
