<x-layouts.app :title="__('Competitors')">
    <div class="space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-slate-100">{{ __('Competitors') }}</h1>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {{ __('Domains competing for the same keywords as') }} <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $website->domain }}</span>
                </p>
            </div>
            @if (empty($pending) && empty($noData) && empty($unavailable))
                <a href="{{ route('report.view', ['url' => $domain]) }}"
                   class="inline-flex items-center gap-1.5 self-start rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 sm:self-auto">
                    {{ __('Full Site Explorer report') }}
                    <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                </a>
            @endif
        </div>

        @if (! empty($unavailable))
            <div class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Competitor data is temporarily unavailable. Please try again later.') }}</p>
            </div>
        @elseif (! empty($noData))
            <div class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <p class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ $domain }}</p>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ __('Site Explorer didn’t find competitor data for this domain.') }}</p>
            </div>
        @elseif (! empty($pending))
            @php $attempt = (int) request('_t', 0); @endphp
            <div class="rounded-2xl bg-white p-14 text-center shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <div class="mx-auto mb-4 h-9 w-9 animate-spin rounded-full border-[3px] border-slate-200 border-t-orange-500 dark:border-slate-700"></div>
                <p class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ $domain }}</p>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Site Explorer is pulling in your competitor data — this runs automatically, nothing to do here.') }}</p>
                @if ($attempt < 15)
                    <script>setTimeout(function () { location.replace('{{ route('competitors.index') }}?_t={{ $attempt + 1 }}'); }, 6000);</script>
                @else
                    <p class="mt-3 text-xs text-slate-400 dark:text-slate-500">{{ __('This is taking longer than usual.') }} <a href="{{ route('competitors.index') }}" class="text-orange-600 hover:underline dark:text-orange-400">{{ __('Refresh') }}</a></p>
                @endif
            </div>
        @else
            @php
                $competitors = collect($payload['competitors'] ?? []);
                $fmt = fn ($v) => $v === null ? '—' : number_format((int) $v);
                $score = fn ($v) => $v === null ? '—' : number_format((float) $v, 2) . '/10';
                $topShared = $competitors->max('shared_keywords');
            @endphp

            @if ($competitors->isEmpty())
                <div class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Site Explorer didn’t find competitor data for this domain.') }}</p>
                </div>
            @else
                {{-- Headline stats --}}
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-3">
                    @foreach ([
                        ['label' => __('Competing domains'), 'value' => number_format($competitors->count())],
                        ['label' => __('Most shared keywords'), 'value' => $fmt($topShared)],
                        ['label' => __('Top competitor'), 'value' => $competitors->first()['domain'] ?? '—', 'small' => true],
                    ] as $stat)
                        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 {{ $loop->last ? 'col-span-2 lg:col-span-1' : '' }}">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ $stat['label'] }}</p>
                            <p @class(['mt-2 font-bold text-slate-900 dark:text-slate-100', 'truncate text-xl' => ! empty($stat['small']), 'text-3xl tabular-nums' => empty($stat['small'])])>{{ $stat['value'] }}</p>
                        </div>
                    @endforeach
                </div>

                {{-- Competitors table --}}
                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900"
                     x-data="{ q: '' }">
                    <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-3 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
                        <span class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ __('Organic competitors') }}</span>
                        <div class="flex items-center gap-3">
                            <input type="text" x-model.debounce.150ms="q" placeholder="{{ __('Filter domains…') }}"
                                   class="w-44 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-700 placeholder:text-slate-400 focus:border-orange-400 focus:outline-none focus:ring-1 focus:ring-orange-400 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                            <span class="inline-flex items-center gap-1 whitespace-nowrap rounded-full bg-orange-50 px-2.5 py-1 text-xs font-semibold text-orange-700 dark:bg-orange-500/15 dark:text-orange-300">
                                {{ number_format($competitors->count()) }} {{ __('rows — scroll for more') }}
                            </span>
                        </div>
                    </div>
                    <div class="max-h-[560px] overflow-y-auto overflow-x-auto [&::-webkit-scrollbar]:w-2.5 [&::-webkit-scrollbar-track]:bg-slate-50 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-slate-300 dark:[&::-webkit-scrollbar-track]:bg-slate-800 dark:[&::-webkit-scrollbar-thumb]:bg-slate-600">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 z-10 bg-white dark:bg-slate-900">
                                <tr class="text-left text-xs uppercase tracking-wide text-slate-400">
                                    <th class="border-b border-slate-100 bg-white px-5 py-2 font-medium dark:border-slate-800 dark:bg-slate-900 w-10">#</th>
                                    <th class="border-b border-slate-100 bg-white px-3 py-2 font-medium dark:border-slate-800 dark:bg-slate-900">{{ __('Domain') }}</th>
                                    <th class="border-b border-slate-100 bg-white px-3 py-2 text-right font-medium dark:border-slate-800 dark:bg-slate-900">{{ __('Shared keywords') }}</th>
                                    <th class="border-b border-slate-100 bg-white px-3 py-2 text-right font-medium dark:border-slate-800 dark:bg-slate-900">{{ __('Avg position') }}</th>
                                    <th class="border-b border-slate-100 bg-white px-5 py-2 text-right font-medium dark:border-slate-800 dark:bg-slate-900">{{ __('Authority') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($competitors as $i => $row)
                                    <tr class="border-t border-slate-100 dark:border-slate-800"
                                        x-show="q === '' || '{{ str_replace("'", '', strtolower($row['domain'] ?? '')) }}'.includes(q.toLowerCase())">
                                        <td class="px-5 py-2.5 tabular-nums text-slate-400 dark:text-slate-500">{{ $i + 1 }}</td>
                                        <td class="px-3 py-2.5">
                                            <span class="inline-flex items-center gap-2">
                                                <img src="https://www.google.com/s2/favicons?domain={{ urlencode($row['domain'] ?? '') }}&sz=32" alt="" width="16" height="16" class="h-4 w-4 flex-none rounded-sm bg-slate-100 dark:bg-slate-800" loading="lazy" onerror="this.style.visibility='hidden'">
                                                <a href="{{ route('report.view', ['url' => $row['domain'] ?? '']) }}" class="font-medium text-slate-800 hover:text-orange-600 hover:underline dark:text-slate-200 dark:hover:text-orange-400">{{ $row['domain'] ?? '' }}</a>
                                            </span>
                                        </td>
                                        <td class="px-3 py-2.5 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $fmt($row['shared_keywords'] ?? null) }}</td>
                                        <td class="px-3 py-2.5 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ isset($row['avg_position']) && $row['avg_position'] !== null ? number_format((float) $row['avg_position'], 1) : '—' }}</td>
                                        <td class="px-5 py-2.5 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $score($row['opr_score'] ?? null) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <p class="text-xs text-slate-400 dark:text-slate-500">
                    {{ __('Click a domain to open its own Site Explorer report. Competitor lookups count toward your plan’s Site Explorer limit.') }}
                </p>
            @endif
        @endif
    </div>
</x-layouts.app>
