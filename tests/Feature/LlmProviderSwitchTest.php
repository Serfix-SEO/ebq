<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\AiBlockEditorService;
use App\Services\Llm\DeepSeekClient;
use App\Services\Llm\LlmClient;
use App\Services\Llm\MistralClient;
use App\Services\Usage\UsageMeter;
use App\Support\AiModelConfig;
use App\Support\LlmProviderConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Multi-provider LLM wiring: the admin-selected provider drives the
 * container binding, per-provider model settings coexist, DeepSeek token
 * spend pools with Mistral under one plan cap, and the block editor's
 * alt-text path pins Mistral for vision when DeepSeek is active.
 */
class LlmProviderSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_binding_defaults_to_mistral(): void
    {
        $this->assertInstanceOf(MistralClient::class, app(LlmClient::class));
    }

    public function test_binding_follows_provider_setting(): void
    {
        LlmProviderConfig::setProvider('deepseek');
        $this->assertInstanceOf(DeepSeekClient::class, app(LlmClient::class));

        LlmProviderConfig::setProvider('mistral');
        $this->assertInstanceOf(MistralClient::class, app(LlmClient::class));
    }

    public function test_invalid_provider_setting_falls_back_to_mistral(): void
    {
        Setting::set(LlmProviderConfig::SETTING_KEY, 'openai');
        $this->assertSame('mistral', LlmProviderConfig::currentProvider());
        $this->assertInstanceOf(MistralClient::class, app(LlmClient::class));
    }

    public function test_per_provider_model_settings_are_independent(): void
    {
        // Legacy key predates multi-provider — it must keep driving Mistral.
        Setting::set(AiModelConfig::SETTING_KEY, 'mistral-large-latest');
        $this->assertSame('mistral-large-latest', AiModelConfig::currentModel('mistral'));
        $this->assertSame('deepseek-chat', AiModelConfig::currentModel('deepseek'));

        AiModelConfig::setModel('deepseek-chat', 'deepseek');
        $this->assertSame('deepseek-chat', AiModelConfig::currentModel('deepseek'));
        $this->assertSame('mistral-large-latest', AiModelConfig::currentModel('mistral'));
    }

    public function test_premium_and_vision_models_resolve_per_provider(): void
    {
        $this->assertSame('mistral-medium-latest', AiModelConfig::premiumModel('mistral'));
        $this->assertSame('deepseek-chat', AiModelConfig::premiumModel('deepseek'));
        $this->assertSame('pixtral-12b-latest', AiModelConfig::visionModel('mistral'));
        $this->assertNull(AiModelConfig::visionModel('deepseek'));
    }

    public function test_deepseek_fallback_model_list_excludes_reasoner(): void
    {
        config(['services.deepseek.key' => '']);
        $ids = array_column(AiModelConfig::listAvailableModels('deepseek'), 'id');

        $this->assertContains('deepseek-chat', $ids);
        $this->assertNotContains('deepseek-reasoner', $ids);
    }

    public function test_deepseek_live_model_list_filters_reasoner(): void
    {
        config(['services.deepseek.key' => 'sk-test']);
        AiModelConfig::clearModelsCache();
        Http::fake([
            'api.deepseek.com/models' => Http::response([
                'data' => [
                    ['id' => 'deepseek-chat', 'object' => 'model'],
                    ['id' => 'deepseek-reasoner', 'object' => 'model'],
                ],
            ]),
        ]);

        $ids = array_column(AiModelConfig::listAvailableModels('deepseek'), 'id');

        $this->assertSame(['deepseek-chat'], $ids);
        AiModelConfig::clearModelsCache();
    }

    // ── Admin settings page ─────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /** @return array<string, mixed> baseline valid settings payload */
    private function settingsPayload(array $overrides = []): array
    {
        return $overrides + [
            'llm_provider' => 'mistral',
            'model' => 'mistral-small-latest',
            'deepseek_model' => 'deepseek-chat',
            'default_check_interval_hours' => 24,
            'keyword_volume_provider' => 'keywords_everywhere',
            'banner_type' => 'image',
        ];
    }

    public function test_settings_page_renders_provider_and_both_model_selects(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('Active provider')
            ->assertSee('Mistral default model')
            ->assertSee('DeepSeek default model')
            ->assertSee('deepseek-chat');
    }

    public function test_admin_can_switch_provider_to_deepseek(): void
    {
        config(['services.deepseek.key' => 'sk-test']);

        $this->actingAs($this->admin())
            ->put(route('admin.settings.update'), $this->settingsPayload(['llm_provider' => 'deepseek']))
            ->assertRedirect(route('admin.settings'))
            ->assertSessionHasNoErrors();

        $this->assertSame('deepseek', LlmProviderConfig::currentProvider());
        $this->assertInstanceOf(DeepSeekClient::class, app(LlmClient::class));
    }

    public function test_switching_to_unconfigured_provider_is_rejected(): void
    {
        config(['services.deepseek.key' => '']);

        $this->actingAs($this->admin())
            ->put(route('admin.settings.update'), $this->settingsPayload(['llm_provider' => 'deepseek']))
            ->assertSessionHasErrors('llm_provider');

        $this->assertSame('mistral', LlmProviderConfig::currentProvider());
    }

    public function test_cross_provider_model_id_is_rejected(): void
    {
        config(['services.deepseek.key' => 'sk-test']);

        $this->actingAs($this->admin())
            ->put(route('admin.settings.update'), $this->settingsPayload(['deepseek_model' => 'mistral-small-latest']))
            ->assertSessionHasErrors('deepseek_model');
    }

    // ── Pooled token cap ────────────────────────────────────────────

    public function test_deepseek_and_mistral_consumption_pool_against_one_cap(): void
    {
        \App\Models\Plan::create([
            'slug' => 'trial',
            'name' => 'Trial',
            'is_active' => true,
            'api_limits' => ['mistral' => ['monthly_tokens' => 1000]],
        ]);
        $user = User::factory()->create();

        $meter = app(UsageMeter::class);

        \App\Models\ClientActivity::create([
            'user_id' => $user->id, 'type' => 'api_usage.mistral',
            'provider' => 'mistral', 'units_consumed' => 600,
        ]);
        \App\Models\ClientActivity::create([
            'user_id' => $user->id, 'type' => 'api_usage.deepseek',
            'provider' => 'deepseek', 'units_consumed' => 300,
        ]);

        // Both providers see the pooled 900 and the same 1000-token cap.
        $this->assertSame(900, $meter->consumedInWindow($user, 'deepseek'));
        $this->assertSame(900, $meter->consumedInWindow($user, 'mistral'));
        $this->assertSame(1000, $meter->limit($user, 'deepseek'));

        // 200 more DeepSeek tokens would cross the shared cap.
        $this->expectException(\App\Exceptions\QuotaExceededException::class);
        $meter->assertCanSpend($user, 'deepseek', 200);
    }

    public function test_deepseek_reservation_reserve_release_round_trips(): void
    {
        $user = User::factory()->create();
        $meter = app(UsageMeter::class);

        $meter->reserve($user->id, 'deepseek', 50);
        // Pooled bucket: visible from either provider key.
        $this->assertSame(50, $meter->pendingReserved($user->id, 'deepseek'));
        $this->assertSame(50, $meter->pendingReserved($user->id, 'mistral'));

        $meter->release($user->id, 'deepseek', 50);
        $this->assertSame(0, $meter->pendingReserved($user->id, 'deepseek'));
    }

    // ── Vision fallback ─────────────────────────────────────────────

    public function test_alt_text_pins_mistral_vision_when_deepseek_is_active(): void
    {
        config(['services.deepseek.key' => 'sk-ds', 'services.mistral.key' => 'sk-mi']);
        LlmProviderConfig::setProvider('deepseek');

        Http::fake([
            'api.mistral.ai/*' => Http::response([
                'model' => 'pixtral-12b-latest',
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'A red bicycle.']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]),
        ]);

        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id]);

        $out = app(AiBlockEditorService::class)->generate($website, [
            'mode' => 'alt_text',
            'image_url' => 'https://example.com/bike.png',
            'surrounding_text' => 'Our new bike.',
        ]);

        $this->assertTrue($out['ok'], 'alt_text failed: '.json_encode($out));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.mistral.ai')
            && $request['model'] === 'pixtral-12b-latest');
    }

    public function test_alt_text_degrades_gracefully_without_mistral_key(): void
    {
        config(['services.deepseek.key' => 'sk-ds', 'services.mistral.key' => '']);
        LlmProviderConfig::setProvider('deepseek');
        Http::fake();

        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id]);

        $out = app(AiBlockEditorService::class)->generate($website, [
            'mode' => 'alt_text',
            'image_url' => 'https://example.com/bike.png',
            'surrounding_text' => 'Our new bike.',
        ]);

        $this->assertFalse($out['ok']);
        $this->assertSame('vision_not_supported', $out['error']);
        Http::assertNothingSent();
    }
}
