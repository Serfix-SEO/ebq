<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `ebq:package-plugin` must honour `.distignore`. A prior version shipped dev
 * cruft — notably a 13 MB `.code-review-graph/graph.db` — that bloated the
 * release zip to 21 MB and broke WordPress "Update now" (unzip exceeded the
 * client's PHP memory/time limit). prod 2026-07-24.
 */
class PluginPackageTest extends TestCase
{
    public function test_packaged_zip_excludes_dev_cruft_but_keeps_the_runtime(): void
    {
        if (! is_dir(base_path('ebq-wordpress-plugin'))) {
            $this->markTestSkipped('plugin source not present');
        }

        $out = 'storage/app/testing/ebq-seo-'.uniqid().'.zip';
        $abs = base_path($out);
        @mkdir(dirname($abs), 0777, true);

        try {
            $this->assertSame(0, Artisan::call('ebq:package-plugin', ['--output' => $out]));

            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($abs) === true, 'zip opens');
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[] = $zip->getNameIndex($i);
            }
            $zip->close();

            $blob = implode("\n", $names);
            // Dev cruft must be gone.
            $this->assertStringNotContainsString('.code-review-graph/', $blob);
            $this->assertStringNotContainsString('graph.db', $blob);
            $this->assertStringNotContainsString('.claude/', $blob);
            $this->assertStringNotContainsString('ebq-seo/src/', $blob);
            $this->assertStringNotContainsString('package-lock.json', $blob);
            $this->assertStringNotContainsString('node_modules/', $blob);

            // Runtime must be present.
            $this->assertContains('ebq-seo/ebq-seo.php', $names);
            $this->assertTrue((bool) array_filter($names, fn ($n) => str_starts_with($n, 'ebq-seo/build/')), 'build/ ships');
            $this->assertTrue((bool) array_filter($names, fn ($n) => str_starts_with($n, 'ebq-seo/includes/')), 'includes/ ships');

            $this->assertLessThan(12 * 1024 * 1024, filesize($abs), 'zip stays lean (<12MB)');
        } finally {
            @unlink($abs);
        }
    }
}
