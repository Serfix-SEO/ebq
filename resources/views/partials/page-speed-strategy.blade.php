{{-- PageSpeed report (premium dashboard) for one strategy.
     Expects $r = parsed LHR (see LighthouseClient::parseFullLhr). --}}
@php
    $scoreColor = function (?int $s): array {
        if ($s === null) return ['#94a3b8', 'text-slate-500'];
        if ($s >= 90) return ['#10b981', 'text-emerald-600 dark:text-emerald-400'];
        if ($s >= 50) return ['#f59e0b', 'text-amber-600 dark:text-amber-400'];
        return ['#f43f5e', 'text-rose-600 dark:text-rose-400'];
    };
    $ratingDot = [
        'good' => 'bg-emerald-500', 'average' => 'bg-amber-500',
        'poor' => 'bg-rose-500', 'na' => 'bg-slate-300 dark:bg-slate-600',
    ];
    $ratingText = [
        'good' => 'text-emerald-600 dark:text-emerald-400', 'average' => 'text-amber-600 dark:text-amber-400',
        'poor' => 'text-rose-600 dark:text-rose-400', 'na' => 'text-slate-500',
    ];
    $ratingBar = [
        'good' => 'bg-emerald-500', 'average' => 'bg-amber-500',
        'poor' => 'bg-rose-500', 'na' => 'bg-slate-300 dark:bg-slate-600',
    ];
    $ratingBorder = [
        'good' => 'border-s-emerald-400', 'average' => 'border-s-amber-400',
        'poor' => 'border-s-rose-400', 'na' => 'border-s-slate-300 dark:border-s-slate-600',
    ];
    $metricShort = ['fcp' => 'FCP', 'lcp' => 'LCP', 'tbt' => 'TBT', 'cls' => 'CLS', 'si' => 'SI', 'tti' => 'TTI'];
    $fmtSavings = function (int $ms): ?string {
        if ($ms <= 0) return null;
        return $ms >= 1000 ? number_format($ms / 1000, 2).' s' : $ms.' ms';
    };
    $cats = [
        ['key' => 'performance', 'label' => __('Performance')],
        ['key' => 'accessibility', 'label' => __('Accessibility')],
        ['key' => 'best_practices', 'label' => __('Best Practices')],
        ['key' => 'seo', 'label' => __('SEO')],
    ];
    $circ = 2 * M_PI * 26;
    $hasShot = ! empty($r['screenshot']);
@endphp

<div class="space-y-4">
    {{-- ── Scores + screenshot ───────────────────────────────────────── --}}
    <div class="grid gap-4 {{ $hasShot ? 'lg:grid-cols-3' : '' }}">
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 {{ $hasShot ? 'lg:col-span-2' : '' }}">
            @foreach ($cats as $cat)
                @php
                    $score = $r['scores'][$cat['key']] ?? null;
                    [$hex, $txt] = $scoreColor($score);
                    $offset = $score === null ? $circ : $circ * (1 - $score / 100);
                @endphp
                <div class="flex flex-col items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-white py-4 shadow-sm transition hover:shadow-md dark:border-slate-800 dark:bg-slate-900">
                    <div class="relative h-16 w-16" role="img" aria-label="{{ __(':label score :score out of 100', ['label' => $cat['label'], 'score' => $score ?? __('unavailable')]) }}">
                        <svg class="h-full w-full -rotate-90" viewBox="0 0 60 60" aria-hidden="true">
                            <circle cx="30" cy="30" r="26" fill="none" stroke="currentColor" stroke-width="5" class="text-slate-100 dark:text-slate-800" />
                            <circle cx="30" cy="30" r="26" fill="none" stroke="{{ $hex }}" stroke-width="5" stroke-linecap="round"
                                stroke-dasharray="{{ $circ }}" stroke-dashoffset="{{ $offset }}" style="transition: stroke-dashoffset .7s ease" />
                        </svg>
                        <span class="absolute inset-0 flex items-center justify-center text-xl font-bold {{ $txt }}">{{ $score ?? '—' }}</span>
                    </div>
                    <span class="text-[11px] font-semibold text-slate-600 dark:text-slate-300">{{ $cat['label'] }}</span>
                </div>
            @endforeach
        </div>

        @if ($hasShot)
            <div class="flex flex-col rounded-2xl border border-slate-200 bg-white p-3 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <p class="mb-2 text-[10px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Final screenshot') }}</p>
                <div class="flex flex-1 items-center justify-center overflow-hidden rounded-lg bg-slate-50 dark:bg-slate-950">
                    <img src="{{ $r['screenshot'] }}" alt="{{ __('Rendered page screenshot') }}" loading="lazy" decoding="async" class="max-h-64 w-auto" />
                </div>
            </div>
        @endif
    </div>

    {{-- ── Metric KPI tiles ──────────────────────────────────────────── --}}
    @if (! empty($r['metrics']))
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            @foreach ($r['metrics'] as $metric)
                <div class="relative overflow-hidden rounded-xl border border-slate-200 bg-white p-3 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <span class="absolute inset-x-0 top-0 h-0.5 {{ $ratingBar[$metric['rating']] ?? $ratingBar['na'] }}"></span>
                    <div class="flex items-center gap-1.5">
                        <span class="h-2 w-2 rounded-full {{ $ratingDot[$metric['rating']] ?? $ratingDot['na'] }}"></span>
                        <span class="text-[10px] font-bold uppercase tracking-wide text-slate-400">{{ $metricShort[$metric['key']] ?? $metric['key'] }}</span>
                    </div>
                    <p class="mt-1 text-lg font-bold tabular-nums {{ $ratingText[$metric['rating']] ?? $ratingText['na'] }}">{{ $metric['display'] }}</p>
                    <p class="truncate text-[10px] text-slate-400" title="{{ $metric['label'] }}">{{ $metric['label'] }}</p>
                </div>
            @endforeach
        </div>
    @endif

    {{-- ── Opportunities (accent cards) ──────────────────────────────── --}}
    <div>
        <div class="mb-2 flex items-baseline justify-between">
            <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Opportunities') }}</h3>
            <span class="text-[11px] text-slate-400">{{ __('Expand to see affected resources') }}</span>
        </div>
        @if (! empty($r['opportunities']))
            <div class="space-y-2.5">
                @foreach ($r['opportunities'] as $op)
                    <details class="group overflow-hidden rounded-xl border border-s-4 border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900 {{ $ratingBorder[$op['rating']] ?? $ratingBorder['na'] }}">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 p-3.5">
                            <span class="min-w-0 text-sm font-semibold text-slate-800 dark:text-slate-100">{{ $op['title'] }}</span>
                            <span class="flex shrink-0 items-center gap-2.5">
                                @if ($s = $fmtSavings($op['savings_ms'] ?? 0))
                                    <span class="rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-bold text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">−{{ $s }}</span>
                                @endif
                                <svg class="h-4 w-4 text-slate-400 transition group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                            </span>
                        </summary>
                        <div class="border-t border-slate-100 bg-slate-50/60 p-3.5 dark:border-slate-800 dark:bg-slate-950/40">
                            @if (! empty($op['description']))
                                <p class="text-[11px] leading-relaxed text-slate-500 dark:text-slate-400">{{ $op['description'] }}</p>
                            @endif
                            @include('partials.page-speed-resources', ['res' => $op['resources'] ?? null])
                        </div>
                    </details>
                @endforeach
            </div>
        @else
            <div class="flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-xs font-medium text-emerald-700 dark:border-emerald-900/40 dark:bg-emerald-500/10 dark:text-emerald-400">
                <svg class="h-4 w-4 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" /></svg>
                {{ __('No major load opportunities — this page is well optimized.') }}
            </div>
        @endif
    </div>

    {{-- ── Diagnostics (accent cards) ────────────────────────────────── --}}
    @if (! empty($r['diagnostics']))
        <div>
            <h3 class="mb-2 text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Diagnostics') }}</h3>
            <div class="space-y-2.5">
                @foreach ($r['diagnostics'] as $diag)
                    <details class="group overflow-hidden rounded-xl border border-s-4 border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900 {{ $ratingBorder[$diag['rating']] ?? $ratingBorder['na'] }}">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 p-3.5">
                            <span class="min-w-0 text-sm font-semibold text-slate-800 dark:text-slate-100">{{ $diag['title'] }}</span>
                            <span class="flex shrink-0 items-center gap-2.5">
                                @if (! empty($diag['display']))
                                    <span class="text-[11px] font-medium text-slate-500">{{ $diag['display'] }}</span>
                                @endif
                                <svg class="h-4 w-4 text-slate-400 transition group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                            </span>
                        </summary>
                        <div class="border-t border-slate-100 bg-slate-50/60 p-3.5 dark:border-slate-800 dark:bg-slate-950/40">
                            @if (! empty($diag['description']))
                                <p class="text-[11px] leading-relaxed text-slate-500 dark:text-slate-400">{{ $diag['description'] }}</p>
                            @endif
                            @include('partials.page-speed-resources', ['res' => $diag['resources'] ?? null])
                        </div>
                    </details>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ── Failed checks for the other categories ────────────────────── --}}
    @php
        $auditGroups = [
            ['key' => 'accessibility', 'label' => __('Accessibility')],
            ['key' => 'best_practices', 'label' => __('Best Practices')],
            ['key' => 'seo', 'label' => __('SEO')],
        ];
    @endphp
    @if (collect($auditGroups)->contains(fn ($g) => ! empty($r['failed_audits'][$g['key']] ?? [])))
        <div class="grid gap-4 sm:grid-cols-3">
            @foreach ($auditGroups as $group)
                @php $items = $r['failed_audits'][$group['key']] ?? []; @endphp
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between">
                        <h3 class="text-xs font-bold text-slate-700 dark:text-slate-200">{{ $group['label'] }}</h3>
                        @if (count($items))
                            <span class="rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-semibold text-rose-600 dark:bg-rose-500/10 dark:text-rose-400">{{ __(':count to fix', ['count' => count($items)]) }}</span>
                        @else
                            <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400">{{ __('All passed') }}</span>
                        @endif
                    </div>
                    @if (count($items))
                        <ul class="mt-2.5 space-y-2">
                            @foreach ($items as $item)
                                <li class="flex gap-1.5">
                                    <svg class="mt-0.5 h-3 w-3 shrink-0 text-rose-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" /></svg>
                                    <span class="text-[11px] leading-snug text-slate-600 dark:text-slate-300" @if (! empty($item['description'])) title="{{ $item['description'] }}" @endif>{{ $item['title'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
