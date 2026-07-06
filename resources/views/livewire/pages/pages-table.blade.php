<div>
    <div class="mb-4">
        <div class="flex flex-wrap items-center gap-2">
            <div class="relative flex-1 sm:max-w-xs">
                <svg class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
                <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search pages…"
                    class="h-8 w-full rounded-md border border-slate-200 bg-white pl-8 pr-2.5 text-xs placeholder-slate-400 shadow-sm transition focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800 dark:placeholder-slate-500" />
            </div>
            <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border border-slate-200 bg-white px-2.5 py-1.5 text-[11px] font-medium text-slate-600 shadow-sm transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700">
                <input type="checkbox" wire:model.live="onlyFailingWithTraffic" class="h-3 w-3 rounded border-slate-300 text-orange-600 focus:ring-orange-500 dark:border-slate-600 dark:bg-slate-700" />
                <span>Only indexing-fails with traffic</span>
            </label>
            <livewire:dashboard.country-filter />
        </div>
        @if (! empty($gscKeywordLookbackDays))
            <p class="mt-2 text-[11px] text-slate-500 dark:text-slate-400">Clicks, impressions, and averages use Search Console data from the last <span class="font-semibold text-slate-700 dark:text-slate-300">{{ (int) $gscKeywordLookbackDays }}</span> days (same window as page detail and Settings → Reports).</p>
        @endif
    </div>

    @if ($rows instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator && $rows->isNotEmpty())
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-[11px] font-semibold text-slate-500 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-400">
                            <x-sort-header column="page" :sortBy="$sortBy" :sortDir="$sortDir">Page</x-sort-header>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-slate-500 dark:text-slate-400">Market</th>
                            <x-sort-header column="total_clicks" :sortBy="$sortBy" :sortDir="$sortDir" align="right">Clicks</x-sort-header>
                            <x-sort-header column="total_impressions" :sortBy="$sortBy" :sortDir="$sortDir" align="right">Impressions</x-sort-header>
                            <x-sort-header column="avg_ctr" :sortBy="$sortBy" :sortDir="$sortDir" align="right">Avg CTR</x-sort-header>
                            <x-sort-header column="avg_position" :sortBy="$sortBy" :sortDir="$sortDir" align="right">Avg Position</x-sort-header>
                            <x-sort-header column="last_google_status_checked_at" :sortBy="$sortBy" :sortDir="$sortDir" align="right">Google Indexing Status</x-sort-header>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($rows as $row)
                            <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                <td class="max-w-sm truncate px-4 py-2.5">
                                    <a href="{{ route('pages.show', ['id' => urlencode($row->page)]) }}" class="font-medium text-orange-600 hover:text-orange-800 dark:text-orange-400 dark:hover:text-orange-300">
                                        {{ $row->page }}
                                    </a>
                                </td>
                                <td class="max-w-[8rem] truncate px-4 py-2.5 text-[11px] text-slate-600 dark:text-slate-300" title="{{ \App\Support\Audit\PageLocalePresentation::shortLabel($pageLocaleByHash[hash('sha256', $row->page)] ?? null) ?? '' }}">
                                    {{ \App\Support\Audit\PageLocalePresentation::shortLabel($pageLocaleByHash[hash('sha256', $row->page)] ?? null) ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-slate-700 dark:text-slate-300">{{ number_format($row->total_clicks) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-slate-700 dark:text-slate-300">{{ number_format($row->total_impressions) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-slate-700 dark:text-slate-300">{{ number_format($row->avg_ctr * 100, 1) }}%</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right">
                                    <span @class([
                                        'inline-flex rounded-full px-1.5 py-px text-[10px] font-semibold tabular-nums',
                                        'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => $row->avg_position <= 3,
                                        'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-400' => $row->avg_position > 3 && $row->avg_position <= 10,
                                        'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400' => $row->avg_position > 10 && $row->avg_position <= 20,
                                        'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300' => $row->avg_position > 20,
                                    ])>{{ number_format($row->avg_position, 1) }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right text-slate-700 dark:text-slate-300">
                                    <div class="text-xs font-semibold text-slate-800 dark:text-slate-100">{{ $row->google_verdict ?? 'Not checked' }}</div>
                                    <div class="text-[11px] text-slate-500 dark:text-slate-400">
                                        {{ $row->last_google_status_checked_at ? format_user_datetime($row->last_google_status_checked_at, 'M j, Y g:i A') : 'Never checked' }}
                                    </div>
                                    @if ($row->google_last_crawl_at)
                                        <div class="text-[10px] text-slate-500 dark:text-slate-400">crawl: {{ format_user_datetime($row->google_last_crawl_at, 'M j, Y') }}</div>
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
            <svg class="h-12 w-12 text-slate-300 dark:text-slate-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
            <p class="mt-3 text-sm font-medium text-slate-500 dark:text-slate-400">No page data yet</p>
            <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">Data will appear after the daily sync runs.</p>
        </div>
    @endif
</div>
