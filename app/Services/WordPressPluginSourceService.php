<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;

class WordPressPluginSourceService
{
    private const VERSION_PATTERN = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/';

    public function pluginMainFile(): string
    {
        return base_path('ebq-wordpress-plugin/ebq-seo.php');
    }

    public function readCurrentVersion(): ?string
    {
        $file = $this->pluginMainFile();
        if (! is_file($file)) {
            return null;
        }

        $contents = (string) file_get_contents($file, false, null, 0, 8192);

        if (preg_match('/^\s*\*\s*Version:\s*(.+?)\s*$/mi', $contents, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * Updates the plugin header Version line and EBQ_SEO_VERSION in ebq-seo.php, then runs packaging.
     *
     * @throws InvalidArgumentException
     */
    public function syncVersionAndPackage(string $version): void
    {
        $this->setVersionInSource($version);
        $code = Artisan::call('ebq:package-plugin', ['--output' => 'public/downloads/ebq-seo.zip']);
        if ($code !== 0) {
            throw new InvalidArgumentException('Failed to package plugin (ebq:package-plugin exited '.$code.').');
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    public function setVersionInSource(string $version): void
    {
        $version = trim($version);
        if (! preg_match(self::VERSION_PATTERN, $version)) {
            throw new InvalidArgumentException('Version must look like 1.2.3 or 1.2.3-beta (letters, numbers, dots, hyphens after patch).');
        }

        $path = $this->pluginMainFile();
        if (! is_file($path)) {
            throw new InvalidArgumentException('Plugin main file missing: '.$path);
        }

        $contents = (string) file_get_contents($path);
        $updated = preg_replace('/^\s*\*\s*Version:\s*.+$/m', ' * Version:           '.$version, $contents, 1, $headerCount);
        if ($headerCount === 0) {
            throw new InvalidArgumentException('Could not find Version header in ebq-seo.php.');
        }

        $updated = preg_replace(
            "/define\s*\(\s*'EBQ_SEO_VERSION'\s*,\s*'[^']*'\s*\)/",
            "define('EBQ_SEO_VERSION', '".$version."')",
            $updated,
            1,
            $defineCount
        );
        if ($defineCount === 0) {
            throw new InvalidArgumentException("Could not find EBQ_SEO_VERSION define in ebq-seo.php.");
        }

        if (! is_writable($path)) {
            throw new InvalidArgumentException(
                'Cannot write plugin main file (not writable by this PHP process): '.$path.'. '.
                $this->filesystemPermissionHint($path)
            );
        }

        $written = @file_put_contents($path, $updated);
        if ($written === false) {
            $extra = '';
            $err = error_get_last();
            if (is_array($err) && isset($err['message']) && str_contains((string) $err['message'], 'Permission denied')) {
                $extra = ' '.$this->filesystemPermissionHint($path);
            }

            throw new InvalidArgumentException('Failed to write '.$path.'.'.$extra);
        }
    }

    private function filesystemPermissionHint(string $path): string
    {
        $dir = dirname($path);
        $user = function_exists('posix_geteuid') && function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? null) : null;
        $who = $user ? 'Current process user: '.$user.'. ' : '';

        return $who.
            'Fix ownership/ACL so the web/PHP user can write `ebq-wordpress-plugin/ebq-seo.php` and `public/downloads/` '.
            '(example on Debian/Ubuntu: `sudo chown -R www-data:www-data '.$dir.'` if PHP-FPM runs as www-data). '.
            'Alternatively, apply the version over SSH as the deploy user: `cd '.base_path().' && php artisan ebq:apply-plugin-version <version> --package`.';
    }
}
