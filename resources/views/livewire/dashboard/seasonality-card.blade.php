<div>
    @if (! empty($rows))
        <div class="rounded-xl border border-amber-200 bg-amber-50/40 p-5 shadow-sm dark:border-amber-900/40 dark:bg-amber-500/5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-amber-700 dark:text-amber-400">
                        <span class="me-1">◐</span> {{ __('Seasonal peaks ahead') }}
                    </p>
                    <p class="mt-0.5 text-[11px] text-amber-800/70 dark:text-amber-300/70">
                        {{ __('Refresh these pages now — historical search peaks arrive in the next 60 days.') }}
                    </p>
                </div>
            </div>

            <ul class="mt-4 space-y-2.5">
                @foreach ($rows as $row)
                    <li class="flex items-center justify-between gap-3 text-xs">
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-semibold text-slate-900 dark:text-slate-100" title="{{ $row['keyword'] }}">{{ $row['keyword'] }}<x-keyword-language :language="$row['language'] ?? null" /></div>
                            <div class="mt-0.5 text-[10px] text-slate-500 dark:text-slate-400">
                                {{ __('peaks in') }} <span class="font-medium text-amber-700 dark:text-amber-400">{{ $row['peak_month_name'] }}</span>
                                @if ($row['search_volume'] !== null)
                                    · {{ number_format($row['search_volume']) }}{{ __('/mo at peak') }}
                                @endif
                            </div>
                        </div>
                        <span class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold tabular-nums text-amber-800 dark:bg-amber-500/20 dark:text-amber-300">
                            @if ($row['months_until'] === 0)
                                {{ __('this month') }}
                            @elseif ($row['months_until'] === 1)
                                {{ __('1 mo away') }}
                            @else
                                {{ $row['months_until'] }} {{ __('mo away') }}
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
