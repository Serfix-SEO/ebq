@php
    $messages = collect([
        ['text' => $reindexMessage, 'kind' => $reindexMessageKind],
        ['text' => $googleStatusMessage, 'kind' => $googleStatusMessageKind],
        ['text' => $snippetMessage, 'kind' => $snippetMessageKind],
        ['text' => $auditMessage, 'kind' => $auditMessageKind],
    ])->filter(fn ($m) => ! empty($m['text']))->values();

@endphp

<div class="space-y-5" wire:init="autoGenerateSnippet">
    {{-- ═══ Header ═══ --}}
    <header class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="px-5 pt-4">
            <a href="{{ route('pages.index') }}" class="inline-flex items-center gap-1 text-[11px] font-medium text-slate-500 transition hover:text-orange-600 dark:text-slate-400 dark:hover:text-orange-400">
                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" /></svg>
                {{ __('Back to pages') }}
            </a>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <h1 class="min-w-0 max-w-full truncate text-base font-bold tracking-tight text-slate-900 dark:text-slate-100">{{ $pageUrl }}</h1>
                @php
                    $headerLocale = $auditReport && is_array($auditReport->result ?? null) ? ($auditReport->result['page_locale'] ?? null) : null;
                    $headerLocaleLabel = \App\Support\Audit\PageLocalePresentation::shortLabel(is_array($headerLocale) ? $headerLocale : null);
                @endphp
                @if ($headerLocaleLabel)
                    <span class="inline-flex max-w-[14rem] items-center truncate rounded-full border border-sky-200 bg-sky-50 px-2 py-0.5 text-[10px] font-semibold text-sky-800 dark:border-sky-800 dark:bg-sky-500/10 dark:text-sky-200" title='{{ __('From latest audit HTML') }}'>{{ $headerLocaleLabel }}</span>
                @endif
                <a href="{{ $pageUrl }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-slate-50 px-2 py-0.5 text-[11px] font-medium text-slate-600 transition hover:border-orange-300 hover:text-orange-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:text-orange-400">
                    {{ __('Open') }}
                    <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                </a>
            </div>
        </div>

        {{-- Actions --}}
        <div class="flex flex-wrap items-center gap-2 px-5 pt-3">
            <button type="button" wire:click="preparePageAudit" wire:loading.attr="disabled" wire:target="preparePageAudit,confirmPageAuditWithSerpCountry"
                    class="inline-flex h-8 items-center gap-1.5 rounded-md bg-orange-600 px-3 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-700 disabled:opacity-60">
                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" /></svg>
                <span wire:loading.remove wire:target="preparePageAudit,confirmPageAuditWithSerpCountry">{{ __('Audit this page') }}</span>
                <span wire:loading wire:target="preparePageAudit,confirmPageAuditWithSerpCountry">{{ __('Auditing…') }}</span>
            </button>

            <div class="mx-1 hidden h-5 w-px bg-slate-200 sm:block dark:bg-slate-700"></div>

            <button type="button" wire:click="requestReindex" wire:loading.attr="disabled" wire:target="requestReindex"
                    class="inline-flex h-8 items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182" /></svg>
                <span wire:loading.remove wire:target="requestReindex">{{ __('Request reindex') }}</span>
                <span wire:loading wire:target="requestReindex">{{ __('Requesting…') }}</span>
            </button>
            <button type="button" wire:click="refreshGoogleIndexingStatus" wire:loading.attr="disabled" wire:target="refreshGoogleIndexingStatus"
                    class="inline-flex h-8 items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 4.5v5.25h5.25M19.5 19.5v-5.25h-5.25M4.5 9.75a7.5 7.5 0 0 1 13.307-4.914L19.5 6.75m0 7.5a7.5 7.5 0 0 1-13.307 4.914L4.5 17.25" /></svg>
                <span wire:loading.remove wire:target="refreshGoogleIndexingStatus">{{ __('Refresh status') }}</span>
                <span wire:loading wire:target="refreshGoogleIndexingStatus">{{ __('Refreshing…') }}</span>
            </button>
            <button type="button" wire:click="generateGoogleSnippet" wire:loading.attr="disabled" wire:target="generateGoogleSnippet"
                    class="inline-flex h-8 items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h3m6-9H6a2.25 2.25 0 0 0-2.25 2.25v8.25A2.25 2.25 0 0 0 6 19.75h12a2.25 2.25 0 0 0 2.25-2.25V9.25A2.25 2.25 0 0 0 18 7Zm0 0V5.75A1.75 1.75 0 0 0 16.25 4h-8.5A1.75 1.75 0 0 0 6 5.75V7" /></svg>
                <span wire:loading.remove wire:target="generateGoogleSnippet">{{ __('Snippet preview') }}</span>
                <span wire:loading wire:target="generateGoogleSnippet">{{ __('Generating…') }}</span>
            </button>
        </div>

        {{-- Messages --}}
        @if ($messages->isNotEmpty() || $needsGoogleReconnect)
            <div class="flex flex-wrap items-center gap-2 px-5 pt-3">
                @foreach ($messages as $m)
                    <span @class([
                        'inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-[11px] font-medium',
                        'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => $m['kind'] === 'success',
                        'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-400' => $m['kind'] === 'info',
                        'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400' => $m['kind'] === 'error',
                    ])>{{ $m['text'] }}</span>
                @endforeach
                @if ($needsGoogleReconnect)
                    <a href="{{ route('google.redirect') }}" class="inline-flex h-7 items-center rounded-md border border-slate-300 bg-white px-2.5 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
                        {{ __('Reconnect Google') }}
                    </a>
                @endif
            </div>
        @endif

        {{-- Footnote --}}
        <p class="px-5 pb-4 pt-3 text-[11px] text-slate-500 dark:text-slate-400">{{ __('Audits use a Googlebot user-agent. Reindex uses the Google Indexing API — processing is not guaranteed.') }}</p>
    </header>

    @if ($serpCountryModalOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="serp-country-modal-title">
            <div class="absolute inset-0 bg-slate-900/60" wire:click="cancelPageAuditSerpCountryModal" role="presentation"></div>
            <div class="relative z-10 w-full max-w-md rounded-2xl border border-slate-200 bg-white p-5 shadow-xl dark:border-slate-700 dark:bg-slate-900">
                <h3 id="serp-country-modal-title" class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Choose Google SERP country') }}</h3>
                @if ($serpCountryRecommendationHint)
                    <p class="mt-2 text-xs leading-relaxed text-slate-600 dark:text-slate-400">{{ $serpCountryRecommendationHint }}</p>
                @else
                    <p class="mt-2 text-xs leading-relaxed text-slate-600 dark:text-slate-400">{{ __('Pick the market used for the SERP organic snapshot (competitor sample and rank-in-top-10 check).') }}</p>
                @endif
                <label for="serp-country-gl" class="mt-4 block text-xs font-semibold text-slate-700 dark:text-slate-300">{{ __('Country') }}</label>
                <select id="serp-country-gl" wire:model="serpCountryGl" class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                    @foreach (\App\Support\Audit\SerpGlCatalog::selectOptions() as $code => $label)
                        <option value="{{ $code }}">{{ $label }}</option>
                    @endforeach
                </select>
                <div class="mt-5 flex flex-wrap justify-end gap-2">
                    <button type="button" wire:click="cancelPageAuditSerpCountryModal" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="confirmPageAuditWithSerpCountry" wire:loading.attr="disabled" wire:target="confirmPageAuditWithSerpCountry" class="rounded-lg bg-orange-600 px-3 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-500 disabled:opacity-60 dark:bg-orange-500 dark:hover:bg-orange-400">
                        <span wire:loading.remove wire:target="confirmPageAuditWithSerpCountry">{{ __('Run audit') }}</span>
                        <span wire:loading wire:target="confirmPageAuditWithSerpCountry">{{ __('Auditing…') }}</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ═══ KPI row ═══ --}}
    @if ($summary)
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['label' => __('Clicks'), 'value' => number_format($summary->total_clicks), 'color' => 'text-blue-600 dark:text-blue-400', 'icon' => 'M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007zM8.625 10.5a.375.375 0 11-.75 0 .375.375 0 01.75 0zm7.5 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z'],
                ['label' => __('Impressions'), 'value' => number_format($summary->total_impressions), 'color' => 'text-emerald-600 dark:text-emerald-400', 'icon' => 'M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z M15 12a3 3 0 11-6 0 3 3 0 016 0z'],
                ['label' => __('Avg CTR'), 'value' => number_format(($summary->avg_ctr ?? 0) * 100, 1).'%', 'color' => 'text-orange-600 dark:text-orange-400', 'icon' => 'M15.042 21.672L13.684 16.6m0 0l-2.51 2.225.569-9.47 5.227 7.917-3.286-.672zm-7.518-.267A8.25 8.25 0 1120.25 10.5M8.288 14.212A5.25 5.25 0 1117.25 10.5'],
                ['label' => __('Avg Position'), 'value' => number_format($summary->avg_position ?? 0, 1), 'color' => 'text-amber-600 dark:text-amber-400', 'icon' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z'],
            ] as $card)
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ $card['label'] }}</p>
                        <svg class="h-4 w-4 {{ $card['color'] }}" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $card['icon'] }}" /></svg>
                    </div>
                    <p class="mt-2 text-2xl font-bold tabular-nums {{ $card['color'] }}">{{ $card['value'] }}</p>
                </div>
            @endforeach
        </div>
        @if (! empty($gscKeywordLookbackDays))
            <p class="mt-2 text-[11px] text-slate-500 dark:text-slate-400">{{ __('Figures above use Search Console data from the last :days days.', ['days' => (int) $gscKeywordLookbackDays]) }}</p>
        @endif
    @endif

    {{-- ═══ Indexing + Snippet ═══ --}}
    <div class="grid gap-4 sm:grid-cols-2">
        {{-- Google indexing status --}}
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Google Indexing') }}</h3>
                <span
                    title="{{ $indexVerdict['tooltip'] }}"
                    @class([
                        'inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold',
                        'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => $indexVerdict['kind'] === 'success',
                        'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400' => $indexVerdict['kind'] === 'warning',
                        'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400' => $indexVerdict['kind'] === 'muted',
                    ])>{{ $indexVerdict['label'] }}</span>
            </div>
            <dl class="mt-3 space-y-2 text-xs">
                <div class="flex items-center justify-between gap-3 border-b border-slate-100 pb-2 dark:border-slate-800">
                    <dt class="text-slate-500 dark:text-slate-400">{{ __('Impressions') }}{{ $gscKeywordLookbackDays ? ' ('.(int) $gscKeywordLookbackDays.'d)' : '' }}</dt>
                    <dd class="text-end font-medium text-slate-800 dark:text-slate-100">{{ number_format((int) ($summary->total_impressions ?? 0)) }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 border-b border-slate-100 pb-2 dark:border-slate-800">
                    <dt class="text-slate-500 dark:text-slate-400">{{ __('Coverage') }}</dt>
                    <dd class="truncate text-end font-medium text-slate-800 dark:text-slate-100">{{ $indexingStatus?->google_coverage_state ?? '—' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 border-b border-slate-100 pb-2 dark:border-slate-800">
                    <dt class="text-slate-500 dark:text-slate-400">{{ __('Indexing state') }}</dt>
                    <dd class="truncate text-end font-medium text-slate-800 dark:text-slate-100">{{ $indexingStatus?->google_indexing_state ?? '—' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 border-b border-slate-100 pb-2 dark:border-slate-800">
                    <dt class="text-slate-500 dark:text-slate-400">{{ __('Last crawl') }}</dt>
                    <dd class="text-end font-medium text-slate-800 dark:text-slate-100">{{ $indexingStatus?->google_last_crawl_at ? format_user_datetime($indexingStatus->google_last_crawl_at, 'M j, Y g:i A') : '—' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 border-b border-slate-100 pb-2 dark:border-slate-800">
                    <dt class="text-slate-500 dark:text-slate-400">{{ __('Last status check') }}</dt>
                    <dd class="text-end font-medium text-slate-800 dark:text-slate-100">{{ $indexingStatus?->last_google_status_checked_at ? format_user_datetime($indexingStatus->last_google_status_checked_at, 'M j, Y g:i A') : __('Never') }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-slate-500 dark:text-slate-400">{{ __('Last reindex request') }}</dt>
                    <dd class="text-end font-medium text-slate-800 dark:text-slate-100">{{ $indexingStatus?->last_reindex_requested_at ? format_user_datetime($indexingStatus->last_reindex_requested_at, 'M j, Y g:i A') : __('Never') }}</dd>
                </div>
            </dl>
        </div>

        {{-- Google snippet preview --}}
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Google Snippet Preview') }}</h3>
            @if ($snippetTitle || $snippetDescription)
                <div class="mt-3 rounded-lg bg-slate-50 p-4 dark:bg-slate-800/50">
                    <p class="text-xs text-emerald-700 dark:text-emerald-400">{{ $snippetDisplayUrl }}</p>
                    <p class="mt-1 text-lg font-medium leading-snug text-blue-700 dark:text-blue-400">{{ $snippetTitle }}</p>
                    <p class="mt-1 text-sm leading-6 text-slate-600 dark:text-slate-300">{{ $snippetDescription }}</p>
                </div>
            @else
                <div class="mt-3 flex h-32 items-center justify-center rounded-lg border border-dashed border-slate-300 bg-slate-50/50 text-xs text-slate-500 dark:border-slate-700 dark:bg-slate-800/30 dark:text-slate-400">
                    {{ __('Click "Snippet preview" to generate.') }}
                </div>
            @endif
        </div>
    </div>

    {{-- ═══ Page audits (history + latest snapshot) ═══ --}}
    @if ($auditReport || $pageAuditRuns->isNotEmpty())
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 pb-4 dark:border-slate-800">
                <div>
                    <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Page audits') }}</h3>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ __('Runs logged for this URL. Open any row to see that report (latest run overwrites the saved snapshot for the page).') }}</p>
                </div>
                @if ($auditReport)
                    <div class="flex flex-wrap gap-2">
                        <a
                            href="{{ route('page-audits.show', $auditReport) }}"
                            wire:navigate
                            class="inline-flex h-9 items-center gap-1.5 rounded-md bg-orange-600 px-3 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-500 dark:bg-orange-500 dark:hover:bg-orange-400"
                        >
                            {{ __('Latest report') }}
                        </a>
                        <a
                            href="{{ route('page-audits.download', $auditReport->id) }}"
                            class="inline-flex h-9 items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
                        >
                            {{ __('Download latest') }}
                        </a>
                    </div>
                @endif
            </div>

            @if ($pageAuditRuns->isNotEmpty())
                <div class="mt-4 overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
                    <table class="min-w-full divide-y divide-slate-200 text-start text-xs dark:divide-slate-700">
                        <thead class="bg-slate-50 font-semibold uppercase tracking-wide text-slate-500 dark:bg-slate-800/80 dark:text-slate-400">
                            <tr>
                                <th class="whitespace-nowrap px-3 py-2.5">{{ __('When') }}</th>
                                <th class="px-3 py-2.5">{{ __('Source') }}</th>
                                <th class="min-w-[6rem] px-3 py-2.5">{{ __('Market') }}</th>
                                <th class="min-w-[8rem] px-3 py-2.5">{{ __('SERP keyword') }}</th>
                                <th class="whitespace-nowrap px-3 py-2.5">{{ __('By') }}</th>
                                <th class="whitespace-nowrap px-3 py-2.5">{{ __('Status') }}</th>
                                <th class="whitespace-nowrap px-3 py-2.5 text-end">{{ __('Report') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach ($pageAuditRuns as $run)
                                <tr @class(['bg-orange-50/40 dark:bg-orange-500/5' => $auditReport && $run->page_audit_report_id === $auditReport->id])>
                                    <td class="whitespace-nowrap px-3 py-2 text-slate-600 dark:text-slate-300" title="{{ $run->created_at->toIso8601String() }}">{{ $run->created_at->diffForHumans() }}</td>
                                    <td class="px-3 py-2">
                                        @if ($run->source === \App\Models\CustomPageAudit::SOURCE_CUSTOM)
                                            <span class="rounded-full bg-orange-100 px-2 py-0.5 text-[10px] font-bold uppercase text-orange-800 dark:bg-orange-500/20 dark:text-orange-200">{{ __('Custom') }}</span>
                                        @else
                                            <span class="rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-semibold text-slate-700 dark:bg-slate-600 dark:text-slate-200">{{ __('Page') }}</span>
                                        @endif
                                    </td>
                                    <td class="max-w-[7rem] truncate px-3 py-2 text-[11px] text-slate-600 dark:text-slate-300" title="{{ \App\Support\Audit\PageLocalePresentation::shortLabel(is_array($run->pageAuditReport?->result['page_locale'] ?? null) ? $run->pageAuditReport->result['page_locale'] : null) ?? '' }}">
                                        {{ \App\Support\Audit\PageLocalePresentation::shortLabel(is_array($run->pageAuditReport?->result['page_locale'] ?? null) ? $run->pageAuditReport->result['page_locale'] : null) ?? '—' }}
                                    </td>
                                    <td class="max-w-[10rem] truncate px-3 py-2 font-mono text-slate-800 dark:text-slate-100" title="{{ $run->target_keyword }}">{{ $run->target_keyword !== '' ? $run->target_keyword : '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-slate-600 dark:text-slate-300">
                                        @if ($run->user_id === auth()->id())
                                            {{ __('You') }}
                                        @else
                                            {{ $run->user?->name ?? '—' }}
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2">
                                        @if ($run->status === 'completed')
                                            <span class="text-emerald-700 dark:text-emerald-400">{{ __('Done') }}</span>
                                        @else
                                            <span class="text-rose-700 dark:text-rose-400">{{ __('Failed') }}</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-end">
                                        @if ($run->page_audit_report_id)
                                            <a href="{{ route('page-audits.show', $run->page_audit_report_id) }}" wire:navigate class="font-semibold text-orange-600 hover:text-orange-500 dark:text-orange-400">{{ __('View') }}</a>
                                        @else
                                            <span class="text-slate-400">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @elseif ($auditReport)
                <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">{{ __('No run history yet for this URL. After you use') }} <strong class="text-slate-600 dark:text-slate-300">{{ __('Audit this page') }}</strong> {{ __('or') }} <strong class="text-slate-600 dark:text-slate-300">{{ __('Custom audit') }}</strong>{{ __(', runs will appear here.') }}</p>
            @endif
        </div>
    @endif

    {{-- ═══ Keywords ═══ --}}
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-col gap-1 border-b border-slate-200 px-5 py-3 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
            <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Keywords ranking for this page') }}</h3>
            @if ($keywords instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator && $keywords->total() > 0)
                <span class="text-[11px] text-slate-500 dark:text-slate-400">{{ number_format($keywords->total()) }} {{ __('total') }}</span>
            @endif
        </div>
        @if (! empty($gscKeywordLookbackDays))
            <p class="border-b border-slate-200 px-5 py-2 text-[11px] text-slate-500 dark:border-slate-800 dark:text-slate-400">
                {{ __('Search Console queries aggregated for the last') }} <span class="font-semibold text-slate-700 dark:text-slate-300">{{ (int) $gscKeywordLookbackDays }}</span> {{ __('days (same window as page audits).') }}
            </p>
        @endif

        @if ($keywords instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator && $keywords->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-[11px] font-semibold text-slate-500 dark:border-slate-800 dark:bg-slate-800/40 dark:text-slate-400">
                            <x-sort-header column="query" :sortBy="$sortBy" :sortDir="$sortDir">{{ __('Keyword') }}</x-sort-header>
                            <x-sort-header column="total_clicks" :sortBy="$sortBy" :sortDir="$sortDir" align="right">{{ __('Clicks') }}</x-sort-header>
                            <x-sort-header column="total_impressions" :sortBy="$sortBy" :sortDir="$sortDir" align="right">{{ __('Impressions') }}</x-sort-header>
                            <x-sort-header column="avg_ctr" :sortBy="$sortBy" :sortDir="$sortDir" align="right">{{ __('Avg CTR') }}</x-sort-header>
                            <x-sort-header column="avg_position" :sortBy="$sortBy" :sortDir="$sortDir" align="right">{{ __('Avg Position') }}</x-sort-header>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($keywords as $kw)
                            <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                <td class="whitespace-nowrap px-4 py-2.5 font-medium text-slate-900 dark:text-slate-100">{{ $kw->query }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-end tabular-nums text-slate-700 dark:text-slate-300">{{ number_format($kw->total_clicks) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-end tabular-nums text-slate-700 dark:text-slate-300">{{ number_format($kw->total_impressions) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-end tabular-nums text-slate-700 dark:text-slate-300">{{ number_format(($kw->avg_ctr ?? 0) * 100, 1) }}%</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-end">
                                    <span @class([
                                        'inline-flex rounded-full px-1.5 py-px text-[10px] font-semibold tabular-nums',
                                        'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => ($kw->avg_position ?? 0) <= 3,
                                        'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-400' => ($kw->avg_position ?? 0) > 3 && ($kw->avg_position ?? 0) <= 10,
                                        'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400' => ($kw->avg_position ?? 0) > 10 && ($kw->avg_position ?? 0) <= 20,
                                        'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300' => ($kw->avg_position ?? 0) > 20,
                                    ])>{{ number_format($kw->avg_position ?? 0, 1) }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-5 py-3 dark:border-slate-800">{{ $keywords->links() }}</div>
        @else
            <div class="flex flex-col items-center justify-center px-6 py-16">
                <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ __('No keyword data for this page yet') }}</p>
            </div>
        @endif
    </div>
</div>
