<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class WordPressConnectTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_shows_consent_for_authorized_user(): void
    {
        $user = User::factory()->create();
        Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);

        $this->actingAs($user)
            ->get(route('wordpress.connect.start', [
                'site_url' => 'https://example.com',
                'redirect' => 'https://example.com/wp-admin/options-general.php?page=ebq-seo&ebq_cb=1',
                'state' => str_repeat('a', 32),
            ]))
            ->assertOk()
            ->assertSee('Connect WordPress to Serfix')
            ->assertSee('example.com');
    }

    public function test_start_rejects_cross_domain_redirect(): void
    {
        $user = User::factory()->create();
        Website::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('wordpress.connect.start', [
                'site_url' => 'https://good.com',
                'redirect' => 'https://evil.com/steal',
                'state' => str_repeat('a', 32),
            ]))
            ->assertStatus(400)
            ->assertSee('Redirect URL must be on the same domain');
    }

    public function test_start_requires_authentication(): void
    {
        $response = $this->get(route('wordpress.connect.start', [
            'site_url' => 'https://example.com',
            'redirect' => 'https://example.com/wp-admin/',
            'state' => str_repeat('a', 32),
        ]));

        $response->assertRedirect(route('login'));
    }

    public function test_approve_mints_scoped_token_and_redirects_back(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);

        $response = $this->actingAs($user)
            ->post(route('wordpress.connect.approve'), [
                'website_id' => $website->id,
                'site_url' => 'https://example.com',
                'redirect' => 'https://example.com/wp-admin/options-general.php?page=ebq-seo&ebq_cb=1',
                'state' => 'wp-state-abc',
            ]);

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('example.com/wp-admin/options-general.php', $location);
        $this->assertStringContainsString('ebq_token=', $location);
        $this->assertStringContainsString('state=wp-state-abc', $location);
        $this->assertStringContainsString('website_id='.$website->id, $location);

        $this->assertSame(1, PersonalAccessToken::query()
            ->where('tokenable_type', Website::class)
            ->where('tokenable_id', $website->id)
            ->count());
    }

    public function test_approve_rejects_token_for_non_owned_website(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $owner->id, 'domain' => 'example.com']);

        $this->actingAs($intruder)
            ->post(route('wordpress.connect.approve'), [
                'website_id' => $website->id,
                'site_url' => 'https://example.com',
                'redirect' => 'https://example.com/wp-admin/',
                'state' => 'x',
            ])
            ->assertForbidden();

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }
}
