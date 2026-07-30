<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\PublishingSettings;
use App\Models\ContentArticle;
use App\Models\ContentIntegration;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\Publishing\WebhookDriver;
use App\Support\Audit\SafeHttpGuard;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The webhook signing secret is the ENTIRE authentication boundary between us
 * and a customer's website: anyone holding it can publish HTML onto their
 * pages. Two properties follow from that — the secret must be strong, and the
 * transport must not leak it or the content.
 */
class ContentWebhookSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    /** @return array{0: User, 1: Website} */
    private function contentUser(): array
    {
        $user = User::factory()->create([
            'content_trial_started_at' => now(),
            'content_trial_ends_at' => now()->addDays(5),
        ]);
        $website = Website::factory()->for($user)->create();
        ContentPlan::factory()->create([
            'website_id' => $website->id,
            'billing_covered_at' => now(),
        ]);

        return [$user, $website];
    }

    /** A strong secret is pre-filled, so a customer never invents a weak one. */
    public function test_a_strong_signing_secret_is_generated_for_the_customer(): void
    {
        [$user, $website] = $this->contentUser();

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);
        session(['current_website_id' => $website->id]);
        $component = Livewire::test(PublishingSettings::class);

        $first = $component->get('whSecret');
        $this->assertGreaterThanOrEqual(32, strlen($first));

        $component->call('regenerateSecret');
        $this->assertNotSame($first, $component->get('whSecret'), 'regenerate must produce a new secret');
    }

    /** The generated secret can be replaced, but not with something guessable. */
    public function test_a_weak_secret_is_rejected(): void
    {
        [$user, $website] = $this->contentUser();

        $this->actingAs($user);
        session(['current_website_id' => $website->id]);
        Livewire::test(PublishingSettings::class)
            ->set('platform', ContentIntegration::PLATFORM_WEBHOOK)
            ->set('whEndpoint', 'https://client.test/serfix/content-ai/webhook')
            ->set('whSecret', 'password12345678')   // 16 chars: used to pass
            ->call('connect')
            ->assertHasErrors('whSecret');

        $this->assertSame(0, ContentIntegration::query()->count());
    }

    /**
     * The HMAC prevents forgery, not disclosure. Over plain http every article
     * — and the site's whole content plan — crosses the internet in cleartext.
     */
    public function test_a_plain_http_endpoint_is_rejected_by_the_form(): void
    {
        [$user, $website] = $this->contentUser();

        $this->actingAs($user);
        session(['current_website_id' => $website->id]);
        Livewire::test(PublishingSettings::class)
            ->set('platform', ContentIntegration::PLATFORM_WEBHOOK)
            ->set('whEndpoint', 'http://client.test/serfix/content-ai/webhook')
            ->set('whSecret', str_repeat('a', 48))
            ->call('connect')
            ->assertHasErrors('whEndpoint');

        $this->assertSame(0, ContentIntegration::query()->count());
    }

    /**
     * Defence in depth: rows can be created outside the form (admin tooling, a
     * seeder, a future API), so the sending path refuses http too.
     */
    public function test_the_driver_refuses_to_send_over_plain_http(): void
    {
        [, $website] = $this->contentUser();

        $integration = ContentIntegration::create([
            'website_id' => $website->id,
            'platform' => ContentIntegration::PLATFORM_WEBHOOK,
            'credentials' => [
                'endpoint_url' => 'http://client.test/hook',   // bypassed the form
                'secret' => str_repeat('a', 48),
            ],
            'status' => ContentIntegration::STATUS_CONNECTED,
        ]);

        $result = app(WebhookDriver::class)->verify($integration);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('https://', (string) $result->error);
    }

    /**
     * The real public URL contract: when the receiver answers a bare 200
     * without {"url": ...}, the driver falls back to the article's own
     * canonical_url — and NEVER invents a base+slug guess.
     */
    public function test_publish_falls_back_to_canonical_url_when_receiver_returns_none(): void
    {
        [, $website] = $this->contentUser();
        $plan = ContentPlan::where('website_id', $website->id)->first();
        $topic = ContentTopic::factory()->create([
            'plan_id' => $plan->id, 'website_id' => $website->id,
        ]);
        $article = ContentArticle::storeVersion($topic, [
            'h1' => 'T', 'html' => '<p>x</p>', 'slug' => 'my-post',
            'canonical_url' => 'https://client.test/blog/my-post',
        ]);
        $integration = ContentIntegration::create([
            'website_id' => $website->id,
            'platform' => ContentIntegration::PLATFORM_WEBHOOK,
            'credentials' => ['endpoint_url' => 'https://client.test/hook', 'secret' => str_repeat('a', 48)],
            'status' => ContentIntegration::STATUS_CONNECTED,
        ]);

        // SafeHttpGuard does live DNS resolution; the fake hostname must not
        // depend on the internet. HTTP itself is faked below.
        $this->app->bind(SafeHttpGuard::class, fn () => new class extends SafeHttpGuard
        {
            public function check(string $url): array
            {
                return ['ok' => true];
            }
        });
        Http::fake([
            'client.test/hook' => Http::response(['ok' => true], 200), // no url in body
        ]);

        $result = app(WebhookDriver::class)->publish($article, $integration);

        $this->assertTrue($result->ok);
        $this->assertSame('https://client.test/blog/my-post', $result->externalUrl);
    }

    /** Credentials must never be readable in the database. */
    public function test_the_secret_is_encrypted_at_rest(): void
    {
        [, $website] = $this->contentUser();
        $secret = str_repeat('z', 48);

        ContentIntegration::create([
            'website_id' => $website->id,
            'platform' => ContentIntegration::PLATFORM_WEBHOOK,
            'credentials' => ['endpoint_url' => 'https://client.test/hook', 'secret' => $secret],
            'status' => ContentIntegration::STATUS_PENDING,
        ]);

        $raw = (string) \DB::table('content_integrations')->value('credentials');

        $this->assertStringNotContainsString($secret, $raw, 'plaintext secret must never hit the DB');
        $this->assertStringNotContainsString('client.test', $raw);
    }
}
