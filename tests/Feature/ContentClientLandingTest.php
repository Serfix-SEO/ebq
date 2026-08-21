<?php

namespace Tests\Feature;

use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * With the SEO UI off, `route('dashboard')` 301s to Site Health — so every
 * auth flow that hard-coded 'dashboard' as its landing dumped content
 * clients on Site Health instead of the Content Calendar (2026-08-21).
 * Login already used User::firstAccessibleRoute; these pin the other flows.
 */
class ContentClientLandingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Website} */
    private function contentClient(bool $verified = true): array
    {
        config(['features.seo_platform_ui' => false]);
        $this->seed(PlanSeeder::class);
        $user = User::factory()->when(! $verified, fn ($f) => $f->unverified())->create([
            'content_trial_started_at' => now(),
            'content_trial_ends_at' => now()->addDays(5),
        ]);
        $website = Website::factory()->for($user)->create([
            'ga_property_id' => 'properties/1', 'gsc_site_url' => 'sc-domain:x.com',
        ]);
        $plan = ContentPlan::factory()->create(['website_id' => $website->id, 'billing_covered_at' => now()]);
        ContentTopic::create(['plan_id' => $plan->id, 'website_id' => $website->id,
            'title' => 'T', 'target_keyword' => 'kw', 'status' => 'approved']);

        return [$user, $website];
    }

    public function test_login_lands_on_the_content_calendar(): void
    {
        [$user] = $this->contentClient();
        $user->forceFill(['password' => bcrypt('secret-pass-123')])->save();

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass-123'])
            ->assertRedirect(route('content.index'));
    }

    public function test_email_verification_link_lands_on_the_content_calendar(): void
    {
        [$user, $website] = $this->contentClient(verified: false);

        $link = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]);

        $this->actingAs($user)->withSession(['current_website_id' => $website->id])
            ->get($link)
            ->assertRedirect(route('content.index'));
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_admin_impersonation_lands_where_the_client_lands(): void
    {
        [$client] = $this->contentClient();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('admin.clients.impersonate', $client))
            ->assertRedirect(route('content.index'));
    }
}
