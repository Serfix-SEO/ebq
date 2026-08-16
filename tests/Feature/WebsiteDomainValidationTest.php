<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Website;
use App\Services\WebsiteAttachService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every door where a user types "your website" must reject things that are not
 * websites. Before 2026-08-16 the only rule was `required|string|max:255`, and
 * a visitor's email address became a real website + crawl site on prod.
 */
class WebsiteDomainValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_attach_service_refuses_an_email_address(): void
    {
        $user = User::factory()->create();

        $result = app(WebsiteAttachService::class)->attach($user, 'santoshvarma.water@gmail.com');

        $this->assertNull($result['website']);
        $this->assertSame('invalid_domain', $result['blocked']);
        $this->assertSame(0, Website::query()->count());
    }

    public function test_attach_service_refuses_junk_input(): void
    {
        $user = User::factory()->create();

        foreach (['my website', 'localhost', 'hello', '203.0.113.9'] as $junk) {
            $result = app(WebsiteAttachService::class)->attach($user, $junk);
            $this->assertSame('invalid_domain', $result['blocked'], $junk.' should be refused');
        }

        $this->assertSame(0, Website::query()->count());
    }

    public function test_attach_service_still_accepts_a_real_domain(): void
    {
        $user = User::factory()->create();

        // example.org, not example.com: attaching bootstraps a crawl, which
        // increments the shared `crawl-rl:<domain>` Redis bucket that
        // WorkerFleetTest asserts an exact count on for example.com.
        $result = app(WebsiteAttachService::class)->attach($user, 'https://www.example.org/pricing');

        $this->assertNotNull($result['website']);
        $this->assertNull($result['blocked']);
        $this->assertSame('example.org', $result['website']->normalized_domain);
    }

    public function test_add_website_form_rejects_an_email_with_a_useful_message(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(\App\Livewire\Websites\WebsitesList::class)
            ->set('domain', 'someone@gmail.com')
            ->call('addWebsite')
            ->assertHasErrors('domain');

        $this->assertSame(0, Website::query()->count());
    }

    public function test_public_funnel_rejects_an_email_before_creating_anything(): void
    {
        $response = $this->post(route('content.onboarding.begin'), [
            'domain' => 'santoshvarma.water@gmail.com',
        ]);

        $response->assertSessionHasErrors('domain');
        $this->assertSame(0, Website::query()->count());
        $this->assertSame(0, \App\Models\CrawlSite::query()->count());
    }

    public function test_public_funnel_rejects_a_bare_word(): void
    {
        $response = $this->post(route('content.onboarding.begin'), ['domain' => 'mywebsite']);

        $response->assertSessionHasErrors('domain');
        $this->assertSame(0, \App\Models\CrawlSite::query()->count());
    }
}
