<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PluginRelease;
use App\Models\Setting;
use App\Services\ClientActivityLogger;
use App\Services\PluginReleaseResolver;
use App\Services\WordPressPluginSourceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class PluginReleaseController extends Controller
{
    public function index(WordPressPluginSourceService $source): View
    {
        return view('admin.plugin-releases.index', [
            'releases' => PluginRelease::query()->latest('id')->paginate(20),
            'sourceVersion' => $source->readCurrentVersion(),
            'updatesEnabled' => ((string) Setting::get('plugin.updates_enabled', '1')) !== '0',
        ]);
    }

    /**
     * Global update kill-switch. When disabled, the /wordpress/plugin/version
     * endpoint broadcasts updates_enabled=false and every install's
     * EBQ_Updater suppresses the "update available" offer until re-enabled.
     */
    public function toggleUpdates(Request $request, ClientActivityLogger $logger): RedirectResponse
    {
        $enabled = $request->boolean('enabled');
        Setting::set('plugin.updates_enabled', $enabled ? '1' : '0');

        $logger->log('admin.plugin_updates_toggled', meta: ['enabled' => $enabled]);

        return redirect()
            ->route('admin.plugin-releases.index')
            ->with('status', $enabled
                ? 'Plugin updates re-enabled — installs will see the latest published release.'
                : 'Plugin updates disabled — no install will be offered an update until re-enabled.');
    }

    public function store(
        Request $request,
        ClientActivityLogger $logger,
        PluginReleaseResolver $resolver,
        WordPressPluginSourceService $source,
    ): RedirectResponse {
        $data = $request->validate([
            'version' => ['required', 'string', 'max:40', 'regex:/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/'],
            'channel' => ['required', Rule::in(['stable', 'beta'])],
            'release_notes' => ['nullable', 'string'],
            'publish_mode' => ['required', Rule::in(['draft', 'now', 'schedule'])],
            'publish_at' => ['nullable', 'date'],
            'zip' => ['nullable', 'file', 'mimes:zip', 'max:20480', 'required_if:publish_mode,now,schedule'],
        ], [
            'zip.required_if' => 'A plugin ZIP is required when publishing now or scheduling. (Source-tree packaging is disabled from the UI in production.)',
        ]);

        $exists = PluginRelease::query()
            ->where('slug', 'ebq-seo')
            ->where('version', $data['version'])
            ->where('channel', $data['channel'])
            ->exists();
        if ($exists) {
            return back()->withErrors(['version' => 'This version already exists for the selected channel.']);
        }

        $uploaded = $request->file('zip');
        $status = PluginRelease::STATUS_DRAFT;
        $publishAt = null;
        $publishedAt = null;
        $zipPath = PluginRelease::ZIP_PUBLIC_BUILD;

        if ($uploaded !== null) {
            // Stash the uploaded ZIP in storage so it survives draft → publish.
            $stored = sprintf('plugin-releases/ebq-seo-%s-%s.zip', $data['version'], $data['channel']);
            $uploaded->storeAs('plugin-releases', basename($stored), 'local');
            $zipPath = $stored;
        }

        if ($data['publish_mode'] === 'now') {
            if ($uploaded !== null) {
                $this->promoteUploadedZipToPublic($zipPath);
                $zipPath = PluginRelease::ZIP_PUBLIC_BUILD;
            } else {
                try {
                    $source->syncVersionAndPackage($data['version']);
                } catch (InvalidArgumentException $e) {
                    return back()->withErrors(['version' => $e->getMessage()]);
                }
            }
            $status = PluginRelease::STATUS_PUBLISHED;
            $publishedAt = now();
        } elseif ($data['publish_mode'] === 'schedule') {
            $status = PluginRelease::STATUS_SCHEDULED;
            $publishAt = $data['publish_at'] ? Carbon::parse($data['publish_at']) : null;
        }

        $release = PluginRelease::query()->create([
            'slug' => 'ebq-seo',
            'version' => $data['version'],
            'channel' => $data['channel'],
            'status' => $status,
            'release_notes' => $data['release_notes'] ?? null,
            'zip_path' => $zipPath,
            'publish_at' => $publishAt,
            'published_at' => $publishedAt,
            'created_by' => $request->user()?->id,
        ]);

        if ($release->status === PluginRelease::STATUS_PUBLISHED) {
            $resolver->markPublished($release);
        }

        $logger->log('admin.plugin_release_created', meta: [
            'release_id' => $release->id,
            'version' => $release->version,
            'channel' => $release->channel,
            'status' => $release->status,
        ]);

        return back()->with('status', 'Plugin release saved. Source version and ZIP are updated when you publish (or when a scheduled release runs).');
    }

    public function publish(
        PluginRelease $pluginRelease,
        PluginReleaseResolver $resolver,
        ClientActivityLogger $logger,
        WordPressPluginSourceService $source,
    ): RedirectResponse {
        if (str_starts_with((string) $pluginRelease->zip_path, 'plugin-releases/')
            && Storage::disk('local')->exists($pluginRelease->zip_path)) {
            $this->promoteUploadedZipToPublic($pluginRelease->zip_path);
        } else {
            try {
                $source->syncVersionAndPackage($pluginRelease->version);
            } catch (InvalidArgumentException $e) {
                return back()->withErrors(['version' => $e->getMessage()]);
            }
        }
        $pluginRelease->forceFill(['zip_path' => PluginRelease::ZIP_PUBLIC_BUILD])->save();

        $resolver->markPublished($pluginRelease);

        $logger->log('admin.plugin_release_published', meta: [
            'release_id' => $pluginRelease->id,
            'version' => $pluginRelease->version,
            'channel' => $pluginRelease->channel,
        ]);

        return back()->with('status', 'Release published; ebq-wordpress-plugin version updated and plugin packaged.');
    }

    public function rollback(
        PluginRelease $pluginRelease,
        ClientActivityLogger $logger,
        WordPressPluginSourceService $source,
    ): RedirectResponse {
        if ($pluginRelease->status !== PluginRelease::STATUS_PUBLISHED) {
            return back()->withErrors(['release' => 'Only published releases can be rolled back.']);
        }

        $replacement = PluginRelease::query()
            ->where('slug', $pluginRelease->slug)
            ->where('channel', $pluginRelease->channel)
            ->where('status', PluginRelease::STATUS_ROLLED_BACK)
            ->whereNotNull('published_at')
            ->when($pluginRelease->published_at, fn ($q) => $q->where('published_at', '<', $pluginRelease->published_at))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();

        $pluginRelease->update([
            'status' => PluginRelease::STATUS_ROLLED_BACK,
            'rolled_back_at' => now(),
        ]);

        if ($replacement) {
            try {
                $source->syncVersionAndPackage($replacement->version);
            } catch (InvalidArgumentException $e) {
                return back()->withErrors(['release' => $e->getMessage()]);
            }
            $replacement->forceFill([
                'status' => PluginRelease::STATUS_PUBLISHED,
                'published_at' => now(),
                'rolled_back_at' => null,
                'rollback_of_id' => $pluginRelease->id,
                'zip_path' => PluginRelease::ZIP_PUBLIC_BUILD,
            ])->save();
        }

        $logger->log('admin.plugin_release_rolled_back', meta: [
            'release_id' => $pluginRelease->id,
            'replacement_id' => $replacement?->id,
        ]);

        return back()->with('status', $replacement
            ? 'Rolled back; source and package restored to the previous release version.'
            : 'Release marked rolled back (no prior release to restore).');
    }

    public function uploadZip(
        PluginRelease $pluginRelease,
        Request $request,
        ClientActivityLogger $logger,
    ): RedirectResponse {
        if (! in_array($pluginRelease->status, [PluginRelease::STATUS_DRAFT, PluginRelease::STATUS_SCHEDULED], true)) {
            return back()->withErrors(['zip' => 'ZIP can only be attached to draft or scheduled releases.']);
        }

        $request->validate([
            'zip' => ['required', 'file', 'mimes:zip', 'max:20480'],
        ]);

        if (str_starts_with((string) $pluginRelease->zip_path, 'plugin-releases/')
            && Storage::disk('local')->exists($pluginRelease->zip_path)) {
            Storage::disk('local')->delete($pluginRelease->zip_path);
        }

        $stored = sprintf('plugin-releases/ebq-seo-%s-%s.zip', $pluginRelease->version, $pluginRelease->channel);
        $request->file('zip')->storeAs('plugin-releases', basename($stored), 'local');
        $pluginRelease->update(['zip_path' => $stored]);

        $logger->log('admin.plugin_release_zip_attached', meta: [
            'release_id' => $pluginRelease->id,
            'version' => $pluginRelease->version,
            'channel' => $pluginRelease->channel,
        ]);

        return back()->with('status', 'ZIP attached. Click Publish to promote it to public/downloads/ebq-seo.zip.');
    }

    /**
     * Copy a stored upload (storage/app/plugin-releases/...) to the public download
     * path so /wordpress/plugin.zip serves the operator-uploaded ZIP. Public folder
     * is gitignored — the file lives outside source control by design.
     */
    private function promoteUploadedZipToPublic(string $storagePath): void
    {
        $absoluteSource = Storage::disk('local')->path($storagePath);
        $destination = public_path('downloads/ebq-seo.zip');

        if (! is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0755, true);
        }

        copy($absoluteSource, $destination);
    }

    public function destroy(PluginRelease $pluginRelease): RedirectResponse
    {
        if ($pluginRelease->status === PluginRelease::STATUS_PUBLISHED) {
            return back()->withErrors(['release' => 'Cannot delete a published release. Roll it back first.']);
        }

        if ($pluginRelease->zip_path !== ''
            && $pluginRelease->zip_path !== PluginRelease::ZIP_PUBLIC_BUILD
            && str_starts_with($pluginRelease->zip_path, 'plugin-releases/')
            && Storage::disk('local')->exists($pluginRelease->zip_path)) {
            Storage::disk('local')->delete($pluginRelease->zip_path);
        }

        $pluginRelease->delete();

        return back()->with('status', 'Release deleted.');
    }
}
