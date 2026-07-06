@php
    use Illuminate\Support\Str;
@endphp

<div class="mx-auto max-w-4xl space-y-6" @if ($hasPending) wire:poll.3s @endif>
    {{-- Page header --}}
    <header class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="border-b border-slate-100 px-5 py-4 dark:border-slate-800">
            <a href="{{ route('pages.index') }}" wire:navigate class="inline-flex items-center gap-1 text-[11px] font-medium text-slate-500 transition hover:text-orange-600 dark:text-slate-400 dark:hover:text-orange-400">
                <svg class="h-3.5 w-3.5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" /></svg>
                Back to pages
            </a>
            <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <h1 class="text-xl font-bold tracking-tight text-slate-900 dark:text-slate-100 sm:text-2xl">Custom page audit</h1>
                        <x-guide-link anchor="custom-audit" />
                    </div>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-slate-500 dark:text-slate-400">
                        Run an audit for any URL on the selected site and set the <span class="font-medium text-slate-700 dark:text-slate-300">SERP benchmark keyword</span> yourself. Search Console’s primary query is not used for the SERP snapshot on this flow.
                    </p>
                </div>
                @if ($website)
                    <span class="inline-flex shrink-0 items-center rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-semibold text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200" title="Current website">
                        {{ $website->domain }}
                    </span>
                @endif
            </div>
        </div>
        <p class="px-5 py-3 text-[11px] leading-relaxed text-slate-500 dark:text-slate-400">
            Audits fetch the live page, run checks, and may take up to a minute. You will confirm the Google SERP country before the run starts when we can infer locale from the page.
        </p>
    </header>

    @if ($message)
        <div
            @class([
                'rounded-xl border px-4 py-3 text-sm shadow-sm',
                'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900/40 dark:bg-emerald-500/10 dark:text-emerald-200' => $messageKind === 'success',
                'border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-900/40 dark:bg-rose-500/10 dark:text-rose-200' => $messageKind === 'error',
                'border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-900/40 dark:bg-sky-500/10 dark:text-sky-200' => $messageKind === 'info',
            ])
            role="status"
            aria-live="polite"
        >
            {{ $message }}
        </div>
    @endif

    @if ($website && ! $website->hasGa() && ! $website->hasGsc())
        {{-- Degraded audit: no Google data connected. The audit still runs
             (live page + SERP checks), but without Analytics/Search Console
             we can't enrich it with real traffic, rankings, impressions or
             indexing status. Prominent CTA opens the connect-sources modal. --}}
        <div class="overflow-hidden rounded-2xl border border-orange-300 bg-orange-50 shadow-sm dark:border-orange-500/40 dark:bg-orange-500/10">
            <div class="flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-orange-100 text-orange-600 dark:bg-orange-500/20 dark:text-orange-300">
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m9.86-3.06a4.5 4.5 0 00-1.242-7.244l-4.5-4.5a4.5 4.5 0 00-6.364 6.364L4.34 8.374" /></svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-sm font-bold text-orange-900 dark:text-orange-100">You’re running audits in limited mode</p>
                        <p class="mt-1 max-w-xl text-xs leading-relaxed text-orange-800/90 dark:text-orange-200/80">
                            No Google Analytics or Search Console is connected for <span class="font-semibold">{{ $website->domain ?: 'this website' }}</span>, so audits can’t show real traffic, keyword rankings, impressions or indexing status. Connect a source to unlock the full audit.
                        </p>
                    </div>
                </div>
                <button
                    type="button"
                    x-on:click="window.dispatchEvent(new CustomEvent('open-connect-sources'))"
                    class="inline-flex h-9 shrink-0 items-center justify-center gap-1.5 rounded-lg bg-orange-600 px-4 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-700"
                >
                    Connect now
                </button>
            </div>
        </div>
    @endif

    @if ($websiteId === 0)
        <div class="rounded-2xl border border-amber-200 bg-amber-50/90 px-5 py-4 shadow-sm dark:border-amber-900/50 dark:bg-amber-500/10">
            <div class="flex gap-3">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                <div>
                    <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">Select a website first</p>
                    <p class="mt-1 text-xs leading-relaxed text-amber-800/90 dark:text-amber-200/80">Choose a site from the header, then return here to run a custom audit.</p>
                    <a href="{{ route('websites.index') }}" wire:navigate class="mt-3 inline-flex text-xs font-semibold text-amber-900 underline decoration-amber-700/40 underline-offset-2 hover:decoration-amber-900 dark:text-amber-100 dark:hover:text-white">Manage websites</a>
                </div>
            </div>
        </div>
    @else
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="border-b border-slate-100 px-5 py-3 dark:border-slate-800">
                <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">Run audit</h2>
                <p class="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">URLs must stay on the current site’s domain. <code class="rounded bg-slate-100 px-1 py-px font-mono text-[10px] text-slate-700 dark:bg-slate-800 dark:text-slate-300">https://</code> is added when omitted.</p>
            </div>

            <form wire:submit="queueAudit" class="space-y-5 p-5" novalidate>
                <div class="space-y-5">
                    <div>
                        <label for="custom-audit-url" class="block text-xs font-semibold uppercase tracking-wide text-slate-700 dark:text-slate-300">Page URL</label>
                        <input
                            id="custom-audit-url"
                            type="url"
                            inputmode="url"
                            autocomplete="url"
                            wire:model.blur="pageUrl"
                            placeholder="https://example.com/your-page"
                            @class([
                                'mt-1.5 block w-full rounded-lg border bg-white px-3 py-2.5 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-slate-950 dark:text-slate-100 dark:placeholder:text-slate-500',
                                'border-rose-400 focus:border-rose-500 focus:ring-rose-500/25 dark:border-rose-500/60' => $errors->has('pageUrl'),
                                'border-slate-300 focus:border-orange-500 focus:ring-orange-500/20 dark:border-slate-600' => ! $errors->has('pageUrl'),
                            ])
                            aria-invalid="{{ $errors->has('pageUrl') ? 'true' : 'false' }}"
                            @if ($errors->has('pageUrl')) aria-describedby="custom-audit-url-error" @endif
                        />
                        @if ($errors->has('pageUrl'))
                            <p id="custom-audit-url-error" class="mt-1.5 text-xs font-medium text-rose-600 dark:text-rose-400">{{ $errors->first('pageUrl') }}</p>
                        @endif
                    </div>

                    <div>
                        <label for="custom-audit-keyword" class="block text-xs font-semibold uppercase tracking-wide text-slate-700 dark:text-slate-300">Target keyword (SERP)</label>
                        <p class="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">Drives SERP organic results, in-sample rank checks, and competitor readability averages.</p>
                        <input
                            id="custom-audit-keyword"
                            type="text"
                            autocomplete="off"
                            wire:model.blur="targetKeyword"
                            placeholder="e.g. best project management software"
                            @class([
                                'mt-1.5 block w-full rounded-lg border bg-white px-3 py-2.5 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-slate-950 dark:text-slate-100 dark:placeholder:text-slate-500',
                                'border-rose-400 focus:border-rose-500 focus:ring-rose-500/25 dark:border-rose-500/60' => $errors->has('targetKeyword'),
                                'border-slate-300 focus:border-orange-500 focus:ring-orange-500/20 dark:border-slate-600' => ! $errors->has('targetKeyword'),
                            ])
                            aria-invalid="{{ $errors->has('targetKeyword') ? 'true' : 'false' }}"
                            @if ($errors->has('targetKeyword')) aria-describedby="custom-audit-keyword-error" @endif
                        />
                        @if ($errors->has('targetKeyword'))
                            <p id="custom-audit-keyword-error" class="mt-1.5 text-xs font-medium text-rose-600 dark:text-rose-400">{{ $errors->first('targetKeyword') }}</p>
                        @endif
                    </div>
                </div>

                @if ($awaitingSerpCountryChoice)
                    <div class="rounded-xl border border-orange-200 bg-orange-50/60 p-4 dark:border-orange-900/50 dark:bg-orange-500/10" role="region" aria-labelledby="custom-audit-serp-heading">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 id="custom-audit-serp-heading" class="text-sm font-bold text-slate-900 dark:text-slate-100">Confirm SERP country</h3>
                            <span class="rounded-full bg-white/80 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-orange-700 ring-1 ring-orange-200 dark:bg-slate-900/80 dark:text-orange-300 dark:ring-orange-800">Step 2 of 2</span>
                        </div>
                        @if ($serpCountryRecommendationHint)
                            <p class="mt-2 text-xs leading-relaxed text-slate-600 dark:text-slate-400">{{ $serpCountryRecommendationHint }}</p>
                        @else
                            <p class="mt-2 text-xs leading-relaxed text-slate-600 dark:text-slate-400">Choose the regional Google index used for the organic snapshot. A best guess is pre-selected from your page.</p>
                        @endif
                        <label for="custom-audit-serp-gl" class="mt-3 block text-[11px] font-semibold text-slate-700 dark:text-slate-300">Country</label>
                        <select
                            id="custom-audit-serp-gl"
                            wire:model="serpCountryGl"
                            class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"
                        >
                            @foreach (\App\Support\Audit\SerpGlCatalog::selectOptions() as $code => $label)
                                <option value="{{ $code }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="flex flex-col gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="queueAudit"
                            class="inline-flex h-9 items-center justify-center rounded-lg bg-orange-600 px-4 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-500 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-orange-500 dark:hover:bg-orange-400"
                        >
                            <span wire:loading.remove wire:target="queueAudit">{{ $awaitingSerpCountryChoice ? 'Confirm and queue audit' : 'Run audit' }}</span>
                            <span wire:loading wire:target="queueAudit" class="inline-flex items-center gap-2">
                                <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                Queuing…
                            </span>
                        </button>
                        @if ($awaitingSerpCountryChoice)
                            <button
                                type="button"
                                wire:click="cancelSerpCountryStep"
                                wire:loading.attr="disabled"
                                wire:target="queueAudit"
                                class="inline-flex h-9 items-center justify-center rounded-lg border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:opacity-60 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
                            >
                                Edit URL &amp; keyword
                            </button>
                        @endif
                    </div>
                    <p wire:loading.remove wire:target="queueAudit" class="text-[11px] text-slate-500 dark:text-slate-400">First submit checks the page and may ask for SERP region. Second submit queues the audit — it runs in the background.</p>
                    <p wire:loading wire:target="queueAudit" class="text-[11px] font-medium text-orange-600 dark:text-orange-400">Queuing…</p>
                </div>
            </form>
        </div>

        @if ($hasPending)
            <div class="flex items-start gap-3 rounded-xl border border-orange-200 bg-orange-50/70 px-4 py-3 text-sm shadow-sm dark:border-orange-900/50 dark:bg-orange-500/10" role="status" aria-live="polite">
                <svg class="mt-0.5 h-4 w-4 shrink-0 animate-spin text-orange-600 dark:text-orange-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                <div class="min-w-0 text-xs leading-relaxed text-orange-900 dark:text-orange-200">
                    <p class="font-semibold">Audits are running in the background.</p>
                    <p class="mt-0.5 text-orange-800/90 dark:text-orange-200/80">The list updates itself every few seconds. You can close this tab and come back — rows stay here with their final status.</p>
                </div>
            </div>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900" aria-labelledby="custom-audit-history-heading">
            <div class="flex flex-col gap-1 border-b border-slate-100 px-5 py-4 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 id="custom-audit-history-heading" class="text-sm font-bold text-slate-900 dark:text-slate-100">Recent audits</h2>
                    <p class="mt-1 max-w-2xl text-[11px] leading-relaxed text-slate-500 dark:text-slate-400">
                        Runs from this page, WordPress SEO Analysis, and page-level audits on Serfix. Opening the report shows the <span class="font-medium text-slate-600 dark:text-slate-300">latest saved audit</span> for that URL.
                    </p>
                </div>
                @if ($recentAudits->isNotEmpty())
                    <span class="text-[11px] font-medium text-slate-400 dark:text-slate-500">Last {{ $recentAudits->count() }} on this site</span>
                @endif
            </div>

            @if ($recentAudits->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-left text-xs dark:divide-slate-700">
                        <thead class="bg-slate-50 text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:bg-slate-800/80 dark:text-slate-400">
                            <tr>
                                <th scope="col" class="whitespace-nowrap px-4 py-3">When</th>
                                <th scope="col" class="whitespace-nowrap px-4 py-3">Source</th>
                                <th scope="col" class="min-w-[10rem] px-4 py-3">Page</th>
                                <th scope="col" class="min-w-[7rem] px-4 py-3">Market</th>
                                <th scope="col" class="min-w-[8rem] px-4 py-3">Keyword</th>
                                <th scope="col" class="whitespace-nowrap px-4 py-3">By</th>
                                <th scope="col" class="whitespace-nowrap px-4 py-3">Status</th>
                                <th scope="col" class="whitespace-nowrap px-4 py-3 text-right">Report</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach ($recentAudits as $row)
                                <tr class="bg-white transition hover:bg-slate-50/80 dark:bg-slate-900 dark:hover:bg-slate-800/40">
                                    <td class="whitespace-nowrap px-4 py-3 text-slate-600 dark:text-slate-300">
                                        <time datetime="{{ $row->created_at->toIso8601String() }}" title="{{ $row->created_at->toIso8601String() }}">{{ $row->created_at->diffForHumans() }}</time>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-slate-600 dark:text-slate-300">
                                        {{ $row->portalSourceLabel() }}
                                    </td>
                                    <td class="max-w-[14rem] px-4 py-3">
                                        <span class="block truncate font-mono text-[11px] text-slate-800 dark:text-slate-200" title="{{ $row->page_url }}">{{ Str::limit($row->page_url, 56) }}</span>
                                    </td>
                                    <td class="max-w-[10rem] truncate px-4 py-3 text-slate-600 dark:text-slate-300" title="{{ $row->serp_sample_gl ? 'SERP country: '.$row->serp_sample_gl : '' }}">
                                        @php
                                            $caPl = is_array($row->pageAuditReport?->result['page_locale'] ?? null) ? $row->pageAuditReport->result['page_locale'] : null;
                                            $mkt = \App\Support\Audit\PageLocalePresentation::shortLabel($caPl);
                                            if ($mkt === null && filled($row->serp_sample_gl)) {
                                                $mkt = 'SERP: '.\App\Support\Audit\SerpGlCatalog::labelFor($row->serp_sample_gl).' ('.$row->serp_sample_gl.')';
                                            }
                                        @endphp
                                        {{ $mkt ?? '—' }}
                                    </td>
                                    <td class="max-w-[12rem] px-4 py-3">
                                        <span class="line-clamp-2 text-slate-800 dark:text-slate-200" title="{{ $row->target_keyword !== '' ? $row->target_keyword : '—' }}">{{ $row->target_keyword !== '' ? Str::limit($row->target_keyword, 80) : '—' }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-slate-600 dark:text-slate-300">
                                        @if ($row->user_id === auth()->id())
                                            You
                                        @else
                                            {{ $row->user?->name ?? '—' }}
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3">
                                        @switch($row->status)
                                            @case(\App\Models\CustomPageAudit::STATUS_QUEUED)
                                                <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">
                                                    <span class="relative flex h-1.5 w-1.5">
                                                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-500 opacity-75"></span>
                                                        <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                                                    </span>
                                                    Queued
                                                </span>
                                                @break
                                            @case(\App\Models\CustomPageAudit::STATUS_RUNNING)
                                                <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-100 px-2 py-0.5 text-[10px] font-semibold text-sky-800 dark:bg-sky-500/15 dark:text-sky-300">
                                                    <svg class="h-3 w-3 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                                    Running
                                                </span>
                                                @break
                                            @case(\App\Models\CustomPageAudit::STATUS_COMPLETED)
                                                <span class="inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300">Done</span>
                                                @break
                                            @case(\App\Models\CustomPageAudit::STATUS_FAILED)
                                                <span
                                                    class="inline-flex rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-semibold text-rose-800 dark:bg-rose-500/15 dark:text-rose-300"
                                                    @if ($row->error_message) title="{{ $row->error_message }}" @endif
                                                >Failed</span>
                                                @break
                                            @default
                                                <span class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-700 dark:bg-slate-700 dark:text-slate-200">{{ ucfirst((string) $row->status) }}</span>
                                        @endswitch
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right">
                                        @if ($row->isCompleted() && $row->page_audit_report_id)
                                            <a
                                                href="{{ route('page-audits.show', $row->page_audit_report_id) }}"
                                                wire:navigate
                                                class="inline-flex h-7 items-center rounded-md border border-slate-200 bg-white px-2.5 text-[11px] font-semibold text-orange-700 shadow-sm transition hover:border-orange-200 hover:bg-orange-50 dark:border-slate-600 dark:bg-slate-900 dark:text-orange-300 dark:hover:border-orange-900 dark:hover:bg-orange-500/10"
                                            >View</a>
                                        @elseif ($row->isFailed())
                                            <button
                                                type="button"
                                                wire:click="retryAudit('{{ $row->id }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="retryAudit('{{ $row->id }}')"
                                                class="inline-flex h-7 items-center rounded-md border border-rose-200 bg-white px-2.5 text-[11px] font-semibold text-rose-700 shadow-sm transition hover:bg-rose-50 disabled:opacity-60 dark:border-rose-900/60 dark:bg-slate-900 dark:text-rose-300 dark:hover:bg-rose-500/10"
                                            >Retry</button>
                                        @else
                                            <span class="text-slate-400">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="flex flex-col items-center justify-center px-6 py-14 text-center">
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800">
                        <svg class="h-6 w-6 text-slate-400 dark:text-slate-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.25" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    </div>
                    <p class="mt-4 text-sm font-medium text-slate-600 dark:text-slate-300">No custom audits yet</p>
                    <p class="mt-1 max-w-sm text-xs text-slate-500 dark:text-slate-400">Submit the form above to log runs here. Completed audits open in a full report.</p>
                </div>
            @endif
        </section>
    @endif
</div>
