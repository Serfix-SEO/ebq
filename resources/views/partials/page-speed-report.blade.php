{{-- Shared PageSpeed report body. Expects:
       $result    = ['mobile'=>?array, 'desktop'=>?array, 'fetched_at'=>?, 'lighthouse_version'=>?]
       $testedUrl = string --}}
@php
    $strategies = [
        'mobile' => is_array($result['mobile'] ?? null) ? $result['mobile'] : null,
        'desktop' => is_array($result['desktop'] ?? null) ? $result['desktop'] : null,
    ];
    $defaultStrategy = $strategies['mobile'] !== null ? 'mobile' : 'desktop';
    $isPartial = ($strategies['mobile'] === null) !== ($strategies['desktop'] === null);
    $deviceIcons = [
        'mobile' => 'M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3',
        'desktop' => 'M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12v6.75a2.25 2.25 0 01-2.25 2.25H5.25a2.25 2.25 0 01-2.25-2.25V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25',
    ];
    $perfBadge = fn (?int $s) => $s === null
        ? 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'
        : ($s >= 90 ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400'
            : ($s >= 50 ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400'
                : 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-400'));
@endphp

<section x-data="{ tab: '{{ $defaultStrategy }}' }">
    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <h2 class="text-base font-bold text-slate-900 dark:text-slate-100">Report</h2>
                @if (! empty($testedUrl))
                    <a href="{{ $testedUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex max-w-[16rem] items-center gap-1 truncate text-xs text-orange-600 hover:underline dark:text-orange-400">
                        {{ \Illuminate\Support\Str::after($testedUrl, '://') }}
                        <svg class="h-3 w-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                    </a>
                @endif
            </div>
            <p class="mt-0.5 text-[11px] text-slate-400 dark:text-slate-500">
                @if (! empty($result['fetched_at'])) Measured {{ \Carbon\Carbon::parse($result['fetched_at'])->diffForHumans() }}@endif
                @if (! empty($result['lighthouse_version'])) · Lighthouse {{ $result['lighthouse_version'] }}@endif
                · Lab data
            </p>
        </div>

        <div class="inline-flex shrink-0 rounded-lg border border-slate-200 bg-slate-50 p-0.5 dark:border-slate-700 dark:bg-slate-800" role="tablist" aria-label="Device">
            @foreach (['mobile' => 'Mobile', 'desktop' => 'Desktop'] as $key => $label)
                <button type="button" role="tab"
                    x-on:click="tab = '{{ $key }}'"
                    :aria-selected="tab === '{{ $key }}' ? 'true' : 'false'"
                    :class="tab === '{{ $key }}' ? 'bg-white text-orange-700 shadow-sm dark:bg-slate-900 dark:text-orange-300' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400'"
                    class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $deviceIcons[$key] }}" /></svg>
                    {{ $label }}
                    @php $ps = $strategies[$key]['scores']['performance'] ?? null; @endphp
                    <span class="rounded px-1 py-px text-[10px] font-bold {{ $perfBadge($ps) }}">{{ $ps ?? '—' }}</span>
                </button>
            @endforeach
        </div>
    </div>

    @if ($isPartial)
        <div class="mb-4 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-800 dark:border-amber-900/40 dark:bg-amber-500/10 dark:text-amber-300" role="status">
            <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
            <span>Only one device could be measured this run — the other timed out or failed. The results below are still valid; run the test again for the missing device.</span>
        </div>
    @endif

    @foreach (['mobile', 'desktop'] as $key)
        <div x-show="tab === '{{ $key }}'" x-cloak role="tabpanel">
            @if ($strategies[$key] !== null)
                @include('partials.page-speed-strategy', ['r' => $strategies[$key]])
            @else
                <div class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-slate-50/60 px-4 py-12 text-center dark:border-slate-700 dark:bg-slate-900/40">
                    <svg class="h-8 w-8 text-slate-300 dark:text-slate-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
                    <p class="mt-2 text-sm font-medium text-slate-600 dark:text-slate-300">{{ ucfirst($key) }} scan didn’t complete</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">The site may have been slow or unreachable for this device. Run the test again to retry.</p>
                </div>
            @endif
        </div>
    @endforeach

    <p class="mt-5 text-[11px] leading-relaxed text-slate-400 dark:text-slate-500">
        Lab data from a single Lighthouse run on our servers (no CrUX field data). Scores &amp; thresholds mirror PageSpeed Insights and <span class="font-mono">web.dev/vitals</span>. TBT is the lab-side proxy for INP.
    </p>
</section>
