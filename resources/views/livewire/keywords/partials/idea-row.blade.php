{{-- One keyword-idea result row. Params: $row, $compPill, $intentPill, $selected, $withCheckbox, $hasGsc, $gscMetrics. --}}
@php
    $key = mb_strtolower($row['keyword']);
    [$intentShort, $intentClass, $intentTitle] = $intentPill[$row['intent'] ?? 'other'] ?? $intentPill['other'];
    $gsc = ($hasGsc ?? false) ? (($gscMetrics ?? [])[$key] ?? null) : null;
@endphp
<tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/30" wire:key="row-{{ md5($key) }}">
    @if ($withCheckbox ?? false)
        <td class="w-8 px-3 py-2.5">
            <input type="checkbox" wire:click="toggleSelected(@js($row['keyword']))" @checked(in_array($key, $selected, true))
                class="rounded border-slate-300 text-orange-600 focus:ring-orange-500/30 dark:border-slate-600" />
        </td>
    @endif
    <td class="px-4 py-2.5 font-medium text-slate-800 dark:text-slate-100">{{ $row['keyword'] }}</td>
    <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ $row['volume'] !== null ? number_format($row['volume']) : '—' }}</td>
    <td class="px-4 py-2.5">
        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold {{ $compPill[$row['comp_level']] ?? $compPill['unknown'] }}">
            {{ $row['competition'] }}@if ($row['competitionIndex'] !== null) <span class="opacity-60">{{ $row['competitionIndex'] }}</span>@endif
        </span>
    </td>
    <td class="px-4 py-2.5 text-right tabular-nums text-slate-600 dark:text-slate-300">
        @if ($row['low'] !== null || $row['high'] !== null)
            ${{ number_format((float) ($row['low'] ?? 0), 2) }}–${{ number_format((float) ($row['high'] ?? 0), 2) }}
        @else
            —
        @endif
    </td>
    <td class="px-4 py-2.5">
        <span title="{{ $intentTitle }}" class="inline-flex h-5 w-5 items-center justify-center rounded-full text-[10px] font-bold {{ $intentClass }}">{{ $intentShort }}</span>
    </td>
    <td class="px-4 py-2.5 text-right whitespace-nowrap">
        @if (! ($hasGsc ?? false))
            <span class="text-[11px] text-slate-300 dark:text-slate-600" title="{{ __('Connect Search Console to see if you already rank for this keyword.') }}">—</span>
        @elseif ($gsc)
            <div class="inline-flex flex-col items-end leading-tight">
                <span class="text-xs font-semibold tabular-nums text-slate-900 dark:text-slate-100">#{{ $gsc['position'] }}</span>
                <span class="text-[10px] tabular-nums text-slate-500 dark:text-slate-400">{{ number_format($gsc['clicks']) }} {{ __('clicks') }} · {{ number_format($gsc['impressions']) }} {{ __('impr') }}</span>
            </div>
        @else
            <span class="inline-flex items-center rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-semibold text-sky-700 dark:bg-sky-500/10 dark:text-sky-400" title="{{ __('Not currently ranking for this keyword — an untapped topic.') }}">{{ __('New') }}</span>
        @endif
    </td>
</tr>
