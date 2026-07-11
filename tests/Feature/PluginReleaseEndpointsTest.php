<?php

namespace Tests\Feature;

use App\Models\PluginRelease;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PluginReleaseEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_version_endpoint_prefers_published_release_by_channel(): void
    {
        PluginRelease::query()->create([
            'slug' => 'ebq-seo',
            'version' => '9.9.9',
            'channel' => 'stable',
            'status' => PluginRelease::STATUS_PUBLISHED,
            'zip_path' => PluginRelease::ZIP_PUBLIC_BUILD,
            'published_at' => now(),
        ]);
        PluginRelease::query()->create([
            'slug' => 'ebq-seo',
            'version' => '9.9.10-beta',
            'channel' => 'beta',
            'status' => PluginRelease::STATUS_PUBLISHED,
            'zip_path' => PluginRelease::ZIP_PUBLIC_BUILD,
            'published_at' => now(),
        ]);

        $this->getJson(route('wordpress.plugin.version', ['channel' => 'stable']))
            ->assertOk()
            ->assertJsonPath('version', '9.9.9')
            ->assertJsonPath('channel', 'stable');

        $this->getJson(route('wordpress.plugin.version', ['channel' => 'beta']))
            ->assertOk()
            ->assertJsonPath('version', '9.9.10-beta')
            ->assertJsonPath('channel', 'beta');
    }

    public function test_admin_can_create_scheduled_plugin_release(): void
    {
        // Scheduling now REQUIRES an uploaded ZIP (source-tree packaging is
        // blocked from the UI in production); the ZIP is stashed in storage
        // and only promoted to @public when the release actually publishes.
        \Illuminate\Support\Facades\Storage::fake('local');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.plugin-releases.store'), [
            'version' => '2.3.0',
            'channel' => 'stable',
            'publish_mode' => 'schedule',
            'publish_at' => now()->addHour()->format('Y-m-d H:i:s'),
            'release_notes' => 'Scheduled release',
            'zip' => \Illuminate\Http\UploadedFile::fake()->create('ebq-seo.zip', 120, 'application/zip'),
        ])->assertRedirect();

        $this->assertDatabaseHas('plugin_releases', [
            'version' => '2.3.0',
            'channel' => 'stable',
            'status' => PluginRelease::STATUS_SCHEDULED,
            'zip_path' => 'plugin-releases/ebq-seo-2.3.0-stable.zip',
        ]);
        \Illuminate\Support\Facades\Storage::disk('local')
            ->assertExists('plugin-releases/ebq-seo-2.3.0-stable.zip');
    }
}
