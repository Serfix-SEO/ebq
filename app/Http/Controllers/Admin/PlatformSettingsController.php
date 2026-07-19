<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\AiModelConfig;
use App\Support\AuditConfig;
use App\Support\ContentAutopilotConfig;
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
            'autopilot' => [
                'stages' => ContentAutopilotConfig::STAGES,
                'stage_models' => collect(ContentAutopilotConfig::STAGES)
                    ->mapWithKeys(function (string $stage) {
                        $m = Setting::get('content.model.'.$stage);

                        return [$stage => is_array($m) && isset($m['provider'], $m['model'])
                            ? $m['provider'].':'.$m['model'] : 'auto'];
                    })->all(),
                'images_enabled' => ContentAutopilotConfig::imagesEnabled(),
                'featured_enabled' => ContentAutopilotConfig::featuredImageEnabled(),
                'max_inline' => ContentAutopilotConfig::maxInlineImages(),
                'rendering_speed' => ContentAutopilotConfig::renderingSpeed(),
                'style_type' => ContentAutopilotConfig::styleType(),
                'target_score' => ContentAutopilotConfig::targetScore(),
                'max_revisions' => ContentAutopilotConfig::maxRevisions(),
                'publish_floor' => ContentAutopilotConfig::publishFloor(),
                'banned_phrases' => implode("\n", ContentAutopilotConfig::bannedPhrases()),
            ],
            'content_billing' => [
                'monthly_price_id' => (string) Setting::get('content.pricing.monthly_price_id', ''),
                'annual_price_id' => (string) Setting::get('content.pricing.annual_price_id', ''),
                'addon_monthly_price_id' => (string) Setting::get('content.pricing.addon_monthly_price_id', ''),
                'addon_annual_price_id' => (string) Setting::get('content.pricing.addon_annual_price_id', ''),
                'first_month_coupon' => (string) Setting::get('content.pricing.first_month_coupon', ''),
                'monthly_usd' => ContentAutopilotConfig::displayPrice('monthly'),
                'annual_usd' => ContentAutopilotConfig::displayPrice('annual'),
                'addon_monthly_usd' => ContentAutopilotConfig::displayPrice('addon_monthly'),
                'addon_annual_usd' => ContentAutopilotConfig::displayPrice('addon_annual'),
                'first_month_usd' => ContentAutopilotConfig::displayPrice('first_month'),
                'trial_days' => ContentAutopilotConfig::trialDays(),
                'trial_articles' => ContentAutopilotConfig::trialArticles(),
                'monthly_articles_per_website' => ContentAutopilotConfig::monthlyArticlesPerWebsite(),
                'content_only_crawl_pages' => ContentAutopilotConfig::contentOnlyCrawlCap(),
            ],
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
            'autopilot_model_ideate' => ['nullable', 'string', 'max:120'],
            'autopilot_model_write' => ['nullable', 'string', 'max:120'],
            'autopilot_model_revise' => ['nullable', 'string', 'max:120'],
            'autopilot_model_image_prompts' => ['nullable', 'string', 'max:120'],
            'autopilot_images_enabled' => ['nullable', 'boolean'],
            'autopilot_featured_enabled' => ['nullable', 'boolean'],
            'autopilot_max_inline' => ['required', 'integer', 'min:0', 'max:4'],
            'autopilot_rendering_speed' => ['required', 'string', Rule::in(['FLASH', 'TURBO', 'DEFAULT', 'QUALITY'])],
            'autopilot_style_type' => ['required', 'string', Rule::in(['AUTO', 'GENERAL', 'REALISTIC', 'DESIGN'])],
            'autopilot_target_score' => ['required', 'integer', 'min:50', 'max:100'],
            'autopilot_max_revisions' => ['required', 'integer', 'min:0', 'max:6'],
            'autopilot_publish_floor' => ['required', 'integer', 'min:0', 'max:100'],
            'autopilot_banned_phrases' => ['nullable', 'string', 'max:8000'],
            // Content product billing & limits (Stripe price ids + coupon +
            // display prices + trial/monthly caps). Price ids validated to look
            // like Stripe ids so a typo never becomes a silent no-checkout.
            'content_monthly_price_id' => ['nullable', 'string', 'regex:/^price_/', 'max:120'],
            'content_annual_price_id' => ['nullable', 'string', 'regex:/^price_/', 'max:120'],
            'content_addon_monthly_price_id' => ['nullable', 'string', 'regex:/^price_/', 'max:120'],
            'content_addon_annual_price_id' => ['nullable', 'string', 'regex:/^price_/', 'max:120'],
            'content_first_month_coupon' => ['nullable', 'string', 'max:120'],
            'content_monthly_usd' => ['required', 'integer', 'min:0', 'max:9999'],
            'content_annual_usd' => ['required', 'integer', 'min:0', 'max:9999'],
            'content_addon_monthly_usd' => ['required', 'integer', 'min:0', 'max:9999'],
            'content_addon_annual_usd' => ['required', 'integer', 'min:0', 'max:9999'],
            'content_first_month_usd' => ['required', 'integer', 'min:0', 'max:9999'],
            'content_trial_days' => ['required', 'integer', 'min:0', 'max:60'],
            'content_trial_articles' => ['required', 'integer', 'min:0', 'max:50'],
            'content_monthly_articles_per_website' => ['required', 'integer', 'min:1', 'max:1000'],
            'content_only_crawl_pages' => ['required', 'integer', 'min:20', 'max:100000'],
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

        // Content Autopilot — per-stage model pins ("auto" clears the pin,
        // otherwise "provider:model" validated against that provider's list).
        foreach (ContentAutopilotConfig::STAGES as $stage) {
            $raw = trim((string) ($data['autopilot_model_'.$stage] ?? 'auto'));
            if ($raw === '' || $raw === 'auto' || ! str_contains($raw, ':')) {
                ContentAutopilotConfig::setModelFor($stage, null, null);

                continue;
            }
            [$provider, $model] = explode(':', $raw, 2);
            $ids = $provider === LlmProviderConfig::PROVIDER_DEEPSEEK ? $deepseekIds : $mistralIds;
            if (in_array($provider, LlmProviderConfig::PROVIDERS, true) && in_array($model, $ids, true)) {
                ContentAutopilotConfig::setModelFor($stage, $provider, $model);
            }
        }
        Setting::set('content.images.enabled', $request->boolean('autopilot_images_enabled'));
        Setting::set('content.images.featured_enabled', $request->boolean('autopilot_featured_enabled'));
        Setting::set('content.images.max_inline', (int) $data['autopilot_max_inline']);
        Setting::set('content.images.rendering_speed', (string) $data['autopilot_rendering_speed']);
        Setting::set('content.images.style_type', (string) $data['autopilot_style_type']);
        Setting::set('content.revise.target_score', (int) $data['autopilot_target_score']);
        Setting::set('content.revise.max_iterations', (int) $data['autopilot_max_revisions']);
        Setting::set('content.revise.publish_floor', (int) $data['autopilot_publish_floor']);
        $phrases = array_values(array_filter(array_map(
            static fn ($line) => mb_strtolower(trim($line)),
            preg_split('/\r?\n/', (string) ($data['autopilot_banned_phrases'] ?? '')) ?: []
        )));
        Setting::set('content.humanizer.banned_phrases', $phrases === [] ? null : $phrases);

        // Content product billing & limits.
        foreach ([
            'content.pricing.monthly_price_id' => 'content_monthly_price_id',
            'content.pricing.annual_price_id' => 'content_annual_price_id',
            'content.pricing.addon_monthly_price_id' => 'content_addon_monthly_price_id',
            'content.pricing.addon_annual_price_id' => 'content_addon_annual_price_id',
            'content.pricing.first_month_coupon' => 'content_first_month_coupon',
        ] as $settingKey => $field) {
            $val = trim((string) ($data[$field] ?? ''));
            Setting::set($settingKey, $val === '' ? null : $val);
        }
        Setting::set('content.pricing.monthly_usd', (int) $data['content_monthly_usd']);
        Setting::set('content.pricing.annual_usd', (int) $data['content_annual_usd']);
        Setting::set('content.pricing.addon_monthly_usd', (int) $data['content_addon_monthly_usd']);
        Setting::set('content.pricing.addon_annual_usd', (int) $data['content_addon_annual_usd']);
        Setting::set('content.pricing.first_month_usd', (int) $data['content_first_month_usd']);
        Setting::set('content.limits.trial_days', (int) $data['content_trial_days']);
        Setting::set('content.limits.trial_articles', (int) $data['content_trial_articles']);
        Setting::set('content.limits.monthly_articles_per_website', (int) $data['content_monthly_articles_per_website']);
        Setting::set('content.limits.content_only_crawl_pages', (int) $data['content_only_crawl_pages']);

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
