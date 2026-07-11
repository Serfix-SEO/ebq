<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\AiModelConfig;
use App\Support\AuditConfig;
use App\Support\KeywordProviderConfig;
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
 *   - Default AI model (Mistral) for every AI feature
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
            'models'                       => AiModelConfig::listAvailableModels(),
            'currentModel'                 => AiModelConfig::currentModel(),
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
        $availableIds = array_column(AiModelConfig::listAvailableModels(), 'id');

        $data = $request->validate([
            'model' => ['required', 'string', Rule::in($availableIds)],
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

        AiModelConfig::setModel((string) $data['model']);
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
            ->with('status', 'Model list refreshed from the provider.');
    }
}
