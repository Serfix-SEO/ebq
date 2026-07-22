<?php

namespace App\Console\Commands;

use App\Support\Zip\SimpleZipWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PackageWordPressPlugin extends Command
{
    protected $signature = 'ebq:package-plugin {--output=public/downloads/ebq-seo.zip}';
    protected $description = 'Zip the ebq-wordpress-plugin/ plugin source into public/downloads/ebq-seo.zip for public download.';

    public function handle(): int
    {
        $base = base_path('ebq-wordpress-plugin');
        if (! is_dir($base)) {
            $this->error('Plugin source not found at '.$base);

            return self::FAILURE;
        }

        $output = base_path((string) $this->option('output'));
        File::ensureDirectoryExists(dirname($output));

        // build/ is intentionally included — the no-build Gutenberg sidebar
        // lives in build/sidebar.js and must ship with the plugin.
        $skipDirs = ['node_modules', '.git', 'tests', 'coverage'];
        $skipExts = ['zip', 'log'];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $current) use ($skipDirs): bool {
                    if ($current->isDir() && in_array($current->getBasename(), $skipDirs, true)) {
                        return false;
                    }

                    return true;
                }
            ),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $writer = new SimpleZipWriter();
        $added = 0;

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir()) {
                continue;
            }
            if (in_array(strtolower($file->getExtension()), $skipExts, true)) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($base) + 1);
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            $archiveName = 'ebq-seo/'.$relative;

            $content = (string) file_get_contents($file->getPathname());
            $writer->addFile($archiveName, $content);
            $added++;
        }

        file_put_contents($output, $writer->toBinary());

        $this->info(sprintf('Packaged %d files → %s (%s).', $added, $output, $this->formatSize(filesize($output))));

        return self::SUCCESS;
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1024 / 1024, 2).' MB';
    }
}
