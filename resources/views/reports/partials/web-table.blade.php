{{-- $scroll (optional): cap the table to a scrollable viewport with a sticky
     header once there are more rows than fit comfortably on screen — the
     full row set is still in the DOM (and exportable), just not all visible
     at once. Omit/false to render a plain, fully-expanded table.

     Production-grade table behavior (2026-07-16): every column header is
     click-to-sort (numeric-aware) and larger tables get a live text filter —
     both pure client-side via reports/partials/table-tools.blade.php, so
     they work on the public share page too.

     The default browser scrollbar inside a nested overflow box is easy to
     miss entirely (thin/hidden-until-hover on most OS+browser combos) — a
     420px box holds ~25 rows before it needs scrolling, and without an
     obvious scroll affordance that reads as "only 25 rows exist" even
     though the rest are right there in the DOM. Fixed with an always-visible
     styled scrollbar + a loud row-count badge instead of muted text. --}}
@php
    $isScrollable = ($scroll ?? false) && count($rows) > 8;
    $tableId = 'rpt-'.\Illuminate\Support\Str::slug($title).'-'.substr(md5($title.count($rows)), 0, 6);
@endphp
<div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800" id="{{ $tableId }}">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-3 dark:border-slate-800">
        <span class="inline-flex items-center gap-2 text-base font-semibold text-slate-900 dark:text-slate-100">
            {{ $title }}
            @if (! empty($badge))
                {{-- Data-source tag: shown when the section's data is not a
                     direct measurement of the site itself. --}}
                <span class="rounded-full bg-sky-50 px-2 py-0.5 text-[11px] font-medium normal-case tracking-normal text-sky-700 ring-1 ring-sky-200 dark:bg-sky-500/15 dark:text-sky-300 dark:ring-sky-800">{{ $badge }}</span>
            @endif
        </span>
        <span class="flex items-center gap-2.5">
            @if ($isScrollable)
                <input type="text" data-rpt-filter="{{ $tableId }}" placeholder="Filter…"
                       class="w-36 rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-700 placeholder:text-slate-400 focus:border-orange-400 focus:outline-none focus:ring-1 focus:ring-orange-400">
                <span class="inline-flex items-center gap-1 rounded-full bg-orange-50 px-2.5 py-1 text-xs font-semibold text-orange-700 dark:bg-orange-500/15 dark:text-orange-300">
                    <span data-rpt-count="{{ $tableId }}" data-total="{{ number_format(count($rows)) }}">{{ number_format(count($rows)) }}</span>
                    {{ \Illuminate\Support\Str::plural('row', count($rows)) }}
                </span>
            @else
                <span class="text-xs text-slate-400">{{ number_format(count($rows)) }} {{ \Illuminate\Support\Str::plural('row', count($rows)) }}</span>
            @endif
        </span>
    </div>
    <div @class([
        'overflow-x-auto',
        'max-h-[420px] overflow-y-auto [&::-webkit-scrollbar]:w-2.5 [&::-webkit-scrollbar-track]:bg-slate-50 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-slate-300 dark:[&::-webkit-scrollbar-track]:bg-slate-800 dark:[&::-webkit-scrollbar-thumb]:bg-slate-600' => $scroll ?? false,
    ])>
        <table class="w-full text-sm">
            <thead @class(['bg-white', 'sticky top-0 z-10' => $scroll ?? false])>
                <tr class="text-left text-xs uppercase tracking-wide text-slate-400">
                    @foreach ($head as $i => $col)
                        <th data-sort class="cursor-pointer select-none border-b border-slate-100 bg-white px-5 py-2 font-medium transition hover:text-slate-600 {{ $i === 0 ? '' : 'text-right' }}"
                            title="Sort by {{ strtolower($col) }}">
                            {{ $col }} <span class="sort-ico text-[10px] text-slate-300">↕</span>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    @php
                        // Full-text haystack for the filter — includes each
                        // cell's 'search' override (untruncated anchors/URLs).
                        $rowSearch = collect($row)->map(fn ($c) => $c['search'] ?? ($c['anchorSearch'] ?? ($c['link'] ?? ($c['text'] ?? ($c['pill'] ?? '')))))->implode(' ');
                        $rowRisk = collect($row)->pluck('risk')->filter()->first();
                    @endphp
                    <tr @class(['border-t border-slate-100 transition', 'hover:bg-slate-50' => $rowRisk === null, 'bg-rose-50/60 hover:bg-rose-50' => $rowRisk === 'high', 'bg-amber-50/60 hover:bg-amber-50' => $rowRisk === 'medium']) data-search="{{ $rowSearch }}">
                        @foreach ($row as $cell)
                            <td class="px-5 py-2.5 {{ ! empty($cell['right']) ? 'text-right text-slate-600' : '' }}">
                                @if (isset($cell['link']))
                                    <span class="inline-flex items-center gap-2">
                                        <img src="https://www.google.com/s2/favicons?domain={{ urlencode($cell['link']) }}&sz=32" alt="" width="16" height="16" class="h-4 w-4 flex-none rounded-sm bg-slate-100" loading="lazy" onerror="this.style.visibility='hidden'">
                                        <span class="font-medium text-slate-800">{{ $cell['link'] }}</span>
                                        @if (($cell['risk'] ?? null) === 'high')
                                            <span title="{{ $cell['riskWhy'] ?? '' }}" class="rounded-full bg-rose-100 px-1.5 py-0.5 text-[10px] font-bold text-rose-700">⚠ Toxic</span>
                                        @elseif (($cell['risk'] ?? null) === 'medium')
                                            <span title="{{ $cell['riskWhy'] ?? '' }}" class="rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-700">Risky</span>
                                        @endif
                                    </span>
                                @elseif (isset($cell['anchorSearch']))
                                    {{-- Clickable anchor: searches the backlinks table for it. --}}
                                    <button type="button" data-anchor-search="{{ $cell['anchorSearch'] }}" data-target="{{ $cell['target'] ?? 'rpt-backlinks' }}"
                                            title="Search the backlinks list for this anchor"
                                            class="text-left text-slate-700 underline decoration-dotted decoration-slate-300 underline-offset-2 transition hover:text-orange-600">{{ $cell['text'] ?? $cell['anchorSearch'] }}</button>
                                @elseif (isset($cell['pill']))
                                    @php $pv = $cell['pill']; @endphp
                                    @if (is_numeric($pv))
                                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums {{ (int) $pv >= 60 ? 'bg-emerald-50 text-emerald-700' : ((int) $pv >= 30 ? 'bg-amber-50 text-amber-700' : 'bg-rose-50 text-rose-700') }}">{{ (int) $pv }}</span>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                @else
                                    <span class="text-slate-700">{{ $cell['text'] ?? '' }}</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@once
    @include('reports.partials.table-tools')
@endonce
