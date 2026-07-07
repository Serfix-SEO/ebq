@php
    $gaMatched = ($data['_users_source'] ?? 'daily_sum') === 'ga4_range';
    $hasNewUsers = !empty($data['new_users']);
@endphp
<div>
    @if (!empty($data['_window']))
        <p class="mb-2 text-right text-[11px] text-slate-400">
            {{ __('Window:') }} {{ \Illuminate\Support\Carbon::parse($data['_window']['start'])->format('M j') }} – {{ \Illuminate\Support\Carbon::parse($data['_window']['end'])->format('M j, Y') }}
            · {{ __('vs the 30 days before') }} · {{ __('Search Console data lags ~3 days') }}
            @if ($gaMatched)
                · {{ __('user counts deduplicated across the window (matches GA4)') }}
            @endif
        </p>
    @endif
<div class="grid gap-4 sm:grid-cols-2 {{ $hasNewUsers ? 'xl:grid-cols-5' : 'xl:grid-cols-4' }}">
    @foreach ([
        ['key' => 'clicks', 'label' => __('Clicks (30d)'), 'icon' => 'M15.042 21.672L13.684 16.6m0 0l-2.51 2.225.569-9.47 5.227 7.917-3.286-.672zM12 2.25V4.5m5.834.166l-1.591 1.591M20.25 10.5H18M7.757 14.743l-1.59 1.59M6 10.5H3.75m4.007-4.243l-1.59-1.59', 'color' => 'text-blue-600 dark:text-blue-400', 'icon-bg' => 'bg-blue-100 dark:bg-blue-500/20'],
        ['key' => 'impressions', 'label' => __('Impressions (30d)'), 'icon' => 'M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z M15 12a3 3 0 11-6 0 3 3 0 016 0z', 'color' => 'text-emerald-600 dark:text-emerald-400', 'icon-bg' => 'bg-emerald-100 dark:bg-emerald-500/20'],
        ['key' => 'users', 'label' => $gaMatched ? __('Active users (30d)') : __('Users · daily sum (30d)'), 'icon' => 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z', 'color' => 'text-orange-600 dark:text-orange-400', 'icon-bg' => 'bg-orange-100 dark:bg-orange-500/20'],
        ['key' => 'sessions', 'label' => __('Sessions (30d)'), 'icon' => 'M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3m0 0l.5 1.5m-.5-1.5h-9.5m0 0l-.5 1.5m.75-9l3-3 2.148 2.148A12.061 12.061 0 0116.5 7.605', 'color' => 'text-amber-600 dark:text-amber-400', 'icon-bg' => 'bg-amber-100 dark:bg-amber-500/20'],
        ['key' => 'new_users', 'label' => __('New users (30d)'), 'icon' => 'M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM3 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 019.374 21c-2.331 0-4.512-.645-6.374-1.766z', 'color' => 'text-sky-600 dark:text-sky-400', 'icon-bg' => 'bg-sky-100 dark:bg-sky-500/20'],
    ] as $card)
        @php $metric = $data[$card['key']] ?? null; @endphp
        @continue($metric === null)
        <div class="group flex min-h-[142px] flex-col rounded-xl border border-slate-200 bg-white p-5 transition hover:shadow-md dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ $card['label'] }}</p>
                <span class="{{ $card['icon-bg'] }} flex h-9 w-9 items-center justify-center rounded-lg">
                    <svg class="h-4 w-4 {{ $card['color'] }}" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $card['icon'] }}" /></svg>
                </span>
            </div>
            <p class="mt-3 text-3xl font-bold tracking-tight tabular-nums {{ $card['color'] }}">{{ number_format($metric['current']) }}</p>
            <p class="mt-auto pt-2 text-xs">
                @if (is_null($metric['change_percent']))
                    <span class="font-semibold text-slate-500 dark:text-slate-400">{{ __('new') }}</span>
                @elseif ($metric['direction'] === 'up')
                    <span class="font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">+{{ $metric['change_percent'] }}%</span>
                @elseif ($metric['direction'] === 'down')
                    <span class="font-semibold tabular-nums text-red-600 dark:text-red-400">{{ $metric['change_percent'] }}%</span>
                @else
                    <span class="font-semibold tabular-nums text-slate-500 dark:text-slate-400">0.0%</span>
                @endif
                <span class="text-slate-400 dark:text-slate-500">{{ __('vs previous 30d') }}</span>
            </p>
        </div>
    @endforeach
</div>
</div>
