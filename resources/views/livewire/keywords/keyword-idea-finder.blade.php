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
                ])>From seed keywords</button>
            <button type="button" wire:click="$set('mode', 'website')"
                @class([
                    'rounded-md px-3 py-1.5 transition',
                    'bg-orange-600 text-white' => $mode === 'website',
                    'text-slate-600 dark:text-slate-300' => $mode !== 'website',
                ])>From a website</button>
        </div>

        <form wire:submit.prevent="run" class="space-y-4">
            @if ($mode === 'seeds')
                <label class="block">
                    <span class="text-xs font-medium text-slate-600 dark:text-slate-300">Seed keywords (one per line or comma-separated)</span>
                    <textarea wire:model="seedsInput" rows="4" placeholder="running shoes&#10;trail running"
                        class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800"></textarea>
                </label>
            @else
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="block sm:col-span-2">
                        <span class="text-xs font-medium text-slate-600 dark:text-slate-300">Website or page URL</span>
                        <input type="text" wire:model="url" placeholder="nike.com"
                            class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800" />
                    </label>
                    <label class="block">
                        <span class="text-xs font-medium text-slate-600 dark:text-slate-300">Scope</span>
                        <select wire:model="scope"
                            class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800">
                            <option value="site">Entire site</option>
                            <option value="page">Single page</option>
                        </select>
                    </label>
                </div>
            @endif

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block">
                    <span class="text-xs font-medium text-slate-600 dark:text-slate-300">Location</span>
                    <input type="text" wire:model="location" list="kif-locations" placeholder="Search a country…" autocomplete="off"
                        class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800" />
                    <datalist id="kif-locations">
                        @foreach ($locationNames as $name)
                            <option value="{{ $name }}"></option>
                        @endforeach
                    </datalist>
                    <p class="mt-1 text-[10px] text-slate-400 dark:text-slate-500">Any country, region or city. Use “All” for worldwide.</p>
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-slate-600 dark:text-slate-300">Language</span>
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
                    <span wire:loading.remove wire:target="run">Find keywords</span>
                    <span wire:loading wire:target="run">Submitting…</span>
                </button>
                <span class="text-[11px] text-slate-400">A query typically takes 20–60 seconds.</span>
            </div>
        </form>
    </div>

    {{-- Error --}}
    @if ($errorMessage)
        <div class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-medium text-rose-800">
            {{ $errorMessage }}
        </div>
    @endif

    {{-- In-flight --}}
    @if ($this->isPolling())
        <div class="mt-4 flex items-center gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs font-medium text-amber-800">
            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
            Working on it — keyword ideas are being generated ({{ $status }}). This page will update automatically.
        </div>
    @endif

    {{-- Results --}}
    @if ($hasRun && ! $this->isPolling() && ! $errorMessage && $results !== [])
        @if ($fromCache)
            <p class="mb-2 inline-flex items-center gap-1.5 rounded-md bg-orange-50 px-2.5 py-1 text-xs font-medium text-orange-700 dark:bg-orange-500/10 dark:text-orange-400">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                Instant result — someone already searched this this month, so this came straight from cache.
            </p>
        @endif
        @php
            $compPill = [
                'low' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
                'medium' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400',
                'high' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400',
                'unknown' => 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
            ];
            $sortable = [
                'keyword' => ['label' => 'Keyword', 'align' => 'text-left'],
                'volume' => ['label' => 'Avg. searches', 'align' => 'text-right'],
                'competitionIndex' => ['label' => 'Competition', 'align' => 'text-left'],
                'cpc' => ['label' => 'Top-of-page bid', 'align' => 'text-right'],
            ];
        @endphp

        {{-- Toolbar: filters + export --}}
        <div class="mt-4 flex flex-wrap items-end gap-2 rounded-xl border border-slate-200 bg-white p-3 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <label class="flex flex-col gap-1 text-[11px] text-slate-500 dark:text-slate-400">
                <span class="font-medium">Filter keyword</span>
                <input type="text" wire:model.live.debounce.400ms="filterText" placeholder="contains…"
                    class="w-44 rounded-md border border-slate-300 px-2.5 py-1.5 text-xs focus:border-orange-500 focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800" />
            </label>
            <label class="flex flex-col gap-1 text-[11px] text-slate-500 dark:text-slate-400">
                <span class="font-medium">Min volume</span>
                <input type="number" min="0" wire:model.live.debounce.400ms="minVolume" placeholder="0"
                    class="w-24 rounded-md border border-slate-300 px-2.5 py-1.5 text-xs focus:border-orange-500 focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800" />
            </label>
            <label class="flex flex-col gap-1 text-[11px] text-slate-500 dark:text-slate-400">
                <span class="font-medium">Max volume</span>
                <input type="number" min="0" wire:model.live.debounce.400ms="maxVolume" placeholder="∞"
                    class="w-24 rounded-md border border-slate-300 px-2.5 py-1.5 text-xs focus:border-orange-500 focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800" />
            </label>
            <label class="flex flex-col gap-1 text-[11px] text-slate-500 dark:text-slate-400">
                <span class="font-medium">Competition</span>
                <select wire:model.live="comp"
                    class="rounded-md border border-slate-300 px-2.5 py-1.5 text-xs focus:border-orange-500 focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800">
                    <option value="all">All</option>
                    <option value="low">Low</option>
                    <option value="medium">Medium</option>
                    <option value="high">High</option>
                </select>
            </label>

            <div class="ml-auto flex items-end gap-2">
                <span class="pb-1.5 text-[11px] text-slate-400">{{ number_format($totalResults) }} keyword(s)</span>
                <button type="button" wire:click="export"
                    class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                    Export CSV
                </button>
            </div>
        </div>

        @if ($trackNotice)
            <p class="mt-3 text-xs text-emerald-600 dark:text-emerald-400">{{ $trackNotice }}</p>
        @endif

        {{-- Table --}}
        <div class="mt-3 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-500 dark:bg-slate-800/50">
                        <tr>
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
                            <th class="px-4 py-2.5 text-right font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @forelse ($rows as $row)
                            <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/30">
                                <td class="px-4 py-2.5 font-medium text-slate-800 dark:text-slate-100">{{ $row['keyword'] }}</td>
                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ $row['volume'] !== null ? number_format($row['volume']) : '—' }}</td>
                                <td class="px-4 py-2.5">
                                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold {{ $compPill[$row['comp_level']] ?? $compPill['unknown'] }}">
                                        {{ $row['competition'] }}@if ($row['competitionIndex'] !== null) <span class="opacity-60">{{ $row['competitionIndex'] }}</span>@endif
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                    @if ($row['low'] !== null || $row['high'] !== null)
                                        ${{ number_format((float) ($row['low'] ?? 0), 2) }}–${{ number_format((float) ($row['high'] ?? 0), 2) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                    <div class="inline-flex items-center gap-2 text-[11px]">
                                        <button type="button" wire:click="sendToVolume(@js($row['keyword']))" class="text-orange-600 hover:underline dark:text-orange-400">Volume</button>
                                        <button type="button" wire:click="track(@js($row['keyword']))" class="text-slate-600 hover:underline dark:text-slate-300">Track</button>
                                        @if (auth()->user()?->hasFeatureAccess('audits', (string) session('current_website_id', '')))
                                            <a href="{{ route('keywords.fix', ['keyword' => $row['keyword']]) }}" class="text-slate-600 hover:underline dark:text-slate-300">Brief</a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-8 text-center text-sm text-slate-500">No keywords match your filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-4 py-2 text-[11px] text-slate-500 dark:border-slate-800 dark:text-slate-400">
                <div class="flex items-center gap-2">
                    <span>Rows</span>
                    <select wire:model.live="perPage" class="rounded border border-slate-300 px-1.5 py-0.5 text-[11px] dark:border-slate-700 dark:bg-slate-800">
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <div class="flex items-center gap-1.5">
                    <button type="button" wire:click="setPage({{ $page - 1 }})" @disabled($page <= 1)
                        class="rounded border border-slate-300 px-2 py-0.5 font-semibold disabled:opacity-40 dark:border-slate-700">Prev</button>
                    <span>Page {{ $page }} of {{ $totalPages }}</span>
                    <button type="button" wire:click="setPage({{ $page + 1 }})" @disabled($page >= $totalPages)
                        class="rounded border border-slate-300 px-2 py-0.5 font-semibold disabled:opacity-40 dark:border-slate-700">Next</button>
                </div>
            </div>
        </div>
    @elseif ($hasRun && ! $this->isPolling() && ! $errorMessage)
        <div class="mt-4 rounded-md border border-dashed border-slate-300 bg-white px-4 py-8 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900">
            No keyword ideas were returned. Try different seeds or a different URL.
        </div>
    @endif
</div>
