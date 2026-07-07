<div>
    @if (! empty($rows))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50/40 p-5 shadow-sm dark:border-emerald-900/40 dark:bg-emerald-500/5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">
                        Quick wins
                    </p>
                    <p class="mt-0.5 text-[11px] text-emerald-800/70 dark:text-emerald-300/70">
                        Low-competition keywords with real volume where you aren't in the top 10 yet.
                    </p>
                </div>
                <a href="{{ route('reports.index') }}?insight=quick_wins" wire:navigate class="text-[11px] font-semibold text-emerald-700 hover:underline dark:text-emerald-400">
                    View all →
                </a>
            </div>

            <ul class="mt-4 space-y-2.5">
                @foreach ($rows as $row)
                    @php
                        $_auditParams = ['targetKeyword' => $row['keyword']];
                        if (! empty($row['current_page'])) {
                            $_auditParams['pageUrl'] = $row['current_page'];
                        }
                        $_auditUrl = route('custom-audit.index').'?'.http_build_query($_auditParams);
                    @endphp
                    <li>
                        <a href="{{ $_auditUrl }}" wire:navigate
                           class="flex items-center justify-between gap-3 rounded-md px-1 py-1 text-xs transition hover:bg-white/60 dark:hover:bg-slate-800/40"
                           title="Open a custom audit with this keyword pre-filled">
                            <div class="min-w-0 flex-1">
                                <div class="truncate font-semibold text-slate-900 dark:text-slate-100">{{ $row['keyword'] }}<x-keyword-language :language="$row['language'] ?? null" /></div>
                                <div class="mt-0.5 text-[10px] text-slate-500 dark:text-slate-400">
                                    {{ number_format($row['search_volume']) }}/mo
                                    @if ($row['current_position'] !== null)
                                        · currently <span class="font-medium">#{{ $row['current_position'] }}</span>
                                    @else
                                        · <span class="font-medium">unranked</span>
                                    @endif
                                    @if ($row['competition'] !== null)
                                        · comp {{ number_format($row['competition'] * 100, 0) }}%
                                    @endif
                                </div>
                            </div>
                            <span class="shrink-0 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold tabular-nums text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-300">
                                {{ number_format((int) $row['search_volume']) }} vol/mo
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
