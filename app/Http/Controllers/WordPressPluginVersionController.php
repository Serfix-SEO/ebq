<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Website;
use App\Services\PluginReleaseResolver;
use Illuminate\Http\JsonResponse;

class WordPressPluginVersionController extends Controller
{
    /**
     * Returns the current packaged plugin metadata. The plugin uses this to
     * decide whether a newer version is available and to populate the native
     * WordPress update flow.
     */
    public function __invoke(PluginReleaseResolver $resolver): JsonResponse
    {
        $channel = request()->query('channel', 'stable');
        $channel = in_array($channel, ['stable', 'beta'], true) ? $channel : 'stable';

        $sourceFile = base_path('ebq-seo-wp/ebq-seo.php');
        $release = $resolver->latestPublished($channel);

        $version = $release?->version ?: $this->parseVersion($sourceFile);
        $tested = $this->parseHeader($sourceFile, 'Tested up to') ?: '6.7';
        $requiresWp = $this->parseHeader($sourceFile, 'Requires at least') ?: '6.0';
        $requiresPhp = $this->parseHeader($sourceFile, 'Requires PHP') ?: '8.1';

        $packagedAt = $release?->published_at?->timestamp;
        if (! $packagedAt) {
            $zipPath = public_path('downloads/ebq-seo.zip');
            $packagedAt = is_file($zipPath) ? (int) filemtime($zipPath) : null;
        }

        return response()->json([
            'slug' => 'ebq-seo',
            'name' => 'Serfix SEO',
            'version' => $version,
            'channel' => $channel,
            'download_url' => route('wordpress.plugin.download', ['channel' => $channel]),
            'packaged_at' => $packagedAt ? date('c', $packagedAt) : null,
            'requires' => [
                'wp' => $requiresWp,
                'php' => $requiresPhp,
            ],
            'tested' => $tested,
            // Global update kill-switch, toggled from the EBQ admin
            // (Plugin Releases page). When false, every install's
            // EBQ_Updater suppresses the "update available" offer. Absent
            // => enabled (back-compat with older plugin builds).
            'updates_enabled' => ((string) Setting::get('plugin.updates_enabled', '1')) !== '0',
            'homepage' => url('/features').'#wordpress',
            'changelog_url' => url('/features').'#wordpress',
            'release_notes' => $release?->release_notes,
            // Master per-feature kill-switch broadcast to every install
            // — connected and unconnected. The plugin's EBQ_Updater
            // captures this map every ~6 h via its existing transient
            // and stores it in `ebq_global_feature_flags`. The plugin's
            // is_enabled() AND's this against per-site flags so a
            // global FALSE always wins. Anonymous: no install identifier
            // is captured server-side from this endpoint.
            'global_features' => Website::globalFeatureFlags(),
            // Small promo banner shown on the plugin's EBQ HQ pages.
            // Configured from EBQ Admin → Settings; null when disabled.
            'banner' => $this->bannerPayload(),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    /**
     * Banner config broadcast to the plugin. Returns null when disabled or
     * when no usable media is set, so the plugin renders nothing.
     *
     * @return array<string, mixed>|null
     */
    private function bannerPayload(): ?array
    {
        if (((string) Setting::get('plugin.banner.enabled', '0')) !== '1') {
            return null;
        }

        $type = (string) Setting::get('plugin.banner.type', 'image');
        $type = in_array($type, ['image', 'youtube'], true) ? $type : 'image';
        $imageUrl = trim((string) Setting::get('plugin.banner.image_url', ''));
        $youtubeUrl = trim((string) Setting::get('plugin.banner.youtube_url', ''));

        // Require the media for the selected type, otherwise broadcast nothing.
        if ($type === 'image' && $imageUrl === '') {
            return null;
        }
        if ($type === 'youtube' && $youtubeUrl === '') {
            return null;
        }

        return [
            'type' => $type,
            'title' => trim((string) Setting::get('plugin.banner.title', '')),
            'image_url' => $imageUrl,
            'link_url' => trim((string) Setting::get('plugin.banner.link_url', '')),
            'youtube_url' => $youtubeUrl,
        ];
    }

    private function parseVersion(string $file): string
    {
        return $this->parseHeader($file, 'Version') ?: '0.0.0';
    }

    private function parseHeader(string $file, string $key): ?string
    {
        if (! is_file($file)) {
            return null;
        }
        $contents = (string) file_get_contents($file, false, null, 0, 8192);
        if (preg_match('/^\s*\*\s*'.preg_quote($key, '/').'\s*:\s*(.+?)\s*$/mi', $contents, $m)) {
            return trim($m[1]);
        }

        return null;
    }
}
