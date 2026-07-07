<div class="space-y-5">
    {{-- Report Header --}}
    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-5">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-base font-bold tracking-tight sm:text-lg">{{ __(':type Performance Report', ['type' => ucfirst($reportType)]) }}</h2>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                    <span class="font-medium text-slate-700 dark:text-slate-200">{{ $website?->domain ?? __('Unknown') }}</span>
                    &mdash;
                    @if ($startDate === $endDate)
                        {{ format_user_date($startDate, 'l, F j, Y') }}
                    @else
                        {{ format_user_date($startDate, 'M j') }} &ndash; {{ format_user_date($endDate, 'M j, Y') }}
                    @endif
                </p>
            </div>
            <p class="text-[11px] italic text-slate-400 dark:text-slate-500">
                {{ __('vs') }} {{ $report['period']['previous_label'] }}
                ({{ format_user_date($report['period']['prev_start'], 'M j') }} &ndash; {{ format_user_date($report['period']['prev_end'], 'M j') }})
            </p>
        </div>
    </div>

    {{-- GOOGLE ANALYTICS --}}
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="border-b border-slate-100 px-4 py-3 dark:border-slate-800 sm:px-5">
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center rounded-md bg-blue-50 px-2 py-0.5 text-[11px] font-semibold text-blue-700 ring-1 ring-blue-600/20 ring-inset dark:bg-blue-500/10 dark:text-blue-400 dark:ring-blue-500/30">GA</span>
                <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ __('Website Traffic') }}</h3>
            </div>
        </div>

        <div class="p-4 sm:p-5">
            <div class="mb-5 grid grid-cols-1 gap-2.5 md:grid-cols-3">
                @include('livewire.reports.partials.kpi-card', ['label' => __('Users'), 'metric' => $report['analytics']['users'], 'format' => 'number'])
                @include('livewire.reports.partials.kpi-card', ['label' => __('Sessions'), 'metric' => $report['analytics']['sessions'], 'format' => 'number'])
                @include('livewire.reports.partials.kpi-card', ['label' => __('Bounce Rate'), 'metric' => $report['analytics']['bounce_rate'], 'format' => 'percent', 'changeSuffix' => 'pp'])
            </div>

            @if (count($report['analytics']['top_sources']) > 0)
                <h4 class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ __('Top Traffic Sources') }}</h4>
                <div class="overflow-x-auto rounded-lg border border-slate-100 dark:border-slate-800">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="bg-slate-50 text-left text-[11px] font-medium uppercase tracking-wider text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
                                <th class="px-3 py-2">{{ __('Source') }}</th>
                                <th class="px-3 py-2 text-end">{{ $report['period']['current_label'] }}</th>
                                <th class="px-3 py-2 text-end">{{ $report['period']['previous_label'] }}</th>
                                <th class="px-3 py-2 text-end">{{ __('Change') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach ($report['analytics']['top_sources'] as $source)
                                <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                    <td class="px-3 py-2 font-medium text-slate-700 dark:text-slate-300">{{ $source['source'] }}</td>
                                    <td class="px-3 py-2 text-end text-slate-900 dark:text-slate-100">{{ number_format($source['users']) }}</td>
                                    <td class="px-3 py-2 text-end text-slate-400">{{ number_format($source['prev_users']) }}</td>
                                    <td class="px-3 py-2 text-end">@include('livewire.reports.partials.change-badge', ['metric' => $source['change']])</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-xs text-slate-400 dark:text-slate-500">{{ __('No analytics data available for this period.') }}</p>
            @endif

            <div class="mt-5 grid grid-cols-1 gap-3 lg:grid-cols-2">
                <div class="rounded-lg border border-slate-100 px-3 py-3 dark:border-slate-800">
                    <p class="text-[10px] font-medium uppercase tracking-wider text-slate-400">{{ __('Engagement Insight') }}</p>
                    <p class="mt-1 text-sm font-semibold leading-tight text-slate-800 dark:text-slate-100">
                        {{ __(':n sessions/user', ['n' => $report['analytics']['sessions_per_user']['current']]) }}
                    </p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        {{ __('was') }} {{ $report['analytics']['sessions_per_user']['previous'] }} {{ __('in') }} {{ $report['period']['previous_label'] }}
                    </p>
                </div>
                <div class="rounded-lg border border-slate-100 px-3 py-3 dark:border-slate-800">
                    <p class="text-[10px] font-medium uppercase tracking-wider text-slate-400">{{ __('Source Concentration') }}</p>
                    <p class="mt-1 text-sm font-semibold leading-tight text-slate-800 dark:text-slate-100">
                        {{ __(':pct% from top 3 sources', ['pct' => $report['analytics']['source_concentration_top3']]) }}
                    </p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Higher values mean channel concentration risk.') }}</p>
                </div>
            </div>

            @if (count($report['analytics']['top_source_gainers']) > 0 || count($report['analytics']['top_source_losers']) > 0)
                <div class="mt-5 grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <div class="rounded-lg border border-slate-100 p-3 dark:border-slate-800">
                        <h4 class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">{{ __('Source Gainers') }}</h4>
                        <ul class="space-y-1 text-xs">
                            @foreach ($report['analytics']['top_source_gainers'] as $item)
                                <li class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-2 rounded border border-slate-100 px-2 py-1.5 dark:border-slate-800">
                                    <span class="truncate text-slate-700 dark:text-slate-300" title="{{ $item['source'] }}">{{ $item['source'] }}</span>
                                    <span class="whitespace-nowrap font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">+{{ number_format($item['change']) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="rounded-lg border border-slate-100 p-3 dark:border-slate-800">
                        <h4 class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-red-600 dark:text-red-400">{{ __('Source Losers') }}</h4>
                        <ul class="space-y-1 text-xs">
                            @foreach ($report['analytics']['top_source_losers'] as $item)
                                <li class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-2 rounded border border-slate-100 px-2 py-1.5 dark:border-slate-800">
                                    <span class="truncate text-slate-700 dark:text-slate-300" title="{{ $item['source'] }}">{{ $item['source'] }}</span>
                                    <span class="whitespace-nowrap font-semibold tabular-nums text-red-600 dark:text-red-400">{{ number_format($item['change']) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- GOOGLE SEARCH CONSOLE --}}
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="border-b border-slate-100 px-4 py-3 dark:border-slate-800 sm:px-5">
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center rounded-md bg-purple-50 px-2 py-0.5 text-[11px] font-semibold text-purple-700 ring-1 ring-purple-600/20 ring-inset dark:bg-purple-500/10 dark:text-purple-400 dark:ring-purple-500/30">GSC</span>
                <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ __('Search Performance') }}</h3>
            </div>
        </div>

        <div class="p-4 sm:p-5">
            <div class="mb-5 grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                @include('livewire.reports.partials.kpi-card', ['label' => __('Clicks'), 'metric' => $report['search_console']['clicks'], 'format' => 'number'])
                @include('livewire.reports.partials.kpi-card', ['label' => __('Impressions'), 'metric' => $report['search_console']['impressions'], 'format' => 'number'])
                @include('livewire.reports.partials.kpi-card', ['label' => __('Avg Position'), 'metric' => $report['search_console']['position'], 'format' => 'decimal'])
                @include('livewire.reports.partials.kpi-card', ['label' => __('Avg CTR'), 'metric' => $report['search_console']['ctr'], 'format' => 'percent', 'changeSuffix' => 'pp'])
            </div>

            @if (count($report['search_console']['top_queries']) > 0)
                <h4 class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ __('Top Search Queries') }}</h4>
                <div class="mb-5 overflow-x-auto rounded-lg border border-slate-100 dark:border-slate-800">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="bg-slate-50 text-left text-[11px] font-medium uppercase tracking-wider text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
                                <th class="px-3 py-2">{{ __('Query') }}</th>
                                <th class="px-3 py-2 text-end">{{ __('Clicks') }}</th>
                                <th class="px-3 py-2 text-end">{{ __('Prev') }}</th>
                                <th class="px-3 py-2 text-end">{{ __('Impr.') }}</th>
                                <th class="px-3 py-2 text-end">{{ __('Pos') }}</th>
                                <th class="px-3 py-2 text-end">{{ __('CTR') }}</th>
                                <th class="px-3 py-2 text-end">{{ __('Change') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach ($report['search_console']['top_queries'] as $q)
                                <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                    <td class="max-w-[10rem] truncate px-3 py-2 font-medium text-slate-700 dark:text-slate-300">{{ $q['query'] }}</td>
                                    <td class="px-3 py-2 text-end font-semibold text-slate-900 dark:text-slate-100">{{ number_format($q['clicks']) }}</td>
                                    <td class="px-3 py-2 text-end text-slate-400">{{ number_format($q['prev_clicks']) }}</td>
                                    <td class="px-3 py-2 text-end text-slate-600 dark:text-slate-300">{{ number_format($q['impressions']) }}</td>
                                    <td class="px-3 py-2 text-end text-slate-600 dark:text-slate-300">{{ $q['position'] }}</td>
                                    <td class="px-3 py-2 text-end text-slate-600 dark:text-slate-300">{{ $q['ctr'] }}%</td>
                                    <td class="px-3 py-2 text-end">@include('livewire.reports.partials.change-badge', ['metric' => $q['change']])</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if (count($report['search_console']['top_pages']) > 0)
                <h4 class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ __('Top Pages') }}</h4>
                <div class="mb-5 overflow-x-auto rounded-lg border border-slate-100 dark:border-slate-800">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="bg-slate-50 text-left text-[11px] font-medium uppercase tracking-wider text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
                                <th class="px-3 py-2">{{ __('Page') }}</th>
                                <th class="px-3 py-2 text-end">{{ __('Clicks') }}</th>
                                <th class="px-3 py-2 text-end">{{ __('Prev') }}</th>
                                <th class="px-3 py-2 text-end">{{ __('Impr.') }}</th>
                                <th class="px-3 py-2 text-end">{{ __('CTR') }}</th>
                                <th class="px-3 py-2 text-end">{{ __('Change') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach ($report['search_console']['top_pages'] as $p)
                                <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                    <td class="max-w-[12rem] truncate px-3 py-2 font-medium text-slate-700 dark:text-slate-300" title="{{ $p['page'] }}">{{ \Illuminate\Support\Str::limit($p['page'], 50) }}</td>
                                    <td class="px-3 py-2 text-end font-semibold text-slate-900 dark:text-slate-100">{{ number_format($p['clicks']) }}</td>
                                    <td class="px-3 py-2 text-end text-slate-400">{{ number_format($p['prev_clicks']) }}</td>
                                    <td class="px-3 py-2 text-end text-slate-600 dark:text-slate-300">{{ number_format($p['impressions']) }}</td>
                                    <td class="px-3 py-2 text-end text-slate-600 dark:text-slate-300">{{ $p['ctr'] }}%</td>
                                    <td class="px-3 py-2 text-end">@include('livewire.reports.partials.change-badge', ['metric' => $p['change']])</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if (count($report['search_console']['devices']) > 0)
                <h4 class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ __('Device Breakdown') }}</h4>
                <div class="mb-5 grid grid-cols-2 gap-2.5 sm:grid-cols-3">
                    @foreach ($report['search_console']['devices'] as $device)
                        <div class="min-w-0 rounded-lg border border-slate-100 px-3 py-2 dark:border-slate-800">
                            <p class="truncate text-[10px] font-medium uppercase tracking-wider text-slate-400">{{ ucfirst($device['device']) }}</p>
                            <p class="mt-0.5 text-lg font-bold tabular-nums leading-tight text-slate-900 dark:text-slate-100">{{ number_format($device['clicks']) }}</p>
                            <p class="text-[10px] text-slate-400">{{ __(':pct% of clicks', ['pct' => $device['percentage']]) }}</p>
                            <div class="mt-0.5">@include('livewire.reports.partials.change-badge', ['metric' => $device['change']])</div>
                            <p class="text-[10px] text-slate-400">{{ __('was') }} {{ number_format($device['prev_clicks']) }}</p>
                        </div>
                    @endforeach
                </div>
            @endif

            @if (count($report['search_console']['countries']) > 0)
                <h4 class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ __('Top Countries') }}</h4>
                <div class="overflow-x-auto rounded-lg border border-slate-100 dark:border-slate-800">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="bg-slate-50 text-left text-[11px] font-medium uppercase tracking-wider text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
                                <th class="px-3 py-2">{{ __('Country') }}</th>
                                <th class="px-3 py-2 text-end">{{ __('Clicks') }}</th>
                                <th class="px-3 py-2 text-end">{{ __('Prev') }}</th>
                                <th class="px-3 py-2 text-end">{{ __('Impr.') }}</th>
                                <th class="px-3 py-2 text-end">{{ __('Change') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach ($report['search_console']['countries'] as $c)
                                <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                    <td class="px-3 py-2 font-medium text-slate-700 dark:text-slate-300">{{ $c['country'] ?: __('Unknown') }}</td>
                                    <td class="px-3 py-2 text-end font-semibold text-slate-900 dark:text-slate-100">{{ number_format($c['clicks']) }}</td>
                                    <td class="px-3 py-2 text-end text-slate-400">{{ number_format($c['prev_clicks']) }}</td>
                                    <td class="px-3 py-2 text-end text-slate-600 dark:text-slate-300">{{ number_format($c['impressions']) }}</td>
                                    <td class="px-3 py-2 text-end">@include('livewire.reports.partials.change-badge', ['metric' => $c['change']])</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if (! empty($report['search_console']['position_buckets']))
                <h4 class="mb-2 mt-5 text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ __('Position Buckets') }}</h4>
                <div class="mb-5 grid grid-cols-2 gap-2.5 lg:grid-cols-4">
                    @foreach ([
                        ['label' => __('Top 3'), 'value' => $report['search_console']['position_buckets']['top_3']],
                        ['label' => '4-10', 'value' => $report['search_console']['position_buckets']['top_10']],
                        ['label' => '11-20', 'value' => $report['search_console']['position_buckets']['near_page_1']],
                        ['label' => '20+', 'value' => $report['search_console']['position_buckets']['beyond_20']],
                    ] as $bucket)
                        <div class="rounded-lg border border-slate-100 px-3 py-2.5 dark:border-slate-800">
                            <p class="text-[10px] uppercase tracking-wider text-slate-400">{{ $bucket['label'] }}</p>
                            <p class="mt-1 text-lg font-bold leading-tight tabular-nums text-slate-900 dark:text-slate-100">{{ number_format($bucket['value']) }}</p>
                            <p class="text-[10px] text-slate-400">{{ __('keywords') }}</p>
                        </div>
                    @endforeach
                </div>
            @endif

            @if (count($report['search_console']['opportunities']) > 0)
                <h4 class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-orange-600 dark:text-orange-400">{{ __('Optimization Opportunities') }}</h4>
                <div class="mb-5 overflow-x-auto rounded-lg border border-slate-100 dark:border-slate-800">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="bg-slate-50 text-left text-[11px] font-medium uppercase tracking-wider text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
                                <th class="px-3 py-2">{{ __('Query') }}</th>
                                <th class="px-3 py-2 text-end">{{ __('Impr.') }}</th>
                                <th class="px-3 py-2 text-end">{{ __('CTR') }}</th>
                                <th class="px-3 py-2 text-end">{{ __('Pos') }}</th>
                                <th class="px-3 py-2 text-end">{{ __('Score') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach ($report['search_console']['opportunities'] as $opp)
                                <tr>
                                    <td class="max-w-[12rem] truncate px-3 py-2 text-slate-700 dark:text-slate-300">{{ $opp['query'] }}</td>
                                    <td class="px-3 py-2 text-end text-slate-700 dark:text-slate-300">{{ number_format($opp['impressions']) }}</td>
                                    <td class="px-3 py-2 text-end text-slate-700 dark:text-slate-300">{{ $opp['ctr'] }}%</td>
                                    <td class="px-3 py-2 text-end text-slate-700 dark:text-slate-300">{{ $opp['position'] }}</td>
                                    <td class="px-3 py-2 text-end font-semibold text-orange-600 dark:text-orange-400">{{ $opp['score'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if (count($report['search_console']['top_query_gainers']) > 0 || count($report['search_console']['top_query_losers']) > 0)
                <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <div class="rounded-lg border border-slate-100 p-3 dark:border-slate-800">
                        <h4 class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">{{ __('Query Gainers') }}</h4>
                        <ul class="space-y-1 text-xs">
                            @foreach ($report['search_console']['top_query_gainers'] as $item)
                                <li class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-2 rounded border border-slate-100 px-2 py-1.5 dark:border-slate-800">
                                    <span class="truncate text-slate-700 dark:text-slate-300" title="{{ $item['query'] }}">{{ $item['query'] }}</span>
                                    <span class="whitespace-nowrap font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">+{{ number_format($item['change']) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="rounded-lg border border-slate-100 p-3 dark:border-slate-800">
                        <h4 class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-red-600 dark:text-red-400">{{ __('Query Losers') }}</h4>
                        <ul class="space-y-1 text-xs">
                            @foreach ($report['search_console']['top_query_losers'] as $item)
                                <li class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-2 rounded border border-slate-100 px-2 py-1.5 dark:border-slate-800">
                                    <span class="truncate text-slate-700 dark:text-slate-300" title="{{ $item['query'] }}">{{ $item['query'] }}</span>
                                    <span class="whitespace-nowrap font-semibold tabular-nums text-red-600 dark:text-red-400">{{ number_format($item['change']) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            @if (count($report['search_console']['top_queries']) === 0 && count($report['search_console']['top_pages']) === 0)
                <p class="text-xs text-slate-400 dark:text-slate-500">{{ __('No search console data available for this period.') }}</p>
            @endif
        </div>
    </div>

    {{-- INDEXING STATUS --}}
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="border-b border-slate-100 px-4 py-3 dark:border-slate-800 sm:px-5">
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center rounded-md bg-cyan-50 px-2 py-0.5 text-[11px] font-semibold text-cyan-700 ring-1 ring-cyan-600/20 ring-inset dark:bg-cyan-500/10 dark:text-cyan-400 dark:ring-cyan-500/30">{{ __('Indexing') }}</span>
                <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ __('Latest Google Indexing Status') }}</h3>
            </div>
        </div>

        <div class="p-4 sm:p-5">
            <div class="mb-5 grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                <div class="rounded-lg border border-slate-100 px-3 py-2 dark:border-slate-800">
                    <p class="text-[10px] uppercase tracking-wider text-slate-400">{{ __('Tracked Pages') }}</p>
                    <p class="mt-1 text-lg font-bold text-slate-900 dark:text-slate-100">{{ number_format($report['indexing']['summary']['tracked_pages'] ?? 0) }}</p>
                </div>
                <div class="rounded-lg border border-slate-100 px-3 py-2 dark:border-slate-800">
                    <p class="text-[10px] uppercase tracking-wider text-slate-400">{{ __('Checked Pages') }}</p>
                    <p class="mt-1 text-lg font-bold text-slate-900 dark:text-slate-100">{{ number_format($report['indexing']['summary']['checked_pages'] ?? 0) }}</p>
                </div>
                <div class="rounded-lg border border-slate-100 px-3 py-2 dark:border-slate-800">
                    <p class="text-[10px] uppercase tracking-wider text-slate-400">{{ __('PASS Verdict') }}</p>
                    <p class="mt-1 text-lg font-bold text-emerald-600 dark:text-emerald-400">{{ number_format($report['indexing']['summary']['pass_pages'] ?? 0) }}</p>
                </div>
                <div class="rounded-lg border border-slate-100 px-3 py-2 dark:border-slate-800">
                    <p class="text-[10px] uppercase tracking-wider text-slate-400">{{ __('FAIL Verdict') }}</p>
                    <p class="mt-1 text-lg font-bold text-rose-600 dark:text-rose-400">{{ number_format($report['indexing']['summary']['fail_pages'] ?? 0) }}</p>
                </div>
            </div>

            <p class="mb-2 text-xs text-slate-500 dark:text-slate-400">
                {{ __('Last checked:') }}
                <span class="font-medium text-slate-700 dark:text-slate-200">
                    {{ !empty($report['indexing']['summary']['last_checked_at']) ? format_user_datetime($report['indexing']['summary']['last_checked_at'], 'M j, Y g:i A') : __('Never') }}
                </span>
            </p>

            @if (count($report['indexing']['latest'] ?? []) > 0)
                <div class="overflow-x-auto rounded-lg border border-slate-100 dark:border-slate-800">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="bg-slate-50 text-left text-[11px] font-medium uppercase tracking-wider text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
                                <th class="px-3 py-2">{{ __('Page') }}</th>
                                <th class="px-3 py-2">{{ __('Verdict') }}</th>
                                <th class="px-3 py-2">{{ __('Coverage') }}</th>
                                <th class="px-3 py-2 text-end">{{ __('Last Crawl') }}</th>
                                <th class="px-3 py-2 text-end">{{ __('Checked') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach (($report['indexing']['latest'] ?? []) as $row)
                                <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                    <td class="max-w-[16rem] truncate px-3 py-2 font-medium text-slate-700 dark:text-slate-300" title="{{ $row['page'] }}">{{ \Illuminate\Support\Str::limit($row['page'], 70) }}</td>
                                    <td class="px-3 py-2">
                                        <span @class([
                                            'inline-flex rounded-full px-1.5 py-px text-[10px] font-semibold',
                                            'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => $row['verdict'] === 'PASS',
                                            'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400' => $row['verdict'] === 'FAIL',
                                            'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-300' => !in_array($row['verdict'], ['PASS', 'FAIL'], true),
                                        ])>{{ $row['verdict'] }}</span>
                                    </td>
                                    <td class="px-3 py-2 text-slate-700 dark:text-slate-300">{{ $row['coverage_state'] }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-end text-slate-600 dark:text-slate-300">
                                        {{ $row['last_crawl_at'] ? format_user_datetime($row['last_crawl_at'], 'M j, Y') : '—' }}
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-end text-slate-600 dark:text-slate-300">
                                        {{ $row['checked_at'] ? format_user_datetime($row['checked_at'], 'M j, Y g:i A') : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-xs text-slate-400 dark:text-slate-500">{{ __('No indexing status checks recorded yet.') }}</p>
            @endif
        </div>
    </div>
</div>
