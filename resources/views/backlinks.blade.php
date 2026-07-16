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
            <div class="rounded-2xl bg-white p-14 text-center shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <div class="mx-auto mb-4 h-9 w-9 animate-spin rounded-full border-[3px] border-slate-200 border-t-orange-500 dark:border-slate-700"></div>
                <p class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ $domain }}</p>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Site Explorer is pulling in your existing backlink data — this runs automatically, nothing to do here.') }}</p>
                <p id="bl-slow" class="mt-3 hidden text-xs text-slate-400 dark:text-slate-500">{{ __('This is taking longer than usual.') }} <a href="{{ route('backlinks.index') }}" class="text-orange-600 hover:underline dark:text-orange-400">{{ __('Refresh') }}</a></p>
                <script>
                (function () {
                    var started = Date.now();
                    (function poll() {
                        fetch('{{ route('report.status', ['url' => $domain]) }}', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                            .then(function (r) { return r.ok ? r.json() : null; })
                            .then(function (d) {
                                if (d && d.status && d.status !== 'pending' && d.status !== 'missing' && d.status !== 'unknown') { location.replace('{{ route('backlinks.index') }}'); return; }
                                if (Date.now() - started > 95000) document.getElementById('bl-slow').classList.remove('hidden');
                                setTimeout(poll, 5000);
                            })
                            .catch(function () { setTimeout(poll, 8000); });
                    })();
                })();
                </script>
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
                $scores = $p['scores'] ?? [];
                $csPill = function ($v) {
                    if (! is_numeric($v)) {
                        return null;
                    }
                    $v = (int) $v;

                    return [
                        'value' => $v,
                        'class' => $v >= 60
                            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400'
                            : ($v >= 30
                                ? 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'
                                : 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'),
                    ];
                };
                $authorityCell = fn ($row) => $csPill($row['cs'] ?? null);
                // Per-plan controls: rows shown (render cap — the snapshot
                // keeps the full fetch) + whether the paid anchor drill-down
                // is available (trial: off).
                $plan = auth()->user()?->effectivePlan();
                $canDrilldown = $plan?->apiLimit('report.allow_link_drilldown') !== 0;
                // Monthly row quota outcome (BacklinkRowQuota via resolve());
                // fallback = plain per-view cap when the key is absent.
                $bv = $p['_backlink_view'] ?? null;
                $shownRows = $bv['shown'] ?? ($plan?->apiLimit('report.max_backlink_rows') ?? 1000);
                $backlinkRows = array_slice($p['backlinks'] ?? [], 0, $shownRows);
            @endphp

            @if (! empty($p['meta']['partial']))
                <div class="rounded-2xl border-l-4 border-sky-500 bg-sky-50 p-6 ring-1 ring-sky-200 dark:bg-sky-500/10 dark:ring-sky-800 sm:p-7">
                    <p class="text-xl font-bold text-sky-900 dark:text-sky-200 sm:text-2xl">{{ __('No backlinks discovered for this site yet') }}</p>
                    <p class="mt-2 text-base leading-relaxed text-sky-800 dark:text-sky-300">{{ __('This looks like a new website — backlink data fills in automatically as the web discovers it. In the meantime, the Site Explorer report holds the closest available data we gathered for it.') }}</p>
                </div>
            @endif

            {{-- Trust / Citation scores --}}
            @if (isset($scores['trust']) || isset($scores['citation']))
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    @foreach ([
                        ['label' => __('TrustSignal'), 'caption' => __('Link quality — how trustworthy the sites linking to you are'), 'value' => $scores['trust'] ?? null],
                        ['label' => __('CiteSignal'), 'caption' => __('Link popularity — how widely the web references this site'), 'value' => $scores['citation'] ?? null],
                        ['label' => __('TopicSignal'), 'caption' => __('Trust earned from links that are relevant to your topic'), 'value' => $scores['topical'] ?? null],
                    ] as $card)
                        @php $band = $csPill($card['value']); $isTopical = $card['label'] === __('TopicSignal'); @endphp
                        <div class="flex items-center gap-5 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                            @php
                                $v = is_numeric($card['value']) ? (int) $card['value'] : null;
                                $dash = $v !== null ? round($v / 100 * 163.4, 1) : 0;
                                $stroke = $v === null ? '#e2e8f0' : ($v >= 60 ? '#10b981' : ($v >= 30 ? '#f59e0b' : '#f43f5e'));
                            @endphp
                            <svg width="72" height="72" viewBox="0 0 72 72" class="flex-none" aria-hidden="true">
                                <circle cx="36" cy="36" r="26" fill="none" stroke="currentColor" stroke-width="7" class="text-slate-100 dark:text-slate-800" />
                                {{-- Topical ring always emitted (0-length when null) so the live poller can fill it in place. --}}
                                <circle @if ($isTopical) id="ts-topical-ring" @endif cx="36" cy="36" r="26" fill="none" stroke="{{ $stroke }}" stroke-width="7" stroke-linecap="round" stroke-dasharray="{{ $dash }} 163.4" transform="rotate(-90 36 36)" @if ($v === null && ! $isTopical) style="display:none" @endif />
                                <text @if ($isTopical) id="ts-topical-num" @endif x="36" y="42" text-anchor="middle" fill="currentColor" class="text-slate-900 dark:text-slate-100" font-size="18" font-weight="700">{{ $v ?? '—' }}</text>
                            </svg>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ $card['label'] }}</p>
                                <p @if ($isTopical) id="ts-topical-value" @endif class="mt-1 text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ $v !== null ? $v.'/100' : '—' }}</p>
                                <p class="mt-1 text-xs leading-snug text-slate-500 dark:text-slate-400">{{ $card['caption'] }}</p>
                                <a href="{{ route('trust-score') }}" target="_blank" class="mt-1 inline-block text-[11px] font-medium text-orange-600 hover:underline dark:text-orange-400">{{ __('How it’s calculated') }}</a>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

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

            {{-- Link risk — toxicity interpretation over the backlink profile --}}
            @include('reports.partials.link-risk', [
                'risk' => $p['link_risk'] ?? null,
                'dark' => true,
                'disavowUrl' => route('report.disavow', ['url' => $domain]),
            ])

            {{-- Topical relevance — live card (self-updating, no reloads) --}}
            @include('reports.partials.topical-live', ['domain' => $domain, 'section' => $p['topical_trust'] ?? null, 'dark' => true])

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
                                    <th class="border-b border-slate-100 bg-white px-3 py-2 text-right font-medium dark:border-slate-800 dark:bg-slate-900">{{ __('Trust') }}</th>
                                    <th class="border-b border-slate-100 bg-white px-3 py-2 text-right font-medium dark:border-slate-800 dark:bg-slate-900">{{ __('Citation') }}</th>
                                    <th class="border-b border-slate-100 bg-white px-3 py-2 text-right font-medium dark:border-slate-800 dark:bg-slate-900">{{ __('Backlinks') }}</th>
                                    <th class="border-b border-slate-100 bg-white px-5 py-2 text-right font-medium dark:border-slate-800 dark:bg-slate-900">{{ __('First seen') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($p['top_referring_domains'] as $row)
                                    <tr @class(['border-t border-slate-100 dark:border-slate-800', 'bg-rose-50/60 dark:bg-rose-500/5' => ($row['tox'] ?? null) === 'high', 'bg-amber-50/60 dark:bg-amber-500/5' => ($row['tox'] ?? null) === 'medium'])>
                                        <td class="px-5 py-2.5">
                                            <span class="inline-flex items-center gap-2">
                                                <img src="https://www.google.com/s2/favicons?domain={{ urlencode($row['domain'] ?? '') }}&sz=32" alt="" width="16" height="16" class="h-4 w-4 flex-none rounded-sm bg-slate-100 dark:bg-slate-800" loading="lazy" onerror="this.style.visibility='hidden'">
                                                <span class="font-medium text-slate-800 dark:text-slate-200">{{ $row['domain'] ?? '' }}</span>
                                                @if (($row['tox'] ?? null) === 'high')
                                                    <span title="{{ $row['tox_why'] ?? '' }}" class="rounded-full bg-rose-100 px-1.5 py-0.5 text-[10px] font-bold text-rose-700 dark:bg-rose-500/15 dark:text-rose-300">⚠ {{ __('Toxic') }}</span>
                                                @elseif (($row['tox'] ?? null) === 'medium')
                                                    <span title="{{ $row['tox_why'] ?? '' }}" class="rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">{{ __('Risky') }}</span>
                                                @endif
                                            </span>
                                        </td>
                                        <td class="px-3 py-2.5 text-right">
                                            @php $tsPill = $csPill($row['ts'] ?? null); @endphp
                                            @if ($tsPill)
                                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums {{ $tsPill['class'] }}">{{ $tsPill['value'] }}</span>
                                            @else
                                                <span class="text-slate-400 dark:text-slate-500">—</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2.5 text-right">
                                            @php $pill = $authorityCell($row); @endphp
                                            @if ($pill)
                                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums {{ $pill['class'] }}">{{ $pill['value'] }}</span>
                                            @else
                                                <span class="tabular-nums text-slate-600 dark:text-slate-300">{{ $score($row['opr_score'] ?? null) }}</span>
                                            @endif
                                        </td>
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
                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900" id="bl-table">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-3 dark:border-slate-800">
                        <span class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ __('Backlinks') }}</span>
                        <span class="flex items-center gap-2.5">
                            <span class="inline-flex rounded-lg bg-slate-100 p-0.5 text-xs font-medium dark:bg-slate-800"><button type="button" data-rpt-group="bl-table" data-mode="all" class="rounded-md bg-white px-2.5 py-1 text-slate-900 shadow-sm">All links</button><button type="button" data-rpt-group="bl-table" data-mode="one" class="rounded-md px-2.5 py-1 text-slate-500">One per domain</button></span>
                            <input type="text" data-rpt-filter="bl-table" placeholder="{{ __('Search anchor or URL…') }}"
                                   class="w-44 rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-700 placeholder:text-slate-400 focus:border-orange-400 focus:outline-none focus:ring-1 focus:ring-orange-400 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                            <span class="inline-flex items-center gap-1 rounded-full bg-orange-50 px-2.5 py-1 text-xs font-semibold text-orange-700 dark:bg-orange-500/15 dark:text-orange-300">
                                <span data-rpt-count="bl-table" data-total="{{ number_format(count($backlinkRows)) }}">{{ number_format(count($backlinkRows)) }}</span> {{ __('rows — scroll for more') }}
                            </span>
                        </span>
                    </div>
                    <div class="max-h-[480px] overflow-y-auto overflow-x-auto [&::-webkit-scrollbar]:w-2.5 [&::-webkit-scrollbar-track]:bg-slate-50 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-slate-300 dark:[&::-webkit-scrollbar-track]:bg-slate-800 dark:[&::-webkit-scrollbar-thumb]:bg-slate-600">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 z-10 bg-white dark:bg-slate-900">
                                <tr class="text-left text-xs uppercase tracking-wide text-slate-400">
                                    <th class="border-b border-slate-100 bg-white px-5 py-2 font-medium dark:border-slate-800 dark:bg-slate-900">{{ __('Source page') }}</th>
                                    <th class="border-b border-slate-100 bg-white px-3 py-2 font-medium dark:border-slate-800 dark:bg-slate-900">{{ __('Anchor') }}</th>
                                    <th class="border-b border-slate-100 bg-white px-3 py-2 text-right font-medium dark:border-slate-800 dark:bg-slate-900">{{ __('Type') }}</th>
                                    <th class="border-b border-slate-100 bg-white px-3 py-2 text-right font-medium dark:border-slate-800 dark:bg-slate-900">{{ __('Trust') }}</th>
                                    <th class="border-b border-slate-100 bg-white px-5 py-2 text-right font-medium dark:border-slate-800 dark:bg-slate-900">{{ __('Citation') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($backlinkRows as $row)
                                    <tr @class(['border-t border-slate-100 align-top dark:border-slate-800', 'bg-rose-50/60 dark:bg-rose-500/5' => ($row['tox'] ?? null) === 'high', 'bg-amber-50/60 dark:bg-amber-500/5' => ($row['tox'] ?? null) === 'medium']) data-domain="{{ strtolower((string) parse_url($row['url_from'] ?? '', PHP_URL_HOST)) }}" data-search="{{ ($row['url_from'] ?? '').' '.($row['url_to'] ?? '').' '.($row['anchor'] ?? '') }}">
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
                                            @if (($row['tox'] ?? null) === 'high')
                                                <span title="{{ $row['tox_why'] ?? '' }}" class="ml-1 rounded-full bg-rose-100 px-1.5 py-0.5 text-[10px] font-bold text-rose-700 dark:bg-rose-500/15 dark:text-rose-300">⚠ {{ __('Toxic') }}</span>
                                            @elseif (($row['tox'] ?? null) === 'medium')
                                                <span title="{{ $row['tox_why'] ?? '' }}" class="ml-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">{{ __('Risky') }}</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2.5 text-right">
                                            @php $tsPill = $csPill($row['ts'] ?? null); @endphp
                                            @if ($tsPill)
                                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums {{ $tsPill['class'] }}">{{ $tsPill['value'] }}</span>
                                            @else
                                                <span class="text-slate-400 dark:text-slate-500">—</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-2.5 text-right">
                                            @php $pill = $authorityCell($row); @endphp
                                            @if ($pill)
                                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums {{ $pill['class'] }}">{{ $pill['value'] }}</span>
                                            @else
                                                <span class="tabular-nums text-slate-600 dark:text-slate-300">{{ $score($row['opr_score'] ?? null) }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        @if (count($p['backlinks'] ?? []) > count($backlinkRows))
                            <div class="border-t border-slate-100 bg-orange-50/60 px-5 py-3 text-center text-sm text-orange-800 dark:border-slate-800 dark:bg-orange-500/10 dark:text-orange-300">
                                @if (! empty($bv['exhausted']))
                                    {{ __('Monthly backlink row limit reached — :used of :limit rows used this month.', ['used' => number_format($bv['monthly_used']), 'limit' => number_format($bv['monthly_limit'])]) }}
                                @else
                                    {{ __('Showing :shown of :total backlinks on your current plan.', ['shown' => number_format(count($backlinkRows)), 'total' => number_format(count($p['backlinks']))]) }}
                                    @if (($bv['monthly_limit'] ?? null) !== null)
                                        <span class="opacity-80">({{ __(':used of :limit monthly rows used', ['used' => number_format($bv['monthly_used']), 'limit' => number_format($bv['monthly_limit'])]) }})</span>
                                    @endif
                                @endif
                                <a href="{{ route('billing.show') }}" class="font-semibold underline underline-offset-2 hover:text-orange-600 dark:hover:text-orange-200">{{ __('Upgrade to see them all') }}</a>
                            </div>
                        @endif
                        <div data-rpt-empty="bl-table" data-none-text="{{ __('The index has no live links for this exact anchor anymore.') }}" class="hidden border-t border-slate-100 px-5 py-6 text-center text-sm text-slate-500 dark:border-slate-800 dark:text-slate-400">
                            {{ __('No backlinks match your search.') }} {{ count($p['backlinks']) >= 1000 ? __('Very large profiles show the top 1,000 links by strength.') : '' }}
                            @if ($canDrilldown)
                            <div>
                                <button type="button" data-anchor-fetch="bl-table" data-endpoint="{{ route('report.anchor-links', ['url' => $domain]) }}"
                                        data-loading="{{ __('Fetching from the live index…') }}" data-failed="{{ __('Nothing found in the index either') }}" data-retry="{{ __('Try again') }}"
                                        class="mt-3 inline-flex items-center gap-2 rounded-lg bg-orange-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-orange-700 disabled:opacity-60">
                                    {{ __("Fetch this anchor's links from the live index") }}
                                </button>
                            </div>
                            @else
                                <p class="mt-2 text-xs text-slate-400 dark:text-slate-500"><a href="{{ route('billing.show') }}" class="text-orange-600 hover:underline dark:text-orange-400">{{ __('Upgrade') }}</a> {{ __('to fetch this anchor’s links from the live index.') }}</p>
                            @endif
                            <div data-anchor-results></div>
                        </div>
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
                                    <tr @class(['border-t border-slate-100 dark:border-slate-800', 'bg-rose-50/60 dark:bg-rose-500/5' => ($row['tox'] ?? null) === 'high'])>
                                        <td class="max-w-[400px] truncate px-5 py-2.5">
                                            @if (($row['anchor'] ?? '') !== '')
                                                <button type="button" data-anchor-search="{{ $row['anchor'] }}" data-target="bl-table"
                                                        title="{{ __('Search the backlinks list for this anchor') }}"
                                                        class="max-w-full truncate text-left text-slate-700 underline decoration-dotted decoration-slate-300 underline-offset-2 transition hover:text-orange-600 dark:text-slate-200 dark:decoration-slate-600 dark:hover:text-orange-400">{{ $row['anchor'] }}</button>
                                            @else
                                                <span class="text-slate-400 dark:text-slate-500">{{ __('(empty)') }}</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2.5 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $fmt($row['backlinks'] ?? null) }}</td>
                                        <td class="px-5 py-2.5 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $fmt($row['referring_domains'] ?? null) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- Filter/sort + anchor→backlinks search JS (window-guarded). --}}
            @include('reports.partials.table-tools')
        @endif
    </div>
</x-layouts.app>
