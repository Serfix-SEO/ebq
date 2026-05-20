<x-layouts.app>
    <div class="mx-auto max-w-2xl">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">AI model</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Picks the default Mistral chat model for every AI feature
                (brief, writer, strategy tools, custom-prompt classifier).
                Per-call overrides still take precedence when a service
                pins itself to a specific model.
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

        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <form method="POST" action="{{ route('admin.ai-model.settings.update') }}">
                @csrf
                @method('PUT')

                <div class="space-y-5">
                    @php
                        $modelsJson = json_encode($models, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                        $initialId = old('model', $currentModel);
                        $initialLabel = collect($models)->firstWhere('id', $initialId)['label'] ?? $initialId;
                    @endphp
                    <div
                        x-data="aiModelCombobox({
                            models: {{ $modelsJson }},
                            initialId: @js($initialId),
                            initialLabel: @js($initialLabel),
                        })"
                        @keydown.escape.stop="close()"
                        @click.outside="close()"
                        class="relative"
                    >
                        <label for="model-search" class="block text-sm font-semibold text-slate-900 dark:text-slate-100">
                            Default model
                        </label>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Currently active: <code class="rounded bg-slate-100 px-1.5 py-px font-mono text-[11px] dark:bg-slate-800 dark:text-slate-200">{{ $currentModel }}</code>
                        </p>

                        {{-- Real form value — the combobox writes the selected id here. --}}
                        <input type="hidden" name="model" :value="selectedId">

                        <div class="relative mt-2">
                            <input
                                id="model-search"
                                type="text"
                                autocomplete="off"
                                role="combobox"
                                aria-autocomplete="list"
                                aria-controls="model-combobox-list"
                                :aria-expanded="open"
                                x-ref="search"
                                x-model="query"
                                @focus="open = true; activeIndex = 0"
                                @input="open = true; activeIndex = 0"
                                @keydown.arrow-down.prevent="moveActive(1)"
                                @keydown.arrow-up.prevent="moveActive(-1)"
                                @keydown.enter.prevent="commitActive()"
                                @keydown.tab="close()"
                                placeholder="Type to search models…"
                                class="block w-full rounded-md border-slate-300 bg-white pl-3 pr-9 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                            <button
                                type="button"
                                @click="toggle()"
                                tabindex="-1"
                                aria-label="Toggle list"
                                class="absolute inset-y-0 right-0 flex items-center px-2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
                            >
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                </svg>
                            </button>
                        </div>

                        <ul
                            id="model-combobox-list"
                            x-show="open"
                            x-cloak
                            role="listbox"
                            class="absolute z-20 mt-1 max-h-72 w-full overflow-y-auto rounded-md border border-slate-200 bg-white py-1 shadow-lg dark:border-slate-700 dark:bg-slate-900"
                            x-transition:enter="transition ease-out duration-100"
                            x-transition:enter-start="opacity-0 -translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-75"
                            x-transition:leave-start="opacity-100"
                            x-transition:leave-end="opacity-0"
                        >
                            <template x-for="(option, idx) in filtered" :key="option.id">
                                <li
                                    role="option"
                                    :aria-selected="option.id === selectedId"
                                    :class="{
                                        'bg-indigo-50 text-indigo-900 dark:bg-indigo-500/15 dark:text-indigo-100': idx === activeIndex,
                                        'text-slate-700 dark:text-slate-200': idx !== activeIndex,
                                    }"
                                    @mouseenter="activeIndex = idx"
                                    @mousedown.prevent="pick(option)"
                                    class="cursor-pointer px-3 py-2 text-sm flex items-center justify-between gap-2"
                                >
                                    <span x-text="option.label" class="truncate"></span>
                                    <svg x-show="option.id === selectedId" class="h-4 w-4 text-indigo-600 dark:text-indigo-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                    </svg>
                                </li>
                            </template>
                            <li
                                x-show="filtered.length === 0"
                                class="px-3 py-2 text-sm text-slate-500 dark:text-slate-400"
                            >
                                No models match "<span x-text="query"></span>".
                            </li>
                        </ul>

                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                            List pulled live from the Mistral
                            <code class="font-mono">/v1/models</code> endpoint and cached
                            for an hour. Use <em>Refresh list</em> below to force a re-fetch
                            after the provider adds a new model.
                        </p>
                    </div>
                </div>

                {{-- Combobox controller. Defined inline so the page is self-contained
                     and we don't need a separate JS bundle for the admin area. --}}
                <script>
                    function aiModelCombobox({ models, initialId, initialLabel }) {
                        return {
                            models,
                            open: false,
                            query: initialLabel || '',
                            selectedId: initialId || '',
                            selectedLabel: initialLabel || '',
                            activeIndex: 0,
                            get filtered() {
                                const q = (this.query || '').trim().toLowerCase();
                                if (q === '' || q === this.selectedLabel.toLowerCase()) {
                                    return this.models;
                                }
                                return this.models.filter((m) =>
                                    m.id.toLowerCase().includes(q) ||
                                    m.label.toLowerCase().includes(q)
                                );
                            },
                            toggle() {
                                this.open = !this.open;
                                if (this.open) {
                                    this.activeIndex = Math.max(
                                        0,
                                        this.filtered.findIndex((m) => m.id === this.selectedId)
                                    );
                                    this.$nextTick(() => this.$refs.search.focus());
                                }
                            },
                            close() {
                                this.open = false;
                                // Revert the search box to the selected label so a
                                // stray query doesn't linger after dismiss.
                                this.query = this.selectedLabel;
                            },
                            moveActive(delta) {
                                if (!this.open) {
                                    this.open = true;
                                    return;
                                }
                                const list = this.filtered;
                                if (list.length === 0) return;
                                this.activeIndex = (this.activeIndex + delta + list.length) % list.length;
                            },
                            commitActive() {
                                const list = this.filtered;
                                if (!this.open || list.length === 0) return;
                                this.pick(list[this.activeIndex] || list[0]);
                            },
                            pick(option) {
                                if (!option) return;
                                this.selectedId = option.id;
                                this.selectedLabel = option.label;
                                this.query = option.label;
                                this.open = false;
                            },
                        };
                    }
                </script>

                <div class="mt-6 flex justify-end">
                    <button type="submit"
                        class="inline-flex h-9 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">
                        Save model
                    </button>
                </div>
            </form>

            <form method="POST" action="{{ route('admin.ai-model.settings.refresh') }}" class="mt-4 flex justify-end border-t border-slate-200 pt-4 dark:border-slate-800">
                @csrf
                <button type="submit"
                    class="inline-flex h-8 items-center rounded-md border border-slate-300 bg-white px-3 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
                    Refresh list from Mistral
                </button>
            </form>
        </div>

        <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
            Tip: <code class="font-mono">mistral-small-latest</code> is the cheapest
            with native JSON-mode and works well for every existing prompt.
            <code class="font-mono">mistral-large-latest</code> produces noticeably
            richer prose but costs ~10x more per token.
        </p>
    </div>
</x-layouts.app>
