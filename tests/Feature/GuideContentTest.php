<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The public /guide page (rebuilt 2026-08-15 as Content-AI-only client
 * documentation). Pins the things that must not regress:
 *  - reachable in both platform modes (see also SeoPlatformUiToggleTest)
 *  - every section of the promised structure is present
 *  - the annotated product screenshots it references exist on disk
 *  - NO third-party supplier is ever named on a client-facing page
 */
class GuideContentTest extends TestCase
{
    public function test_guide_renders_every_section(): void
    {
        $this->get('/guide')
            ->assertOk()
            ->assertSee('Everything Serfix does for your website, explained.')
            // Section anchors — the TOC contract.
            ->assertSee('id="onboarding"', false)
            ->assertSee('id="calendar"', false)
            ->assertSee('id="review"', false)
            ->assertSee('id="publishing"', false)
            ->assertSee('id="research"', false)
            ->assertSee('id="tracker"', false)
            ->assertSee('id="settings"', false)
            ->assertSee('id="sharing"', false)
            ->assertSee('id="support"', false)
            ->assertSee('id="troubleshooting"', false)
            ->assertSee('id="faq"', false)
            // The troubleshooting promises clients rely on.
            ->assertSee('Featured image in article')
            ->assertSee('Common problems, solved');
    }

    public function test_guide_screenshots_exist_on_disk(): void
    {
        $html = $this->get('/guide')->getContent();
        preg_match_all('#images/guide/([a-z0-9\-]+\.webp)#', $html, $m);

        $this->assertNotEmpty($m[1], 'the guide must reference its screenshots');
        foreach (array_unique($m[1]) as $file) {
            $this->assertFileExists(public_path('images/guide/'.$file), $file.' referenced but missing');
        }
    }

    public function test_guide_never_names_a_supplier(): void
    {
        $html = $this->get('/guide')->getContent();

        foreach ([
            'DataForSEO', 'dataforseo', 'Serper', 'serper', 'Moz', 'moz.com',
            'Ideogram', 'ideogram', 'OpenAI', 'Mistral', 'DeepSeek', 'deepseek',
            'Firecrawl', 'SendGrid', 'Postal',
        ] as $vendor) {
            $this->assertStringNotContainsString($vendor, $html, "supplier '{$vendor}' must never appear on the client guide");
        }
    }
}
