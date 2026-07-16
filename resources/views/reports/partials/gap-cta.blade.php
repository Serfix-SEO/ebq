{{-- Prominent "deep dive in the Keyword Gap tool" CTA — styled as a secondary
     table header bar that attaches directly under the preceding table (negative
     top margin closes the gap). Renders nothing without $gapUrl (public shares /
     competitor lookups). Vars: $gapUrl, $gapText. --}}
@if (! empty($gapUrl ?? null))
    {{-- -mt-6 cancels the parent's space-y-6 gap so the bar butts the table above. --}}
    <a href="{{ $gapUrl }}"
       class="group -mt-6 flex items-center justify-between gap-3 rounded-b-2xl border-x border-b border-orange-200 bg-gradient-to-r from-orange-50 to-amber-50 px-5 py-3.5 shadow-sm transition hover:from-orange-100 hover:to-amber-100">
        <span class="flex items-center gap-2.5">
            <span class="flex h-7 w-7 flex-none items-center justify-center rounded-lg bg-orange-600 text-white">
                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h12M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3m0 0l.5 1.5m-.5-1.5h-9.5m0 0l-.5 1.5M9 11.25v1.5M12 9v3.75m3-6v6" /></svg>
            </span>
            <span class="text-sm font-bold text-orange-900">{{ $gapText ?? 'Run a Keyword Gap analysis' }}</span>
        </span>
        <span class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-orange-700">
            Deep dive
            <svg class="h-4 w-4 transition group-hover:translate-x-0.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
        </span>
    </a>
@endif
