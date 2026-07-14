@php
    $result = $auditReport->result ?? [];
    $meta = $result['metadata'] ?? [];
    $content = $result['content'] ?? [];
    $images = $result['images'] ?? [];
    $links = $result['links'] ?? [];
    $technical = $result['technical'] ?? [];
    $advanced = $result['advanced'] ?? [];
    $recs = $result['recommendations'] ?? [];
    $failed = $auditReport->status === 'failed';

    $counts = collect($recs)->groupBy('severity')->map->count();
    $critical = (int) ($counts['critical'] ?? 0);
    $warning = (int) ($counts['warning'] ?? 0);
    $serpGap = (int) ($counts['serp_gap'] ?? 0);
    $info = (int) ($counts['info'] ?? 0);
    $good = (int) ($counts['good'] ?? 0);

    $score = $failed ? 0 : max(0, 100 - ($critical * 15) - ($warning * 6) - ($serpGap * 5) - ($info * 2));
    $scoreTone = $score >= 85 ? 'good' : ($score >= 65 ? 'warn' : 'bad');
    $scoreStrokeClass = $scoreTone === 'good' ? 'text-emerald-500' : ($scoreTone === 'warn' ? 'text-amber-500' : 'text-rose-500');
    $scoreBadgeClass = $scoreTone === 'good'
        ? 'bg-emerald-100 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-900/40'
        : ($scoreTone === 'warn'
            ? 'bg-amber-100 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-900/40'
            : 'bg-rose-100 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-400 dark:ring-rose-900/40');
    $scoreLabel = $score >= 85 ? __('Healthy') : ($score >= 65 ? __('Needs attention') : __('Critical'));
    $circumference = 2 * M_PI * 28;
    $dashOffset = $circumference - ($score / 100) * $circumference;

    $sevMeta = [
        'critical' => ['label' => __('Critical'), 'badge' => 'bg-rose-100 text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-400 dark:ring-rose-900/40', 'bar' => 'border-s-rose-500 bg-rose-50/40 dark:bg-rose-500/5', 'icon' => 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z'],
        'warning'  => ['label' => __('Warning'),  'badge' => 'bg-amber-100 text-amber-700 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-900/40', 'bar' => 'border-s-amber-500 bg-amber-50/40 dark:bg-amber-500/5', 'icon' => 'M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z'],
        'serp_gap' => ['label' => __('SERP gap'), 'badge' => 'bg-orange-100 text-orange-800 ring-1 ring-orange-200 dark:bg-orange-500/10 dark:text-orange-300 dark:ring-orange-900/40', 'bar' => 'border-s-orange-500 bg-orange-50/40 dark:bg-orange-500/5', 'icon' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v7.125c0 .621-.504 1.125-1.125 1.125h-2.25A1.125 1.125 0 013 20.25v-7.125zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z'],
        'info'     => ['label' => __('Info'),     'badge' => 'bg-sky-100 text-sky-700 ring-1 ring-sky-200 dark:bg-sky-500/10 dark:text-sky-400 dark:ring-sky-900/40',          'bar' => 'border-s-sky-500 bg-sky-50/40 dark:bg-sky-500/5',     'icon' => 'M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z'],
        'good'     => ['label' => __('Good'),     'badge' => 'bg-emerald-100 text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-900/40', 'bar' => 'border-s-emerald-500 bg-emerald-50/40 dark:bg-emerald-500/5', 'icon' => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
    ];

    $keywordData = $result['keywords'] ?? [];
    $kwAvailable = (bool) ($keywordData['available'] ?? false);
    $benchmark = $result['benchmark'] ?? null;
    $benchmarkNav = is_array($benchmark);
    $pageLocale = is_array($result['page_locale'] ?? null) ? $result['page_locale'] : null;
    $pageLocaleLabel = \App\Support\Audit\PageLocalePresentation::shortLabel($pageLocale);

    $skippedReasonLabel = fn (?string $reason) => match ($reason) {
        'no_primary_keyword' => __('Benchmark unavailable: no Search Console primary keyword for this page yet.'),
        'serper_request_failed' => __('Benchmark unavailable: the SERP API did not respond.'),
        'no_organic_results' => __('Benchmark unavailable: the SERP API returned no organic results for this keyword.'),
        'no_competitor_pages_fetched' => __('Benchmark unavailable: none of the top-ranking pages could be fetched.'),
        'benchmark_error' => __('Benchmark unavailable: an unexpected error occurred while benchmarking.'),
        null, '' => null,
        default => __('Benchmark unavailable:') . ' ' . str_replace('_', ' ', $reason) . '.',
    };

    // Order optimized for how a reader actually uses the report:
    //   1. Action items first (Recommendations)
    //   2. Performance block (Core Web Vitals → Technical) — user experience & foundation
    //   3. Competitive block (SERP benchmark → Keywords) — context for the audit
    //   4. On-page block (Metadata → Content → Images & Links) — drill-down
    //   5. Extras (Advanced)
    $sections = [
        ['key' => 'recommendations', 'label' => __('Recommendations'), 'count' => count($recs), 'show' => ! empty($recs)],
        ['key' => 'core-web-vitals', 'label' => __('Core Web Vitals'), 'count' => null,         'show' => is_array($result['core_web_vitals'] ?? null)],
        ['key' => 'technical',       'label' => __('Technical'),       'count' => null,         'show' => true],
        ['key' => 'benchmark',       'label' => __('SERP benchmark'),  'count' => is_array($benchmark) ? count($benchmark['competitors'] ?? []) : null, 'show' => $benchmarkNav],
        ['key' => 'keywords',        'label' => __('Keywords'),        'count' => $kwAvailable ? (int) ($keywordData['coverage']['total'] ?? 0) : null, 'show' => true],
        ['key' => 'country',         'label' => __('Traffic by country'), 'count' => null,      'show' => ($showCountrySection ?? true)],
        ['key' => 'metadata',        'label' => __('Metadata'),        'count' => null,         'show' => true],
        ['key' => 'content',         'label' => __('Content'),         'count' => null,         'show' => true],
        ['key' => 'links',           'label' => __('Images & Links'),  'count' => null,         'show' => true],
        ['key' => 'advanced',        'label' => __('Advanced'),        'count' => null,         'show' => true],
    ];
    $auditSummaryOpen = (bool) ($openAuditSummary ?? false);
@endphp

<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
    <details class="group" @if ($auditSummaryOpen) open @endif>
        {{-- ═══ Report Header ═══ --}}
        <summary class="flex cursor-pointer list-none items-start gap-4 border-b border-slate-200 bg-gradient-to-br from-slate-50 to-white px-5 py-4 dark:border-slate-800 dark:from-slate-900 dark:to-slate-900 [&::-webkit-details-marker]:hidden">
            {{-- Score donut --}}
            @if (! $failed)
                <div class="relative hidden shrink-0 sm:block">
                    <svg class="h-16 w-16 -rotate-90" viewBox="0 0 64 64">
                        <circle cx="32" cy="32" r="28" fill="none" stroke="currentColor" stroke-width="6" class="text-slate-200 dark:text-slate-800" />
                        <circle cx="32" cy="32" r="28" fill="none" stroke="currentColor" stroke-width="6" stroke-linecap="round"
                                stroke-dasharray="{{ $circumference }}" stroke-dashoffset="{{ $dashOffset }}"
                                class="{{ $scoreStrokeClass }} transition-all" />
                    </svg>
                    <div class="absolute inset-0 flex flex-col items-center justify-center">
                        <span class="text-lg font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ $score }}</span>
                        <span class="text-[9px] font-semibold uppercase tracking-wider text-slate-500">score</span>
                    </div>
                </div>
            @endif

            {{-- Title + badges --}}
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <svg class="h-3.5 w-3.5 text-slate-500 transition-transform group-open:rotate-90" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                    <h2 class="text-base font-bold tracking-tight text-slate-900 dark:text-slate-100">{{ __('Page Audit Report') }}</h2>
                    @if ($failed)
                        <span class="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-semibold text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-400 dark:ring-rose-900/40">
                            <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                            {{ __('Failed') }}
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 {{ $scoreBadgeClass }}">
                            {{ $scoreLabel }}
                        </span>
                    @endif
                </div>

                {{-- Severity pills --}}
                @if (! $failed && ! empty($recs))
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach (['critical', 'warning', 'serp_gap', 'info', 'good'] as $sev)
                            @if (($counts[$sev] ?? 0) > 0)
                                <span class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-[10px] font-semibold {{ $sevMeta[$sev]['badge'] }}">
                                    <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $sevMeta[$sev]['icon'] }}" /></svg>
                                    {{ $counts[$sev] }} {{ $sevMeta[$sev]['label'] }}
                                </span>
                            @endif
                        @endforeach
                    </div>
                @endif

                <p class="mt-2 text-[11px] text-slate-500 dark:text-slate-400">
                    {{ __('Audited') }} <span class="font-medium text-slate-700 dark:text-slate-300">{{ $auditReport->audited_at?->diffForHumans() ?? '—' }}</span>
                    @if ($auditReport->audited_at)
                        <span class="text-slate-400">·</span> {{ format_user_datetime($auditReport->audited_at, 'M j, Y g:i A') }}
                    @endif
                </p>
                @if (filled($auditReport->primary_keyword))
                    <p class="mt-1.5 text-[11px] text-slate-600 dark:text-slate-300">
                        <span class="font-semibold text-slate-700 dark:text-slate-200">{{ __('Primary keyword') }}</span>
                        <span class="font-mono text-slate-800 dark:text-slate-100">“{{ $auditReport->primary_keyword }}”</span>
                        @if (($auditReport->primary_keyword_source ?? null) === 'custom_audit')
                            <span class="ms-1 rounded bg-orange-100 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide text-orange-800 dark:bg-orange-500/20 dark:text-orange-200">{{ __('Custom') }}</span>
                        @elseif (($auditReport->primary_keyword_source ?? null) === 'gsc_primary')
                            <span class="ms-1 text-slate-400 dark:text-slate-500">· {{ __('Search Console') }}</span>
                        @endif
                    </p>
                @endif
                @if ($pageLocaleLabel)
                    <p class="mt-1.5 text-[11px] text-slate-600 dark:text-slate-300">
                        <span class="font-semibold text-slate-700 dark:text-slate-200">{{ __('Detected market') }}</span>
                        <span>{{ $pageLocaleLabel }}</span>
                        @if (! empty($pageLocale['source'] ?? null))
                            <span class="text-slate-400 dark:text-slate-500">· {{ str_replace('_', ' ', (string) $pageLocale['source']) }}</span>
                        @endif
                    </p>
                @endif
            </div>
        </summary>

        @if ($failed)
            <div class="flex items-start gap-3 px-5 py-4 text-sm">
                <svg class="h-5 w-5 shrink-0 text-rose-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                <div>
                    <p class="font-semibold text-slate-900 dark:text-slate-100">{{ __('Audit failed') }}</p>
                    <p class="mt-0.5 text-slate-600 dark:text-slate-400">{{ $auditReport->error_message ?? __('Unknown error') }}</p>
                    @if (filled($auditReport->primary_keyword))
                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                            {{ __('Intended primary keyword:') }} <span class="font-mono font-medium text-slate-700 dark:text-slate-200">{{ $auditReport->primary_keyword }}</span>
                        </p>
                    @endif
                </div>
            </div>
        @else
            {{-- ═══ Section nav ═══ --}}
            <nav class="sticky top-0 z-10 flex gap-1 overflow-x-auto border-b border-slate-200 bg-white/95 px-5 py-2 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95">
                @foreach ($sections as $s)
                    @if ($s['show'])
                        <a href="#audit-{{ $s['key'] }}" class="inline-flex shrink-0 items-center gap-1.5 rounded-md px-2.5 py-1 text-[11px] font-semibold text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100">
                            {{ $s['label'] }}
                            @if ($s['count'] !== null)
                                <span class="rounded-full bg-slate-200 px-1.5 text-[9px] font-bold tabular-nums text-slate-700 dark:bg-slate-700 dark:text-slate-200">{{ $s['count'] }}</span>
                            @endif
                        </a>
                    @endif
                @endforeach
            </nav>

            <div class="space-y-6 px-5 py-5">
                {{-- Each section renders into a named buffer; a single loop
                     below emits them in the order defined by $sections. This
                     lets us reorder sections without moving any markup. --}}
                @php $sectionHtml = []; @endphp

                @php ob_start(); @endphp
                {{-- ══════ Recommendations ══════ --}}
                @if (! empty($recs))
                    <section id="audit-recommendations" class="scroll-mt-16">
                        <div class="mb-3 flex items-center gap-2">
                            <div class="flex h-7 w-7 items-center justify-center rounded-md bg-orange-100 text-orange-600 dark:bg-orange-500/10 dark:text-orange-400">
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18v-5.25m0 0a6.01 6.01 0 001.5-.189m-1.5.189a6.01 6.01 0 01-1.5-.189m3.75 7.478a12.06 12.06 0 01-4.5 0m3.75 2.383a14.406 14.406 0 01-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 10-7.517 0c.85.493 1.509 1.333 1.509 2.316V18" /></svg>
                            </div>
                            <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Recommendations') }}</h3>
                            <span class="ml-auto text-[11px] text-slate-500 dark:text-slate-400">{{ count($recs) }} {{ count($recs) === 1 ? __('item') : __('items') }}</span>
                        </div>
                        <div class="space-y-2">
                            @foreach ($recs as $r)
                                @php $sm = $sevMeta[$r['severity']] ?? $sevMeta['info']; @endphp
                                <div class="flex gap-3 rounded-lg border border-slate-200 border-s-4 {{ $sm['bar'] }} p-3 transition hover:shadow-sm dark:border-slate-700">
                                    <svg class="mt-0.5 h-4 w-4 shrink-0 {{ $r['severity'] === 'critical' ? 'text-rose-500' : ($r['severity'] === 'warning' ? 'text-amber-500' : ($r['severity'] === 'serp_gap' ? 'text-orange-600 dark:text-orange-400' : ($r['severity'] === 'info' ? 'text-sky-500' : 'text-emerald-500'))) }}" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $sm['icon'] }}" /></svg>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $r['title'] }}</p>
                                            <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ $r['section'] }}</span>
                                        </div>
                                        <p class="mt-1 text-xs leading-relaxed text-slate-600 dark:text-slate-300">{{ $r['why'] }}</p>
                                        <p class="mt-1.5 text-xs leading-relaxed text-slate-700 dark:text-slate-200"><span class="font-semibold">{{ __('Fix:') }}</span> {{ $r['fix'] }}</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif
                @php $sectionHtml['recommendations'] = ob_get_clean(); @endphp

                @php ob_start(); @endphp
                {{-- ══════ Keywords ══════ --}}
                <section id="audit-keywords" class="scroll-mt-16">
                    <div class="mb-3 flex items-center gap-2">
                        <div class="flex h-7 w-7 items-center justify-center rounded-md bg-teal-100 text-teal-600 dark:bg-teal-500/10 dark:text-teal-400">
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z M6 6h.008v.008H6V6z" /></svg>
                        </div>
                        <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Keyword Strategy') }}</h3>
                        @if ($kwAvailable)
                            <span class="ml-auto text-[11px] text-slate-500 dark:text-slate-400">{{ $keywordData['coverage']['total'] ?? 0 }} {{ ($keywordData['coverage']['total'] ?? 0) === 1 ? __('target keyword') : __('target keywords') }}</span>
                        @endif
                    </div>
                    @if (isset($keywordData['gsc_lookback_days']) && is_numeric($keywordData['gsc_lookback_days']))
                        <p class="mb-3 text-[11px] text-slate-500 dark:text-slate-400">
                            {{ __('Based on Search Console for the last') }} <span class="font-semibold text-slate-700 dark:text-slate-300">{{ (int) $keywordData['gsc_lookback_days'] }}</span> {{ __('days (saved with this audit).') }}
                        </p>
                    @endif

                    @if (! $kwAvailable)
                        <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center text-xs text-slate-500 dark:border-slate-700 dark:bg-slate-800/40 dark:text-slate-400">
                            {{ $keywordData['reason'] ?? __('No Search Console data for this page yet.') }}
                        </div>
                    @else
                        @php
                            $pp = $keywordData['power_placement'] ?? [];
                            $cov = $keywordData['coverage'] ?? [];
                            $intent = $keywordData['intent'] ?? [];
                            $accidental = $keywordData['accidental'] ?? [];
                            $primary = $keywordData['primary'] ?? null;
                            $covScore = (float) ($cov['score'] ?? 0);
                            $covTone = $covScore >= 80 ? 'good' : ($covScore >= 50 ? 'warn' : 'bad');
                            $covClass = $covTone === 'good' ? 'text-emerald-600' : ($covTone === 'warn' ? 'text-amber-600' : 'text-rose-600');
                            $covBar = $covTone === 'good' ? 'bg-emerald-500' : ($covTone === 'warn' ? 'bg-amber-500' : 'bg-rose-500');
                        @endphp

                        {{-- Primary keyword + Power Placement --}}
                        @if ($primary)
                            <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ __('Primary keyword') }}</p>
                                        <p class="mt-0.5 truncate text-sm font-bold text-slate-900 dark:text-slate-100">{{ $primary['query'] }}</p>
                                    </div>
                                    @if (($keywordData['primary_source'] ?? null) === 'custom_audit')
                                        <span class="shrink-0 rounded-full bg-orange-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-orange-800 dark:bg-orange-500/20 dark:text-orange-200">{{ __('Custom audit') }}</span>
                                    @else
                                        <div class="flex items-center gap-3 text-[11px] text-slate-500">
                                            <span><span class="font-semibold text-slate-700 dark:text-slate-200">{{ number_format($primary['clicks'] ?? 0) }}</span> {{ __('clicks') }}</span>
                                            <span><span class="font-semibold text-slate-700 dark:text-slate-200">{{ number_format($primary['impressions'] ?? 0) }}</span> {{ __('impressions') }}</span>
                                            <span>{{ __('Pos') }} <span class="font-semibold text-slate-700 dark:text-slate-200">{{ number_format($primary['position'] ?? 0, 1) }}</span></span>
                                        </div>
                                    @endif
                                </div>
                                <div class="mt-3 grid grid-cols-3 gap-2 text-xs">
                                    @foreach ([['in_title', __('Title')], ['in_h1', 'H1'], ['in_meta_description', __('Meta description')]] as [$key, $label])
                                        @php $present = (bool) ($pp[$key] ?? false); @endphp
                                        <div class="rounded-md border {{ $present ? 'border-emerald-200 bg-emerald-50/50 dark:border-emerald-900/40 dark:bg-emerald-500/5' : 'border-rose-200 bg-rose-50/50 dark:border-rose-900/40 dark:bg-rose-500/5' }} px-2.5 py-2">
                                            <div class="flex items-center justify-between">
                                                <p class="text-[10px] font-semibold uppercase tracking-wider {{ $present ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400' }}">{{ $label }}</p>
                                                @if ($present)
                                                    <svg class="h-3.5 w-3.5 text-emerald-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                                @else
                                                    <svg class="h-3.5 w-3.5 text-rose-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                                @endif
                                            </div>
                                            <p class="mt-1 text-[11px] font-semibold {{ $present ? 'text-emerald-800 dark:text-emerald-300' : 'text-rose-800 dark:text-rose-300' }}">
                                                {{ $present ? __('Present') : __('Missing') }}
                                            </p>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        {{-- Coverage --}}
                        <div class="mt-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <div class="flex items-center justify-between">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ __('Topical coverage') }}</p>
                                <span class="text-xs font-bold tabular-nums {{ $covClass }}">{{ $cov['found_count'] ?? 0 }}/{{ $cov['total'] ?? 0 }} · {{ $covScore }}%</span>
                            </div>
                            <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800">
                                <div class="h-full rounded-full transition-all {{ $covBar }}" style="width: {{ min(100, $covScore) }}%"></div>
                            </div>
                            <p class="mt-2 text-[11px] text-slate-500 dark:text-slate-400">
                                @if ($covScore >= 80)
                                    {{ __('High topical authority — most ranking queries appear in the body.') }}
                                @elseif ($covScore < 50)
                                    {{ __('Low coverage — many ranking queries aren\'t mentioned in the body.') }}
                                @else
                                    {{ __('Partial coverage — some ranking queries aren\'t mentioned.') }}
                                @endif
                            </p>

                            @if (! empty($cov['missing']))
                                <details class="group/miss mt-2 rounded-md border border-slate-200 dark:border-slate-700">
                                    <summary class="flex cursor-pointer list-none items-center justify-between px-2.5 py-1.5 text-[11px] font-semibold text-slate-600 dark:text-slate-300 [&::-webkit-details-marker]:hidden">
                                        <span>{{ __('Missing from body ·') }} {{ $cov['missing_count'] ?? count($cov['missing']) }}</span>
                                        <svg class="h-3 w-3 transition-transform group-open/miss:rotate-180" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                                    </summary>
                                    <ul class="max-h-48 space-y-0.5 overflow-auto border-t border-slate-200 px-2.5 py-1.5 text-xs dark:border-slate-700">
                                        @foreach ($cov['missing'] as $m)
                                            <li class="flex items-center justify-between gap-2">
                                                <span class="truncate text-slate-700 dark:text-slate-200">{{ $m['query'] }}</span>
                                                <span class="shrink-0 text-[10px] text-slate-500">{{ number_format($m['impressions'] ?? 0) }} impr</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </details>
                            @endif
                        </div>

                        {{-- Intent --}}
                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ __('Search intent') }}</p>
                                @php
                                    $dom = $intent['dominant'] ?? 'unclear';
                                    $intentDominantLabels = [
                                        'informational' => __('Informational'),
                                        'utility' => __('Tool / app'),
                                        'commercial' => __('Commercial'),
                                        'transactional' => __('Transactional'),
                                        'navigational' => __('Navigational'),
                                        'local' => __('Local'),
                                        'support' => __('Support'),
                                        'commercial_utility' => __('Commercial + tool / app'),
                                        'commercial_informational' => __('Commercial + informational'),
                                        'commercial_transactional' => __('Commercial + transactional'),
                                        'informational_utility' => __('Informational + tool / app'),
                                        'mixed' => __('Mixed (3+ tied top scores)'),
                                        'unclear' => __('Unclear'),
                                    ];
                                    $dominantLabel = $intentDominantLabels[$dom] ?? collect(explode('_', $dom))->map(function (string $part) {
                                        return $part === 'utility' ? __('Tool / app') : ucfirst($part);
                                    })->implode(' + ');
                                    $intentBucketLabels = [
                                        __('Informational') => (int) ($intent['informational_count'] ?? 0),
                                        __('Tool / app') => (int) ($intent['utility_count'] ?? 0),
                                        __('Commercial') => (int) ($intent['commercial_count'] ?? 0),
                                        __('Transactional') => (int) ($intent['transactional_count'] ?? 0),
                                        __('Navigational') => (int) ($intent['navigational_count'] ?? 0),
                                        __('Local') => (int) ($intent['local_count'] ?? 0),
                                        __('Support') => (int) ($intent['support_count'] ?? 0),
                                    ];
                                    $intentSummaryParts = [];
                                    foreach ($intentBucketLabels as $label => $n) {
                                        if ($n > 0) {
                                            $intentSummaryParts[] = $label . ': ' . $n;
                                        }
                                    }
                                    $intentSummary = $intentSummaryParts !== [] ? implode(' · ', $intentSummaryParts) : __('No trigger matches');
                                    $scoreParts = [];
                                    foreach ($intent['intent_scores'] ?? [] as $sk => $sv) {
                                        if ((float) $sv > 0) {
                                            $scoreParts[] = $sk . ': ' . $sv;
                                        }
                                    }
                                    $intentScoreLine = $scoreParts !== [] ? __('Weighted:') . ' ' . implode(' · ', $scoreParts) : '';
                                @endphp
                                <p class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">
                                    {{ $dominantLabel }}
                                </p>
                                <p class="mt-1 text-[11px] leading-relaxed text-slate-500 dark:text-slate-400">
                                    {{ $intentSummary }}
                                </p>
                                @if ($intentScoreLine !== '')
                                    <p class="mt-1 text-[10px] leading-relaxed text-slate-400 dark:text-slate-500">{{ $intentScoreLine }}</p>
                                @endif
                            </div>
                            <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ __('Accidental authority') }}</p>
                                @if (empty($accidental))
                                    <p class="mt-1 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('None detected') }}</p>
                                    <p class="mt-0.5 text-[11px] text-slate-500">{{ __('No high-density terms missing from title/H1.') }}</p>
                                @else
                                    <p class="mt-1 text-sm font-bold text-amber-600">{{ count($accidental) }} {{ count($accidental) === 1 ? __('candidate') : __('candidates') }}</p>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        @foreach ($accidental as $a)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">
                                                <strong>{{ $a['term'] }}</strong> <span class="opacity-70">{{ $a['density'] }}%</span>
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>

                        {{-- Target keyword table --}}
                        @if (! empty($keywordData['target_keywords']))
                            <details class="group/kw mt-3 rounded-lg border border-slate-200 dark:border-slate-700">
                                <summary class="flex cursor-pointer list-none items-center justify-between px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500 [&::-webkit-details-marker]:hidden">
                                    <span>{{ __('Target keywords from Search Console ·') }} {{ count($keywordData['target_keywords']) }}</span>
                                    <svg class="h-3 w-3 transition-transform group-open/kw:rotate-180" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                                </summary>
                                <div class="max-h-72 overflow-auto border-t border-slate-200 dark:border-slate-700">
                                    @php
                                        $foundSet = collect($cov['missing'] ?? [])->pluck('query')->map(fn ($q) => mb_strtolower($q))->flip();
                                        $_targetQueries = collect($keywordData['target_keywords'])->pluck('query')->map(fn ($q) => (string) $q)->values()->all();
                                        $_targetKe = $_targetQueries === []
                                            ? []
                                            : \App\Models\KeywordMetric::query()
                                                ->whereIn('keyword_hash', array_unique(array_map(fn ($q) => \App\Models\KeywordMetric::hashKeyword($q), $_targetQueries)))
                                                ->where('country', 'global')
                                                ->get()
                                                ->keyBy('keyword_hash')
                                                ->all();
                                    @endphp
                                    <table class="w-full text-xs">
                                        <thead>
                                            <tr class="sticky top-0 bg-slate-50 text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-800/80 dark:text-slate-400">
                                                <th class="px-3 py-1.5 text-start">{{ __('Keyword') }}</th>
                                                <th class="px-3 py-1.5 text-end">{{ __('Clicks') }}</th>
                                                <th class="px-3 py-1.5 text-end">{{ __('Impr.') }}</th>
                                                <th class="px-3 py-1.5 text-end">{{ __('Pos') }}</th>
                                                <th class="px-3 py-1.5 text-end" title="{{ __('Monthly search volume') }}">{{ __('Vol') }}</th>
                                                <th class="px-3 py-1.5 text-center">{{ __('In body') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                            @foreach ($keywordData['target_keywords'] as $t)
                                                @php
                                                    $missing = isset($foundSet[mb_strtolower($t['query'])]);
                                                    $_ke = $_targetKe[\App\Models\KeywordMetric::hashKeyword((string) $t['query'])] ?? null;
                                                @endphp
                                                <tr>
                                                    <td class="px-3 py-1.5 text-slate-800 dark:text-slate-100">{{ $t['query'] }}</td>
                                                    <td class="px-3 py-1.5 text-end tabular-nums text-slate-600 dark:text-slate-300">{{ number_format($t['clicks']) }}</td>
                                                    <td class="px-3 py-1.5 text-end tabular-nums text-slate-600 dark:text-slate-300">{{ number_format($t['impressions']) }}</td>
                                                    <td class="px-3 py-1.5 text-end tabular-nums text-slate-600 dark:text-slate-300">{{ number_format($t['position'], 1) }}</td>
                                                    <td class="px-3 py-1.5 text-end tabular-nums text-slate-600 dark:text-slate-300">
                                                        @if ($_ke && $_ke->search_volume !== null)
                                                            <span title="{{ __('Updated') }} {{ $_ke->fetched_at->diffForHumans() }}">{{ number_format($_ke->search_volume) }}</span>
                                                        @else
                                                            <span class="text-slate-400">—</span>
                                                        @endif
                                                    </td>
                                                    <td class="px-3 py-1.5 text-center">
                                                        @if ($missing)
                                                            <span class="inline-flex rounded-full bg-rose-100 px-1.5 py-px text-[9px] font-bold text-rose-700 dark:bg-rose-500/10 dark:text-rose-400">{{ __('NO') }}</span>
                                                        @else
                                                            <span class="inline-flex rounded-full bg-emerald-100 px-1.5 py-px text-[9px] font-bold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">{{ __('YES') }}</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        @endif
                    @endif
                </section>
                @php $sectionHtml['keywords'] = ob_get_clean(); @endphp

                {{-- Country data comes from GSC; hidden for guest/no-website audits via $showCountrySection. --}}
                @if (($showCountrySection ?? true))
                @php ob_start(); @endphp
                {{-- ══════ Traffic by country (GSC) ══════ --}}
                @php
                    $country_data = app(\App\Services\PluginInsightResolver::class)
                        ->countryBreakdownForAuditReport($auditReport->website, (string) $auditReport->page, 10);
                    $country_rows = $country_data['rows'];
                    $country_totalClicks = $country_data['total_clicks'];
                @endphp
                <section id="audit-country" class="scroll-mt-16">
                    <div class="mb-3 flex items-center gap-2">
                        <div class="flex h-7 w-7 items-center justify-center rounded-md bg-orange-100 text-orange-600 dark:bg-orange-500/10 dark:text-orange-400">
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18zm0 0a8.949 8.949 0 004.951-1.488A3.987 3.987 0 0013 16.128V15a2 2 0 012-2h3.88A9 9 0 0012 21zm3-10a1 1 0 11-2 0 1 1 0 012 0zm-7 4a3 3 0 100-6 3 3 0 000 6z" /></svg>
                        </div>
                        <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Traffic by country') }}</h3>
                        <span class="ml-auto text-[11px] text-slate-500 dark:text-slate-400">{{ __('Search Console · last 30 days') }}</span>
                    </div>

                    @if (empty($country_rows))
                        <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center text-xs text-slate-500 dark:border-slate-700 dark:bg-slate-800/40 dark:text-slate-400">
                            {{ __('No Search Console country data yet for this page.') }}
                        </div>
                    @else
                        @php $country_primary = $country_rows[0]; @endphp
                        <p class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[11px] text-slate-500 dark:text-slate-400">
                            <span>{{ count($country_rows) }} {{ \Illuminate\Support\Str::plural('market', count($country_rows)) }}</span>
                            <span aria-hidden="true" class="text-slate-300 dark:text-slate-600">·</span>
                            <span><span class="font-semibold text-slate-700 dark:text-slate-200">{{ number_format($country_totalClicks) }}</span> {{ __('clicks') }}</span>
                            <span aria-hidden="true" class="text-slate-300 dark:text-slate-600">·</span>
                            <span>
                                {{ __('top:') }}
                                @if (! empty($country_primary['flag']))
                                    <span aria-hidden="true">{{ $country_primary['flag'] }}</span>
                                @endif
                                <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $country_primary['name'] }}</span>
                                <span class="text-slate-400 dark:text-slate-500">({{ $country_primary['share_pct'] }}%)</span>
                            </span>
                        </p>

                        <div class="overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700">
                            <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                                @foreach ($country_rows as $i => $row)
                                    <li class="flex items-center gap-3 px-4 py-2 text-xs">
                                        <span class="w-4 shrink-0 text-end text-[10px] font-mono text-slate-400 dark:text-slate-500">{{ $i + 1 }}</span>

                                        <span class="flex min-w-0 flex-1 items-center gap-2">
                                            @if (! empty($row['flag']))
                                                <span aria-hidden="true" class="shrink-0 text-sm leading-none">{{ $row['flag'] }}</span>
                                            @endif
                                            <span class="min-w-0 truncate font-medium text-slate-800 dark:text-slate-100" title="{{ $row['hover_title'] }}">{{ $row['name'] }}</span>
                                            <span class="shrink-0 text-[10px] font-mono text-slate-400 dark:text-slate-500">{{ $row['country'] }}</span>
                                        </span>

                                        <div class="hidden w-40 items-center gap-2 sm:flex md:w-56">
                                            <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                                                <div class="h-full rounded-full {{ $i === 0 ? 'bg-orange-600' : 'bg-orange-400/80 dark:bg-orange-500/70' }}" style="width: {{ $row['width_pct'] }}%" role="progressbar" aria-valuenow="{{ $row['share_pct'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ $row['name'] }} {{ __('share of clicks:') }} {{ $row['share_pct'] }}%"></div>
                                            </div>
                                        </div>

                                        <span class="w-12 shrink-0 text-end font-semibold tabular-nums text-slate-600 dark:text-slate-300">{{ $row['share_pct'] }}%</span>
                                        <span class="w-16 shrink-0 text-end tabular-nums text-slate-900 dark:text-slate-100">{{ number_format($row['clicks']) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                        <p class="mt-2 text-[10px] text-slate-400 dark:text-slate-500">{{ __('Hover a row for impressions and average position.') }}</p>
                    @endif
                </section>
                @php $sectionHtml['country'] = ob_get_clean(); @endphp
                @endif

                @php ob_start(); @endphp
                @if ($benchmarkNav)
                    {{-- ══════ SERP readability benchmark ══════ --}}
                    <section id="audit-benchmark" class="scroll-mt-16">
                        <div class="mb-3 flex items-center gap-2">
                            <div class="flex h-7 w-7 items-center justify-center rounded-md bg-orange-100 text-orange-600 dark:bg-orange-500/10 dark:text-orange-400">
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3m0 0l.5 1.5m-.5-1.5h-9.5m0 0l-.5 1.5M9 11.25v1.5M12 9v3.75m3-6v6" /></svg>
                            </div>
                            <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('SERP readability benchmark') }}</h3>
                        </div>
                        @if (! empty($benchmark['your_serp']) && is_array($benchmark['your_serp']))
                            @php
                                $ys = $benchmark['your_serp'];
                                $ysFound = ! empty($ys['found']);
                                $ysPos = $ys['position'] ?? null;
                                $ysFirst = $ys['on_first_page'] ?? null;
                                $ysN = (int) ($ys['organic_sample_size'] ?? 0);
                                $ysHeroBorder = $ysFound && is_numeric($ysPos)
                                    ? ($ysFirst === true
                                        ? 'border-emerald-400/80 bg-gradient-to-br from-emerald-50 to-white ring-2 ring-emerald-500/20 dark:border-emerald-500/50 dark:from-emerald-950/40 dark:to-slate-900 dark:ring-emerald-400/15'
                                        : 'border-amber-400/80 bg-gradient-to-br from-amber-50 to-white ring-2 ring-amber-500/20 dark:border-amber-500/50 dark:from-amber-950/30 dark:to-slate-900 dark:ring-amber-400/15')
                                    : 'border-slate-300 bg-slate-50 ring-1 ring-slate-200/80 dark:border-slate-600 dark:bg-slate-800/60 dark:ring-slate-700/80';
                            @endphp
                            <div class="mb-4 overflow-hidden rounded-2xl border-2 shadow-sm {{ $ysHeroBorder }}">
                                <div class="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:gap-6 sm:p-5">
                                    @if ($ysFound && is_numeric($ysPos))
                                        <div class="flex shrink-0 flex-col items-center justify-center rounded-xl bg-white/90 px-6 py-4 shadow-inner dark:bg-slate-950/50 sm:min-w-[140px] sm:px-8 sm:py-5">
                                            <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">{{ __('Organic rank') }}</p>
                                            <p class="mt-1 text-5xl font-black leading-none tabular-nums tracking-tight {{ $ysFirst === true ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400' }}">#{{ $ysPos }}</p>
                                            @if ($ysFirst === true)
                                                <span class="mt-2 inline-flex items-center rounded-full bg-emerald-600 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white shadow-sm dark:bg-emerald-500">{{ __('First page') }}</span>
                                            @else
                                                <span class="mt-2 inline-flex items-center rounded-full bg-amber-600 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white shadow-sm dark:bg-amber-500">{{ __('Page 2+') }}</span>
                                            @endif
                                        </div>
                                        <div class="min-w-0 flex-1 text-sm leading-relaxed text-slate-700 dark:text-slate-300">
                                            <p class="font-bold text-slate-900 dark:text-slate-100">{{ __('Search position in this snapshot') }}</p>
                                            @if ($ysFirst === true)
                                                <p class="mt-1.5">{{ __('Your site (same domain in this sample) appears in the') }} <strong>{{ __('top 10') }}</strong> {{ __('organic results for the primary query below — the listing URL may differ from the page you audited. This snapshot approximates Google; use Search Console for official average position.') }}</p>
                                            @else
                                                <p class="mt-1.5">{{ __('Your site shows at') }} <strong class="tabular-nums">#{{ $ysPos }}</strong> {{ __('in this sample —') }} <strong>{{ __('outside the first results page') }}</strong> {{ __('(positions 1–10) in this snapshot.') }}</p>
                                            @endif
                                            <p class="mt-2 text-[11px] text-slate-500 dark:text-slate-400">{{ __('Sample:') }} {{ $ysN }} {{ __('organic listings checked.') }}</p>
                                        </div>
                                    @elseif ($ysN === 0)
                                        <div class="flex w-full flex-col items-center justify-center py-2 text-center sm:flex-row sm:gap-6 sm:text-start">
                                            <div class="flex h-20 w-20 shrink-0 items-center justify-center rounded-2xl bg-slate-200/80 text-2xl font-black text-slate-500 dark:bg-slate-700 dark:text-slate-400">—</div>
                                            <div class="mt-3 min-w-0 flex-1 text-sm sm:mt-0">
                                                <p class="font-bold text-slate-900 dark:text-slate-100">{{ __('Search position') }}</p>
                                                <p class="mt-1 text-slate-600 dark:text-slate-400">{{ __('Rank could not be checked — no organic links were returned in the SERP response.') }}</p>
                                            </div>
                                        </div>
                                    @else
                                        <div class="flex w-full flex-col items-center justify-center py-2 sm:flex-row sm:items-center sm:gap-6 sm:text-start">
                                            <div class="flex h-20 w-20 shrink-0 items-center justify-center rounded-2xl border-2 border-dashed border-slate-300 bg-white/80 text-xl font-black text-slate-400 dark:border-slate-600 dark:bg-slate-900/50 dark:text-slate-500">?</div>
                                            <div class="mt-3 min-w-0 flex-1 text-sm sm:mt-0">
                                                <p class="font-bold text-slate-900 dark:text-slate-100">{{ __('Not in top') }} {{ $ysN }} {{ __('of this sample') }}</p>
                                                <p class="mt-1 text-slate-600 dark:text-slate-400">{{ __('Your site’s domain did not match any of the') }} {{ $ysN }} {{ __('organic results in this snapshot (we compare by domain, not the full audited URL path). That does not mean you are not ranking — verify in Search Console or a live SERP.') }}</p>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                                @include('partials.serp-vs-audited-snippet', ['ys' => $ys])
                            </div>
                        @endif
                        <p class="mb-3 text-[11px] leading-relaxed text-slate-500 dark:text-slate-400">
                            {{ __('Organic URLs pulled live from Google at audit time; Flesch scores computed from HTML we fetched ourselves. Not identical to live Google rankings; use as a directional sample.') }}
                            @if (\App\Support\Audit\PageLocalePresentation::shouldShowSerpLocationNote($benchmark['serp_locale'] ?? null))
                                @php $serpLocLine = \App\Support\Audit\PageLocalePresentation::serpParamsLine($benchmark['serp_locale'] ?? null); @endphp
                                @if ($serpLocLine)
                                    <span class="mt-1 block text-slate-500 dark:text-slate-400">{{ __('SERP sample region:') }} <span class="font-mono text-slate-700 dark:text-slate-300">{{ $serpLocLine }}</span></span>
                                @endif
                            @endif
                        </p>
                        @if (! empty($benchmark['keyword']))
                            <p class="mb-2 flex flex-wrap items-center gap-2 text-xs font-semibold text-slate-700 dark:text-slate-200">
                                <span>{{ __('SERP keyword:') }} <span class="font-mono text-slate-900 dark:text-slate-100">{{ $benchmark['keyword'] }}</span></span>
                                @if (($benchmark['keyword_source'] ?? null) === 'manual')
                                    <span class="rounded-full bg-orange-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-orange-800 dark:bg-orange-500/20 dark:text-orange-200">{{ __('Custom audit') }}</span>
                                @elseif (($benchmark['keyword_source'] ?? null) === 'gsc_primary')
                                    <span class="rounded-full bg-slate-200/80 px-2 py-0.5 text-[10px] font-semibold text-slate-600 dark:bg-slate-700 dark:text-slate-300">{{ __('From Search Console') }}</span>
                                @endif
                            </p>
                        @endif
                        @php $skippedLabel = $skippedReasonLabel($benchmark['skipped_reason'] ?? null); @endphp
                        @if ($skippedLabel && empty($benchmark['competitors']))
                            <div class="rounded-lg border border-dashed border-amber-200 bg-amber-50/50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900/40 dark:bg-amber-500/5 dark:text-amber-200">
                                {{ $skippedLabel }}
                            </div>
                        @endif
                        @if (! empty($benchmark['competitors']))
                            @php
                                // Batch-load cached competitor backlinks once per render — no N+1.
                                $competitorDomains = [];
                                foreach ($benchmark['competitors'] as $_cRow) {
                                    if (isset($_cRow['url']) && is_string($_cRow['url'])) {
                                        $d = \App\Models\CompetitorBacklink::extractDomain($_cRow['url']);
                                        if ($d !== '') $competitorDomains[$d] = true;
                                    }
                                }
                                $competitorDomains = array_keys($competitorDomains);
                                $competitorBacklinks = $competitorDomains === []
                                    ? collect()
                                    : \App\Models\CompetitorBacklink::query()
                                        ->whereIn('competitor_domain', $competitorDomains)
                                        ->orderByDesc('domain_authority')
                                        ->get()
                                        ->groupBy('competitor_domain');
                            @endphp
                            <div class="overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
                                <table class="w-full min-w-[320px] text-xs">
                                    <thead>
                                        <tr class="border-b border-slate-200 bg-slate-50 text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:border-slate-700 dark:bg-slate-800/80 dark:text-slate-400">
                                            <th class="px-3 py-2 text-start">{{ __('Page') }}</th>
                                            <th class="px-3 py-2 text-end">{{ __('Flesch') }}</th>
                                            <th class="px-3 py-2 text-start">{{ __('Grade band') }}</th>
                                            <th class="px-3 py-2 text-start">{{ __('Top backlinks') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                        <tr class="bg-orange-50/40 dark:bg-orange-500/5">
                                            <td class="px-3 py-2 font-semibold text-slate-900 dark:text-slate-100">{{ __('This page (audited)') }}</td>
                                            <td class="px-3 py-2 text-end tabular-nums font-semibold text-slate-900 dark:text-slate-100">{{ is_numeric($benchmark['your_flesch'] ?? null) ? $benchmark['your_flesch'] : '—' }}</td>
                                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300">{{ data_get($advanced, 'readability.grade') ?? '—' }}</td>
                                            <td class="px-3 py-2 text-slate-400">—</td>
                                        </tr>
                                        @foreach ($benchmark['competitors'] as $row)
                                            @php
                                                $_compDomain = isset($row['url']) && is_string($row['url'])
                                                    ? \App\Models\CompetitorBacklink::extractDomain($row['url'])
                                                    : '';
                                                $_compLinks = $_compDomain !== '' ? ($competitorBacklinks[$_compDomain] ?? collect()) : collect();
                                                $_compLinkCount = $_compLinks->count();
                                            @endphp
                                            <tr>
                                                <td class="max-w-[200px] px-3 py-2">
                                                    <a href="{{ $row['url'] }}" target="_blank" rel="noopener noreferrer" class="font-medium text-orange-600 hover:underline dark:text-orange-400">{{ \Illuminate\Support\Str::limit($row['title'] ?: $row['url'], 48) }}</a>
                                                    @if (isset($row['position']))
                                                        <span class="ms-1 text-[10px] text-slate-400">#{{ $row['position'] }}</span>
                                                    @endif
                                                    @if ($_compDomain !== '')
                                                        <div class="mt-0.5 text-[10px] text-slate-400">{{ $_compDomain }}</div>
                                                    @endif
                                                </td>
                                                <td class="px-3 py-2 text-end tabular-nums text-slate-800 dark:text-slate-100">{{ is_numeric($row['flesch'] ?? null) ? $row['flesch'] : '—' }}</td>
                                                <td class="px-3 py-2 text-slate-600 dark:text-slate-300">{{ $row['grade'] ?? '—' }}</td>
                                                <td class="px-3 py-2">
                                                    @if ($_compLinkCount === 0)
                                                        <span class="text-[10px] text-slate-400" title="{{ __('Competitor backlinks will appear here once the background fetch completes. If this has stayed blank for more than a few minutes, the queue worker may not be running — re-open this page to re-queue.') }}">{{ __('pending…') }}</span>
                                                    @else
                                                        <details class="group/cblk">
                                                            <summary class="cursor-pointer list-none text-[10px] font-semibold text-orange-600 transition hover:text-orange-800 dark:text-orange-400 dark:hover:text-orange-300 [&::-webkit-details-marker]:hidden">
                                                                <span class="inline-flex items-center gap-1">
                                                                    {{ $_compLinkCount }} {{ $_compLinkCount === 1 ? __('link') : __('links') }}
                                                                    <svg class="h-3 w-3 transition-transform group-open/cblk:rotate-180" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                                                                </span>
                                                            </summary>
                                                            <ul class="mt-2 max-h-64 space-y-1.5 overflow-y-auto rounded-md border border-slate-200 bg-slate-50 p-2 text-[10px] dark:border-slate-700 dark:bg-slate-800/40">
                                                                @foreach ($_compLinks->take(10) as $_link)
                                                                    <li class="flex items-start gap-2">
                                                                        @if ($_link->domain_authority !== null)
                                                                            <span class="mt-0.5 inline-flex h-5 w-7 shrink-0 items-center justify-center rounded bg-orange-100 text-[9px] font-bold text-orange-700 dark:bg-orange-500/20 dark:text-orange-300" title="{{ __('Referring domain authority (0–100)') }}">{{ $_link->domain_authority }}</span>
                                                                        @endif
                                                                        <div class="min-w-0 flex-1">
                                                                            <a href="{{ $_link->referring_page_url }}" target="_blank" rel="noopener noreferrer" class="block truncate font-medium text-slate-800 hover:text-orange-600 dark:text-slate-200 dark:hover:text-orange-400" title="{{ $_link->referring_page_url }}">{{ $_link->referring_domain ?: \Illuminate\Support\Str::limit($_link->referring_page_url, 60) }}</a>
                                                                            @if ($_link->anchor_text)
                                                                                <p class="truncate text-slate-500 dark:text-slate-400" title="{{ $_link->anchor_text }}">“{{ \Illuminate\Support\Str::limit($_link->anchor_text, 80) }}”</p>
                                                                            @endif
                                                                            @if ($_link->backlink_type)
                                                                                <span class="me-1 inline-flex items-center rounded bg-slate-200 px-1 py-px text-[9px] font-semibold uppercase tracking-wide text-slate-600 dark:bg-slate-700 dark:text-slate-300">{{ $_link->backlink_type }}</span>
                                                                            @endif
                                                                        </div>
                                                                    </li>
                                                                @endforeach
                                                                @if ($_compLinkCount > 10)
                                                                    <li class="pt-1 text-[9px] text-slate-400">{{ __('showing 10 of') }} {{ $_compLinkCount }}</li>
                                                                @endif
                                                            </ul>
                                                        </details>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                        @if (! empty($benchmark['gap_table']['rows']))
                            <div class="mt-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900/40">
                                <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ __('You vs. market average') }}</h4>
                                <p class="mt-1 text-[11px] leading-relaxed text-slate-500 dark:text-slate-400">{{ __('Mean of the competitor pages fetched for this benchmark (same snapshot as the table above). Word count alone does not capture visual engagement — image counts highlight that gap.') }}</p>
                                <div class="mt-3 overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
                                    <table class="w-full min-w-[360px] text-xs">
                                        <thead>
                                            <tr class="border-b border-slate-200 bg-slate-50 text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:border-slate-700 dark:bg-slate-800/80 dark:text-slate-400">
                                                <th class="px-3 py-2 text-start">{{ __('Metric') }}</th>
                                                <th class="px-3 py-2 text-end">{{ __('Your page') }}</th>
                                                <th class="px-3 py-2 text-end">{{ __('Market avg') }}</th>
                                                <th class="px-3 py-2 text-start">{{ __('Status') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                            @foreach ($benchmark['gap_table']['rows'] as $g)
                                                @php
                                                    $gk = $g['key'] ?? '';
                                                    $hasYours = isset($g['yours']) && $g['yours'] !== null;
                                                    $hasAvg = isset($g['market_avg']) && $g['market_avg'] !== null;
                                                    $hasDelta = isset($g['delta']) && is_numeric($g['delta']);
                                                    $yoursFmt = $hasYours
                                                        ? ($gk === 'word_count'
                                                            ? number_format((int) $g['yours'])
                                                            : (($gk === 'flesch') ? number_format((float) $g['yours'], 1) : (is_numeric($g['yours']) ? number_format((int) $g['yours']) : (string) $g['yours'])))
                                                        : '—';
                                                    $avgFmt = $hasAvg
                                                        ? ($gk === 'word_count'
                                                            ? number_format((int) round((float) $g['market_avg']))
                                                            : (($gk === 'flesch') ? number_format((float) $g['market_avg'], 1) : (is_numeric($g['market_avg']) ? number_format((float) $g['market_avg'], 1) : (string) $g['market_avg'])))
                                                        : '—';
                                                    $d = $hasDelta ? (float) $g['delta'] : 0.0;
                                                    $sign = $d > 0 ? '+' : '';
                                                    $deltaFmt = $hasDelta
                                                        ? ($gk === 'word_count'
                                                            ? $sign.number_format((int) round($d), 0, '.', ',')
                                                            : $sign.number_format($d, 1))
                                                        : '—';
                                                    $st = (string) ($g['status'] ?? '');
                                                    $deltaTone = ! $hasDelta
                                                        ? 'text-slate-500 dark:text-slate-400'
                                                        : ($d < -0.5 ? 'text-rose-600 dark:text-rose-400' : ($d > 0.5 ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-600 dark:text-slate-400'));
                                                @endphp
                                                <tr>
                                                    <td class="px-3 py-2 font-medium text-slate-900 dark:text-slate-100">{{ $g['metric'] ?? '' }}</td>
                                                    <td class="px-3 py-2 text-end tabular-nums text-slate-800 dark:text-slate-100">{{ $yoursFmt }}</td>
                                                    <td class="px-3 py-2 text-end tabular-nums text-slate-600 dark:text-slate-300">{{ $avgFmt }}</td>
                                                    <td class="px-3 py-2 font-medium tabular-nums {{ $deltaTone }}">{{ $deltaFmt }} <span class="font-normal text-slate-500 dark:text-slate-400">({{ $st }})</span></td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                @php
                                    $gapHasFleschOutOfRange = false;
                                    foreach ($benchmark['gap_table']['rows'] as $gRow) {
                                        if (($gRow['sample_note'] ?? null) === 'flesch_out_of_range') { $gapHasFleschOutOfRange = true; break; }
                                    }
                                @endphp
                                @if ($gapHasFleschOutOfRange)
                                    <p class="mt-3 text-[11px] italic leading-relaxed text-slate-500 dark:text-slate-400">
                                        {{ __('Some competitor pages in the SERP sample were not long-form articles (Flesch outside the 10–95 prose range) and were excluded from the readability average.') }}
                                    </p>
                                @endif
                            </div>
                        @endif
                    </section>
                @endif
                @php $sectionHtml['benchmark'] = ob_get_clean(); @endphp

                @php ob_start(); @endphp
                {{-- ══════ Metadata ══════ --}}
                <section id="audit-metadata" class="scroll-mt-16">
                    <div class="mb-3 flex items-center gap-2">
                        <div class="flex h-7 w-7 items-center justify-center rounded-md bg-blue-100 text-blue-600 dark:bg-blue-500/10 dark:text-blue-400">
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8.25V18a2.25 2.25 0 002.25 2.25h13.5A2.25 2.25 0 0021 18V8.25m-18 0V6a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 6v2.25m-18 0h18M5.25 6h.008v.008H5.25V6zM7.5 6h.008v.008H7.5V6zm2.25 0h.008v.008H9.75V6z" /></svg>
                        </div>
                        <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Metadata') }}</h3>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        {{-- Title --}}
                        @php $titleLen = $meta['title_length'] ?? 0; $titleOk = $titleLen >= 30 && $titleLen <= 60; $titleBad = $titleLen === 0 || $titleLen > 60; @endphp
                        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <div class="flex items-center justify-between">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ __('Title tag') }}</p>
                                <span class="text-[10px] font-bold tabular-nums {{ $titleOk ? 'text-emerald-600' : ($titleBad ? 'text-rose-600' : 'text-amber-600') }}">{{ $titleLen }} / 60</span>
                            </div>
                            <p class="mt-1.5 break-words text-sm text-slate-800 dark:text-slate-100">{{ $meta['title'] ?? '—' }}</p>
                            <div class="mt-2 h-1 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800">
                                <div class="h-full rounded-full transition-all {{ $titleOk ? 'bg-emerald-500' : ($titleBad ? 'bg-rose-500' : 'bg-amber-500') }}" style="width: {{ min(100, ($titleLen / 60) * 100) }}%"></div>
                            </div>
                        </div>
                        {{-- Description --}}
                        @php $descLen = $meta['meta_description_length'] ?? 0; $descOk = $descLen >= 120 && $descLen <= 160; $descBad = $descLen === 0 || $descLen > 160; @endphp
                        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <div class="flex items-center justify-between">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ __('Meta description') }}</p>
                                <span class="text-[10px] font-bold tabular-nums {{ $descOk ? 'text-emerald-600' : ($descBad ? 'text-rose-600' : 'text-amber-600') }}">{{ $descLen }} / 160</span>
                            </div>
                            <p class="mt-1.5 break-words text-sm text-slate-800 dark:text-slate-100">{{ $meta['meta_description'] ?? '—' }}</p>
                            <div class="mt-2 h-1 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800">
                                <div class="h-full rounded-full transition-all {{ $descOk ? 'bg-emerald-500' : ($descBad ? 'bg-rose-500' : 'bg-amber-500') }}" style="width: {{ min(100, ($descLen / 160) * 100) }}%"></div>
                            </div>
                        </div>
                        {{-- Canonical --}}
                        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <div class="flex items-center justify-between">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ __('Canonical URL') }}</p>
                                <span class="inline-flex items-center gap-1 text-[10px] font-semibold {{ ($meta['canonical_matches'] ?? false) ? 'text-emerald-600' : 'text-amber-600' }}">
                                    <span class="h-1.5 w-1.5 rounded-full {{ ($meta['canonical_matches'] ?? false) ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
                                    {{ ($meta['canonical_matches'] ?? false) ? __('Matches') : __('Mismatch') }}
                                </span>
                            </div>
                            <p class="mt-1.5 break-all text-sm text-slate-800 dark:text-slate-100">{{ $meta['canonical'] ?? '—' }}</p>
                        </div>
                        {{-- Social --}}
                        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ __('Social sharing tags') }}</p>
                            <div class="mt-2 grid grid-cols-2 gap-3 text-sm">
                                <div>
                                    <p class="text-[10px] text-slate-500">OpenGraph</p>
                                    <p class="text-lg font-bold tabular-nums {{ ($meta['og_tag_count'] ?? 0) > 0 ? 'text-emerald-600' : 'text-amber-600' }}">{{ $meta['og_tag_count'] ?? 0 }}</p>
                                </div>
                                <div>
                                    <p class="text-[10px] text-slate-500">Twitter</p>
                                    <p class="text-lg font-bold tabular-nums {{ ($meta['twitter_tag_count'] ?? 0) > 0 ? 'text-emerald-600' : 'text-amber-600' }}">{{ $meta['twitter_tag_count'] ?? 0 }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
                @php $sectionHtml['metadata'] = ob_get_clean(); @endphp

                @php ob_start(); @endphp
                {{-- ══════ Content ══════ --}}
                <section id="audit-content" class="scroll-mt-16">
                    <div class="mb-3 flex items-center gap-2">
                        <div class="flex h-7 w-7 items-center justify-center rounded-md bg-orange-100 text-orange-600 dark:bg-orange-500/10 dark:text-orange-400">
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12" /></svg>
                        </div>
                        <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Content & Structure') }}</h3>
                    </div>
                    <div class="grid gap-3 grid-cols-2 md:grid-cols-4">
                        @php $h1 = $content['h1_count'] ?? 0; $orderOk = $content['heading_order_ok'] ?? false; $wc = $content['word_count'] ?? 0; @endphp
                        <x-audit.stat label="{{ __('H1 count') }}" :value="$h1" :tone="$h1 === 1 ? 'good' : 'bad'" />
                        <x-audit.stat label="{{ __('Heading order') }}" :value="$orderOk ? __('Logical') : __('Skipped')" :tone="$orderOk ? 'good' : 'bad'" />
                        <x-audit.stat label="{{ __('Word count') }}" :value="number_format($wc)" :tone="$wc >= 300 ? 'good' : ($wc === 0 ? 'bad' : 'warn')" />
                        <x-audit.stat label="{{ __('Headings total') }}" :value="count($content['headings'] ?? [])" tone="neutral" />
                    </div>

                    @if (! empty($content['first_150_words']))
                        <div class="mt-3 rounded-lg border border-slate-200 bg-slate-50/50 p-3 dark:border-slate-700 dark:bg-slate-800/30">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ __('Answer readiness — first 150 words') }}</p>
                            <p class="mt-2 text-sm leading-relaxed text-slate-700 dark:text-slate-300">{{ $content['first_150_words'] }}</p>
                        </div>
                    @endif

                    @if (! empty($content['keyword_density']))
                        <div class="mt-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <div class="mb-2 flex items-center justify-between">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ __('Keyword density — top 20') }}</p>
                                @php $topDensity = $content['keyword_density'][0]['density'] ?? 0; @endphp
                                @if ($topDensity >= 3)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-semibold text-rose-700 dark:bg-rose-500/10 dark:text-rose-400">{{ __('Stuffing risk') }}</span>
                                @endif
                            </div>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($content['keyword_density'] as $kw)
                                    @php $d = (float) $kw['density']; $cls = $d >= 3 ? 'bg-rose-100 text-rose-700 ring-rose-200' : ($d >= 1.5 ? 'bg-amber-100 text-amber-700 ring-amber-200' : 'bg-slate-100 text-slate-700 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700'); @endphp
                                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] ring-1 {{ $cls }}">
                                        <strong>{{ $kw['term'] }}</strong>
                                        <span class="opacity-70">×{{ $kw['count'] }} · {{ $kw['density'] }}%</span>
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if (! empty($content['headings']))
                        <details class="group/outline mt-3 rounded-lg border border-slate-200 dark:border-slate-700">
                            <summary class="flex cursor-pointer list-none items-center justify-between px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500 [&::-webkit-details-marker]:hidden">
                                <span>{{ __('Heading outline ·') }} {{ count($content['headings']) }}</span>
                                <svg class="h-3 w-3 transition-transform group-open/outline:rotate-180" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                            </summary>
                            <ul class="max-h-72 space-y-0.5 overflow-auto border-t border-slate-200 px-3 py-2 text-xs dark:border-slate-700">
                                @foreach ($content['headings'] as $h)
                                    <li class="text-slate-700 dark:text-slate-300" style="padding-left: {{ ($h['level'] - 1) * 14 }}px;">
                                        <span class="me-1.5 inline-flex h-4 w-6 items-center justify-center rounded bg-slate-100 font-mono text-[9px] font-bold text-slate-600 dark:bg-slate-800 dark:text-slate-400">H{{ $h['level'] }}</span>
                                        {{ $h['text'] }}
                                    </li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                </section>
                @php $sectionHtml['content'] = ob_get_clean(); @endphp

                @php ob_start(); @endphp
                {{-- ══════ Images & Links ══════ --}}
                <section id="audit-links" class="scroll-mt-16">
                    <div class="mb-3 flex items-center gap-2">
                        <div class="flex h-7 w-7 items-center justify-center rounded-md bg-pink-100 text-pink-600 dark:bg-pink-500/10 dark:text-pink-400">
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" /></svg>
                        </div>
                        <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Images & Links') }}</h3>
                    </div>
                    <div class="grid gap-3 grid-cols-2 md:grid-cols-4">
                        <x-audit.stat label="{{ __('Images') }}" :value="$images['total'] ?? 0" tone="neutral" />
                        <x-audit.stat label="{{ __('Missing alt') }}" :value="$images['missing_alt_count'] ?? 0" :tone="($images['missing_alt_count'] ?? 0) > 0 ? 'bad' : 'good'" />
                        <x-audit.stat label="{{ __('Modern formats') }}" :value="$images['modern_format_count'] ?? 0" tone="neutral" />
                        <x-audit.stat label="{{ __('Broken links') }}" :value="count($links['broken'] ?? [])" :tone="count($links['broken'] ?? []) > 0 ? 'bad' : 'good'" />
                        <x-audit.stat label="{{ __('Internal links') }}" :value="$links['internal_count'] ?? 0" tone="neutral" />
                        <x-audit.stat label="{{ __('External links') }}" :value="$links['external_count'] ?? 0" tone="neutral" />
                    </div>

                    @if (! empty($links['broken']))
                        <details class="group/broken mt-3 overflow-hidden rounded-lg border border-rose-200 bg-rose-50/40 dark:border-rose-900/40 dark:bg-rose-500/5" open>
                            <summary class="flex cursor-pointer list-none items-center justify-between px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-rose-700 dark:text-rose-400 [&::-webkit-details-marker]:hidden">
                                <span class="flex items-center gap-1.5">
                                    <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                                    {{ __('Broken links ·') }} {{ count($links['broken']) }}
                                </span>
                                <svg class="h-3 w-3 transition-transform group-open/broken:rotate-180" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                            </summary>
                            <ul class="max-h-72 space-y-1 overflow-auto border-t border-rose-200 px-3 py-2 dark:border-rose-900/40">
                                @foreach ($links['broken'] as $b)
                                    <li class="flex items-start gap-2">
                                        <span class="inline-flex shrink-0 rounded bg-rose-200 px-1.5 py-px font-mono text-[10px] font-bold text-rose-800 dark:bg-rose-500/30 dark:text-rose-300">{{ $b['status'] ?? 'ERR' }}</span>
                                        <a href="{{ $b['href'] }}" target="_blank" rel="noopener" class="break-all text-xs text-rose-700 underline dark:text-rose-400">{{ $b['href'] }}</a>
                                    </li>
                                @endforeach
                            </ul>
                        </details>
                    @endif

                    @if (! empty($images['missing_alt']))
                        <details class="group/alt mt-3 rounded-lg border border-slate-200 dark:border-slate-700">
                            <summary class="flex cursor-pointer list-none items-center justify-between px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500 [&::-webkit-details-marker]:hidden">
                                <span>{{ __('Images missing alt ·') }} {{ count($images['missing_alt']) }}</span>
                                <svg class="h-3 w-3 transition-transform group-open/alt:rotate-180" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                            </summary>
                            <ul class="max-h-48 space-y-0.5 overflow-auto border-t border-slate-200 px-3 py-2 font-mono text-[11px] dark:border-slate-700">
                                @foreach ($images['missing_alt'] as $src)
                                    <li class="truncate text-slate-600 dark:text-slate-300">{{ $src }}</li>
                                @endforeach
                            </ul>
                        </details>
                    @endif

                    @if (! empty($links['internal']) || ! empty($links['external']))
                        <details class="group/all mt-3 rounded-lg border border-slate-200 dark:border-slate-700">
                            <summary class="flex cursor-pointer list-none items-center justify-between px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500 [&::-webkit-details-marker]:hidden">
                                <span>{{ __('All links ·') }} {{ ($links['internal_count'] ?? 0) + ($links['external_count'] ?? 0) }}</span>
                                <svg class="h-3 w-3 transition-transform group-open/all:rotate-180" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                            </summary>
                            <div class="max-h-80 space-y-3 overflow-auto border-t border-slate-200 px-3 py-2 dark:border-slate-700">
                                @if (! empty($links['internal']))
                                    <div>
                                        <p class="mb-1 text-[10px] font-bold uppercase text-slate-500">{{ __('Internal ·') }} {{ count($links['internal']) }}</p>
                                        <ul class="space-y-0.5">
                                            @foreach ($links['internal'] as $l)
                                                <li class="truncate text-xs text-slate-600 dark:text-slate-300"><a href="{{ $l['href'] }}" target="_blank" rel="noopener" class="hover:underline">{{ $l['href'] }}</a></li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                                @if (! empty($links['external']))
                                    <div>
                                        <p class="mb-1 text-[10px] font-bold uppercase text-slate-500">{{ __('External ·') }} {{ count($links['external']) }}</p>
                                        <ul class="space-y-0.5">
                                            @foreach ($links['external'] as $l)
                                                <li class="truncate text-xs text-slate-600 dark:text-slate-300"><a href="{{ $l['href'] }}" target="_blank" rel="noopener" class="hover:underline">{{ $l['href'] }}</a></li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            </div>
                        </details>
                    @endif
                </section>
                @php $sectionHtml['links'] = ob_get_clean(); @endphp

                @php ob_start(); @endphp
                {{-- ══════ Technical ══════ --}}
                <section id="audit-technical" class="scroll-mt-16">
                    <div class="mb-3 flex items-center gap-2">
                        <div class="flex h-7 w-7 items-center justify-center rounded-md bg-amber-100 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400">
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>
                        </div>
                        <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Technical Performance') }}</h3>
                    </div>
                    @php
                        $httpStatus = $technical['http_status'] ?? 0;
                        $ttfb = $technical['ttfb_ms'] ?? null;
                        $size = $technical['page_size_bytes'] ?? null;
                        $compression = $technical['compression'] ?? 'none';
                        $https = $technical['is_https'] ?? false;
                        $stack = $technical['stack'] ?? null;
                        $stackLabel = $stack['label'] ?? __('Unknown');
                        $stackType = $stack['type'] ?? 'unknown';
                        $stackTone = match ($stackType) {
                            'modern' => 'good',
                            'cms' => 'warn',
                            default => 'neutral',
                        };

                        $stackGapRow = null;
                        foreach (data_get($result, 'benchmark.gap_table.rows', []) as $gapRow) {
                            if (($gapRow['key'] ?? null) === 'stack') { $stackGapRow = $gapRow; break; }
                        }
                        $deltaKind = $stackGapRow['delta_kind'] ?? null;
                        $gapChipClass = match ($deltaKind) {
                            'moat' => 'bg-emerald-100 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-900/40',
                            'disadvantage' => 'bg-rose-100 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-400 dark:ring-rose-900/40',
                            'parity' => 'bg-slate-100 text-slate-700 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',
                            default => null,
                        };
                        $gapChipLabel = match ($deltaKind) {
                            'moat' => __('Moat vs Top 3'),
                            'disadvantage' => __('Gap vs Top 3'),
                            'parity' => __('Parity vs Top 3'),
                            default => null,
                        };
                    @endphp
                    <div class="grid gap-3 grid-cols-2 md:grid-cols-6">
                        <x-audit.stat label="{{ __('HTTP status') }}" :value="$httpStatus ?: '—'" :tone="$httpStatus > 0 && $httpStatus < 400 ? 'good' : 'bad'" />
                        <x-audit.stat label="{{ __('Response time') }}" :value="$ttfb !== null ? $ttfb.' ms' : '—'" :tone="$ttfb === null ? 'neutral' : ($ttfb < 500 ? 'good' : ($ttfb < 1000 ? 'warn' : 'bad'))" />
                        <x-audit.stat label="{{ __('Page size') }}" :value="$size !== null ? number_format($size / 1024, 1).' KB' : '—'" tone="neutral" />
                        <x-audit.stat label="{{ __('Compression') }}" :value="$compression === '' || $compression === 'none' ? __('None') : strtoupper($compression)" :tone="in_array($compression, ['br', 'brotli']) ? 'good' : ($compression === 'gzip' ? 'warn' : 'bad')" />
                        <x-audit.stat label="{{ __('HTTPS') }}" :value="$https ? __('Secure') : __('Insecure')" :tone="$https ? 'good' : 'bad'" />
                        <div class="rounded-lg border border-slate-200 bg-white p-3 transition hover:shadow-sm dark:border-slate-700 dark:bg-slate-900">
                            <div class="flex items-center justify-between">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ __('Tech stack') }}</p>
                                <span @class([
                                    'h-1.5 w-1.5 rounded-full',
                                    'bg-emerald-500' => $stackTone === 'good',
                                    'bg-amber-500' => $stackTone === 'warn',
                                    'bg-slate-300 dark:bg-slate-600' => $stackTone === 'neutral',
                                ])></span>
                            </div>
                            <p @class([
                                'mt-1.5 text-xl font-bold tabular-nums leading-none',
                                'text-emerald-600 dark:text-emerald-400' => $stackTone === 'good',
                                'text-amber-600 dark:text-amber-400' => $stackTone === 'warn',
                                'text-slate-800 dark:text-slate-100' => $stackTone === 'neutral',
                            ])>{{ $stackLabel }}</p>
                            @if ($gapChipLabel)
                                <span class="mt-2 inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 {{ $gapChipClass }}">{{ $gapChipLabel }}</span>
                            @endif
                        </div>
                    </div>
                </section>
                @php $sectionHtml['technical'] = ob_get_clean(); @endphp

                @php ob_start(); @endphp
                {{-- ══════ Core Web Vitals ══════ --}}
                @php $cwv = $result['core_web_vitals'] ?? null; @endphp
                @if (is_array($cwv))
                    <section id="audit-core-web-vitals" class="scroll-mt-16">
                        <div class="mb-3 flex items-center gap-2">
                            <div class="flex h-7 w-7 items-center justify-center rounded-md bg-orange-100 text-orange-600 dark:bg-orange-500/10 dark:text-orange-400">
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v18M8.25 19.5V9m4.5 10.5V13.5M17.25 19.5V6" /></svg>
                            </div>
                            <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Core Web Vitals') }}</h3>
                            @if (! empty($cwv['fetched_at']))
                                <span class="text-[10px] text-slate-400 dark:text-slate-500">{{ __('measured') }} {{ \Carbon\Carbon::parse($cwv['fetched_at'])->diffForHumans() }}</span>
                            @endif
                        </div>

                        @php
                            // Google's published thresholds (web.dev/vitals).
                            $lcpTone = fn (?int $ms) => $ms === null ? 'neutral' : ($ms <= 2500 ? 'good' : ($ms <= 4000 ? 'warn' : 'bad'));
                            $clsTone = fn (?float $v) => $v === null ? 'neutral' : ($v <= 0.1 ? 'good' : ($v <= 0.25 ? 'warn' : 'bad'));
                            $tbtTone = fn (?int $ms) => $ms === null ? 'neutral' : ($ms <= 200 ? 'good' : ($ms <= 600 ? 'warn' : 'bad'));
                            $fcpTone = fn (?int $ms) => $ms === null ? 'neutral' : ($ms <= 1800 ? 'good' : ($ms <= 3000 ? 'warn' : 'bad'));
                            $ttfbTone = fn (?int $ms) => $ms === null ? 'neutral' : ($ms <= 800 ? 'good' : ($ms <= 1800 ? 'warn' : 'bad'));
                            $scoreTone = fn (?int $s) => $s === null ? 'neutral' : ($s >= 90 ? 'good' : ($s >= 50 ? 'warn' : 'bad'));
                            $fmt = fn ($v, $suffix = '') => $v === null ? '—' : (is_float($v) ? number_format($v, 2) : ($v.$suffix));

                            $strategies = [
                                'mobile' => ['label' => __('Mobile'), 'data' => is_array($cwv['mobile'] ?? null) ? $cwv['mobile'] : null],
                                'desktop' => ['label' => __('Desktop'), 'data' => is_array($cwv['desktop'] ?? null) ? $cwv['desktop'] : null],
                            ];
                        @endphp

                        <div class="grid gap-4 md:grid-cols-2">
                            @foreach ($strategies as $key => $strategy)
                                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
                                    <div class="flex items-center justify-between border-b border-slate-100 pb-2 dark:border-slate-800">
                                        <div class="flex items-center gap-1.5">
                                            @if ($key === 'mobile')
                                                <svg class="h-3.5 w-3.5 text-slate-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3" /></svg>
                                            @else
                                                <svg class="h-3.5 w-3.5 text-slate-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12v6.75a2.25 2.25 0 01-2.25 2.25H5.25a2.25 2.25 0 01-2.25-2.25V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25" /></svg>
                                            @endif
                                            <h4 class="text-xs font-bold uppercase tracking-wide text-slate-700 dark:text-slate-300">{{ $strategy['label'] }}</h4>
                                        </div>
                                        @if ($strategy['data'])
                                            @php
                                                $sc = $strategy['data']['performance_score'] ?? null;
                                                $scTone = $scoreTone($sc);
                                                $scCls = match ($scTone) {
                                                    'good' => 'bg-emerald-100 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-900/40',
                                                    'warn' => 'bg-amber-100 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-900/40',
                                                    'bad' => 'bg-rose-100 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-400 dark:ring-rose-900/40',
                                                    default => 'bg-slate-100 text-slate-600 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',
                                                };
                                            @endphp
                                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 {{ $scCls }}">
                                                {{ __('Score') }} {{ $sc ?? '—' }}
                                            </span>
                                        @else
                                            <span class="text-[10px] text-slate-400">{{ __('unavailable') }}</span>
                                        @endif
                                    </div>

                                    @if ($strategy['data'] !== null)
                                        @php $d = $strategy['data']; @endphp
                                        <div class="mt-3 grid grid-cols-3 gap-2">
                                            <x-audit.stat label="LCP" :value="$fmt($d['lcp_ms'] ?? null, ' ms')" :tone="$lcpTone($d['lcp_ms'] ?? null)" />
                                            <x-audit.stat label="CLS" :value="$fmt($d['cls'] ?? null)" :tone="$clsTone($d['cls'] ?? null)" />
                                            <x-audit.stat label="TBT" :value="$fmt($d['tbt_ms'] ?? null, ' ms')" :tone="$tbtTone($d['tbt_ms'] ?? null)" />
                                            <x-audit.stat label="FCP" :value="$fmt($d['fcp_ms'] ?? null, ' ms')" :tone="$fcpTone($d['fcp_ms'] ?? null)" />
                                            <x-audit.stat label="TTFB" :value="$fmt($d['ttfb_ms'] ?? null, ' ms')" :tone="$ttfbTone($d['ttfb_ms'] ?? null)" />
                                            <x-audit.stat label="{{ __('Speed Index') }}" :value="$fmt($d['speed_index_ms'] ?? null, ' ms')" tone="neutral" />
                                        </div>
                                        @if (! empty($d['runtime_error']))
                                            <p class="mt-2 text-[10px] text-rose-600 dark:text-rose-400">{{ __('Runtime note:') }} {{ $d['runtime_error'] }}</p>
                                        @endif
                                    @else
                                        <p class="mt-3 text-[11px] text-slate-500 dark:text-slate-400">{{ __('This strategy failed during the performance scan. Re-run the audit to retry.') }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        <p class="mt-3 text-[10px] leading-relaxed text-slate-500 dark:text-slate-500">
                            {{ __('Lab data from a single Core Web Vitals run. TBT is the lab-side proxy for INP — field INP requires CrUX and is not available here. Thresholds follow') }} <span class="font-mono">web.dev/vitals</span> {{ __('(good / needs-improvement / poor).') }}
                        </p>
                    </section>
                @endif
                @php $sectionHtml['core-web-vitals'] = ob_get_clean(); @endphp

                @php ob_start(); @endphp
                {{-- ══════ Advanced ══════ --}}
                <section id="audit-advanced" class="scroll-mt-16">
                    <div class="mb-3 flex items-center gap-2">
                        <div class="flex h-7 w-7 items-center justify-center rounded-md bg-emerald-100 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400">
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6.878V6a2.25 2.25 0 012.25-2.25h7.5A2.25 2.25 0 0118 6v.878m-12 0c.235-.083.487-.128.75-.128h10.5c.263 0 .515.045.75.128m-12 0A2.25 2.25 0 004.5 9v.878m13.5-3A2.25 2.25 0 0119.5 9v.878m0 0a2.246 2.246 0 00-.75-.128H5.25c-.263 0-.515.045-.75.128m15 0A2.25 2.25 0 0121 12v6a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 18v-6c0-.98.626-1.813 1.5-2.122" /></svg>
                        </div>
                        <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Advanced Data') }}</h3>
                    </div>
                    @php $flesch = data_get($advanced, 'readability.flesch'); $fleschTone = ! is_numeric($flesch) ? 'neutral' : ($flesch >= 60 ? 'good' : ($flesch >= 30 ? 'warn' : 'bad')); @endphp
                    <div class="grid gap-3 grid-cols-2 md:grid-cols-3">
                        <x-audit.stat label="{{ __('Schema (JSON-LD)') }}" :value="($advanced['schema_blocks'] ?? 0).' '.__('block(s)')" :tone="($advanced['schema_blocks'] ?? 0) > 0 ? 'good' : 'warn'" />
                        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ __('Readability (Flesch)') }}</p>
                            <p class="mt-1 text-xl font-bold tabular-nums {{ $fleschTone === 'good' ? 'text-emerald-600' : ($fleschTone === 'warn' ? 'text-amber-600' : ($fleschTone === 'bad' ? 'text-rose-600' : 'text-slate-800 dark:text-slate-100')) }}">{{ $flesch ?? '—' }}</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400">{{ data_get($advanced, 'readability.grade') ?? '' }}</p>
                        </div>
                        <x-audit.stat label="{{ __('Favicon') }}" :value="($advanced['has_favicon'] ?? false) ? __('Present') : __('Missing')" :tone="($advanced['has_favicon'] ?? false) ? 'good' : 'warn'" />
                    </div>
                </section>
                @php $sectionHtml['advanced'] = ob_get_clean(); @endphp

                {{-- Emit captured sections in the order defined by $sections. --}}
                @foreach ($sections as $s)
                    @if ($s['show'] && ! empty($sectionHtml[$s['key']]))
                        {!! $sectionHtml[$s['key']] !!}
                    @endif
                @endforeach
            </div>
        @endif
    </details>

    {{-- ═══ Footer toolbar ═══ (download + email — Livewire/route coupled; hidden for guest audits) --}}
    @if (($showAuditToolbar ?? true))
    <div class="flex flex-col gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3 sm:flex-row sm:items-center dark:border-slate-800 dark:bg-slate-800/30">
        <a href="{{ route('page-audits.download', $auditReport->id) }}"
           class="inline-flex h-9 items-center justify-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
            {{ __('Download') }}
        </a>

        <form wire:submit.prevent="emailAuditReport" class="flex flex-1 items-center gap-2">
            <div class="relative flex-1">
                <svg class="pointer-events-none absolute start-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>
                <input type="email" wire:model="auditEmail" placeholder="recipient@example.com"
                       class="h-9 w-full rounded-md border border-slate-300 bg-white ps-8 pe-2.5 text-xs text-slate-700 placeholder-slate-400 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:placeholder-slate-500" />
            </div>
            <button type="submit" wire:loading.attr="disabled" wire:target="emailAuditReport"
                    class="inline-flex h-9 shrink-0 items-center justify-center gap-1.5 rounded-md bg-orange-600 px-3 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-700 disabled:opacity-60">
                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.125A59.769 59.769 0 0121.485 12 59.768 59.768 0 013.27 20.875L5.999 12zm0 0h7.5" /></svg>
                <span wire:loading.remove wire:target="emailAuditReport">{{ __('Email report') }}</span>
                <span wire:loading wire:target="emailAuditReport">{{ __('Sending…') }}</span>
            </button>
        </form>
    </div>
    @endif
</div>
