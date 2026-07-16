<div @if($this->isPolling()) wire:poll.2000ms="poll" @endif>
    @php $fmtN = fn ($n) => $n === null ? '—' : number_format((int) $n); @endphp

    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        {{-- Mode toggle --}}
        <div class="mb-4 inline-flex rounded-lg border border-slate-200 p-0.5 text-xs font-semibold dark:border-slate-700">
            <button type="button" wire:click="$set('mode', 'seeds')"
                @class([
                    'rounded-md px-3 py-1.5 transition',
                    'bg-orange-600 text-white' => $mode === 'seeds',
                    'text-slate-600 dark:text-slate-300' => $mode !== 'seeds',
                ])>{{ __('From seed keywords') }}</button>
            <button type="button" wire:click="$set('mode', 'website')"
                @class([
                    'rounded-md px-3 py-1.5 transition',
                    'bg-orange-600 text-white' => $mode === 'website',
                    'text-slate-600 dark:text-slate-300' => $mode !== 'website',
                ])>{{ __('From a website') }}</button>
        </div>

        <form wire:submit.prevent="run" class="space-y-4">
            @if ($mode === 'seeds')
                <label class="block">
                    <span class="text-xs font-medium text-slate-600 dark:text-slate-300">{{ __('Seed keywords (one per line or comma-separated)') }}</span>
                    <textarea wire:model="seedsInput" rows="4" placeholder="running shoes&#10;trail running"
                        class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800"></textarea>
                </label>
            @else
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="block sm:col-span-2">
                        <span class="text-xs font-medium text-slate-600 dark:text-slate-300">{{ __('Website or page URL') }}</span>
                        <input type="text" wire:model="url" placeholder="nike.com"
                            class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800" />
                    </label>
                    <label class="block">
                        <span class="text-xs font-medium text-slate-600 dark:text-slate-300">{{ __('Scope') }}</span>
                        <select wire:model="scope"
                            class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800">
                            <option value="site">{{ __('Entire site') }}</option>
                            <option value="page">{{ __('Single page') }}</option>
                        </select>
                    </label>
                </div>
            @endif

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block">
                    <span class="text-xs font-medium text-slate-600 dark:text-slate-300">{{ __('Location') }}</span>
                    <input type="text" wire:model="location" list="kif-locations" placeholder="{{ __('Search a country…') }}" autocomplete="off"
                        class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800" />
                    <datalist id="kif-locations">
                        @foreach ($locationNames as $name)
                            <option value="{{ $name }}"></option>
                        @endforeach
                    </datalist>
                    <p class="mt-1 text-[10px] text-slate-400 dark:text-slate-500">{{ __('Any country, region or city. Use “All” for worldwide.') }}</p>
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-slate-600 dark:text-slate-300">{{ __('Language') }}</span>
                    <select wire:model="language"
                        class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800">
                        @foreach ($languageOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" wire:loading.attr="disabled" wire:target="run"
                    class="inline-flex items-center gap-2 rounded-md bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-700 disabled:opacity-60">
                    <span wire:loading.remove wire:target="run">{{ __('Find keywords') }}</span>
                    <span wire:loading wire:target="run">{{ __('Submitting…') }}</span>
                </button>
                <span class="text-[11px] text-slate-400">{{ __('A query typically takes 20–60 seconds.') }}</span>
            </div>
        </form>
    </div>

    {{-- Error --}}
    @if ($errorMessage)
        @if (\App\Support\QuotaMessage::isQuota($errorMessage))
            <x-quota-alert :message="$errorMessage" />
        @else
            <div class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-medium text-rose-800">
                {{ $errorMessage }}
            </div>
        @endif
    @endif

    {{-- In-flight --}}
    @if ($this->isPolling())
        <div class="mt-4 flex items-center gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs font-medium text-amber-800">
            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
            {{ __('Working on it — keyword ideas are being generated') }} ({{ $status }}). {{ __('This page will update automatically.') }}
        </div>
    @endif

    {{-- Results --}}
    @if ($hasRun && ! $this->isPolling() && ! $errorMessage && $results !== [])
        @php
            $compPill = [
                'low' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
                'medium' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400',
                'high' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400',
                'unknown' => 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
            ];
            $intentPill = [
                'informational' => ['I', 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-400', __('Informational')],
                'commercial' => ['C', 'bg-teal-50 text-teal-700 dark:bg-teal-500/10 dark:text-teal-400', __('Commercial')],
                'transactional' => ['T', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400', __('Transactional')],
                'navigational' => ['N', 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400', __('Navigational')],
                'other' => ['—', 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400', __('Unclassified')],
            ];
            $sortable = [
                'keyword' => ['label' => __('Keyword'), 'align' => 'text-left'],
                'volume' => ['label' => __('Avg. searches'), 'align' => 'text-right'],
                'competitionIndex' => ['label' => __('Competition'), 'align' => 'text-left'],
                'cpc' => ['label' => __('Top-of-page bid'), 'align' => 'text-right'],
            ];
        @endphp

        {{-- Toolbar: filters + view mode + export --}}
        <div class="mt-4 flex flex-wrap items-end gap-2 rounded-xl border border-slate-200 bg-white p-3 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <label class="flex flex-col gap-1 text-[11px] text-slate-500 dark:text-slate-400">
                <span class="font-medium">{{ __('Include') }}</span>
                <input type="text" wire:model.live.debounce.400ms="filterText" placeholder="{{ __('contains…') }}"
                    class="w-36 rounded-md border border-slate-300 px-2.5 py-1.5 text-xs focus:border-orange-500 focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800" />
            </label>
            <label class="flex flex-col gap-1 text-[11px] text-slate-500 dark:text-slate-400">
                <span class="font-medium">{{ __('Exclude') }}</span>
                <input type="text" wire:model.live.debounce.400ms="excludeText" placeholder="{{ __('does not contain…') }}"
                    class="w-36 rounded-md border border-slate-300 px-2.5 py-1.5 text-xs focus:border-orange-500 focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800" />
            </label>
            <label class="flex flex-col gap-1 text-[11px] text-slate-500 dark:text-slate-400">
                <span class="font-medium">{{ __('Min volume') }}</span>
                <input type="number" min="0" wire:model.live.debounce.400ms="minVolume" placeholder="0"
                    class="w-20 rounded-md border border-slate-300 px-2.5 py-1.5 text-xs focus:border-orange-500 focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800" />
            </label>
            <label class="flex flex-col gap-1 text-[11px] text-slate-500 dark:text-slate-400">
                <span class="font-medium">{{ __('Max volume') }}</span>
                <input type="number" min="0" wire:model.live.debounce.400ms="maxVolume" placeholder="∞"
                    class="w-20 rounded-md border border-slate-300 px-2.5 py-1.5 text-xs focus:border-orange-500 focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800" />
            </label>
            <label class="flex flex-col gap-1 text-[11px] text-slate-500 dark:text-slate-400">
                <span class="font-medium">{{ __('Competition') }}</span>
                <select wire:model.live="comp"
                    class="rounded-md border border-slate-300 px-2.5 py-1.5 text-xs focus:border-orange-500 focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800">
                    <option value="all">{{ __('All') }}</option>
                    <option value="low">{{ __('Low') }}</option>
                    <option value="medium">{{ __('Medium') }}</option>
                    <option value="high">{{ __('High') }}</option>
                </select>
            </label>
            <label class="flex flex-col gap-1 text-[11px] text-slate-500 dark:text-slate-400">
                <span class="font-medium">{{ __('Intent') }}</span>
                <select wire:model.live="intent"
                    class="rounded-md border border-slate-300 px-2.5 py-1.5 text-xs focus:border-orange-500 focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800">
                    <option value="all">{{ __('All') }}</option>
                    <option value="informational">{{ __('Informational') }}</option>
                    <option value="commercial">{{ __('Commercial') }}</option>
                    <option value="transactional">{{ __('Transactional') }}</option>
                    <option value="navigational">{{ __('Navigational') }}</option>
                    <option value="other">{{ __('Unclassified') }}</option>
                </select>
            </label>
            <label class="flex items-center gap-1.5 pb-1.5 text-[11px] font-medium text-slate-500 dark:text-slate-400">
                <input type="checkbox" wire:model.live="questionsOnly" class="rounded border-slate-300 text-orange-600 focus:ring-orange-500/30 dark:border-slate-700" />
                {{ __('Questions only') }}
            </label>

            <div class="ml-auto flex items-end gap-2">
                <span class="pb-1.5 text-[11px] text-slate-400">{{ number_format($totalResults) }} {{ __('keyword(s)') }} · {{ number_format($totalVolume) }} {{ __('searches/mo') }}</span>
                @if ($clusterMap !== null)
                    <div class="inline-flex rounded-md border border-slate-300 p-0.5 text-[11px] font-semibold dark:border-slate-700">
                        <button type="button" wire:click="setViewMode('list')" @class(['rounded px-2 py-1', 'bg-orange-600 text-white' => $viewMode === 'list', 'text-slate-600 dark:text-slate-300' => $viewMode !== 'list'])>{{ __('List') }}</button>
                        <button type="button" wire:click="setViewMode('clusters')" @class(['rounded px-2 py-1', 'bg-orange-600 text-white' => $viewMode === 'clusters', 'text-slate-600 dark:text-slate-300' => $viewMode !== 'clusters'])>{{ __('Clusters') }}</button>
                    </div>
                    <button type="button" wire:click="clusterWithAi(true)" wire:loading.attr="disabled" wire:target="clusterWithAi(true)"
                        title="{{ __('Not happy with these clusters? Ask the AI to try again.') }}"
                        class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50 disabled:opacity-60 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                        <svg wire:loading.remove wire:target="clusterWithAi(true)" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                        <svg wire:loading wire:target="clusterWithAi(true)" class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        {{ __('Recluster') }}
                    </button>
                @endif
                <button type="button" wire:click="export"
                    class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                    {{ __('Export CSV') }}
                </button>
            </div>
        </div>

        {{-- AI clustering callout — prominent, standalone, not buried in the filter row --}}
        @if ($clusterMap === null)
            <div class="mt-4 flex flex-col items-center justify-between gap-4 rounded-xl border-2 border-orange-200 bg-gradient-to-r from-orange-50 to-white p-4 sm:flex-row dark:border-orange-500/30 dark:from-orange-500/10 dark:to-transparent">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-orange-100 text-orange-600 dark:bg-orange-500/20 dark:text-orange-400">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09zM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456z" /></svg>
                    </span>
                    <div>
                        <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Group these keywords into topic clusters') }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('AI groups your keywords into named topics you can each target with one page.') }}</p>
                    </div>
                </div>
                <button type="button" wire:click="clusterWithAi" wire:loading.attr="disabled" wire:target="clusterWithAi"
                    class="inline-flex w-full flex-none items-center justify-center gap-2 rounded-lg bg-orange-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-orange-700 disabled:opacity-60 sm:w-auto">
                    <svg wire:loading.remove wire:target="clusterWithAi" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09z" /></svg>
                    <svg wire:loading wire:target="clusterWithAi" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    <span wire:loading.remove wire:target="clusterWithAi">{{ __('Cluster with AI') }}</span>
                    <span wire:loading wire:target="clusterWithAi">{{ __('Clustering…') }}</span>
                </button>
            </div>
        @endif

        @if ($clusterError)
            <p class="mt-3 text-xs text-rose-600 dark:text-rose-400">{{ $clusterError }}</p>
        @endif
        @if ($trackNotice)
            <p class="mt-3 text-xs text-emerald-600 dark:text-emerald-400">{{ $trackNotice }}</p>
        @endif

        {{-- Selected-keywords action bar --}}
        @if ($selected !== [])
            <div class="mt-3 flex flex-wrap items-center gap-2 rounded-xl border border-orange-200 bg-orange-50/70 px-3 py-2 text-xs dark:border-orange-500/30 dark:bg-orange-500/10"
                x-data @copy-to-clipboard.window="navigator.clipboard && navigator.clipboard.writeText($event.detail.text)">
                <span class="font-semibold text-orange-800 dark:text-orange-300">{{ count($selected) }} {{ __('selected') }}</span>
                <button type="button" wire:click="trackSelected" class="rounded-md bg-orange-600 px-2.5 py-1 font-semibold text-white hover:bg-orange-700">{{ __('Track all') }}</button>
                <button type="button" wire:click="copySelected" class="rounded-md border border-orange-300 px-2.5 py-1 font-semibold text-orange-700 hover:bg-orange-100 dark:border-orange-500/40 dark:text-orange-300">{{ __('Copy') }}</button>
                <button type="button" wire:click="clearSelected" class="ml-auto text-orange-700 hover:underline dark:text-orange-300">{{ __('Clear') }}</button>
            </div>
        @endif

        {{-- Left rail + results. The rail follows the view: AI "Topics" nav in
             Clusters view, algorithmic "Groups" filter in List view. --}}
        <div class="mt-3 grid gap-3 lg:grid-cols-[230px_minmax(0,1fr)]">
            @if ($viewMode === 'clusters' && $clusterMap !== null)
                {{-- Topics nav (AI clusters — jump to each topic below) --}}
                <div class="self-start overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <p class="border-b border-slate-100 px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:border-slate-800">{{ __('Topics') }}</p>
                    <div class="max-h-[480px] overflow-y-auto p-1.5">
                        @forelse ($clusters as $cluster)
                            @php $isActive = ($activeTopic ?? null) === $cluster['label']; @endphp
                            <button type="button" wire:click="setTopic(@js($cluster['label']))"
                                @class([
                                    'flex w-full items-center justify-between gap-2 rounded-lg px-2.5 py-1.5 text-left text-xs',
                                    'bg-orange-600 font-semibold text-white' => $isActive,
                                    'text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-800' => ! $isActive,
                                ])>
                                <span class="truncate {{ ! $isActive && $cluster['label'] === __('Other') ? 'text-slate-400' : '' }}">{{ $cluster['label'] }}</span>
                                <span @class(['flex-none tabular-nums text-[10px]', 'text-orange-100' => $isActive, 'text-slate-400' => ! $isActive])>{{ count($cluster['rows']) }}</span>
                            </button>
                        @empty
                            <p class="px-2.5 py-2 text-[11px] text-slate-400">{{ __('No topics yet.') }}</p>
                        @endforelse
                    </div>
                </div>
            @else
                {{-- Groups rail (algorithmic term groups — instant filter) --}}
                <div class="self-start overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <p class="border-b border-slate-100 px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:border-slate-800">{{ __('Groups') }}</p>
                    <div class="max-h-[480px] overflow-y-auto p-1.5">
                        <button type="button" wire:click="setGroup('')"
                            @class([
                                'flex w-full items-center justify-between rounded-lg px-2.5 py-1.5 text-left text-xs',
                                'bg-orange-600 font-semibold text-white' => $groupTerm === '',
                                'text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-800' => $groupTerm !== '',
                            ])>
                            <span>{{ __('All keywords') }}</span>
                        </button>
                        @foreach ($termGroups as $g)
                            <button type="button" wire:click="setGroup(@js($g['term']))"
                                @class([
                                    'flex w-full items-center justify-between gap-2 rounded-lg px-2.5 py-1.5 text-left text-xs',
                                    'bg-orange-600 font-semibold text-white' => $groupTerm === $g['term'],
                                    'text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-800' => $groupTerm !== $g['term'],
                                ])>
                                <span class="truncate">{{ $g['term'] }}</span>
                                <span @class(['flex-none tabular-nums text-[10px]', 'text-orange-100' => $groupTerm === $g['term'], 'text-slate-400' => $groupTerm !== $g['term']])>{{ $g['count'] }}</span>
                            </button>
                        @endforeach
                        @if ($termGroups === [])
                            <p class="px-2.5 py-2 text-[11px] text-slate-400">{{ __('No shared terms found.') }}</p>
                        @endif
                    </div>
                </div>
            @endif

            <div class="min-w-0">
                @if ($viewMode === 'clusters')
                    {{-- Clusters view: the selected topic only (Topics nav switches) --}}
                    <div class="space-y-4">
                        @forelse ($visibleClusters as $cluster)
                            @php $isOther = $cluster['label'] === __('Other'); @endphp
                            <div @class([
                                'overflow-hidden rounded-xl border shadow-sm dark:bg-slate-900',
                                'border-slate-200 bg-white dark:border-slate-800' => ! $isOther,
                                'border-dashed border-slate-200 bg-slate-50/40 opacity-75 dark:border-slate-800' => $isOther,
                            ])>
                                <div @class([
                                    'flex flex-wrap items-center justify-between gap-3 border-b-2 px-5 py-4',
                                    'border-orange-200 bg-orange-50/50 dark:border-orange-500/20 dark:bg-orange-500/5' => ! $isOther,
                                    'border-slate-100 bg-slate-50/60 dark:border-slate-800 dark:bg-slate-800/40' => $isOther,
                                ])>
                                    <div class="flex items-center gap-2.5">
                                        @unless ($isOther)
                                            <span class="flex h-8 w-8 flex-none items-center justify-center rounded-lg bg-orange-100 text-orange-600 dark:bg-orange-500/20 dark:text-orange-400">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6.878V6a2.25 2.25 0 0 1 2.25-2.25h7.5A2.25 2.25 0 0 1 18 6v.878m-12 0c.235-.083.487-.128.75-.128h10.5c.263 0 .515.045.75.128m-12 0A2.25 2.25 0 0 0 4.5 9v.878m13.5-3A2.25 2.25 0 0 1 19.5 9v.878m0 0a2.246 2.246 0 0 0-.75-.128H5.25c-.263 0-.515.045-.75.128m15 0A2.25 2.25 0 0 1 21 12v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6c0-.98.626-1.813 1.5-2.122" /></svg>
                                            </span>
                                        @endunless
                                        <p @class(['font-bold leading-tight text-slate-900 dark:text-slate-100', 'text-lg' => ! $isOther, 'text-sm text-slate-500 dark:text-slate-400' => $isOther])>
                                            {{ $cluster['label'] }}
                                            @if ($isOther)
                                                <span class="font-normal text-slate-400" title="{{ __('Keywords that didn’t fit a clear topic with at least one other keyword.') }}">{{ __('(ungrouped)') }}</span>
                                            @endif
                                        </p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="rounded-full bg-white px-2.5 py-1 text-[11px] font-semibold text-slate-600 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700">{{ count($cluster['rows']) }} {{ __('keywords') }}</span>
                                        <span class="rounded-full bg-white px-2.5 py-1 text-[11px] font-semibold text-slate-600 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700">{{ number_format($cluster['volume']) }} {{ __('searches/mo') }}</span>
                                    </div>
                                </div>
                                <table class="min-w-full divide-y divide-slate-100 text-sm dark:divide-slate-800">
                                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                        @foreach ($cluster['rows'] as $row)
                                            @include('livewire.keywords.partials.idea-row', ['row' => $row, 'compPill' => $compPill, 'intentPill' => $intentPill, 'selected' => $selected, 'withCheckbox' => true, 'hasGsc' => $hasGsc, 'gscMetrics' => $gscMetrics])
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @empty
                            <div class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-8 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900">{{ __('No keywords match your filters.') }}</div>
                        @endforelse
                    </div>
                @else
                    {{-- List view --}}
                    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                                <thead class="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-500 dark:bg-slate-800/50">
                                    <tr>
                                        <th class="w-8 px-3 py-2.5">
                                            <input type="checkbox" wire:click="toggleSelectPage" @checked($pageAllSelected)
                                                class="rounded border-slate-300 text-orange-600 focus:ring-orange-500/30 dark:border-slate-600" />
                                        </th>
                                        @foreach ($sortable as $field => $meta)
                                            <th class="px-4 py-2.5 font-semibold {{ $meta['align'] }}">
                                                <button type="button" wire:click="sortBy('{{ $field }}')" class="inline-flex items-center gap-1 hover:text-slate-800 dark:hover:text-slate-200">
                                                    <span>{{ $meta['label'] }}</span>
                                                    @if ($sortField === $field)
                                                        <span class="text-orange-500">{{ $sortDir === 'asc' ? '▲' : '▼' }}</span>
                                                    @else
                                                        <span class="text-slate-300 dark:text-slate-600">↕</span>
                                                    @endif
                                                </button>
                                            </th>
                                        @endforeach
                                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Intent') }}</th>
                                        <th class="px-4 py-2.5 text-right font-semibold">{{ __('Your GSC (28d)') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    @forelse ($rows as $row)
                                        @include('livewire.keywords.partials.idea-row', ['row' => $row, 'compPill' => $compPill, 'intentPill' => $intentPill, 'selected' => $selected, 'withCheckbox' => true, 'hasGsc' => $hasGsc, 'gscMetrics' => $gscMetrics])
                                    @empty
                                        <tr><td colspan="7" class="px-4 py-8 text-center text-sm text-slate-500">{{ __('No keywords match your filters.') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        {{-- Pagination --}}
                        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-4 py-2 text-[11px] text-slate-500 dark:border-slate-800 dark:text-slate-400">
                            <div class="flex items-center gap-2">
                                <span>{{ __('Rows') }}</span>
                                <select wire:model.live="perPage" class="rounded border border-slate-300 px-1.5 py-0.5 text-[11px] dark:border-slate-700 dark:bg-slate-800">
                                    <option value="25">25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                </select>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <button type="button" wire:click="setPage({{ $page - 1 }})" @disabled($page <= 1)
                                    class="rounded border border-slate-300 px-2 py-0.5 font-semibold disabled:opacity-40 dark:border-slate-700">{{ __('Prev') }}</button>
                                <span>{{ __('Page') }} {{ $page }} {{ __('of') }} {{ $totalPages }}</span>
                                <button type="button" wire:click="setPage({{ $page + 1 }})" @disabled($page >= $totalPages)
                                    class="rounded border border-slate-300 px-2 py-0.5 font-semibold disabled:opacity-40 dark:border-slate-700">{{ __('Next') }}</button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @elseif ($hasRun && ! $this->isPolling() && ! $errorMessage)
        <div class="mt-4 rounded-md border border-dashed border-slate-300 bg-white px-4 py-8 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900">
            {{ __('No keyword ideas were returned. Try different seeds or a different URL.') }}
        </div>
    @endif
</div>
