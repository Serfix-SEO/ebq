<?php
namespace Tests\Feature;

use App\Livewire\Websites\WebsitesList;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class AddWebsiteFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_adding_a_new_site_pins_it_and_lands_on_overview_explorer(): void
    {
        Http::fake();
        Queue::fake();
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(WebsitesList::class)
            ->set('domain', 'brand-new-site.com')
            ->call('addWebsite')
            ->assertRedirect(route('website-overview', ['tab' => 'explorer']));

        $site = Website::where('domain', 'brand-new-site.com')->firstOrFail();
        $this->assertSame($site->id, session('current_website_id'));
    }

    public function test_re_adding_an_existing_domain_does_not_redirect(): void
    {
        Http::fake();
        Queue::fake();
        $user = User::factory()->create();
        Website::factory()->create(['user_id' => $user->id, 'domain' => 'already-here.com']);

        Livewire::actingAs($user)
            ->test(WebsitesList::class)
            ->set('domain', 'already-here.com')
            ->call('addWebsite')
            ->assertNoRedirect();
    }
}
