<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\PublishingSettings;
use App\Models\ContentIntegration;
use App\Models\ContentPlan;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\Publishing\ShopifyDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** The connect UI for the token-based destinations, incl. the two-step target picker. */
class ContentConnectPlatformsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Website $website;

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

    private function shopifyVerify(array $blogs): void
    {
        Http::fake(['*.myshopify.com/admin/api/'.ShopifyDriver::API_VERSION.'/graphql.json' => Http::response(['data' => [
            'shop' => ['name' => 'Demo', 'primaryDomain' => ['url' => 'https://demo.example.com']],
            'blogs' => ['nodes' => $blogs],
        ]])]);
    }

    public function test_integrations_page_shows_all_platform_tiles(): void
    {
        Livewire::actingAs($this->user)
            ->test(PublishingSettings::class)
            ->set('showConnect', true)
            ->assertSee('WordPress')
            ->assertSee('Shopify')
            ->assertSee('Webflow')
            ->assertSee('Wix')
            ->assertSee('HubSpot')
            ->assertSee('Sanity')
            ->assertSee('Laravel')
            ->assertSee(__('Custom (webhook)'));
    }

    public function test_shopify_with_multiple_blogs_pauses_on_the_blog_picker_then_connects(): void
    {
        $this->shopifyVerify([
            ['id' => 'gid://shopify/Blog/11', 'title' => 'News', 'handle' => 'news'],
            ['id' => 'gid://shopify/Blog/22', 'title' => 'Guides', 'handle' => 'guides'],
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(PublishingSettings::class)
            ->call('selectPlatform', ContentIntegration::PLATFORM_SHOPIFY)
            ->set('shopifyStoreDomain', 'demo-store.myshopify.com')
            ->set('shopifyToken', 'shpat_token')
            ->call('connect')
            ->assertHasNoErrors()
            ->assertSee(__('One more step'))
            ->assertSee('Guides');

        // Credentials saved, but not connected until the blog is chosen.
        $integration = ContentIntegration::query()->where('website_id', $this->website->id)->firstOrFail();
        $this->assertSame(ContentIntegration::STATUS_PENDING, $integration->status);
        // The token must not sit in the component's public state anymore.
        $this->assertSame('shpat_token', $integration->credentials['access_token']);

        $component
            ->set('chosenTargetId', 'gid://shopify/Blog/22')
            ->call('chooseTarget')
            ->assertHasNoErrors();

        $integration->refresh();
        $this->assertSame(ContentIntegration::STATUS_CONNECTED, $integration->status);
        $this->assertSame('gid://shopify/Blog/22', $integration->config['blog_id']);
        $this->assertNotNull($integration->last_verified_at);
    }

    public function test_shopify_with_a_single_blog_auto_connects(): void
    {
        $this->shopifyVerify([['id' => 'gid://shopify/Blog/11', 'title' => 'News', 'handle' => 'news']]);

        Livewire::actingAs($this->user)
            ->test(PublishingSettings::class)
            ->call('selectPlatform', ContentIntegration::PLATFORM_SHOPIFY)
            ->set('shopifyStoreDomain', 'demo-store.myshopify.com')
            ->set('shopifyToken', 'shpat_token')
            ->set('postStatus', 'draft')
            ->call('connect')
            ->assertHasNoErrors();

        $integration = ContentIntegration::query()->where('website_id', $this->website->id)->firstOrFail();
        $this->assertSame(ContentIntegration::STATUS_CONNECTED, $integration->status);
        $this->assertSame('gid://shopify/Blog/11', $integration->config['blog_id']);
        $this->assertSame('draft', $integration->config['post_status']);
    }

    public function test_webflow_walks_both_steps_site_then_collection(): void
    {
        Http::fake([
            'api.webflow.com/v2/sites' => Http::response(['sites' => [
                ['id' => 'site-1', 'displayName' => 'Only site', 'shortName' => 'only', 'customDomains' => []],
            ]]),
            'api.webflow.com/v2/sites/site-1/collections' => Http::response(['collections' => [
                ['id' => 'coll-1', 'displayName' => 'Blog Posts', 'slug' => 'blog-posts'],
                ['id' => 'coll-2', 'displayName' => 'Team', 'slug' => 'team'],
            ]]),
            'api.webflow.com/v2/collections/coll-1' => Http::response(['fields' => [
                ['slug' => 'post-body', 'type' => 'RichText'],
            ]]),
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(PublishingSettings::class)
            ->call('selectPlatform', ContentIntegration::PLATFORM_WEBFLOW)
            ->set('webflowToken', 'wf_token')
            ->call('connect')
            ->assertHasNoErrors()
            // Single site auto-picked; the collection choice remains.
            ->assertSee(__('One more step'))
            ->assertSee('Blog Posts');

        $component->set('chosenTargetId', 'coll-1')->call('chooseTarget')->assertHasNoErrors();

        $integration = ContentIntegration::query()->where('website_id', $this->website->id)->firstOrFail();
        $this->assertSame(ContentIntegration::STATUS_CONNECTED, $integration->status);
        $this->assertSame('only.webflow.io', $integration->config['site_domain']);
        $this->assertSame('post-body', $integration->config['body_field']);
    }

    public function test_bad_token_marks_the_integration_errored_and_surfaces_the_message(): void
    {
        Http::fake(['api.hubapi.com/*' => Http::response('nope', 401)]);

        Livewire::actingAs($this->user)
            ->test(PublishingSettings::class)
            ->call('selectPlatform', ContentIntegration::PLATFORM_HUBSPOT)
            ->set('hubspotToken', 'pat-bad')
            ->call('connect')
            ->assertHasErrors('connect');

        $integration = ContentIntegration::query()->where('website_id', $this->website->id)->firstOrFail();
        $this->assertSame(ContentIntegration::STATUS_ERROR, $integration->status);
        $this->assertNotNull($integration->last_error);
    }

    public function test_sanity_url_pattern_requires_the_slug_placeholder(): void
    {
        Http::fake();

        Livewire::actingAs($this->user)
            ->test(PublishingSettings::class)
            ->call('selectPlatform', ContentIntegration::PLATFORM_SANITY)
            ->set('sanityProjectId', 'abc123')
            ->set('sanityToken', 'sk_token')
            ->set('sanityUrlPattern', 'https://demo.com/blog/fixed')
            ->call('connect')
            ->assertHasErrors('sanityUrlPattern');

        Http::assertNothingSent();
    }

    public function test_wix_site_id_must_look_like_a_guid(): void
    {
        Http::fake();

        Livewire::actingAs($this->user)
            ->test(PublishingSettings::class)
            ->call('selectPlatform', ContentIntegration::PLATFORM_WIX)
            ->set('wixApiKey', 'some-key')
            ->set('wixSiteId', 'not-a-guid')
            ->call('connect')
            ->assertHasErrors('wixSiteId');

        Http::assertNothingSent();
    }

    public function test_switching_platform_clears_a_pending_target(): void
    {
        $this->shopifyVerify([
            ['id' => 'gid://shopify/Blog/11', 'title' => 'News', 'handle' => 'news'],
            ['id' => 'gid://shopify/Blog/22', 'title' => 'Guides', 'handle' => 'guides'],
        ]);

        Livewire::actingAs($this->user)
            ->test(PublishingSettings::class)
            ->call('selectPlatform', ContentIntegration::PLATFORM_SHOPIFY)
            ->set('shopifyStoreDomain', 'demo-store.myshopify.com')
            ->set('shopifyToken', 'shpat_token')
            ->call('connect')
            ->assertSet('pendingTarget.key', 'blog')
            ->call('selectPlatform', ContentIntegration::PLATFORM_HUBSPOT)
            ->assertSet('pendingTarget', null);
    }
}
