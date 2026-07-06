<div>
    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-1 items-center gap-2">
            <div class="relative flex-1 sm:max-w-xs">
                <svg class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
                <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search keywords…"
                    class="h-8 w-full rounded-md border border-slate-200 bg-white pl-8 pr-2.5 text-xs placeholder-slate-400 shadow-sm transition focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800 dark:placeholder-slate-500" />
            </div>
            <select wire:model.live="device"
                class="h-8 rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm transition focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800">
                <option value="">All devices</option>
                <option value="DESKTOP">Desktop</option>
                <option value="MOBILE">Mobile</option>
                <option value="TABLET">Tablet</option>
            </select>
            <input wire:model.live="from" type="date" class="h-8 rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm transition focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800" />
            <input wire:model.live="to" type="date" class="h-8 rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm transition focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800" />
            <livewire:dashboard.country-filter />
        </div>

        <div class="inline-flex rounded-md border border-slate-200 bg-slate-50 p-0.5 dark:border-slate-700 dark:bg-slate-800">
            <button wire:click="$set('view', 'aggregated')"
                @class([
                    'h-7 rounded px-2.5 text-xs font-semibold transition',
                    'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-slate-100' => $view === 'aggregated',
                    'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' => $view !== 'aggregated',
                ])>
                Aggregated
            </button>
            <button wire:click="$set('view', 'daily')"
                @class([
                    'h-7 rounded px-2.5 text-xs font-semibold transition',
                    'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-slate-100' => $view === 'daily',
                    'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' => $view !== 'daily',
                ])>
                By Date
            </button>
        </div>
    </div>

    @if ($rows instanceof \Illuminate\Contracts\Pagination\Paginator && $rows->isNotEmpty())
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-[11px] font-semibold text-slate-500 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-400">
                            @if ($view === 'daily')
                                <x-sort-header column="date" :sortBy="$sortBy" :sortDir="$sortDir">Date</x-sort-header>
                            @endif
                            <x-sort-header column="query" :sortBy="$sortBy" :sortDir="$sortDir">Keyword</x-sort-header>
                            <x-sort-header column="clicks" :sortBy="$sortBy" :sortDir="$sortDir" align="right">Clicks</x-sort-header>
                            <x-sort-header column="impressions" :sortBy="$sortBy" :sortDir="$sortDir" align="right">Impressions</x-sort-header>
                            <x-sort-header column="ctr" :sortBy="$sortBy" :sortDir="$sortDir" align="right">CTR</x-sort-header>
                            <x-sort-header column="position" :sortBy="$sortBy" :sortDir="$sortDir" align="right">Position</x-sort-header>
                            <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400" title="Global monthly search volume">Volume</th>
                            <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400" title="Projected monthly organic value at your current position (volume × CTR × CPC)">Value/mo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($rows as $row)
                            <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                @if ($view === 'daily')
                                    <td class="whitespace-nowrap px-4 py-2.5 text-slate-500 dark:text-slate-400">{{ format_user_date($row->date instanceof \Carbon\CarbonInterface ? $row->date->toDateString() : (is_string($row->date) ? $row->date : ''), 'M d, Y') ?: $row->date }}</td>
                                @endif
                                <td class="whitespace-nowrap px-4 py-2.5 font-medium text-slate-900 dark:text-slate-100">
                                    <a href="{{ route('keywords.show', ['query' => rawurlencode((string) $row->query)]) }}" wire:navigate class="text-orange-600 hover:underline dark:text-orange-400">{{ $row->query }}</a>
                                    @php $qKey = mb_strtolower((string) $row->query); @endphp
                                    <x-keyword-language :language="($languages ?? [])[$qKey] ?? null" />
                                    @if (isset($cannibalized[$qKey]))
                                        <span class="ml-1.5 inline-flex items-center rounded-full bg-amber-50 px-1.5 py-px text-[10px] font-semibold text-amber-700 dark:bg-amber-500/15 dark:text-amber-400" title="Multiple pages rank for this query">cannibalized</span>
                                    @endif
                                    @if (isset($tracked[$qKey]))
                                        <span class="ml-1 inline-flex items-center rounded-full bg-orange-50 px-1.5 py-px text-[10px] font-semibold text-orange-700 dark:bg-orange-500/15 dark:text-orange-400" title="Tracked in Rank Tracking">tracked</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-slate-700 dark:text-slate-300">{{ number_format($row->clicks) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-slate-700 dark:text-slate-300">{{ number_format($row->impressions) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-slate-700 dark:text-slate-300">{{ number_format($row->ctr * 100, 1) }}%</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right">
                                    <span @class([
                                        'inline-flex rounded-full px-1.5 py-px text-[10px] font-semibold tabular-nums',
                                        'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => $row->position <= 3,
                                        'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-400' => $row->position > 3 && $row->position <= 10,
                                        'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400' => $row->position > 10 && $row->position <= 20,
                                        'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300' => $row->position > 20,
                                    ])>{{ number_format($row->position, 1) }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-slate-700 dark:text-slate-300">
                                    @php
                                        $ke = ($keMetrics ?? [])[\App\Models\KeywordMetric::hashKeyword((string) $row->query)] ?? null;
                                        $_keTitle = '';
                                        $_trend = 'unknown';
                                        if ($ke) {
                                            $_keTitle = 'Updated '.$ke->fetched_at->diffForHumans();
                                            if ($ke->cpc !== null) {
                                                $_keTitle .= ' · CPC '.($ke->currency ?: 'USD').' '.number_format((float) $ke->cpc, 2);
                                            }
                                            $_trend = $ke->trend_class;
                                        }
                                    @endphp
                                    @if ($ke && $ke->search_volume !== null)
                                        <span class="inline-flex items-center gap-1" title="{{ $_keTitle }}">
                                            {{ number_format($ke->search_volume) }}
                                            @if ($_trend === 'rising')
                                                <span class="text-[9px] font-bold text-emerald-600 dark:text-emerald-400" title="Trend: rising">↑</span>
                                            @elseif ($_trend === 'falling')
                                                <span class="text-[9px] font-bold text-rose-600 dark:text-rose-400" title="Trend: falling">↓</span>
                                            @elseif ($_trend === 'seasonal')
                                                <span class="text-[9px] font-bold text-amber-600 dark:text-amber-400" title="Seasonal pattern">◐</span>
                                            @endif
                                        </span>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums">
                                    @php
                                        $_keValue = $ke ? \App\Services\KeywordValueCalculator::projectedMonthlyValue($ke->search_volume, (float) $row->position, $ke->cpc) : null;
                                    @endphp
                                    @if ($_keValue !== null && $_keValue > 0)
                                        <span class="font-semibold text-slate-900 dark:text-slate-100">${{ number_format($_keValue, 0) }}</span>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-3">{{ $rows->links() }}</div>
    @else
        <div class="flex flex-col items-center justify-center rounded-xl border border-slate-200 bg-white px-6 py-16 dark:border-slate-800 dark:bg-slate-900">
            <svg class="h-12 w-12 text-slate-300 dark:text-slate-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
            <p class="mt-3 text-sm font-medium text-slate-500 dark:text-slate-400">No keyword data yet</p>
            <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">Data will appear after the daily sync runs.</p>
        </div>
    @endif
</div>
