@php
    // Severity → Tailwind tone. Drives the number badge + tag colours.
    $tones = [
        'critical' => ['tag' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300', 'num' => 'bg-rose-500', 'label' => 'CRITICAL'],
        'high' => ['tag' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300', 'num' => 'bg-amber-500', 'label' => 'HIGH'],
        'growth' => ['tag' => 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300', 'num' => 'bg-blue-500', 'label' => 'GROWTH'],
    ];
@endphp

<div>
    {{-- Hidden during the site's first crawl (crawl-derived); the crawl banner
         stands in. Reappears via the `crawl-state-changed` listener on finish. --}}
    @unless ($hide)
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-orange-100 text-orange-600 dark:bg-orange-500/15 dark:text-orange-400">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
                </span>
                <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">Priority Action Queue</h3>
            </div>
            <div class="flex items-center gap-2">
                @if (count($items) > 0)
                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        {{ count($items) }} ranked by impact
                    </span>
                @endif
                @php $canWhitelabel = (auth()->user()?->effectivePlanFeatures()['report_whitelabel'] ?? false) === true; @endphp
                <a href="{{ route('site-audit.download') }}"
                    class="inline-flex items-center gap-1 rounded-md border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M7.5 12l4.5 4.5m0 0l4.5-4.5m-4.5 4.5V3" /></svg>
                    Export PDF
                </a>
                @if ($canWhitelabel)
                    <a href="{{ route('site-audit.download', ['whitelabel' => 0]) }}"
                        class="text-[10px] text-slate-400 hover:text-slate-600 hover:underline dark:text-slate-500 dark:hover:text-slate-300">
                        (Serfix branding instead)
                    </a>
                @endif
            </div>
        </div>

        @if (count($items) === 0)
            <div class="mt-6 rounded-lg border border-dashed border-slate-200 px-4 py-10 text-center dark:border-slate-700">
                <p class="text-sm font-medium text-slate-700 dark:text-slate-200">You're all caught up</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">No priority actions right now. We re-check your data every day.</p>
            </div>
        @else
            <ul class="mt-4 divide-y divide-slate-100 dark:divide-slate-800">
                @foreach ($items as $i => $item)
                    @php $tone = $tones[$item['severity']] ?? $tones['high']; @endphp
                    <li class="flex items-center gap-4 py-3.5">
                        <span class="flex h-7 w-7 flex-none items-center justify-center rounded-full text-xs font-bold text-white {{ $tone['num'] }}">
                            {{ $i + 1 }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="truncate text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $item['title'] }}</span>
                                <span class="rounded-full px-2 py-px text-[10px] font-bold tabular-nums {{ $tone['tag'] }}">{{ $item['count'] }}</span>
                                <span class="rounded px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide {{ $tone['tag'] }}">{{ $tone['label'] }}</span>
                            </div>
                            @if (! empty($item['types']))
                                {{-- Per-type breakdown with counts (crawl categories) --}}
                                <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                    @foreach (array_slice($item['types'], 0, 6) as $t)
                                        <span class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white py-0.5 pl-2 pr-1 text-[11px] font-medium text-slate-700 shadow-sm dark:border-slate-700 dark:bg-slate-800/80 dark:text-slate-200">
                                            {{ $t['label'] }}
                                            <span class="inline-flex min-w-[1.4rem] items-center justify-center rounded px-1 py-px text-[11px] font-bold tabular-nums {{ $tone['tag'] }}">{{ number_format($t['count']) }}</span>
                                        </span>
                                    @endforeach
                                    @if (count($item['types']) > 6)
                                        <span class="inline-flex items-center rounded px-1 py-0.5 text-[11px] font-medium text-slate-400">+{{ count($item['types']) - 6 }} more</span>
                                    @endif
                                    @if ($item['impact_label'])
                                        <span class="text-[11px] font-medium text-emerald-600 dark:text-emerald-400">· {{ $item['impact_label'] }}</span>
                                    @endif
                                </div>
                            @else
                                <p class="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400">
                                    {{ $item['description'] }}@if ($item['impact_label']) <span class="font-medium text-emerald-600 dark:text-emerald-400">· {{ $item['impact_label'] }}</span>@endif
                                </p>
                            @endif
                        </div>
                        <a href="{{ route('issues.show', ['key' => $item['key']]) }}" wire:navigate
                            class="inline-flex flex-none items-center gap-1 rounded-md border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">
                            {{ $item['action_label'] }}
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
    @endunless
</div>
