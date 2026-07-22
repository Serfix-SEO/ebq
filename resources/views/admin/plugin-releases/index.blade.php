<x-layouts.app>
    <x-admin.plugin-tabs current="releases" />

    <div class="space-y-6">
        <div>
            <h2 class="text-lg font-semibold">Plugin Releases</h2>
            <p class="text-sm text-slate-500">
                Set the version in <code class="rounded bg-slate-100 px-1 py-0.5 font-mono text-xs dark:bg-slate-800">ebq-wordpress-plugin/ebq-seo.php</code> and run
                <code class="rounded bg-slate-100 px-1 py-0.5 font-mono text-xs dark:bg-slate-800">php artisan ebq:package-plugin</code> automatically when you publish or when a scheduled release goes live.
            </p>
            @if ($sourceVersion)
                <p class="mt-2 text-xs text-slate-500">Current source version: <span class="font-mono font-semibold text-slate-700 dark:text-slate-300">{{ $sourceVersion }}</span></p>
            @endif
        </div>

        @if (session('status'))
            <div class="rounded border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="rounded border border-rose-300 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Global update kill-switch: broadcast via /wordpress/plugin/version
             to every install. Off = no site is offered an update until re-enabled. --}}
        <div class="flex flex-col gap-3 rounded border p-4 sm:flex-row sm:items-center sm:justify-between {{ $updatesEnabled ? 'border-emerald-200 bg-emerald-50' : 'border-amber-300 bg-amber-50' }}">
            <div>
                <p class="text-sm font-semibold {{ $updatesEnabled ? 'text-emerald-800' : 'text-amber-900' }}">
                    Plugin updates are {{ $updatesEnabled ? 'ENABLED' : 'DISABLED' }}
                </p>
                <p class="mt-0.5 text-xs {{ $updatesEnabled ? 'text-emerald-700' : 'text-amber-800' }}">
                    {{ $updatesEnabled
                        ? 'Every connected WordPress install sees the latest published release on its Plugins page.'
                        : 'No install is being offered an update. Re-enable to resume the rollout of the latest published release.' }}
                </p>
            </div>
            <form method="POST" action="{{ route('admin.plugin-releases.toggle-updates') }}">
                @csrf
                <input type="hidden" name="enabled" value="{{ $updatesEnabled ? '0' : '1' }}" />
                <button class="rounded px-4 py-2 text-sm font-semibold text-white {{ $updatesEnabled ? 'bg-amber-600 hover:bg-amber-700' : 'bg-emerald-600 hover:bg-emerald-700' }}">
                    {{ $updatesEnabled ? 'Disable updates' : 'Re-enable updates' }}
                </button>
            </form>
        </div>

        <form method="POST" action="{{ route('admin.plugin-releases.store') }}" enctype="multipart/form-data" class="rounded border border-slate-200 bg-white p-4">
            @csrf
            <div class="grid gap-2 md:grid-cols-3">
                <input type="text" name="version" placeholder="Version e.g. 2.3.0 or 2.3.0-beta" class="rounded border border-slate-300 px-3 py-2 text-sm" required />
                <select name="channel" class="rounded border border-slate-300 px-3 py-2 text-sm">
                    <option value="stable">stable</option>
                    <option value="beta">beta</option>
                </select>
                <select name="publish_mode" class="rounded border border-slate-300 px-3 py-2 text-sm">
                    <option value="draft">draft</option>
                    <option value="now">publish now</option>
                    <option value="schedule">schedule</option>
                </select>
                <input type="datetime-local" name="publish_at" class="rounded border border-slate-300 px-3 py-2 text-sm md:col-span-3" />
                <textarea name="release_notes" rows="3" placeholder="Release notes" class="rounded border border-slate-300 px-3 py-2 text-sm md:col-span-3"></textarea>
                <div class="md:col-span-3">
                    <label class="block text-xs font-semibold text-slate-700">Plugin ZIP <span class="font-normal text-slate-500">(required when publishing now or scheduling)</span></label>
                    <input type="file" name="zip" accept=".zip,application/zip,application/x-zip-compressed" class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm file:mr-3 file:rounded file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-slate-700" />
                    <p class="mt-1 text-[11px] text-slate-500">Max 20 MB. With <em>publish now</em>, the uploaded file replaces <code class="rounded bg-slate-100 px-1 font-mono">public/downloads/ebq-seo.zip</code> directly. Drafts can be created without a ZIP and uploaded later via re-create.</p>
                </div>
            </div>
            <button class="mt-3 rounded bg-orange-600 px-4 py-2 text-sm font-semibold text-white">Create release</button>
        </form>

        <div class="overflow-auto rounded border border-slate-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left">
                    <tr>
                        <th class="px-3 py-2">Version</th>
                        <th class="px-3 py-2">Channel</th>
                        <th class="px-3 py-2">Status</th>
                        <th class="px-3 py-2">ZIP</th>
                        <th class="px-3 py-2">Publish At</th>
                        <th class="px-3 py-2">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($releases as $release)
                        @php
                            $isEditable = in_array($release->status, [\App\Models\PluginRelease::STATUS_DRAFT, \App\Models\PluginRelease::STATUS_SCHEDULED], true);
                            $hasUploadedZip = is_string($release->zip_path) && str_starts_with($release->zip_path, 'plugin-releases/');
                        @endphp
                        <tr class="border-t border-slate-100 align-top">
                            <td class="px-3 py-2">{{ $release->version }}</td>
                            <td class="px-3 py-2">{{ $release->channel }}</td>
                            <td class="px-3 py-2">{{ $release->status }}</td>
                            <td class="px-3 py-2">
                                @if ($release->status === \App\Models\PluginRelease::STATUS_PUBLISHED)
                                    <span class="rounded bg-emerald-50 px-1.5 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-emerald-200">Live</span>
                                @elseif ($hasUploadedZip)
                                    <span class="rounded bg-orange-50 px-1.5 py-0.5 text-xs font-medium text-orange-700 ring-1 ring-orange-200">Attached</span>
                                @else
                                    <span class="rounded bg-amber-50 px-1.5 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-amber-200">Missing</span>
                                @endif
                                @if ($isEditable)
                                    <form method="POST" action="{{ route('admin.plugin-releases.upload-zip', $release) }}" enctype="multipart/form-data" class="mt-2 flex items-center gap-1.5">
                                        @csrf
                                        <input type="file" name="zip" accept=".zip,application/zip,application/x-zip-compressed" required class="block w-full text-xs file:mr-2 file:rounded file:border-0 file:bg-slate-100 file:px-2 file:py-1 file:text-xs file:font-semibold file:text-slate-700" />
                                        <button type="submit" class="whitespace-nowrap rounded border border-slate-300 px-2 py-1 text-xs">{{ $hasUploadedZip ? 'Replace' : 'Attach' }}</button>
                                    </form>
                                @endif
                            </td>
                            <td class="px-3 py-2">{{ $release->publish_at?->format('Y-m-d H:i') ?? '-' }}</td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap gap-2">
                                    @if ($isEditable)
                                        <form method="POST" action="{{ route('admin.plugin-releases.publish', $release) }}">@csrf<button type="submit" class="rounded border border-orange-300 px-2 py-1 text-xs disabled:opacity-50" @if (! $hasUploadedZip) title="Attach a ZIP first" @endif>Publish</button></form>
                                    @endif
                                    @if ($release->status === \App\Models\PluginRelease::STATUS_PUBLISHED)
                                        <form method="POST" action="{{ route('admin.plugin-releases.rollback', $release) }}">@csrf<button type="submit" class="rounded border border-amber-300 px-2 py-1 text-xs">Rollback</button></form>
                                    @endif
                                    @if ($release->status !== \App\Models\PluginRelease::STATUS_PUBLISHED)
                                        <form method="POST" action="{{ route('admin.plugin-releases.destroy', $release) }}">@csrf @method('DELETE')<button type="submit" class="rounded border border-red-300 px-2 py-1 text-xs">Delete</button></form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $releases->links() }}
    </div>
</x-layouts.app>
