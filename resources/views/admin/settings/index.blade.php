<x-layouts.app>
    <div class="mx-auto max-w-2xl">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">Settings</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Platform defaults: AI model, rank-tracker re-check interval, and Keywords Everywhere usage.
            </p>
        </div>

        @if (session('status'))
            <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.settings.update') }}" class="space-y-6">
            @csrf
            @method('PUT')

            {{-- ── AI model ─────────────────────────────────────────── --}}
            <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">AI model</h2>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Default Mistral model for every AI feature (brief, writer, strategy tools, custom-prompt classifier). Per-call overrides still win when a service pins a model.
                </p>

                <label for="model" class="mt-4 block text-xs font-semibold text-slate-700 dark:text-slate-300">Default model</label>
                <select id="model" name="model"
                    class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                    @foreach ($models as $m)
                        <option value="{{ $m['id'] }}" @selected(old('model', $currentModel) === $m['id'])>
                            {{ $m['label'] ?? $m['id'] }}
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
                    Currently active: <code class="rounded bg-slate-100 px-1.5 py-px font-mono text-[11px] dark:bg-slate-800 dark:text-slate-200">{{ $currentModel }}</code>
                </p>
            </section>

            {{-- ── Rank tracker ─────────────────────────────────────── --}}
            <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Rank tracker</h2>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Default re-check interval applied to newly tracked keywords. Existing keywords keep their own interval.
                </p>

                <label for="default_check_interval_hours" class="mt-4 block text-xs font-semibold text-slate-700 dark:text-slate-300">Default re-check interval (hours)</label>
                <input type="number" id="default_check_interval_hours" name="default_check_interval_hours"
                    min="1" max="168" required
                    value="{{ old('default_check_interval_hours', $checkIntervalHours) }}"
                    class="mt-1 w-40 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">Between 1 and 168 hours (7 days). Default SERP depth is {{ $defaultDepth }}.</p>
            </section>

            {{-- ── Keywords Everywhere ──────────────────────────────── --}}
            <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Keywords Everywhere</h2>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Controls competitor-data lookups, which spend Keywords Everywhere credits.
                </p>

                <label class="mt-4 flex items-start gap-2.5 text-sm text-slate-700 dark:text-slate-200">
                    <input type="hidden" name="competitor_keywords_everywhere" value="0" />
                    <input type="checkbox" name="competitor_keywords_everywhere" value="1"
                        class="mt-0.5 rounded border-slate-300 text-orange-600 focus:ring-orange-500"
                        @checked(old('competitor_keywords_everywhere', $competitorKeywordsEverywhere)) />
                    <span>
                        <span class="font-medium">Fetch competitor data from Keywords Everywhere after audits</span>
                        <span class="mt-0.5 block text-[11px] text-slate-500 dark:text-slate-400">
                            Opening an audit report can also refresh stale domains. Each domain call spends Keywords Everywhere credits.
                        </span>
                    </span>
                </label>
            </section>

            {{-- ── Keyword volume provider ──────────────────────────── --}}
            <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Keyword volume provider</h2>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Which backend powers search-volume lookups across the app. The self-hosted option uses your own server fleet (managed on
                    <a href="{{ route('admin.keyword-servers.index') }}" class="text-orange-600 hover:underline dark:text-orange-400">Keyword Servers</a>)
                    and is asynchronous — results stream in via webhook.
                </p>
                <label for="keyword_volume_provider" class="mt-4 block text-xs font-semibold text-slate-700 dark:text-slate-300">Provider</label>
                <select id="keyword_volume_provider" name="keyword_volume_provider"
                    class="mt-1 w-full max-w-md rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                    @foreach ($keywordProviders as $value => $label)
                        <option value="{{ $value }}" @selected(old('keyword_volume_provider', $keywordProvider) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </section>

            {{-- ── WordPress HQ banner ──────────────────────────────── --}}
            <section x-data="{ type: '{{ old('banner_type', $banner['type']) }}' }"
                class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">WordPress HQ banner</h2>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    A small promo banner shown on the bottom-right of the plugin's Serfix HQ pages. Users can close it; it reappears on the next page load. Updates reach installs within a few hours.
                </p>

                <label class="mt-4 flex items-center gap-2.5 text-sm text-slate-700 dark:text-slate-200">
                    <input type="hidden" name="banner_enabled" value="0" />
                    <input type="checkbox" name="banner_enabled" value="1"
                        class="rounded border-slate-300 text-orange-600 focus:ring-orange-500"
                        @checked(old('banner_enabled', $banner['enabled'])) />
                    <span class="font-medium">Show the banner</span>
                </label>

                <div class="mt-4">
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300">Banner type</label>
                    <select name="banner_type" x-model="type"
                        class="mt-1 w-48 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                        <option value="image">Image</option>
                        <option value="youtube">YouTube video</option>
                    </select>
                </div>

                <div class="mt-4">
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300">Title <span class="font-normal text-slate-400">(optional)</span></label>
                    <input type="text" name="banner_title" maxlength="120" value="{{ old('banner_title', $banner['title']) }}"
                        class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                </div>

                <div class="mt-4" x-show="type === 'image'" x-cloak>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300">Image URL</label>
                    <input type="url" name="banner_image_url" placeholder="https://…/banner.png" value="{{ old('banner_image_url', $banner['image_url']) }}"
                        class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                    <label class="mt-3 block text-xs font-semibold text-slate-700 dark:text-slate-300">Link URL <span class="font-normal text-slate-400">(optional — image click target)</span></label>
                    <input type="url" name="banner_link_url" placeholder="https://ebq.io/…" value="{{ old('banner_link_url', $banner['link_url']) }}"
                        class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                </div>

                <div class="mt-4" x-show="type === 'youtube'" x-cloak>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300">YouTube URL</label>
                    <input type="url" name="banner_youtube_url" placeholder="https://www.youtube.com/watch?v=…" value="{{ old('banner_youtube_url', $banner['youtube_url']) }}"
                        class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                </div>
            </section>

            <div class="flex items-center justify-end">
                <button class="rounded-md bg-orange-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-orange-700">
                    Save settings
                </button>
            </div>
        </form>

        {{-- Model-list refresh is a separate action so it doesn't save the form. --}}
        <form method="POST" action="{{ route('admin.settings.refresh-models') }}" class="mt-4 flex justify-end">
            @csrf
            <button class="text-xs font-semibold text-orange-600 hover:underline dark:text-orange-400">
                Refresh AI model list from provider
            </button>
        </form>
    </div>
</x-layouts.app>
