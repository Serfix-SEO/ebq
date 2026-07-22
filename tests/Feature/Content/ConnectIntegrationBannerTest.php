<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\ContentCalendar;
use App\Models\ContentIntegration;
use App\Models\ContentPlan;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The "connect a destination" banner (calendar view) used to only clear for
 * WordPress and name it explicitly — wrong now that Laravel and custom
 * webhook integrations exist too (2026-07-22): a client who connected via
 * Laravel kept seeing "Connect your WordPress site" forever.
 */
class ConnectIntegrationBannerTest extends TestCase
{
    use RefreshDatabase;

    private function activePlanSite(): array
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id, 'status' => ContentPlan::STATUS_ACTIVE,
        ]);

        return [$user, $website, $plan];
    }

    public function test_banner_shows_with_no_integration_connected(): void
    {
        [$user, $website] = $this->activePlanSite();
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class)
            ->assertSee('Connect a destination to publish')
            ->assertDontSee('Connect your WordPress site')
            ->assertDontSee('Connect WordPress');
    }

    public function test_banner_hides_once_wordpress_is_connected(): void
    {
        [$user, $website] = $this->activePlanSite();
        ContentIntegration::query()->create([
            'website_id' => $website->id,
            'platform' => ContentIntegration::PLATFORM_WORDPRESS,
            'status' => ContentIntegration::STATUS_CONNECTED,
            'credentials' => [],
        ]);
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class)
            ->assertDontSee('Connect a destination to publish');
    }

    /** The bug: a Laravel/custom-webhook connection never cleared the old WP-only banner. */
    public function test_banner_hides_once_a_webhook_flavored_laravel_integration_is_connected(): void
    {
        [$user, $website] = $this->activePlanSite();
        ContentIntegration::query()->create([
            'website_id' => $website->id,
            'platform' => ContentIntegration::PLATFORM_WEBHOOK,
            'status' => ContentIntegration::STATUS_CONNECTED,
            'credentials' => [],
            'config' => ['flavor' => 'laravel'],
        ]);
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class)
            ->assertDontSee('Connect a destination to publish');
    }

    /** A connected row that isn't STATUS_CONNECTED (e.g. failed/disconnected) must not suppress the banner. */
    public function test_banner_still_shows_for_a_non_connected_integration_row(): void
    {
        [$user, $website] = $this->activePlanSite();
        ContentIntegration::query()->create([
            'website_id' => $website->id,
            'platform' => ContentIntegration::PLATFORM_WEBHOOK,
            'status' => ContentIntegration::STATUS_ERROR,
            'credentials' => [],
        ]);
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class)
            ->assertSee('Connect a destination to publish');
    }
}
