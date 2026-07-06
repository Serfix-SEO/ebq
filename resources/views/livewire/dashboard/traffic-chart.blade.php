<div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4 dark:border-slate-800">
        <div>
            <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Traffic Overview</h3>
            <p class="text-xs text-slate-500 dark:text-slate-400">
                @if ($window)
                    {{ \Illuminate\Support\Carbon::parse($window['start'])->format('M j') }} – {{ \Illuminate\Support\Carbon::parse($window['end'])->format('M j') }}
                    <span class="text-slate-400">· last 30 days of Search Console data (finalized data lags ~3 days)</span>
                @else
                    Last 30 days of data
                @endif
            </p>
        </div>
        <div class="flex items-center gap-4 text-xs text-slate-500 dark:text-slate-400">
            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-orange-500"></span> Clicks</span>
            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span> Users</span>
        </div>
    </div>

    @if ($days->isEmpty())
        <div class="flex flex-col items-center justify-center px-6 py-16">
            <svg class="h-12 w-12 text-slate-300 dark:text-slate-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3m0 0l.5 1.5m-.5-1.5h-9.5m0 0l-.5 1.5" /></svg>
            <p class="mt-3 text-sm font-medium text-slate-500 dark:text-slate-400">No traffic data yet</p>
            <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">Data will appear after the daily sync runs.</p>
        </div>
    @else
        @if (! empty($anomalies))
            <div class="mx-6 mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-700/50 dark:bg-amber-900/20 dark:text-amber-300">
                {{ implode(' ', $anomalies) }}
            </div>
        @endif
        @php $perPage = 10; $total = $days->count(); @endphp
        <div
            x-data="{
                page: 0,
                perPage: {{ $perPage }},
                total: {{ $total }},
                get pageCount() { return Math.max(1, Math.ceil(this.total / this.perPage)); },
                get from() { return this.total === 0 ? 0 : this.page * this.perPage + 1; },
                get to() { return Math.min(this.total, (this.page + 1) * this.perPage); },
                next() { if (this.page < this.pageCount - 1) this.page++; },
                prev() { if (this.page > 0) this.page--; },
            }"
        >
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">
                            <th class="px-6 py-3">Date</th>
                            <th class="px-6 py-3 text-right">Clicks</th>
                            <th class="px-6 py-3 text-right">Users</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($days as $day)
                            <tr
                                x-show="{{ $loop->index }} >= page * perPage && {{ $loop->index }} < (page + 1) * perPage"
                                class="transition hover:bg-slate-50 dark:hover:bg-slate-800/50"
                            >
                                <td class="whitespace-nowrap px-6 py-3 text-slate-600 dark:text-slate-300">{{ $day['date'] }}</td>
                                <td class="whitespace-nowrap px-6 py-3 text-right font-medium text-slate-900 dark:text-slate-100">{{ number_format($day['clicks']) }}</td>
                                <td class="whitespace-nowrap px-6 py-3 text-right font-medium text-slate-900 dark:text-slate-100">{{ number_format($day['users']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($total > $perPage)
                <div class="flex items-center justify-between border-t border-slate-200 px-6 py-3 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                    <span>
                        Showing <span class="font-medium text-slate-700 dark:text-slate-200" x-text="from"></span>–<span class="font-medium text-slate-700 dark:text-slate-200" x-text="to"></span> of <span class="font-medium text-slate-700 dark:text-slate-200">{{ $total }}</span>
                    </span>
                    <div class="flex items-center gap-1">
                        <button
                            type="button"
                            @click="prev()"
                            :disabled="page === 0"
                            class="rounded-md border border-slate-200 px-2.5 py-1 font-medium text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                        >
                            Prev
                        </button>
                        <span class="px-2 tabular-nums">
                            <span x-text="page + 1"></span> / <span x-text="pageCount"></span>
                        </span>
                        <button
                            type="button"
                            @click="next()"
                            :disabled="page >= pageCount - 1"
                            class="rounded-md border border-slate-200 px-2.5 py-1 font-medium text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                        >
                            Next
                        </button>
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
