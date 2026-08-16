<?php

namespace App\Support;

/**
 * A short hash of the application's own PHP source, used to tell "this process
 * is running the code I deployed" from "this process is running whatever it
 * booted with".
 *
 * Covers `app/` and `config/` — application code and the settings a worker
 * resolves. Vendor is excluded (it is not rsynced and does not change on a
 * normal deploy) and so are views and routes (the web box's problem, and
 * `route:cache` already surfaces those).
 */
final class CodeFingerprint
{
    /** @var string|null memoised per process — the source cannot change under us */
    private static ?string $cached = null;

    public static function current(): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $hashes = [];
        foreach (['app', 'config'] as $dir) {
            $path = base_path($dir);
            if (! is_dir($path)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($it as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isFile() && $file->getExtension() === 'php') {
                    // Relative path, so an identical tree hashes the same on
                    // both boxes regardless of where it is mounted.
                    $hashes[] = substr($file->getPathname(), strlen(base_path())).':'.md5_file($file->getPathname());
                }
            }
        }

        sort($hashes);

        return self::$cached = substr(md5(implode("\n", $hashes)), 0, 12);
    }
}
