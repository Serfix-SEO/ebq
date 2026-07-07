<div class="space-y-5">
    @if (session('rank_tracking_status'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)"
            class="flex items-center justify-between gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-xs font-medium text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
            <div class="flex items-center gap-2">
                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                {{ session('rank_tracking_status') }}
            </div>
            <button @click="show = false" class="text-emerald-700/70 hover:text-emerald-700 dark:text-emerald-400/70">×</button>
        </div>
    @endif

    {{-- Stats --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <div class="rounded-xl border border-slate-200 bg-white p-3.5 dark:border-slate-800 dark:bg-slate-900">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ __('Tracked') }}</div>
            <div class="mt-1 text-xl font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ $stats['total'] }}</div>
            <div class="mt-0.5 text-[10px] text-slate-400">{{ __(':count active', ['count' => $stats['active']]) }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3.5 dark:border-slate-800 dark:bg-slate-900">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ __('Avg position') }}</div>
            <div class="mt-1 text-xl font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ $stats['avg'] !== null ? '#'.$stats['avg'] : '—' }}</div>
            <div class="mt-0.5 text-[10px] text-slate-400">{{ __('across ranked keywords') }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3.5 dark:border-slate-800 dark:bg-slate-900">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ __('Top 3') }}</div>
            <div class="mt-1 text-xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $stats['top3'] }}</div>
            <div class="mt-0.5 text-[10px] text-slate-400">{{ __('positions 1–3') }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3.5 dark:border-slate-800 dark:bg-slate-900">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ __('Top 10') }}</div>
            <div class="mt-1 text-xl font-bold tabular-nums text-blue-600 dark:text-blue-400">{{ $stats['top10'] }}</div>
            <div class="mt-0.5 text-[10px] text-slate-400">{{ __('first page') }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3.5 dark:border-slate-800 dark:bg-slate-900">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ __('Ranked') }}</div>
            <div class="mt-1 text-xl font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ $stats['top100'] }}</div>
            <div class="mt-0.5 text-[10px] text-slate-400">{{ __('found in SERP') }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3.5 dark:border-slate-800 dark:bg-slate-900">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ __('Unranked') }}</div>
            <div class="mt-1 text-xl font-bold tabular-nums text-slate-500">{{ $stats['unranked'] }}</div>
            <div class="mt-0.5 text-[10px] text-slate-400">{{ __('outside tracked depth') }}</div>
        </div>
    </div>

    {{-- Toolbar --}}
    <div class="rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-1 flex-wrap items-center gap-2">
                <div class="relative w-full sm:w-64">
                    <svg class="pointer-events-none absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
                    <input wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('Search keywords…') }}"
                        class="h-8 w-full rounded-md border border-slate-200 bg-white ps-8 pe-2.5 text-xs placeholder-slate-400 shadow-sm transition focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800 dark:placeholder-slate-500" />
                </div>
                <select wire:model.live="filterStatus" class="h-8 rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm dark:border-slate-700 dark:bg-slate-800">
                    <option value="">{{ __('All status') }}</option>
                    <option value="top3">{{ __('Top 3') }}</option>
                    <option value="top10">{{ __('Top 10') }}</option>
                    <option value="top100">{{ __('Ranked') }}</option>
                    <option value="unranked">{{ __('Unranked') }}</option>
                    <option value="active">{{ __('Active') }}</option>
                    <option value="paused">{{ __('Paused') }}</option>
                    <option value="failed">{{ __('Failed') }}</option>
                </select>
                <select wire:model.live="filterDevice" class="h-8 rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm dark:border-slate-700 dark:bg-slate-800">
                    <option value="">{{ __('All devices') }}</option>
                    <option value="desktop">{{ __('Desktop') }}</option>
                    <option value="mobile">{{ __('Mobile') }}</option>
                </select>
                <select wire:model.live="filterCountry" class="h-8 rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm dark:border-slate-700 dark:bg-slate-800">
                    <option value="">{{ __('All countries') }}</option>
                    @foreach ($countries as $code => $name)
                        <option value="{{ $code }}">{{ strtoupper($code) }} — {{ $name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="filterType" class="h-8 rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm dark:border-slate-700 dark:bg-slate-800">
                    <option value="">{{ __('All types') }}</option>
                    <option value="organic">{{ __('Organic') }}</option>
                    <option value="news">{{ __('News') }}</option>
                    <option value="images">{{ __('Images') }}</option>
                    <option value="videos">{{ __('Videos') }}</option>
                    <option value="shopping">{{ __('Shopping') }}</option>
                    <option value="maps">{{ __('Maps') }}</option>
                    <option value="scholar">{{ __('Scholar') }}</option>
                </select>
                @if ($search || $filterDevice || $filterCountry || $filterType || $filterStatus)
                    <button wire:click="clearFilters" class="h-8 rounded-md px-2.5 text-xs text-slate-500 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-slate-200">
                        {{ __('Clear') }}
                    </button>
                @endif
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <button wire:click="toggleBulkAdd" type="button"
                    class="inline-flex h-8 items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700"
                    title="{{ __('Pull keywords your site already ranks for from Google Search Console') }}">
                    <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9h16.5m-16.5 6.75h16.5"/></svg>
                    {{ $showBulkAdd ? __('Close') : __('Bulk add from GSC') }}
                </button>
                <button wire:click="toggleForm" type="button"
                    class="inline-flex h-8 items-center gap-1.5 rounded-md bg-orange-600 px-3 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-700">
                    <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    {{ $showForm ? __('Close form') : __('Add keyword') }}
                </button>
            </div>
        </div>
    </div>

    {{-- Bulk add panel --}}
    @if ($showBulkAdd)
        <div class="rounded-xl border border-orange-200 bg-orange-50/40 p-4 shadow-sm dark:border-orange-900/40 dark:bg-orange-500/5">
            <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Bulk add from Search Console') }}</h3>
                    <p class="mt-0.5 text-[11px] leading-relaxed text-slate-600 dark:text-slate-400">
                        {{ __('Pull your historically-performing organic queries (filtered by country and ranking) and start tracking them with one click. Already-tracked keywords are skipped.') }}
                    </p>
                </div>
                @if ($bulkStatus)
                    <span class="rounded-md bg-emerald-100 px-2 py-1 text-[11px] font-medium text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300">{{ $bulkStatus }}</span>
                @endif
            </div>

            {{-- Filters --}}
            <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <div>
                    <label class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">{{ __('Country') }}</label>
                    <select wire:model.live="bulkCountry" class="h-8 w-full rounded-md border border-slate-200 bg-white px-2 text-xs shadow-sm dark:border-slate-700 dark:bg-slate-800">
                        @foreach (\App\Models\SearchConsoleData::query()->where('website_id', $websiteId)->where('country', '!=', '')->selectRaw('country, SUM(clicks) as clicks')->groupBy('country')->orderByDesc('clicks')->limit(50)->pluck('country') as $cc)
                            <option value="{{ $cc }}">{{ \App\Support\Countries::flag((string) $cc) }} {{ \App\Support\Countries::name((string) $cc) }} ({{ $cc }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">{{ __('Language') }}</label>
                    <select wire:model.live="bulkLanguage" class="h-8 w-full rounded-md border border-slate-200 bg-white px-2 text-xs shadow-sm dark:border-slate-700 dark:bg-slate-800">
                        @foreach ($languages as $code => $label)
                            <option value="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">{{ __('Device') }}</label>
                    <select wire:model.live="bulkDevice" class="h-8 w-full rounded-md border border-slate-200 bg-white px-2 text-xs shadow-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="desktop">{{ __('Desktop') }}</option>
                        <option value="mobile">{{ __('Mobile') }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">{{ __('Lookback (days)') }}</label>
                    <input type="number" min="7" max="365" wire:model.live.debounce.500ms="bulkLookbackDays" class="h-8 w-full rounded-md border border-slate-200 bg-white px-2 text-xs tabular-nums shadow-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">{{ __('Min impressions') }}</label>
                    <input type="number" min="1" wire:model.live.debounce.500ms="bulkMinImpressions" class="h-8 w-full rounded-md border border-slate-200 bg-white px-2 text-xs tabular-nums shadow-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">{{ __('Max position') }}</label>
                    <input type="number" min="1" max="100" wire:model.live.debounce.500ms="bulkMaxPosition" class="h-8 w-full rounded-md border border-slate-200 bg-white px-2 text-xs tabular-nums shadow-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
            </div>

            {{-- Candidates --}}
            @php $_candidates = $this->bulkCandidates(); @endphp
            <div class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
                @if (empty($_candidates))
                    <div class="px-4 py-6 text-center text-xs text-slate-500 dark:text-slate-400">
                        {{ __('No candidates match the current filters. Loosen the impressions / position thresholds, or pick a different country.') }}
                    </div>
                @else
                    <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-4 py-2 text-[11px] dark:border-slate-700 dark:bg-slate-800/60">
                        <span class="font-semibold text-slate-700 dark:text-slate-300">
                            {{ count($_candidates) === 1 ? __(':count candidate', ['count' => count($_candidates)]) : __(':count candidates', ['count' => count($_candidates)]) }}
                            <span class="ms-1 font-normal text-slate-400">{{ __('· not yet tracked') }}</span>
                        </span>
                        <div class="flex items-center gap-3">
                            <button wire:click="bulkSelectAll(true)" type="button" class="text-orange-600 hover:underline dark:text-orange-400">{{ __('Select all') }}</button>
                            <button wire:click="bulkSelectAll(false)" type="button" class="text-slate-500 hover:underline dark:text-slate-400">{{ __('Clear') }}</button>
                        </div>
                    </div>
                    <div class="max-h-80 overflow-y-auto">
                        <table class="w-full text-xs">
                            <thead class="sticky top-0 border-b border-slate-200 bg-slate-50 text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:border-slate-700 dark:bg-slate-800/80 dark:text-slate-400">
                                <tr>
                                    <th class="w-8 px-3 py-2"></th>
                                    <th class="px-3 py-2 text-start">{{ __('Keyword') }}</th>
                                    <th class="px-3 py-2 text-end">{{ __('Impr.') }}</th>
                                    <th class="px-3 py-2 text-end">{{ __('Clicks') }}</th>
                                    <th class="px-3 py-2 text-end">{{ __('CTR') }}</th>
                                    <th class="px-3 py-2 text-end">{{ __('Avg pos') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                @foreach ($_candidates as $cand)
                                    <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                        <td class="px-3 py-2">
                                            <input type="checkbox" wire:model.live="bulkSelected" value="{{ $cand['query'] }}" class="h-3.5 w-3.5 rounded border-slate-300 text-orange-600 focus:ring-orange-500 dark:border-slate-600 dark:bg-slate-700" />
                                        </td>
                                        <td class="px-3 py-2 font-medium text-slate-800 dark:text-slate-100">{{ $cand['query'] }}</td>
                                        <td class="px-3 py-2 text-end tabular-nums text-slate-600 dark:text-slate-300">{{ number_format($cand['impressions']) }}</td>
                                        <td class="px-3 py-2 text-end tabular-nums text-slate-600 dark:text-slate-300">{{ number_format($cand['clicks']) }}</td>
                                        <td class="px-3 py-2 text-end tabular-nums text-slate-500 dark:text-slate-400">{{ $cand['ctr'] }}%</td>
                                        <td class="px-3 py-2 text-end">
                                            <span @class([
                                                'inline-flex rounded-full px-1.5 py-px text-[10px] font-semibold tabular-nums',
                                                'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => $cand['position'] <= 3,
                                                'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-400' => $cand['position'] > 3 && $cand['position'] <= 10,
                                                'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400' => $cand['position'] > 10 && $cand['position'] <= 20,
                                                'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300' => $cand['position'] > 20,
                                            ])>{{ $cand['position'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <p class="text-[11px] text-slate-500 dark:text-slate-400">
                    {{ __(':count selected · queued for an immediate first SERP check on add', ['count' => count($bulkSelected)]) }}
                </p>
                <div class="flex items-center gap-2">
                    <button wire:click="toggleBulkAdd" type="button" class="h-8 rounded-md border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">{{ __('Cancel') }}</button>
                    <button wire:click="bulkAddSelected" wire:loading.attr="disabled" type="button"
                        @disabled(count($bulkSelected) === 0)
                        class="inline-flex h-8 items-center gap-1.5 rounded-md bg-orange-600 px-3 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-700 disabled:cursor-not-allowed disabled:opacity-50">
                        <span wire:loading.remove wire:target="bulkAddSelected">{{ count($bulkSelected) === 1 ? __('Add :count keyword', ['count' => count($bulkSelected)]) : __('Add :count keywords', ['count' => count($bulkSelected)]) }}</span>
                        <span wire:loading wire:target="bulkAddSelected">{{ __('Adding…') }}</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Add form --}}
    @if ($showForm)
        <form wire:submit.prevent="addKeyword"
            class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="mb-4">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ __('Track a new keyword') }}</h3>
            </div>

            <div class="space-y-5">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">{{ __('Keyword') }} <span class="text-red-500">*</span></label>
                        <input wire:model="newKeyword" type="text" placeholder="{{ __('e.g. best seo tools') }}"
                            class="h-9 w-full rounded-md border border-slate-200 bg-white px-3 text-xs shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800" />
                        @error('newKeyword')<p class="mt-1 text-[11px] text-red-500">{{ $message }}</p>@enderror
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">{{ __('Target URL (optional)') }}</label>
                        <div class="flex h-9 overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm focus-within:border-orange-500 focus-within:ring-2 focus-within:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800">
                            <span class="flex max-w-[55%] shrink-0 items-center border-e border-slate-200 bg-slate-50 px-2 text-[10px] text-slate-500 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-400">{{ $targetUrlPrefix }}</span>
                            <input wire:model="newTargetUrlPath" type="text" placeholder="{{ __('/page-path') }}"
                                class="min-w-0 flex-1 border-0 bg-transparent px-2 text-xs focus:outline-none focus:ring-0 dark:text-slate-100" />
                        </div>
                        @error('newTargetUrlPath')<p class="mt-1 text-[11px] text-red-500">{{ $message }}</p>@enderror
                        <p class="mt-1 text-[10px] text-slate-400">{{ __('Path on your connected domain. Leave blank to match any URL.') }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">{{ __('Search type') }}</label>
                            <select wire:model="newSearchType" class="h-9 w-full rounded-md border border-slate-200 bg-white px-3 text-xs shadow-sm dark:border-slate-700 dark:bg-slate-800">
                                <option value="organic">{{ __('Organic Search') }}</option>
                                <option value="news">{{ __('News') }}</option>
                                <option value="images">{{ __('Images') }}</option>
                                <option value="videos">{{ __('Videos') }}</option>
                                <option value="shopping">{{ __('Shopping') }}</option>
                                <option value="maps">{{ __('Maps / Places') }}</option>
                                <option value="scholar">{{ __('Scholar') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">{{ __('Country') }}</label>
                            <select wire:model="newCountry" class="h-9 w-full rounded-md border border-slate-200 bg-white px-3 text-xs shadow-sm dark:border-slate-700 dark:bg-slate-800">
                                @foreach ($countries as $code => $name)
                                    <option value="{{ $code }}">{{ $name }} ({{ strtoupper($code) }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">{{ __('Language') }}</label>
                            <select wire:model="newLanguage" class="h-9 w-full rounded-md border border-slate-200 bg-white px-3 text-xs shadow-sm dark:border-slate-700 dark:bg-slate-800">
                                @foreach ($languages as $code => $name)
                                    <option value="{{ $code }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">{{ __('Device') }}</label>
                            <select wire:model="newDevice" class="h-9 w-full rounded-md border border-slate-200 bg-white px-3 text-xs shadow-sm dark:border-slate-700 dark:bg-slate-800">
                                <option value="desktop">{{ __('Desktop') }}</option>
                                <option value="mobile">{{ __('Mobile') }}</option>
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">{{ __('Competitor domains') }}</label>
                            <input wire:model="newCompetitors" type="text" placeholder="{{ __('competitor1.com, competitor2.com') }}"
                                class="h-9 w-full rounded-md border border-slate-200 bg-white px-3 text-xs shadow-sm dark:border-slate-700 dark:bg-slate-800" />
                        </div>
                    </div>

                <div class="flex flex-wrap items-center gap-5">
                        <label class="flex cursor-pointer items-center gap-2 text-xs text-slate-600 dark:text-slate-400">
                            <input wire:model="newAutocorrect" type="checkbox" class="rounded border-slate-300 text-orange-600 focus:ring-orange-500" /> {{ __('Autocorrect queries') }}
                        </label>
                        <label class="flex cursor-pointer items-center gap-2 text-xs text-slate-600 dark:text-slate-400">
                            <input wire:model="newSafeSearch" type="checkbox" class="rounded border-slate-300 text-orange-600 focus:ring-orange-500" /> {{ __('Safe search') }}
                        </label>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">{{ __('Notes') }}</label>
                    <textarea wire:model="newNotes" rows="2"
                        class="w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-xs shadow-sm dark:border-slate-700 dark:bg-slate-800"></textarea>
                </div>

                <p class="text-[10px] text-slate-400">{{ __('Rankings are checked every :hours hours (top :depth SERP results).', ['hours' => $defaultCheckIntervalHours, 'depth' => \App\Support\RankTrackerConfig::DEFAULT_DEPTH]) }}</p>
            </div>

            <div class="mt-5 flex items-center justify-end gap-2">
                <button type="button" wire:click="toggleForm"
                    class="h-8 rounded-md border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">{{ __('Cancel') }}</button>
                <button type="submit"
                    wire:loading.attr="disabled"
                    wire:target="addKeyword"
                    class="inline-flex h-8 items-center gap-2 rounded-md bg-orange-600 px-4 text-xs font-semibold text-white shadow-sm hover:bg-orange-700 disabled:opacity-60">
                    <svg wire:loading wire:target="addKeyword" class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"></circle><path fill="currentColor" class="opacity-75" d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4zm2 5.3A7.96 7.96 0 014 12H0c0 3.04 1.1 5.8 3 7.9l3-2.6z"></path></svg>
                    {{ __('Add & run first check') }}
                </button>
            </div>
        </form>
    @endif

    {{-- List --}}
    <div wire:loading.class="opacity-60" wire:target="search,filterDevice,filterCountry,filterType,filterStatus,sort">
        @if ($rows instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator && $rows->isNotEmpty())
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50 text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-400">
                                <th class="px-4 py-3 text-start">
                                    <button wire:click="sort('keyword')" class="inline-flex items-center gap-1 hover:text-slate-700 dark:hover:text-slate-200">
                                        {{ __('Keyword') }}
                                        @if ($sortBy === 'keyword')<span>{{ $sortDir === 'asc' ? '↑' : '↓' }}</span>@endif
                                    </button>
                                </th>
                                <th class="px-4 py-3 text-start">{{ __('Target') }}</th>
                                <th class="px-4 py-3 text-start">{{ __('Market') }}</th>
                                <th class="px-4 py-3 text-end">
                                    <button wire:click="sort('current_position')" class="inline-flex items-center gap-1 hover:text-slate-700 dark:hover:text-slate-200">
                                        {{ __('Rank') }}
                                        @if ($sortBy === 'current_position')<span>{{ $sortDir === 'asc' ? '↑' : '↓' }}</span>@endif
                                    </button>
                                </th>
                                <th class="px-4 py-3 text-end">
                                    <button wire:click="sort('position_change')" class="inline-flex items-center gap-1 hover:text-slate-700 dark:hover:text-slate-200">
                                        {{ __('Δ') }}
                                        @if ($sortBy === 'position_change')<span>{{ $sortDir === 'asc' ? '↑' : '↓' }}</span>@endif
                                    </button>
                                </th>
                                <th class="px-4 py-3 text-end">
                                    <button wire:click="sort('best_position')" class="inline-flex items-center gap-1 hover:text-slate-700 dark:hover:text-slate-200">
                                        {{ __('Best') }}
                                        @if ($sortBy === 'best_position')<span>{{ $sortDir === 'asc' ? '↑' : '↓' }}</span>@endif
                                    </button>
                                </th>
                                <th class="px-4 py-3 text-start">{{ __('GSC (30d)') }}</th>
                                <th class="px-4 py-3 text-start" title="{{ __('Global monthly search volume · CPC · competition') }}">{{ __('Volume') }}</th>
                                <th class="px-4 py-3 text-end" title="{{ __('Projected monthly organic value at current position (volume × CTR × CPC)') }}">{{ __('Value/mo') }}</th>
                                <th class="px-4 py-3 text-start">
                                    <button wire:click="sort('last_checked_at')" class="inline-flex items-center gap-1 hover:text-slate-700 dark:hover:text-slate-200">
                                        {{ __('Last check') }}
                                        @if ($sortBy === 'last_checked_at')<span>{{ $sortDir === 'asc' ? '↑' : '↓' }}</span>@endif
                                    </button>
                                </th>
                                <th class="px-4 py-3 text-end">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach ($rows as $kw)
                                <tr wire:key="rtk-{{ $kw->id }}" class="group transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                    <td class="px-4 py-3">
                                        <a href="{{ route('rank-tracking.show', $kw->id) }}" wire:navigate
                                            class="block font-semibold text-slate-900 hover:text-orange-600 dark:text-slate-100 dark:hover:text-orange-400">
                                            {{ $kw->keyword }}<x-keyword-language :language="($detectedLanguages ?? [])[mb_strtolower(trim((string) $kw->keyword))] ?? null" />
                                        </a>
                                        <div class="mt-0.5 flex flex-wrap items-center gap-1.5">
                                            <span class="rounded bg-slate-100 px-1.5 py-px text-[9px] font-semibold uppercase tracking-wide text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $kw->search_type }}</span>
                                            @if (! $kw->is_active)<span class="rounded bg-slate-200 px-1.5 py-px text-[9px] font-semibold uppercase text-slate-600 dark:bg-slate-700 dark:text-slate-300">{{ __('Paused') }}</span>@endif
                                            @if ($kw->last_status === 'failed')<span class="rounded bg-red-100 px-1.5 py-px text-[9px] font-semibold uppercase text-red-700 dark:bg-red-500/10 dark:text-red-400">{{ __('Failed') }}</span>@endif
                                            @php $risk = $serpRisk[$kw->id] ?? null; @endphp
                                            @if ($risk && $risk['at_risk'])
                                                <span class="rounded bg-amber-100 px-1.5 py-px text-[9px] font-semibold uppercase text-amber-700 dark:bg-amber-500/15 dark:text-amber-400" title="{{ __('SERP has :features and we don\'t own the top result', ['features' => implode(', ', $risk['features_present'])]) }}">{{ __('SERP risk') }}</span>
                                            @endif
                                            @if ($risk && $risk['lost_feature'])
                                                <span class="rounded bg-red-100 px-1.5 py-px text-[9px] font-semibold uppercase text-red-700 dark:bg-red-500/10 dark:text-red-400" title="{{ __('Lost SERP feature: :features', ['features' => implode(', ', $risk['features_lost'])]) }}">{{ __('lost feature') }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-slate-700 dark:text-slate-300">
                                        <div class="font-medium">{{ $kw->target_domain }}</div>
                                        @if ($kw->current_url)
                                            <a href="{{ $kw->current_url }}" target="_blank" rel="noopener" class="block max-w-[240px] truncate text-[10px] text-emerald-700 hover:underline dark:text-emerald-400">{{ $kw->current_url }}</a>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-slate-600 dark:text-slate-400">
                                        <div class="flex items-center gap-1.5">
                                            <span class="rounded bg-slate-100 px-1.5 py-px text-[10px] font-semibold uppercase dark:bg-slate-800">{{ $kw->country }}</span>
                                            <span class="text-[10px]">{{ $kw->language }}</span>
                                            <span class="text-[10px]">·</span>
                                            <span class="text-[10px] capitalize">{{ $kw->device }}</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-end">
                                        @if ($kw->current_position)
                                            <span @class([
                                                'inline-flex min-w-[44px] justify-center rounded-full px-2 py-0.5 text-[11px] font-bold tabular-nums',
                                                'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => $kw->current_position <= 3,
                                                'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-400' => $kw->current_position > 3 && $kw->current_position <= 10,
                                                'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400' => $kw->current_position > 10 && $kw->current_position <= 20,
                                                'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300' => $kw->current_position > 20,
                                            ])>#{{ $kw->current_position }}</span>
                                        @else
                                            <span class="text-slate-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-end tabular-nums">
                                        @if ($kw->position_change > 0)
                                            <span class="inline-flex items-center gap-0.5 text-emerald-600 dark:text-emerald-400">▲{{ $kw->position_change }}</span>
                                        @elseif ($kw->position_change < 0)
                                            <span class="inline-flex items-center gap-0.5 text-red-600 dark:text-red-400">▼{{ abs($kw->position_change) }}</span>
                                        @else
                                            <span class="text-slate-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-end tabular-nums text-slate-700 dark:text-slate-300">{{ $kw->best_position ? '#'.$kw->best_position : '—' }}</td>
                                    <td class="px-4 py-3">
                                        @php $gsc = ($gscByKeyword ?? [])[mb_strtolower(trim($kw->keyword))] ?? null; @endphp
                                        @if ($gsc)
                                            <div class="text-[11px] text-slate-600 dark:text-slate-400">
                                                <div class="flex items-center gap-1.5">
                                                    <span class="inline-flex items-center gap-1 rounded bg-emerald-50 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wider text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400" title="{{ __('Matched against Google Search Console') }}">{{ __('GSC') }}</span>
                                                    <span class="tabular-nums font-semibold text-slate-800 dark:text-slate-200">{{ number_format($gsc['clicks']) }}</span>
                                                    <span class="text-[10px] text-slate-400">{{ __('clicks') }}</span>
                                                </div>
                                                <div class="mt-0.5 text-[10px] text-slate-400">{{ __('Avg #:position · :impressions impr · 30d', ['position' => $gsc['position'] ?? '—', 'impressions' => number_format($gsc['impressions'])]) }}</div>
                                            </div>
                                        @else
                                            <span class="text-[10px] text-slate-400">{{ __('No GSC match') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @php $ke = ($keMetrics ?? [])[\App\Models\KeywordMetric::hashKeyword((string) $kw->keyword)] ?? null; @endphp
                                        @if ($ke && $ke->search_volume !== null)
                                            <div class="text-[11px]" title="{{ __('Updated :time', ['time' => $ke->fetched_at->diffForHumans()]) }}">
                                                <div class="flex items-baseline gap-1.5">
                                                    <span class="tabular-nums font-semibold text-slate-800 dark:text-slate-200">{{ number_format($ke->search_volume) }}</span>
                                                    <span class="text-[10px] text-slate-400">{{ __('/mo') }}</span>
                                                    @php
                                                        $_trend = $ke->trend_class;
                                                        $_seasonalTitle = __('Seasonal pattern');
                                                        if ($_trend === 'seasonal' && $ke->next_peak_month !== null) {
                                                            $_seasonalTitle = __('Seasonal pattern — peaks in :month', ['month' => \Carbon\Carbon::create(null, $ke->next_peak_month, 1)->format('F')]);
                                                        }
                                                    @endphp
                                                    @if ($_trend === 'rising')
                                                        <span class="rounded bg-emerald-100 px-1 py-px text-[9px] font-bold text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400" title="{{ __('Search volume rising over last 6 months') }}">↑</span>
                                                    @elseif ($_trend === 'falling')
                                                        <span class="rounded bg-rose-100 px-1 py-px text-[9px] font-bold text-rose-700 dark:bg-rose-500/15 dark:text-rose-400" title="{{ __('Search volume falling over last 6 months') }}">↓</span>
                                                    @elseif ($_trend === 'seasonal')
                                                        <span class="rounded bg-amber-100 px-1 py-px text-[9px] font-bold text-amber-700 dark:bg-amber-500/15 dark:text-amber-400" title="{{ $_seasonalTitle }}">◐</span>
                                                    @endif
                                                </div>
                                                <div class="mt-0.5 flex flex-wrap items-center gap-x-2 text-[10px] text-slate-400">
                                                    @if ($ke->cpc !== null)
                                                        <span>{{ __('CPC :currency :amount', ['currency' => $ke->currency ?: 'USD', 'amount' => number_format((float) $ke->cpc, 2)]) }}</span>
                                                    @endif
                                                    @if ($ke->competition !== null)
                                                        <span>·</span>
                                                        <span>{{ __('Comp :percent%', ['percent' => number_format((float) $ke->competition * 100, 0)]) }}</span>
                                                    @endif
                                                </div>
                                            </div>
                                        @else
                                            <span class="text-[10px] text-slate-400" title="{{ __('Will populate on the next background fetch') }}">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-end tabular-nums">
                                        @php
                                            $_value = $ke ? \App\Services\KeywordValueCalculator::projectedMonthlyValue(
                                                $ke->search_volume,
                                                $kw->current_position !== null ? (float) $kw->current_position : null,
                                                $ke->cpc
                                            ) : null;
                                        @endphp
                                        @if ($_value !== null && $_value > 0)
                                            <span class="text-xs font-semibold text-slate-900 dark:text-slate-100" title="{{ __('Volume :volume × CTR at pos #:position × :currency :cpc', ['volume' => number_format($ke->search_volume), 'position' => $kw->current_position, 'currency' => $ke->currency ?: 'USD', 'cpc' => number_format((float) $ke->cpc, 2)]) }}">
                                                ${{ number_format($_value, 0) }}
                                            </span>
                                        @else
                                            <span class="text-[10px] text-slate-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-slate-600 dark:text-slate-400">
                                        @if ($kw->last_checked_at)
                                            <div>{{ $kw->last_checked_at->diffForHumans() }}</div>
                                            <div class="text-[10px] text-slate-400">{{ __('Next: :time', ['time' => $kw->next_check_at ? $kw->next_check_at->diffForHumans() : '—']) }}</div>
                                        @else
                                            <span class="text-amber-600 dark:text-amber-400">{{ __('Pending first check') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-end">
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('rank-tracking.show', $kw->id) }}" wire:navigate
                                                class="inline-flex items-center gap-1 rounded-md bg-orange-600 px-2.5 py-1 text-[10px] font-semibold text-white shadow-sm hover:bg-orange-700"
                                                title="{{ __('View detail') }}">
                                                {{ __('View') }}
                                                <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                                            </a>
                                            <button wire:click="recheck('{{ $kw->id }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="recheck('{{ $kw->id }}')"
                                                class="rounded-md border border-slate-200 bg-white p-1.5 text-slate-500 hover:bg-slate-50 hover:text-orange-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:text-orange-400"
                                                title="{{ __('Force re-check') }}">
                                                <svg wire:loading.remove wire:target="recheck('{{ $kw->id }}')" class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                                                <svg wire:loading wire:target="recheck('{{ $kw->id }}')" class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"></circle><path fill="currentColor" class="opacity-75" d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z"></path></svg>
                                            </button>
                                            <button wire:click="togglePause('{{ $kw->id }}')" title="{{ $kw->is_active ? __('Pause') : __('Resume') }}"
                                                class="rounded-md border border-slate-200 bg-white p-1.5 text-slate-500 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                                @if ($kw->is_active)
                                                    <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25v13.5m-7.5-13.5v13.5" /></svg>
                                                @else
                                                    <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347c-.75.412-1.667-.13-1.667-.986z" /></svg>
                                                @endif
                                            </button>
                                            <button wire:click="delete('{{ $kw->id }}')" wire:confirm="{{ __('Delete this tracked keyword and its history?') }}"
                                                class="rounded-md border border-slate-200 bg-white p-1.5 text-slate-500 hover:bg-red-50 hover:text-red-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-red-500/10 dark:hover:text-red-400"
                                                title="{{ __('Delete') }}">
                                                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="mt-3">{{ $rows->links() }}</div>
        @else
            <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-300 bg-white px-6 py-20 dark:border-slate-700 dark:bg-slate-900">
                <div class="rounded-full bg-orange-50 p-3 dark:bg-orange-500/10">
                    <svg class="h-8 w-8 text-orange-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
                </div>
                <p class="mt-3 text-sm font-semibold text-slate-700 dark:text-slate-200">
                    @if ($search || $filterDevice || $filterCountry || $filterType || $filterStatus)
                        {{ __('No keywords match your filters') }}
                    @else
                        {{ __('No keywords being tracked yet') }}
                    @endif
                </p>
                <p class="mt-1 max-w-sm text-center text-xs text-slate-500 dark:text-slate-400">
                    @if ($search || $filterDevice || $filterCountry || $filterType || $filterStatus)
                        {{ __('Try adjusting your filters or clear them to see everything.') }}
                    @else
                        {{ __('Add your first keyword to start monitoring its SERP position. Rankings are checked every :hours hours; you can force a re-check anytime.', ['hours' => $defaultCheckIntervalHours]) }}
                    @endif
                </p>
                <div class="mt-4 flex gap-2">
                    @if ($search || $filterDevice || $filterCountry || $filterType || $filterStatus)
                        <button wire:click="clearFilters" class="h-8 rounded-md border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">{{ __('Clear filters') }}</button>
                    @endif
                    <button wire:click="toggleForm" class="inline-flex h-8 items-center gap-1.5 rounded-md bg-orange-600 px-3 text-xs font-semibold text-white shadow-sm hover:bg-orange-700">
                        <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        {{ __('Add your first keyword') }}
                    </button>
                </div>
            </div>
        @endif
    </div>
</div>
