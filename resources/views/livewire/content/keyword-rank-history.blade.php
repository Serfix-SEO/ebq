@php
    // ── Chart geometry (inline SVG — no chart lib, prebuilt-Tailwind only) ──
    $W = 1000; $H = 300; $PADL = 44; $PADR = 16; $PADT = 14; $PADB = 30;
    $plotW = $W - $PADL - $PADR;
    $plotH = $H - $PADT - $PADB;
    $points = $series['points'];
    $n = max(1, count($points));

    $x = fn (int $i) => round($PADL + ($n <= 1 ? $plotW / 2 : $i * $plotW / ($n - 1)), 1);
    // Rank axis is INVERTED (1 at the top) and log-scaled so the top of page 1
    // — where the difference actually matters — gets most of the height.
    $y = function (float $pos) use ($PADT, $plotH) {
        $pos = min(100, max(1, $pos));
        return round($PADT + (log10($pos) / 2) * $plotH, 1);
    };
    $gridRanks = [1, 3, 10, 30, 100];

    // Live-rank line. Checks are WEEKLY, so consecutive checks must join across
    // the unchecked days between them — only a check that came back "not in the
    // top 100" breaks the line (drawn instead as a hollow marker on the floor),
    // because joining across it would imply a rank we never measured.
    $segments = []; $current = []; $dots = []; $dropouts = [];
    foreach ($points as $i => $p) {
        if (! $p['rank_checked']) {
            continue; // no measurement that day — not a break, just no point
        }
        if ($p['rank'] !== null) {
            $px = $x($i); $py = $y((float) $p['rank']);
            $current[] = "$px,$py";
            $dots[] = ['x' => $px, 'y' => $py, 'date' => $p['date'], 'rank' => $p['rank']];

            continue;
        }
        $dropouts[] = ['x' => $x($i), 'y' => $y(100.0), 'date' => $p['date']];
        if ($current !== []) { $segments[] = $current; $current = []; }
    }
    if ($current !== []) { $segments[] = $current; }

    // Search Console average position (impression-weighted) — daily, dashed.
    $gscSegments = []; $current = [];
    foreach ($points as $i => $p) {
        if ($p['gsc'] !== null) {
            $current[] = $x($i).','.$y((float) $p['gsc']);
        } elseif ($current !== []) {
            $gscSegments[] = $current; $current = [];
        }
    }
    if ($current !== []) { $gscSegments[] = $current; }

    // Impressions volume behind the lines (context, not a second axis).
    $maxImpr = max(1, max(array_map(fn ($p) => $p['impressions'], $points) ?: [1]));
    $barW = max(1.2, $plotW / $n * 0.7);

    $tickEvery = (int) max(1, floor($n / 6));
    $stats = $series['stats'];
    $change = $stats['change'];
    $fmtDate = fn (string $d) => \Illuminate\Support\Carbon::parse($d)->format('M j');
@endphp

<div class="mx-auto w-full max-w-6xl space-y-6">

    {{-- ── Breadcrumb / back ── --}}
    <nav class="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400" aria-label="{{ __('Breadcrumb') }}">
        <a href="{{ route('content.tracker') }}" wire:navigate class="inline-flex items-center gap-1.5 font-medium hover:text-orange-600 dark:hover:text-orange-400">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/></svg>
            {{ __('Content Tracker') }}
        </a>
        <span class="text-slate-300 dark:text-slate-600">/</span>
        <span class="truncate font-medium text-slate-700 dark:text-slate-300">{{ $keyword->keyword }}</span>
    </nav>

    {{-- ── Header ── --}}
    <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-slate-100">{{ $keyword->keyword }}</h1>
                    @if ($keyword->is_primary)
                        <span class="rounded bg-orange-100 px-1.5 py-0.5 text-xs font-bold uppercase tracking-wide text-orange-700 dark:bg-orange-500/15 dark:text-orange-300">{{ __('Primary') }}</span>
                    @endif
                </div>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {{ __('Rank history on :domain', ['domain' => $website->domain]) }}
                    @if ($stats['last_checked'])
                        &nbsp;•&nbsp; {{ __('Last checked :date', ['date' => $fmtDate($stats['last_checked'])]) }}
                    @endif
                </p>
                <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                    @if ($topic)
                        <a href="{{ route('content.review', $topic->id) }}" wire:navigate class="inline-flex items-center gap-1.5 font-medium text-orange-600 hover:text-orange-700 dark:text-orange-400">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5A3.375 3.375 0 0010.125 2.25H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
                            {{ \Illuminate\Support\Str::limit($topic->title, 60) }}
                        </a>
                    @endif
                    @if ($keyword->page_url)
                        <a href="{{ $keyword->page_url }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                            {{ \Illuminate\Support\Str::limit(preg_replace('#^https?://#', '', $keyword->page_url), 48) }}
                        </a>
                    @endif
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                @if ($refreshQueued)
                    <span class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                        {{ __('Check queued') }}
                    </span>
                @else
                    <button type="button" wire:click="refreshRank" wire:loading.attr="disabled"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992V4.356m-4.992 4.992l3.181-3.183a8.25 8.25 0 00-13.803 3.7M4.031 9.865v4.992m0 0h4.99m-4.99 0l3.18 3.185a8.25 8.25 0 0013.804-3.7"/></svg>
                        {{ __('Check rank now') }}
                    </button>
                @endif
            </div>
        </div>
    </div>

    {{-- ── Stat cards ── --}}
    <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
        <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Current position') }}</div>
            <div class="mt-1 text-2xl font-extrabold text-slate-900 dark:text-slate-100">
                @if ($stats['current'])#{{ $stats['current'] }}@elseif ($stats['last_checked'])<span class="text-lg">{{ __('Not in top 100') }}</span>@else<span class="text-lg text-slate-400">{{ __('Checking…') }}</span>@endif
            </div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Best position') }}</div>
            <div class="mt-1 text-2xl font-extrabold text-slate-900 dark:text-slate-100">{{ $stats['best'] ? '#'.$stats['best'] : '—' }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Movement') }}</div>
            <div class="mt-1 flex items-center gap-1.5 text-2xl font-extrabold">
                @if ($change === null || $change === 0)
                    <span class="text-slate-400">{{ $change === 0 ? __('No change') : '—' }}</span>
                @elseif ($change > 0)
                    <svg class="h-5 w-5 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5"/></svg>
                    <span class="text-emerald-600 dark:text-emerald-400">{{ $change }}</span>
                @else
                    <svg class="h-5 w-5 text-rose-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                    <span class="text-rose-600 dark:text-rose-400">{{ abs($change) }}</span>
                @endif
            </div>
            <div class="mt-0.5 text-xs text-slate-400">{{ __('places in this period') }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Search clicks') }}</div>
            <div class="mt-1 text-2xl font-extrabold text-slate-900 dark:text-slate-100">{{ number_format($stats['clicks']) }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Impressions') }}</div>
            <div class="mt-1 text-2xl font-extrabold text-slate-900 dark:text-slate-100">{{ number_format($stats['impressions']) }}</div>
        </div>
    </div>

    {{-- ── Chart ── --}}
    <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Position over time') }}</h2>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('Higher on the chart is a better position.') }}</p>
            </div>
            <div class="inline-flex rounded-lg border border-slate-200 p-0.5 dark:border-slate-700">
                @foreach ($ranges as $r)
                    <button type="button" wire:click="setRange({{ $r }})"
                        class="rounded-md px-3 py-1.5 text-xs font-semibold {{ $range === $r ? 'bg-orange-500 text-white' : 'text-slate-500 hover:bg-slate-50 dark:text-slate-400 dark:hover:bg-slate-800' }}">
                        {{ trans_choice('{1}:count day|[2,*]:count days', $r, ['count' => $r]) }}
                    </button>
                @endforeach
            </div>
        </div>

        @if (! $series['has_rank_history'] && ! $series['has_gsc'])
            <div class="py-14 text-center">
                <svg class="mx-auto h-10 w-10 text-slate-300 dark:text-slate-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/></svg>
                <p class="mt-3 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('No history yet') }}</p>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('We check this keyword\'s position every week and build the chart from there. The first points appear within a few days.') }}</p>
            </div>
        @else
            <div class="mt-4 overflow-x-auto">
                {{-- min-width is inline: arbitrary Tailwind values aren't in the prebuilt bundle. --}}
                <svg viewBox="0 0 {{ $W }} {{ $H }}" class="h-72 w-full" style="min-width: 560px;" role="img"
                     aria-label="{{ __('Position over time for :keyword', ['keyword' => $keyword->keyword]) }}">
                    {{-- gridlines + rank axis --}}
                    @foreach ($gridRanks as $g)
                        @php $gy = $y((float) $g); @endphp
                        <line x1="{{ $PADL }}" y1="{{ $gy }}" x2="{{ $W - $PADR }}" y2="{{ $gy }}"
                              stroke="currentColor" stroke-width="1" class="text-slate-200 dark:text-slate-700"></line>
                        <text x="{{ $PADL - 8 }}" y="{{ $gy + 4 }}" text-anchor="end" font-size="11"
                              fill="currentColor" class="text-slate-400">#{{ $g }}</text>
                    @endforeach

                    {{-- impressions volume (context) --}}
                    @foreach ($points as $i => $p)
                        @if ($p['impressions'] > 0)
                            @php
                                $bh = round($p['impressions'] / $maxImpr * ($plotH * 0.28), 1);
                                $bx = round($x($i) - $barW / 2, 1);
                            @endphp
                            <rect x="{{ $bx }}" y="{{ round($H - $PADB - $bh, 1) }}" width="{{ round($barW, 1) }}" height="{{ $bh }}"
                                  fill="currentColor" class="text-slate-200 dark:text-slate-700" opacity="0.8"></rect>
                        @endif
                    @endforeach

                    {{-- Search Console average position (dashed) --}}
                    @foreach ($gscSegments as $seg)
                        @if (count($seg) > 1)
                            <polyline points="{{ implode(' ', $seg) }}" fill="none" stroke="rgb(16 185 129)" stroke-width="2"
                                      stroke-dasharray="5 4" stroke-linejoin="round" stroke-linecap="round"></polyline>
                        @endif
                    @endforeach

                    {{-- live Google rank --}}
                    @foreach ($segments as $seg)
                        @if (count($seg) > 1)
                            <polyline points="{{ implode(' ', $seg) }}" fill="none" stroke="rgb(249 115 22)" stroke-width="2.5"
                                      stroke-linejoin="round" stroke-linecap="round"></polyline>
                        @endif
                    @endforeach
                    @foreach ($dots as $d)
                        <circle cx="{{ $d['x'] }}" cy="{{ $d['y'] }}" r="4" fill="rgb(249 115 22)" stroke="#fff" stroke-width="1.5">
                            <title>{{ $fmtDate($d['date']) }} — {{ __('position') }} #{{ $d['rank'] }}</title>
                        </circle>
                    @endforeach
                    {{-- checked, but outside the top 100 --}}
                    @foreach ($dropouts as $d)
                        <circle cx="{{ $d['x'] }}" cy="{{ $d['y'] }}" r="4" fill="none" stroke="rgb(148 163 184)" stroke-width="2">
                            <title>{{ $fmtDate($d['date']) }} — {{ __('Not in top 100') }}</title>
                        </circle>
                    @endforeach

                    {{-- date axis --}}
                    @foreach ($points as $i => $p)
                        @if ($i % $tickEvery === 0 || $i === $n - 1)
                            <text x="{{ $x($i) }}" y="{{ $H - 8 }}" text-anchor="middle" font-size="11"
                                  fill="currentColor" class="text-slate-400">{{ $fmtDate($p['date']) }}</text>
                        @endif
                    @endforeach
                </svg>
            </div>

            {{-- legend --}}
            <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-slate-500 dark:text-slate-400">
                <span class="inline-flex items-center gap-1.5">
                    <span class="inline-block h-0.5 w-5 rounded bg-orange-500"></span>
                    {{ __('Live Google position (checked weekly)') }}
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="inline-block h-0.5 w-5 rounded bg-emerald-500"></span>
                    {{ __('Search Console average position (daily)') }}
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="inline-block h-2.5 w-2.5 rounded-sm bg-slate-200 dark:bg-slate-700"></span>
                    {{ __('Impressions') }}
                </span>
            </div>
            <p class="mt-2 text-xs text-slate-400">
                {{ __('The live check is one snapshot from a single location. The Search Console average blends everyone who actually saw your page, so the two rarely match exactly.') }}
            </p>
            @if ($stats['checks'] === 1)
                <p class="mt-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                    {{ __('This is the first recorded check — the trend line builds as the weekly checks come in.') }}
                </p>
            @endif
        @endif
    </div>

    {{-- ── Check log ── --}}
    @if (! empty($series['checks']))
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3.5 dark:border-slate-800">
                <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Every check') }}</h2>
                <span class="text-xs text-slate-400">{{ trans_choice('{1}:count check|[2,*]:count checks', $stats['checks'], ['count' => number_format($stats['checks'])]) }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-slate-400">
                            <th class="px-5 py-2 font-semibold">{{ __('Date') }}</th>
                            <th class="px-5 py-2 font-semibold">{{ __('Position') }}</th>
                            <th class="px-5 py-2 font-semibold">{{ __('Change') }}</th>
                            <th class="hidden px-5 py-2 font-semibold sm:table-cell">{{ __('Ranking page') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($series['checks'] as $i => $check)
                            @php
                                // The list is newest-first, so the "previous" check is the NEXT row.
                                $prev = $series['checks'][$i + 1]['position'] ?? null;
                                $delta = ($prev !== null && $check['position'] !== null) ? $prev - $check['position'] : null;
                            @endphp
                            <tr>
                                <td class="whitespace-nowrap px-5 py-2.5 text-slate-600 dark:text-slate-300">{{ $fmtDate($check['date']) }}</td>
                                <td class="px-5 py-2.5 font-bold text-slate-900 dark:text-slate-100">
                                    @if ($check['position'])#{{ $check['position'] }}@else<span class="font-medium text-slate-400">{{ __('Not in top 100') }}</span>@endif
                                </td>
                                <td class="px-5 py-2.5">
                                    @if ($delta === null)
                                        <span class="text-slate-300 dark:text-slate-600">—</span>
                                    @elseif ($delta > 0)
                                        <span class="font-semibold text-emerald-600 dark:text-emerald-400">▲ {{ $delta }}</span>
                                    @elseif ($delta < 0)
                                        <span class="font-semibold text-rose-600 dark:text-rose-400">▼ {{ abs($delta) }}</span>
                                    @else
                                        <span class="text-slate-400">{{ __('No change') }}</span>
                                    @endif
                                </td>
                                <td class="hidden max-w-md truncate px-5 py-2.5 sm:table-cell">
                                    @if ($check['url'])
                                        <a href="{{ $check['url'] }}" target="_blank" rel="noopener" class="text-slate-500 hover:text-orange-600 dark:text-slate-400 dark:hover:text-orange-400">
                                            {{ \Illuminate\Support\Str::limit(preg_replace('#^https?://#', '', $check['url']), 60) }}
                                        </a>
                                    @else
                                        <span class="text-slate-300 dark:text-slate-600">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
