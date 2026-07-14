<x-layouts.app :title="__('Backlinks')">
    <div class="space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-slate-100">{{ __('Backlinks') }}</h1>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {{ __('The backlinks Site Explorer already found for') }} <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $website->domain }}</span>
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
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Backlink data is temporarily unavailable. Please try again later.') }}</p>
            </div>
        @elseif (! empty($noData))
            <div class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <p class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ $domain }}</p>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ __('Site Explorer didn’t find any backlinks for this domain.') }}</p>
            </div>
        @elseif (! empty($pending))
            @php $attempt = (int) request('_t', 0); @endphp
            <div class="rounded-2xl bg-white p-14 text-center shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <div class="mx-auto mb-4 h-9 w-9 animate-spin rounded-full border-[3px] border-slate-200 border-t-orange-500 dark:border-slate-700"></div>
                <p class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ $domain }}</p>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Site Explorer is pulling in your existing backlink data — this runs automatically, nothing to do here.') }}</p>
                @if ($attempt < 15)
                    <script>setTimeout(function () { location.replace('{{ route('backlinks.index') }}?_t={{ $attempt + 1 }}'); }, 6000);</script>
                @else
                    <p class="mt-3 text-xs text-slate-400 dark:text-slate-500">{{ __('This is taking longer than usual.') }} <a href="{{ route('backlinks.index') }}" class="text-orange-600 hover:underline dark:text-orange-400">{{ __('Refresh') }}</a></p>
                @endif
            </div>
        @else
            @php
                $p = $payload;
                $fmt = fn ($v) => $v === null ? '—' : number_format((int) $v);
                $totals = $p['totals'] ?? [];
                $ratios = $p['ratios'] ?? [];
                $anchorTypes = $p['anchor_types'] ?? [];
                $history = collect($p['history'] ?? [])->map(fn ($h) => [
                    'label' => \Illuminate\Support\Carbon::parse((string) $h['month'])->format('M'),
                    'referring_domains' => (int) ($h['referring_domains'] ?? 0),
                    'active' => (int) ($h['active'] ?? 0),
                    'lost' => (int) ($h['lost'] ?? 0),
                ])->values();
                $latest = $history->last();
                $score = fn ($v) => $v === null ? '—' : number_format((float) $v, 2) . '/10';
            @endphp

            {{-- Headline stats --}}
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                @foreach ([
                    ['label' => __('Backlinks'), 'value' => $fmt($totals['backlinks'] ?? null)],
                    ['label' => __('Referring domains'), 'value' => $fmt($totals['referring_domains'] ?? null)],
                    ['label' => __('Referring IPs'), 'value' => $fmt($totals['referring_ips'] ?? null)],
                    ['label' => __('Dofollow links'), 'value' => isset($ratios['dofollow_pct']) ? ((int) $ratios['dofollow_pct']).'%' : '—'],
                ] as $stat)
                    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ $stat['label'] }}</p>
                        <p class="mt-2 text-3xl font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ $stat['value'] }}</p>
                    </div>
                @endforeach
            </div>

            <div class="grid gap-5 lg:grid-cols-3">
                {{-- Referring-domain trend --}}
                @if ($history->count() >= 2)
                    @php
                        $vals = $history->pluck('referring_domains');
                        $max = max(1, $vals->max());
                        $min = min($vals->min(), $max - 1);
                        $range = max(1, $max - $min);
                        $w = 560; $h = 120;
                        $step = $w / max(1, $history->count() - 1);
                        $points = $history->values()->map(fn ($row, $i) =>
                            round($i * $step, 1) . ',' . round($h - (($row['referring_domains'] - $min) / $range) * ($h - 12) - 6, 1)
                        )->implode(' ');
                    @endphp
                    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 lg:col-span-2">
                        <div class="flex items-baseline justify-between">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ __('Referring domains — last :n months', ['n' => $history->count()]) }}</p>
                            @if ($latest)
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    <span class="font-semibold text-emerald-600 dark:text-emerald-400">+{{ number_format($latest['active']) }} {{ __('new') }}</span>
                                    ·
                                    <span class="font-semibold text-rose-600 dark:text-rose-400">−{{ number_format($latest['lost']) }} {{ __('lost') }}</span>
                                    <span class="text-slate-400">({{ __('this month') }})</span>
                                </p>
                            @endif
                        </div>
                        <svg viewBox="0 0 {{ $w }} {{ $h }}" class="mt-3 block h-28 w-full" preserveAspectRatio="none" aria-hidden="true">
                            <polyline points="{{ $points }}" fill="none" stroke="#F26419" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <div class="mt-1 flex justify-between text-[10px] text-slate-400 dark:text-slate-500">
                            <span>{{ $history->first()['label'] }}</span>
                            <span>{{ $latest['label'] ?? '' }}</span>
                        </div>
                    </div>
                @endif

                {{-- Anchor-type split --}}
                @if (! empty($anchorTypes))
                    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ __('Anchor types') }}</p>
                        <div class="mt-4 space-y-3">
                            @foreach ([
                                ['key' => 'branded', 'label' => __('Branded'), 'bar' => 'bg-orange-500'],
                                ['key' => 'exact', 'label' => __('Exact match'), 'bar' => 'bg-sky-500'],
                                ['key' => 'naked', 'label' => __('Naked URL'), 'bar' => 'bg-amber-500'],
                                ['key' => 'generic', 'label' => __('Generic'), 'bar' => 'bg-slate-400'],
                            ] as $t)
                                @php $pct = (int) ($anchorTypes[$t['key']] ?? 0); @endphp
                                <div>
                                    <div class="flex items-baseline justify-between text-xs">
                                        <span class="font-medium text-slate-700 dark:text-slate-200">{{ $t['label'] }}</span>
                                        <span class="tabular-nums text-slate-500 dark:text-slate-400">{{ $pct }}%</span>
                                    </div>
                                    <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                                        <div class="h-full rounded-full {{ $t['bar'] }}" style="width: {{ max(2, $pct) }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            {{-- Top referring domains --}}
            @if (! empty($p['top_referring_domains']))
                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3 dark:border-slate-800">
                        <span class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ __('Top referring domains') }}</span>
                        <span class="inline-flex items-center gap-1 rounded-full bg-orange-50 px-2.5 py-1 text-xs font-semibold text-orange-700 dark:bg-orange-500/15 dark:text-orange-300">
                            {{ number_format(count($p['top_referring_domains'])) }} {{ __('rows — scroll for more') }}
                        </span>
                    </div>
                    <div class="max-h-[420px] overflow-y-auto overflow-x-auto [&::-webkit-scrollbar]:w-2.5 [&::-webkit-scrollbar-track]:bg-slate-50 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-slate-300 dark:[&::-webkit-scrollbar-track]:bg-slate-800 dark:[&::-webkit-scrollbar-thumb]:bg-slate-600">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 z-10 bg-white dark:bg-slate-900">
                                <tr class="text-left text-xs uppercase tracking-wide text-slate-400">
                                    <th class="border-b border-slate-100 bg-white px-5 py-2 font-medium dark:border-slate-800 dark:bg-slate-900">{{ __('Domain') }}</th>
                                    <th class="border-b border-slate-100 bg-white px-3 py-2 text-right font-medium dark:border-slate-800 dark:bg-slate-900">{{ __('Authority') }}</th>
                                    <th class="border-b border-slate-100 bg-white px-3 py-2 text-right font-medium dark:border-slate-800 dark:bg-slate-900">{{ __('Backlinks') }}</th>
                                    <th class="border-b border-slate-100 bg-white px-5 py-2 text-right font-medium dark:border-slate-800 dark:bg-slate-900">{{ __('First seen') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($p['top_referring_domains'] as $row)
                                    <tr class="border-t border-slate-100 dark:border-slate-800">
                                        <td class="px-5 py-2.5">
                                            <span class="inline-flex items-center gap-2">
                                                <img src="https://www.google.com/s2/favicons?domain={{ urlencode($row['domain'] ?? '') }}&sz=32" alt="" width="16" height="16" class="h-4 w-4 flex-none rounded-sm bg-slate-100 dark:bg-slate-800" loading="lazy" onerror="this.style.visibility='hidden'">
                                                <span class="font-medium text-slate-800 dark:text-slate-200">{{ $row['domain'] ?? '' }}</span>
                                            </span>
                                        </td>
                                        <td class="px-3 py-2.5 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $score($row['opr_score'] ?? null) }}</td>
                                        <td class="px-3 py-2.5 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $fmt($row['backlinks'] ?? null) }}</td>
                                        <td class="px-5 py-2.5 text-right text-slate-500 dark:text-slate-400">{{ $row['first_seen'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- Backlinks --}}
            @if (! empty($p['backlinks']))
                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3 dark:border-slate-800">
                        <span class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ __('Backlinks') }}</span>
                        <span class="inline-flex items-center gap-1 rounded-full bg-orange-50 px-2.5 py-1 text-xs font-semibold text-orange-700 dark:bg-orange-500/15 dark:text-orange-300">
                            {{ number_format(count($p['backlinks'])) }} {{ __('rows — scroll for more') }}
                        </span>
                    </div>
                    <div class="max-h-[480px] overflow-y-auto overflow-x-auto [&::-webkit-scrollbar]:w-2.5 [&::-webkit-scrollbar-track]:bg-slate-50 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-slate-300 dark:[&::-webkit-scrollbar-track]:bg-slate-800 dark:[&::-webkit-scrollbar-thumb]:bg-slate-600">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 z-10 bg-white dark:bg-slate-900">
                                <tr class="text-left text-xs uppercase tracking-wide text-slate-400">
                                    <th class="border-b border-slate-100 bg-white px-5 py-2 font-medium dark:border-slate-800 dark:bg-slate-900">{{ __('Source page') }}</th>
                                    <th class="border-b border-slate-100 bg-white px-3 py-2 font-medium dark:border-slate-800 dark:bg-slate-900">{{ __('Anchor') }}</th>
                                    <th class="border-b border-slate-100 bg-white px-3 py-2 text-right font-medium dark:border-slate-800 dark:bg-slate-900">{{ __('Type') }}</th>
                                    <th class="border-b border-slate-100 bg-white px-5 py-2 text-right font-medium dark:border-slate-800 dark:bg-slate-900">{{ __('Authority') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($p['backlinks'] as $row)
                                    <tr class="border-t border-slate-100 align-top dark:border-slate-800">
                                        <td class="max-w-[320px] px-5 py-2.5">
                                            <div class="flex items-start gap-2">
                                                <img src="https://www.google.com/s2/favicons?domain={{ urlencode(parse_url($row['url_from'] ?? '', PHP_URL_HOST) ?: '') }}&sz=32" alt="" width="16" height="16" class="mt-0.5 h-4 w-4 flex-none rounded-sm bg-slate-100 dark:bg-slate-800" loading="lazy" onerror="this.style.visibility='hidden'">
                                                <div class="min-w-0">
                                                    <a href="{{ $row['url_from'] ?? '#' }}" target="_blank" rel="nofollow noopener" class="break-all text-orange-600 hover:underline dark:text-orange-400">{{ \Illuminate\Support\Str::limit($row['url_from'] ?? '', 60) }}</a>
                                                    @if (! empty($row['url_to']))<div class="truncate text-xs text-slate-400 dark:text-slate-500">→ {{ \Illuminate\Support\Str::limit($row['url_to'], 50) }}</div>@endif
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-3 py-2.5 text-slate-600 dark:text-slate-300">{{ ($row['anchor'] ?? '') !== '' ? \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', $row['anchor'])), 36) : __('(empty)') }}</td>
                                        <td class="px-3 py-2.5 text-right">
                                            <span @class(['rounded-full px-2 py-0.5 text-xs font-medium', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => ! empty($row['dofollow']), 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' => empty($row['dofollow'])])>{{ ! empty($row['dofollow']) ? 'dofollow' : 'nofollow' }}</span>
                                        </td>
                                        <td class="px-5 py-2.5 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $score($row['opr_score'] ?? null) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- Anchor texts --}}
            @if (! empty($p['anchors']))
                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3 dark:border-slate-800">
                        <span class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ __('Anchor texts') }}</span>
                        <span class="inline-flex items-center gap-1 rounded-full bg-orange-50 px-2.5 py-1 text-xs font-semibold text-orange-700 dark:bg-orange-500/15 dark:text-orange-300">
                            {{ number_format(count($p['anchors'])) }} {{ __('rows — scroll for more') }}
                        </span>
                    </div>
                    <div class="max-h-[420px] overflow-y-auto overflow-x-auto [&::-webkit-scrollbar]:w-2.5 [&::-webkit-scrollbar-track]:bg-slate-50 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-slate-300 dark:[&::-webkit-scrollbar-track]:bg-slate-800 dark:[&::-webkit-scrollbar-thumb]:bg-slate-600">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 z-10 bg-white dark:bg-slate-900">
                                <tr class="text-left text-xs uppercase tracking-wide text-slate-400">
                                    <th class="border-b border-slate-100 bg-white px-5 py-2 font-medium dark:border-slate-800 dark:bg-slate-900">{{ __('Anchor') }}</th>
                                    <th class="border-b border-slate-100 bg-white px-3 py-2 text-right font-medium dark:border-slate-800 dark:bg-slate-900">{{ __('Backlinks') }}</th>
                                    <th class="border-b border-slate-100 bg-white px-5 py-2 text-right font-medium dark:border-slate-800 dark:bg-slate-900">{{ __('Ref. domains') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($p['anchors'] as $row)
                                    <tr class="border-t border-slate-100 dark:border-slate-800">
                                        <td class="max-w-[400px] truncate px-5 py-2.5 text-slate-700 dark:text-slate-200">{{ ($row['anchor'] ?? '') !== '' ? $row['anchor'] : __('(empty)') }}</td>
                                        <td class="px-3 py-2.5 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $fmt($row['backlinks'] ?? null) }}</td>
                                        <td class="px-5 py-2.5 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $fmt($row['referring_domains'] ?? null) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        @endif
    </div>
</x-layouts.app>
