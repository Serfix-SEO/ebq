<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeaturesPageTest extends TestCase
{
    // The version endpoint reads settings/plugin_releases; marketing pages
    // read settings via SetLocale. Without migrated tables those 500.
    use RefreshDatabase;

    public function test_features_page_is_public(): void
    {
        $this->get(route('features'))
            ->assertOk()
            ->assertSee('Every signal, every action, in one workspace.')
            ->assertSee('Cross-signal insights')
            ->assertSee('Rank tracking')
            ->assertSee('Anomaly alerts')
            ->assertSee('Site audits')
            ->assertSee('Backlinks')
            ->assertSee('Reporting')
            ->assertSee('Integrations');
    }

    public function test_landing_nav_links_to_features_page(): void
    {
        $this->get(route('landing'))
            ->assertOk()
            ->assertSee(route('features'));
    }

    public function test_landing_advertises_wordpress_plugin_without_direct_download(): void
    {
        $this->get(route('landing'))
            ->assertOk()
            ->assertSee('WordPress Plugin')
            ->assertDontSee('/wordpress/plugin.zip', escape: false);
    }

    public function test_features_page_has_wordpress_plugin_section(): void
    {
        $this->get(route('features'))
            ->assertOk()
            ->assertSee('A full Serfix HQ inside wp-admin.')
            ->assertDontSee('/wordpress/plugin.zip', escape: false);
    }

    public function test_plugin_zip_is_publicly_accessible(): void
    {
        $path = public_path('downloads/ebq-seo.zip');
        $this->assertFileExists($path);
        $this->assertGreaterThan(5_000, filesize($path), 'Plugin zip looks empty.');
    }

    public function test_plugin_version_endpoint_reflects_source_header(): void
    {
        $response = $this->getJson(route('wordpress.plugin.version'));

        $response->assertOk()
            ->assertJsonStructure([
                'slug',
                'name',
                'version',
                'download_url',
                'requires' => ['wp', 'php'],
                'tested',
                'homepage',
            ]);

        $this->assertSame('ebq-seo', $response->json('slug'));
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (string) $response->json('version'));
        $this->assertStringContainsString('/wordpress/plugin.zip', (string) $response->json('download_url'));
    }

    public function test_plugin_download_is_gated_by_the_coming_soon_flag(): void
    {
        config(['services.wordpress_plugin.coming_soon' => true]);

        // 404 (not 503) on purpose: existing installs treat 5xx as a server
        // outage and retry; 404 reads as "no build published".
        $this->get(route('wordpress.plugin.download'))->assertNotFound();
    }
}
