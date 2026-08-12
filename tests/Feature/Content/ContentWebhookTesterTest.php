<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\PublishingSettings;
use App\Models\ContentIntegration;
use App\Models\ContentPlan;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/** "Test your webhook" on /content/integrations — sends a signed sample article. */
class ContentWebhookTesterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Website $website;

    private const SECRET = 'this-is-a-long-signing-secret-for-tests-123456';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->app->bind(\App\Support\Audit\SafeHttpGuard::class, fn () => new class extends \App\Support\Audit\SafeHttpGuard
        {
            public function check(string $url): array
            {
                return ['ok' => true];
            }
        });

        $this->user = User::factory()->create();
        $this->website = Website::factory()->for($this->user)->create();
        ContentPlan::factory()->create(['website_id' => $this->website->id, 'status' => ContentPlan::STATUS_ACTIVE]);
        session(['current_website_id' => $this->website->id]);
    }

    private function webhookIntegration(): ContentIntegration
    {
        $integration = ContentIntegration::query()->create([
            'website_id' => $this->website->id,
            'platform' => ContentIntegration::PLATFORM_WEBHOOK,
            'credentials' => ['endpoint_url' => 'https://client.test/receive', 'secret' => self::SECRET],
            'status' => ContentIntegration::STATUS_CONNECTED,
        ]);
        RateLimiter::clear('webhook-test:'.$integration->id);

        return $integration;
    }

    public function test_tester_section_is_visible_for_webhook_integrations(): void
    {
        $this->webhookIntegration();

        Livewire::actingAs($this->user)
            ->test(PublishingSettings::class)
            ->assertSee(__('Test your webhook'))
            ->assertSee(__('Send test article'));
    }

    public function test_sends_a_signed_sample_article_and_reports_the_returned_url(): void
    {
        Http::fake(['client.test/*' => Http::response(['url' => 'https://client.test/blog/serfix-connection-test', 'id' => '99'])]);
        $integration = $this->webhookIntegration();

        Livewire::actingAs($this->user)
            ->test(PublishingSettings::class)
            ->call('testWebhook', $integration->id)
            ->assertSet('webhookTest.ok', true)
            ->assertSet('webhookTest.url', 'https://client.test/blog/serfix-connection-test')
            ->assertSee('https://client.test/blog/serfix-connection-test');

        Http::assertSent(function (Request $r) {
            $payload = json_decode($r->body(), true);
            $expected = 'sha256='.hash_hmac('sha256', $r->body(), self::SECRET);

            return $payload['event'] === 'article.published'
                && $payload['test'] === true
                && $payload['article']['slug'] === 'serfix-connection-test'
                && $payload['article']['robots_noindex'] === true
                && str_contains($payload['article']['h1'], 'safe to delete')
                && $r->header('X-Serfix-Signature')[0] === $expected;
        });
    }

    public function test_accepted_without_url_shows_the_warning_state(): void
    {
        Http::fake(['client.test/*' => Http::response('ok', 200)]);
        $integration = $this->webhookIntegration();

        Livewire::actingAs($this->user)
            ->test(PublishingSettings::class)
            ->call('testWebhook', $integration->id)
            ->assertSet('webhookTest.ok', true)
            ->assertSet('webhookTest.url', null)
            ->assertSee(__('Your endpoint answered OK, but returned no page link.'));
    }

    public function test_failed_delivery_reports_the_error(): void
    {
        Http::fake(['client.test/*' => Http::response('boom', 500)]);
        $integration = $this->webhookIntegration();

        Livewire::actingAs($this->user)
            ->test(PublishingSettings::class)
            ->call('testWebhook', $integration->id)
            ->assertSet('webhookTest.ok', false)
            ->assertSee(__('The test delivery failed.'));
    }

    public function test_custom_test_url_gets_the_delivery_without_touching_the_saved_integration(): void
    {
        Http::fake(['webhook.site/*' => Http::response('ok', 200)]);
        $integration = $this->webhookIntegration();

        Livewire::actingAs($this->user)
            ->test(PublishingSettings::class)
            ->set('testUrl', 'https://webhook.site/abc-123')
            ->call('testWebhook', $integration->id)
            ->assertHasNoErrors()
            ->assertSet('webhookTest.ok', true);

        // Delivery hit the OVERRIDE url, signed with the integration's secret.
        Http::assertSent(function (Request $r) {
            $expected = 'sha256='.hash_hmac('sha256', $r->body(), self::SECRET);

            return str_starts_with((string) $r->url(), 'https://webhook.site/abc-123')
                && json_decode($r->body(), true)['test'] === true
                && $r->header('X-Serfix-Signature')[0] === $expected;
        });
        Http::assertNotSent(fn (Request $r) => str_contains((string) $r->url(), 'client.test'));

        // Saved integration is completely untouched.
        $integration->refresh();
        $creds = (array) $integration->credentials;
        $this->assertSame('https://client.test/receive', $creds['endpoint_url']);
        $this->assertSame(self::SECRET, $creds['secret']);
        $this->assertSame(ContentIntegration::STATUS_CONNECTED, $integration->status);
    }

    public function test_blank_test_url_falls_back_to_the_saved_endpoint(): void
    {
        Http::fake(['client.test/*' => Http::response(['url' => 'https://client.test/blog/x'])]);
        $integration = $this->webhookIntegration();

        Livewire::actingAs($this->user)
            ->test(PublishingSettings::class)
            ->set('testUrl', '   ')
            ->call('testWebhook', $integration->id)
            ->assertSet('webhookTest.ok', true);

        Http::assertSent(fn (Request $r) => str_starts_with((string) $r->url(), 'https://client.test/receive'));
    }

    public function test_plain_http_test_url_is_rejected_without_sending(): void
    {
        Http::fake();
        $integration = $this->webhookIntegration();

        Livewire::actingAs($this->user)
            ->test(PublishingSettings::class)
            ->set('testUrl', 'http://insecure.test/hook')
            ->call('testWebhook', $integration->id)
            ->assertHasErrors(['testUrl']);

        Http::assertNothingSent();
    }

    public function test_non_webhook_integrations_are_ignored(): void
    {
        Http::fake();
        $integration = ContentIntegration::query()->create([
            'website_id' => $this->website->id,
            'platform' => ContentIntegration::PLATFORM_WORDPRESS_APP_PASSWORD,
            'credentials' => ['site_url' => 'https://x.test', 'username' => 'u', 'app_password' => 'p'],
            'status' => ContentIntegration::STATUS_CONNECTED,
        ]);

        Livewire::actingAs($this->user)
            ->test(PublishingSettings::class)
            ->call('testWebhook', $integration->id)
            ->assertSet('webhookTest', null);

        Http::assertNothingSent();
    }
}
