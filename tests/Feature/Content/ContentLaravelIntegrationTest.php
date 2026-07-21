<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\PublishingSettings;
use App\Models\ContentIntegration;
use App\Models\ContentPlan;
use App\Models\User;
use App\Models\Website;
use App\Support\Audit\SafeHttpGuard;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Laravel is a first-class option on the connect screen, but under the hood it
 * is the WEBHOOK driver — same signed payload, same verification. Only the
 * setup instructions differ, so the tab must not fork the wiring.
 */
class ContentLaravelIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        // SafeHttpGuard resolves DNS for real; .test domains would fail the
        // reachability check and mask what these tests are actually asserting.
        $this->instance(SafeHttpGuard::class, new class extends SafeHttpGuard
        {
            public function check(string $url): array
            {
                return ['ok' => true];
            }
        });
    }

    /** @return array{0: User, 1: Website} */
    private function contentUser(string $domain = 'client-app.test'): array
    {
        $user = User::factory()->create([
            'content_trial_started_at' => now(),
            'content_trial_ends_at' => now()->addDays(5),
        ]);
        $website = Website::factory()->for($user)->create(['domain' => $domain]);
        ContentPlan::factory()->create([
            'website_id' => $website->id,
            'billing_covered_at' => now(),
        ]);

        $this->actingAs($user);
        session(['current_website_id' => $website->id]);

        return [$user, $website];
    }

    /** Selecting Laravel fills the endpoint in — the package mounts at a known path. */
    public function test_choosing_laravel_prefills_the_endpoint_from_the_site_domain(): void
    {
        $this->contentUser('client-app.test');

        Livewire::test(PublishingSettings::class)
            ->call('selectPlatform', PublishingSettings::FLAVOR_LARAVEL)
            ->assertSet('platform', PublishingSettings::FLAVOR_LARAVEL)
            ->assertSet('whEndpoint', 'https://client-app.test/serfix/content-ai/webhook');
    }

    /** Never clobber an endpoint the user already typed. */
    public function test_an_endpoint_the_user_typed_is_not_overwritten(): void
    {
        $this->contentUser();

        Livewire::test(PublishingSettings::class)
            ->set('whEndpoint', 'https://custom.test/my-own-hook')
            ->call('selectPlatform', PublishingSettings::FLAVOR_LARAVEL)
            ->assertSet('whEndpoint', 'https://custom.test/my-own-hook');
    }

    /** Stored as a webhook, tagged as Laravel — one driver, two sets of instructions. */
    public function test_connecting_via_laravel_stores_a_webhook_integration_tagged_laravel(): void
    {
        [, $website] = $this->contentUser();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        Livewire::test(PublishingSettings::class)
            ->call('selectPlatform', PublishingSettings::FLAVOR_LARAVEL)
            ->set('whSecret', str_repeat('k', 48))
            ->call('connect')
            ->assertHasNoErrors();

        $integration = ContentIntegration::query()->where('website_id', $website->id)->first();

        $this->assertNotNull($integration);
        $this->assertSame(ContentIntegration::PLATFORM_WEBHOOK, $integration->platform, 'must use the webhook driver');
        $this->assertSame(PublishingSettings::FLAVOR_LARAVEL, $integration->config['flavor'] ?? null);
        $this->assertSame(ContentIntegration::STATUS_CONNECTED, $integration->status);
    }

    /** A plain webhook connection carries no flavour, so it keeps its own label. */
    public function test_a_plain_webhook_connection_is_not_tagged_laravel(): void
    {
        [, $website] = $this->contentUser();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        Livewire::test(PublishingSettings::class)
            ->call('selectPlatform', ContentIntegration::PLATFORM_WEBHOOK)
            ->set('whEndpoint', 'https://client-app.test/hook')
            ->set('whSecret', str_repeat('k', 48))
            ->call('connect')
            ->assertHasNoErrors();

        $integration = ContentIntegration::query()->where('website_id', $website->id)->first();
        $this->assertNull($integration->config['flavor'] ?? null);
    }

    /** The security rules apply to the Laravel tab exactly as they do elsewhere. */
    public function test_the_laravel_tab_still_enforces_https_and_a_strong_secret(): void
    {
        $this->contentUser();

        Livewire::test(PublishingSettings::class)
            ->call('selectPlatform', PublishingSettings::FLAVOR_LARAVEL)
            ->set('whEndpoint', 'http://client-app.test/serfix/content-ai/webhook')
            ->set('whSecret', str_repeat('k', 48))
            ->call('connect')
            ->assertHasErrors('whEndpoint');

        Livewire::test(PublishingSettings::class)
            ->call('selectPlatform', PublishingSettings::FLAVOR_LARAVEL)
            ->set('whSecret', 'short-secret')
            ->call('connect')
            ->assertHasErrors('whSecret');
    }

    /** The guide has to actually render, with the commands a developer needs. */
    public function test_the_settings_page_shows_the_laravel_guide(): void
    {
        $this->contentUser();

        $html = Livewire::test(PublishingSettings::class)
            ->set('showConnect', true)
            ->call('selectPlatform', PublishingSettings::FLAVOR_LARAVEL)
            ->html();

        foreach ([
            'composer require serfix/content-ai-laravel',
            'php artisan content-ai:install',
            'php artisan migrate',
            'CONTENT_AI_WEBHOOK_SECRET',
            'CONTENT_AI_ROUTE_PREFIX',
            'php artisan content-ai:verify',
            'serfix_head',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html, "guide is missing: {$needle}");
        }
    }

    /** A Laravel connection must be labelled Laravel, not "Custom (webhook)". */
    public function test_a_connected_laravel_site_is_labelled_laravel(): void
    {
        [, $website] = $this->contentUser();

        ContentIntegration::create([
            'website_id' => $website->id,
            'platform' => ContentIntegration::PLATFORM_WEBHOOK,
            'credentials' => ['endpoint_url' => 'https://client-app.test/hook', 'secret' => str_repeat('k', 48)],
            'config' => ['flavor' => PublishingSettings::FLAVOR_LARAVEL],
            'status' => ContentIntegration::STATUS_CONNECTED,
        ]);

        $html = Livewire::test(PublishingSettings::class)->html();

        $this->assertStringContainsString('Laravel', $html);
    }
}
