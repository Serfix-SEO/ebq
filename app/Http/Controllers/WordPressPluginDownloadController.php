<?php

namespace App\Http\Controllers;

use App\Models\PluginRelease;
use App\Services\PluginReleaseResolver;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WordPressPluginDownloadController extends Controller
{
    private function absoluteZipForRelease(?PluginRelease $release): ?string
    {
        if ($release === null || $release->zip_path === null || $release->zip_path === '') {
            return null;
        }

        if ($release->zip_path === PluginRelease::ZIP_PUBLIC_BUILD) {
            $path = public_path('downloads/ebq-seo.zip');

            return is_file($path) ? $path : null;
        }

        if (Storage::disk('local')->exists($release->zip_path)) {
            return Storage::disk('local')->path($release->zip_path);
        }

        return null;
    }

    /**
     * Always serves the latest packaged plugin ZIP with aggressive no-cache
     * headers so re-packaging invalidates every downstream cache instantly.
     * Filename includes the file's mtime so the browser treats each re-package
     * as a different download artifact.
     */
    public function __invoke(PluginReleaseResolver $resolver): BinaryFileResponse|Response
    {
        // Distribution suppressed while the plugin is being rebuilt (see
        // services.wordpress_plugin.coming_soon) — existing installs are
        // untouched (API/embeds/version endpoint stay live), we just stop
        // handing out the outdated ZIP.
        abort_if((bool) config('services.wordpress_plugin.coming_soon'), 404);

        $channel = request()->query('channel', 'stable');
        $channel = in_array($channel, ['stable', 'beta'], true) ? $channel : 'stable';

        $release = $resolver->latestPublished($channel);
        $absolute = $this->absoluteZipForRelease($release);
        if ($release && $absolute !== null) {
            clearstatcache(true, $absolute);
            $filename = 'ebq-seo-'.$release->version.'-'.$channel.'.zip';

            return response()->download($absolute, $filename, [
                'Content-Type' => 'application/zip',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);
        }

        $path = public_path('downloads/ebq-seo.zip');

        if (! is_file($path)) {
            return response('Plugin not packaged yet — run `php artisan ebq:package-plugin`.', 404)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        clearstatcache(true, $path);
        $filename = 'ebq-seo-'.date('Ymd-His', filemtime($path)).'.zip';

        return response()->download($path, $filename, [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }
}
