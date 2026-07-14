{{-- $scroll (optional): cap the table to a scrollable viewport with a sticky
     header once there are more rows than fit comfortably on screen — the
     full row set is still in the DOM (and exportable), just not all visible
     at once. Omit/false to render a plain, fully-expanded table.

     The default browser scrollbar inside a nested overflow box is easy to
     miss entirely (thin/hidden-until-hover on most OS+browser combos) — a
     420px box holds ~25 rows before it needs scrolling, and without an
     obvious scroll affordance that reads as "only 25 rows exist" even
     though the rest are right there in the DOM. Fixed with an always-visible
     styled scrollbar + a loud row-count badge instead of muted text. --}}
@php $isScrollable = ($scroll ?? false) && count($rows) > 8; @endphp
<div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3 dark:border-slate-800">
        <span class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ $title }}</span>
        @if ($isScrollable)
            <span class="inline-flex items-center gap-1 rounded-full bg-orange-50 px-2.5 py-1 text-xs font-semibold text-orange-700 dark:bg-orange-500/15 dark:text-orange-300">
                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 13.5L12 21m0 0l-7.5-7.5M12 21V3" /></svg>
                {{ number_format(count($rows)) }} {{ \Illuminate\Support\Str::plural('row', count($rows)) }} — scroll for more
            </span>
        @else
            <span class="text-xs text-slate-400">{{ number_format(count($rows)) }} {{ \Illuminate\Support\Str::plural('row', count($rows)) }}</span>
        @endif
    </div>
    <div @class([
        'overflow-x-auto',
        'max-h-[420px] overflow-y-auto [&::-webkit-scrollbar]:w-2.5 [&::-webkit-scrollbar-track]:bg-slate-50 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-slate-300 dark:[&::-webkit-scrollbar-track]:bg-slate-800 dark:[&::-webkit-scrollbar-thumb]:bg-slate-600' => $scroll ?? false,
    ])>
        <table class="w-full text-sm">
            <thead @class(['bg-white', 'sticky top-0 z-10' => $scroll ?? false])>
                <tr class="text-left text-xs uppercase tracking-wide text-slate-400">
                    @foreach ($head as $i => $col)
                        <th class="border-b border-slate-100 bg-white px-5 py-2 font-medium {{ $i === 0 ? '' : 'text-right' }}">{{ $col }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="border-t border-slate-100">
                        @foreach ($row as $cell)
                            <td class="px-5 py-2.5 {{ ! empty($cell['right']) ? 'text-right text-slate-600' : '' }}">
                                @if (isset($cell['link']))
                                    <span class="inline-flex items-center gap-2">
                                        <img src="https://www.google.com/s2/favicons?domain={{ urlencode($cell['link']) }}&sz=32" alt="" width="16" height="16" class="h-4 w-4 flex-none rounded-sm bg-slate-100" loading="lazy" onerror="this.style.visibility='hidden'">
                                        <span class="font-medium text-slate-800">{{ $cell['link'] }}</span>
                                    </span>
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
