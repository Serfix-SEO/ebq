<?php

namespace Tests\Feature;

use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Google Consent Mode v2, driven by our own banner.
 *
 * The ordering assertion is the important one. A consent default only applies
 * if it is queued on dataLayer BEFORE gtag.js is fetched; put the loader first
 * and the tag fires once with storage allowed, which is exactly the failure the
 * banner exists to prevent — and it is invisible in the rendered page unless
 * you go looking for the order.
 */
class ConsentModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_consent_defaults_are_queued_before_the_tag_loads(): void
    {
        $html = $this->get(route('content.landing'))->assertOk()->getContent();

        $firstConsent = strpos($html, "gtag('consent', 'default'");
        $loader = strpos($html, 'googletagmanager.com/gtag/js');
        $firstConfig = strpos($html, "gtag('config'");

        $this->assertNotFalse($firstConsent, 'a consent default must be set');
        $this->assertLessThan($loader, $firstConsent, 'consent must be queued before gtag.js is fetched');
        $this->assertLessThan($firstConfig, $firstConsent, 'consent must be queued before any config');
    }

    /** Denied across the regulated regions, granted elsewhere. */
    public function test_the_eea_uk_and_switzerland_default_to_denied(): void
    {
        $html = $this->get(route('content.landing'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            "/'ad_storage': 'denied'.*?'region': \[[^\]]*'GB'[^\]]*'CH'[^\]]*\]/s",
            $html,
            'the region-scoped default must deny and must cover the UK and Switzerland',
        );
        $this->assertStringContainsString("'ad_user_data': 'denied'", $html, 'v2 signals are required, not just ad_storage');
        $this->assertStringContainsString("'ad_personalization': 'denied'", $html);
        $this->assertStringContainsString("'ad_storage': 'granted'", $html, 'unregulated regions keep measurement on');
    }

    public function test_the_banner_offers_both_answers_and_links_the_policy(): void
    {
        $html = $this->get(route('content.landing'))->assertOk()->getContent();

        $this->assertStringContainsString('id="consent-banner"', $html);
        $this->assertStringContainsString('data-consent="granted"', $html);
        $this->assertStringContainsString('data-consent="denied"', $html, 'rejecting must be as available as accepting');
        $this->assertStringContainsString(route('privacy-policy'), $html);
        // Hidden until script decides — a visitor without JS never gets an
        // overlay they have no way to dismiss.
        $this->assertMatchesRegularExpression('/<div id="consent-banner" hidden/', $html);
    }

    /** The banner has to reach every page that carries the tag. */
    public function test_the_banner_renders_on_the_public_pages_that_load_the_tag(): void
    {
        foreach (['landing', 'content.landing', 'pricing', 'tools.audit'] as $route) {
            $html = $this->get(route($route))->assertOk()->getContent();

            $this->assertStringContainsString('googletagmanager.com/gtag/js', $html, "{$route} loads the tag");
            $this->assertStringContainsString('id="consent-banner"', $html, "{$route} must also carry the banner");
        }
    }
}
