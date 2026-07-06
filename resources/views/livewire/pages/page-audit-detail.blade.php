<div class="mx-auto max-w-5xl space-y-5 px-4 py-6">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <a href="{{ route('pages.index') }}" class="inline-flex items-center gap-1 text-[11px] font-medium text-slate-500 transition hover:text-orange-600 dark:text-slate-400 dark:hover:text-orange-400" wire:navigate>
                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" /></svg>
                Pages
            </a>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-bold tracking-tight text-slate-900 dark:text-slate-100">Page audit</h1>
                <x-guide-link anchor="audit-report-sections" label="Guide to this report" />
            </div>
            <p class="mt-1 break-all font-mono text-sm text-slate-600 dark:text-slate-300">{{ $pageAuditReport->page }}</p>
            @php
                $hdrPl = is_array($pageAuditReport->result ?? null) ? ($pageAuditReport->result['page_locale'] ?? null) : null;
                $hdrPlLabel = \App\Support\Audit\PageLocalePresentation::shortLabel(is_array($hdrPl) ? $hdrPl : null);
            @endphp
            @if ($hdrPlLabel)
                <p class="mt-2 text-xs text-slate-600 dark:text-slate-300">
                    <span class="font-semibold text-slate-800 dark:text-slate-100">Detected market</span>
                    {{ $hdrPlLabel }}
                    @if (! empty($hdrPl['source'] ?? null))
                        <span class="text-slate-400 dark:text-slate-500">· {{ str_replace('_', ' ', (string) $hdrPl['source']) }}</span>
                    @endif
                </p>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a
                href="{{ route('pages.show', ['id' => rawurlencode($pageAuditReport->page)]) }}"
                wire:navigate
                class="inline-flex h-9 items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
            >
                Page context
            </a>
            <a
                href="{{ $pageAuditReport->page }}"
                target="_blank"
                rel="noopener"
                class="inline-flex h-9 items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
            >
                Open URL
                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
            </a>
        </div>
    </header>

    @php $auditWebsite = $pageAuditReport->website; @endphp
    @if ($auditWebsite && ! $auditWebsite->hasGa() && ! $auditWebsite->hasGsc())
        {{-- Degraded audit: no Google data connected for this report's site.
             The audit still renders, but lacks real traffic / rankings /
             impressions / indexing context. Prominent CTA opens the modal. --}}
        <div class="overflow-hidden rounded-2xl border border-orange-300 bg-orange-50 shadow-sm dark:border-orange-500/40 dark:bg-orange-500/10">
            <div class="flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-orange-100 text-orange-600 dark:bg-orange-500/20 dark:text-orange-300">
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m9.86-3.06a4.5 4.5 0 00-1.242-7.244l-4.5-4.5a4.5 4.5 0 00-6.364 6.364L4.34 8.374" /></svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-sm font-bold text-orange-900 dark:text-orange-100">Limited audit — no Google data connected</p>
                        <p class="mt-1 max-w-xl text-xs leading-relaxed text-orange-800/90 dark:text-orange-200/80">
                            Connect Google Analytics or Search Console for <span class="font-semibold">{{ $auditWebsite->domain ?: 'this website' }}</span> to enrich this audit with real traffic, keyword rankings, impressions and indexing status.
                        </p>
                    </div>
                </div>
                <button
                    type="button"
                    x-on:click="window.dispatchEvent(new CustomEvent('open-connect-sources', { detail: { websiteId: {{ $auditWebsite->id }} } }))"
                    class="inline-flex h-9 shrink-0 items-center justify-center gap-1.5 rounded-lg bg-orange-600 px-4 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-700"
                >
                    Connect now
                </button>
            </div>
        </div>
    @endif

    @if ($auditMessage)
        <div @class([
            'rounded-lg border px-4 py-3 text-sm',
            'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900/40 dark:bg-emerald-500/10 dark:text-emerald-200' => $auditMessageKind === 'success',
            'border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-900/40 dark:bg-rose-500/10 dark:text-rose-200' => $auditMessageKind === 'error',
            'border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-900/40 dark:bg-sky-500/10 dark:text-sky-200' => $auditMessageKind === 'info',
        ]) role="status">
            {{ $auditMessage }}
        </div>
    @endif

    @include('livewire.pages.partials.audit-report', ['auditReport' => $pageAuditReport, 'openAuditSummary' => true])
</div>
