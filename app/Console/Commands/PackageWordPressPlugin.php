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

        // Honour `.distignore` so the released zip carries ONLY runtime files.
        // Without this the zip shipped dev cruft — notably a 13 MB
        // `.code-review-graph/graph.db` that bloated it to 21 MB and blew the
        // client's PHP memory/time limit during unzip → "Update failed"
        // (prod 2026-07-24). build/ is intentionally NOT ignored — the no-build
        // Gutenberg sidebar lives there and must ship.
        $ignore = $this->loadDistignore($base);
        $excluded = fn (string $relative): bool => $this->isExcluded(
            str_replace(DIRECTORY_SEPARATOR, '/', $relative), $ignore
        );

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $current) use ($base, $excluded): bool {
                    // Prune ignored directories so we never even descend into
                    // heavy trees (node_modules, .code-review-graph, …).
                    $relative = substr($current->getPathname(), strlen($base) + 1);

                    return ! $excluded($relative);
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
            $relative = substr($file->getPathname(), strlen($base) + 1);
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            if ($excluded($relative)) {
                continue;
            }
            $archiveName = 'ebq-seo/'.$relative;

            $content = (string) file_get_contents($file->getPathname());
            $writer->addFile($archiveName, $content);
            $added++;
        }

        file_put_contents($output, $writer->toBinary());

        $this->info(sprintf('Packaged %d files → %s (%s).', $added, $output, $this->formatSize(filesize($output))));

        return self::SUCCESS;
    }

    /**
     * Parse `.distignore` into a pattern list (blank/comment lines dropped,
     * trailing slashes stripped). Always excludes VCS + this tool's own
     * artefacts as a safety net even if the file is missing.
     *
     * @return list<string>
     */
    private function loadDistignore(string $base): array
    {
        $patterns = ['.git', 'node_modules', '.code-review-graph', '.claude'];
        $path = $base.'/.distignore';
        if (is_file($path)) {
            foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $patterns[] = rtrim($line, '/');
            }
        }

        return array_values(array_unique(array_filter($patterns)));
    }

    /**
     * Whether a plugin-relative path is excluded by any `.distignore` pattern:
     * glob patterns (`*.md`, `.eslintrc*`) match the basename or any segment;
     * `foo/bar` patterns match as a path prefix; a plain name matches any path
     * segment (so a dir or file of that name anywhere is dropped).
     *
     * @param  list<string>  $ignore
     */
    private function isExcluded(string $relative, array $ignore): bool
    {
        $segments = explode('/', $relative);
        $basename = end($segments) ?: $relative;

        foreach ($ignore as $p) {
            if (strpbrk($p, '*?[') !== false) {
                if (fnmatch($p, $basename)) {
                    return true;
                }
                foreach ($segments as $seg) {
                    if (fnmatch($p, $seg)) {
                        return true;
                    }
                }

                continue;
            }
            if (str_contains($p, '/')) {
                if ($relative === $p || str_starts_with($relative, $p.'/')) {
                    return true;
                }

                continue;
            }
            if (in_array($p, $segments, true)) {
                return true;
            }
        }

        return false;
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
