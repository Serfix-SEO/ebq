{{-- Compare the live SERP organic row (search-result style) with the audited page’s title + meta description from fetched HTML. --}}
@php
    $hasSerpListing = ! empty($ys['matched_listing_url']);
    $hasAudited = ! empty($ys['audited_page_url'] ?? null);
@endphp
@if ($hasSerpListing || $hasAudited)
    <div class="mt-3 grid gap-4 sm:grid-cols-2">
        <div class="rounded-xl border border-slate-200 bg-white/90 p-4 shadow-inner dark:border-slate-600 dark:bg-slate-950/40">
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">SERP sample (organic listing)</p>
            <p class="mt-1 text-[11px] leading-snug text-slate-500 dark:text-slate-400">From live search results for the benchmark keyword — not guaranteed to match live Google.</p>
            @if ($hasSerpListing)
                <p class="mt-2 text-xs leading-snug text-emerald-800 dark:text-emerald-400/90">{{ $ys['matched_listing_display'] ?? '' }}</p>
                <a href="{{ $ys['matched_listing_url'] }}" target="_blank" rel="noopener noreferrer" class="mt-1 block text-base font-medium leading-snug text-blue-700 hover:underline dark:text-blue-400">
                    {{ $ys['matched_listing_title'] ?? \Illuminate\Support\Str::limit($ys['matched_listing_url'], 72) }}
                </a>
                @if (! empty($ys['matched_listing_snippet']))
                    <p class="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-300">{{ $ys['matched_listing_snippet'] }}</p>
                @endif
                <p class="mt-2 break-all font-mono text-[11px] text-slate-500 dark:text-slate-400">{{ $ys['matched_listing_url'] }}</p>
            @else
                <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">No organic row matched your site’s domain in this snapshot.</p>
            @endif
        </div>
        <div class="rounded-xl border border-orange-200/80 bg-orange-50/40 p-4 shadow-inner dark:border-orange-900/50 dark:bg-orange-500/10">
            <p class="text-[10px] font-bold uppercase tracking-wider text-orange-800 dark:text-orange-200">Your page (live URL, audit snapshot)</p>
            <p class="mt-1 text-[11px] leading-snug text-slate-600 dark:text-slate-400">Title and meta description from the HTML we fetched for this audit — compare wording to the SERP listing on the left.</p>
            @if ($hasAudited)
                <p class="mt-2 text-xs leading-snug text-emerald-900 dark:text-emerald-300/90">{{ $ys['audited_page_display'] ?? '' }}</p>
                <p class="mt-1 text-base font-medium leading-snug text-slate-900 dark:text-slate-100">
                    {{ $ys['audited_page_title'] ?? \Illuminate\Support\Str::limit($ys['audited_page_url'], 72) }}
                </p>
                @if (! empty($ys['audited_page_snippet']))
                    <p class="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-300">{{ $ys['audited_page_snippet'] }}</p>
                @else
                    <p class="mt-2 text-sm italic text-slate-500 dark:text-slate-400">No meta description in HTML.</p>
                @endif
                <p class="mt-2 break-all font-mono text-[11px] text-slate-500 dark:text-slate-400">{{ $ys['audited_page_url'] }}</p>
            @else
                <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">No on-page title or meta snapshot stored for this report (re-run the audit to compare).</p>
            @endif
        </div>
    </div>
@endif
