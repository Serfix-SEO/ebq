<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * /admin/clients additions (2026-07-07): search by website domain +
 * "Trial → paid" and "Trial + card added" KPI cards.
 */
class AdminClientsPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_search_finds_client_by_website_domain(): void
    {
        $owner = User::factory()->create(['name' => 'Owner Person', 'email' => 'owner-x@example.com']);
        Website::factory()->create(['user_id' => $owner->id, 'domain' => 'findme-by-domain.test']);
        $other = User::factory()->create(['name' => 'Other Person', 'email' => 'other-y@example.com']);
        Website::factory()->create(['user_id' => $other->id, 'domain' => 'unrelated.test']);

        $this->actingAs($this->admin())
            ->get(route('admin.clients.index', ['q' => 'findme-by-domain']))
            ->assertOk()
            ->assertSee('owner-x@example.com')
            ->assertDontSee('other-y@example.com');
    }

    public function test_kpi_cards_count_paid_and_trial_with_card(): void
    {
        // Paid: active Cashier subscription.
        $paid = User::factory()->create();
        DB::table('subscriptions')->insert([
            'id' => 1,
            'user_id' => $paid->id,
            'type' => 'default',
            'stripe_id' => 'sub_test_'.uniqid(),
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Trial with a saved card, no subscription.
        User::factory()->create(['pm_type' => 'visa', 'pm_last_four' => '4242']);

        // Plain trial user: neither card nor sub — must count in neither KPI.
        User::factory()->create();

        $response = $this->actingAs($this->admin())
            ->get(route('admin.clients.index'))
            ->assertOk()
            ->assertSee('Trial → paid')
            ->assertSee('Trial + card added');

        $summary = $response->viewData('summary');
        $this->assertSame(1, $summary['converted_paid']);
        $this->assertSame(1, $summary['trial_with_card']);
    }

    public function test_comped_plan_does_not_count_as_converted(): void
    {
        // Admin force-apply sets current_plan_slug without a Stripe sub —
        // must NOT count as a trial->paid conversion.
        User::factory()->create(['current_plan_slug' => 'agency']);

        $response = $this->actingAs($this->admin())->get(route('admin.clients.index'))->assertOk();
        $this->assertSame(0, $response->viewData('summary')['converted_paid']);
    }

    public function test_admin_can_comp_content_autopilot_free_sites(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $client = User::factory()->create();

        $this->actingAs($this->admin())
            ->put(route('admin.clients.update', $client), [
                'name' => $client->name,
                'email' => $client->email,
                'plan_slug' => 'trial',
                'content_comp_sites' => 3,
                'content_comp_until' => now()->addMonth()->toDateString(),
            ])
            ->assertRedirect(route('admin.clients.index'));

        $client->refresh();
        $this->assertSame(3, (int) $client->content_comp_sites);
        $this->assertNotNull($client->content_comp_until);
        // Grant takes effect through the entitlement layer.
        $this->assertTrue(app(\App\Services\Content\ContentEntitlements::class)->hasContentAccess($client));
        $this->assertSame(3, app(\App\Services\Content\ContentEntitlements::class)->sitesAllowed($client));
    }

    public function test_blank_expiry_is_a_permanent_content_comp(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $client = User::factory()->create();

        $this->actingAs($this->admin())
            ->put(route('admin.clients.update', $client), [
                'name' => $client->name, 'email' => $client->email, 'plan_slug' => 'trial',
                'content_comp_sites' => 1, 'content_comp_until' => '',
            ])->assertRedirect();

        $client->refresh();
        $this->assertSame(1, (int) $client->content_comp_sites);
        $this->assertNull($client->content_comp_until, 'blank date = permanent');
    }

    /**
     * The list renders twice: cards below the md breakpoint, the table above
     * it. Both must carry the client, and every id crossing into an Alpine
     * expression must be a quoted JS string — an (int) cast on ULIDs made
     * `isSelected(01m0…)` a syntax error and killed bulk select.
     */
    public function test_list_renders_mobile_cards_and_desktop_table(): void
    {
        $client = User::factory()->create(['email' => 'layout-check@example.com']);
        Website::factory()->create(['user_id' => $client->id, 'domain' => 'detail-site.test']);

        $html = $this->actingAs($this->admin())
            ->get(route('admin.clients.index'))
            ->assertOk()
            ->assertSee('layout-check@example.com')
            ->getContent();

        $this->assertStringContainsString('id="row-m-'.$client->id.'"', $html, 'mobile card missing');
        // Admins recognise accounts by domain — it must be on the row itself.
        $this->assertStringContainsString('detail-site.test', $html, 'website domain missing from the row');
        $this->assertStringContainsString('space-y-2 md:hidden', $html, 'cards must hide from md up');
        $this->assertStringContainsString('hidden overflow-x-auto', $html, 'table must hide below md');
        $this->assertStringContainsString("isSelected('".$client->id."')", $html, 'ids must reach Alpine quoted');
        $this->assertStringNotContainsString('isSelected('.$client->id.')', $html);
    }

    public function test_client_detail_page_renders_the_full_profile(): void
    {
        $client = User::factory()->create(['name' => 'Detail Client', 'email' => 'detail@example.com']);
        $site = Website::factory()->create(['user_id' => $client->id, 'domain' => 'detail-site.test']);

        $this->actingAs($this->admin())
            ->get(route('admin.clients.show', $client))
            ->assertOk()
            ->assertSee('Detail Client')
            ->assertSee('detail@example.com')
            ->assertSee('detail-site.test')
            // Section headings — the page is one screen, no tabs to click through.
            ->assertSee('Billing &amp; entitlements', false)
            ->assertSee('Websites')
            ->assertSee('Content production')
            ->assertSee('Keyword rankings')
            ->assertSee('API usage &amp; spend', false)
            ->assertSee('Support tickets')
            ->assertSee('Admin controls');
    }

    public function test_client_detail_page_is_admin_only(): void
    {
        $client = User::factory()->create();

        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get(route('admin.clients.show', $client))
            ->assertForbidden();
    }

    /** Saving from the detail page must come back to it, not bounce to the list. */
    public function test_editing_from_the_detail_page_returns_to_the_detail_page(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $client = User::factory()->create();

        $this->actingAs($this->admin())
            ->put(route('admin.clients.update', $client), [
                'name' => 'Renamed Here',
                'email' => $client->email,
                'plan_slug' => 'trial',
                'return_to' => 'show',
            ])
            ->assertRedirect(route('admin.clients.show', $client));

        $this->assertSame('Renamed Here', $client->fresh()->name);
    }

    /**
     * The integrations block must answer "where do this client's articles go?"
     * — and must never leak a credential. `credentials` is an encrypted cast
     * holding webhook secrets, tokens and app passwords.
     */
    public function test_detail_page_shows_publishing_integrations_without_credentials(): void
    {
        $client = User::factory()->create();
        $site = Website::factory()->create(['user_id' => $client->id, 'domain' => 'publish-target.test']);

        \App\Models\ContentIntegration::create([
            'website_id' => $site->id,
            'platform' => \App\Models\ContentIntegration::PLATFORM_WEBHOOK,
            'status' => \App\Models\ContentIntegration::STATUS_CONNECTED,
            'credentials' => ['endpoint_url' => 'https://hooks.example.test/serfix', 'secret' => 'super-secret-value'],
            'config' => ['post_status' => 'publish'],
            'last_verified_at' => now(),
        ]);

        $html = $this->actingAs($this->admin())
            ->get(route('admin.clients.show', $client))
            ->assertOk()
            ->assertSee('Publishing integrations')
            ->assertSee('Custom integration')
            ->assertSee('https://hooks.example.test/serfix')
            ->getContent();

        $this->assertStringNotContainsString('super-secret-value', $html, 'credentials must never render');
    }

    /**
     * The Publishing column answers "has this account wired a destination, and
     * is it healthy?" without opening each client. Rolled up across every
     * website they own, so it must distinguish no-website from
     * website-but-never-connected from broken.
     */
    public function test_list_shows_the_publishing_connection_state_per_client(): void
    {
        $noSite = User::factory()->create(['email' => 'nosite@example.com']);

        $unconnected = User::factory()->create(['email' => 'unconnected@example.com']);
        Website::factory()->create(['user_id' => $unconnected->id]);

        $connected = User::factory()->create(['email' => 'connected@example.com']);
        $connectedSite = Website::factory()->create(['user_id' => $connected->id]);
        \App\Models\ContentIntegration::create([
            'website_id' => $connectedSite->id,
            'platform' => \App\Models\ContentIntegration::PLATFORM_WORDPRESS_APP_PASSWORD,
            'status' => \App\Models\ContentIntegration::STATUS_CONNECTED,
        ]);

        $broken = User::factory()->create(['email' => 'broken@example.com']);
        $brokenSite = Website::factory()->create(['user_id' => $broken->id]);
        \App\Models\ContentIntegration::create([
            'website_id' => $brokenSite->id,
            'platform' => \App\Models\ContentIntegration::PLATFORM_WEBHOOK,
            'status' => \App\Models\ContentIntegration::STATUS_ERROR,
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.clients.index'));
        $response->assertOk()
            ->assertSee('Publishing')
            ->assertSee('No website')
            ->assertSee('Not connected')
            ->assertSee('Connected')
            ->assertSee('Connection error')
            // Platform names come from the eager-loaded relation, not a query per row.
            ->assertSee('WordPress');

        // Counts are computed in SQL — assert them rather than trusting the copy.
        $rows = $response->viewData('clients')->getCollection()->keyBy('email');
        $this->assertSame(0, (int) $rows['nosite@example.com']->integrations_total);
        $this->assertSame(0, (int) $rows['unconnected@example.com']->integrations_total);
        $this->assertSame(1, (int) $rows['connected@example.com']->integrations_connected);
        $this->assertSame(1, (int) $rows['broken@example.com']->integrations_error);
        $this->assertSame(0, (int) $rows['broken@example.com']->integrations_connected);
    }

    public function test_extra_websites_fold_into_a_plus_n_chip(): void
    {
        $client = User::factory()->create();
        foreach (['one.test', 'two.test', 'three.test'] as $domain) {
            Website::factory()->create(['user_id' => $client->id, 'domain' => $domain]);
        }

        $this->actingAs($this->admin())
            ->get(route('admin.clients.index'))
            ->assertOk()
            ->assertSee('one.test')
            ->assertSee('two.test')
            ->assertSee('+1')
            // the folded domain is not a chip of its own — it survives only in
            // the +N tooltip listing every domain
            ->assertSee('three.test');
    }
}
