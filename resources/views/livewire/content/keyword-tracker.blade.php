@php
    // ── Inline SVG helpers (no external chart lib; prebuilt-Tailwind only) ──
    // Sparkline: a small polyline of the daily click counts.
    $spark = function (array $vals, int $w = 110, int $h = 28): string {
        $vals = array_values(array_map('intval', $vals));
        $n = count($vals);
        if ($n === 0) {
            return '';
        }
        if ($n === 1) {
            $vals = [$vals[0], $vals[0]];
            $n = 2;
        }
        $max = max($vals) ?: 1;
        $step = $w / ($n - 1);
        $pts = [];
        foreach ($vals as $i => $v) {
            $x = round($i * $step, 1);
            $y = round($h - ($v / $max) * ($h - 2) - 1, 1);
            $pts[] = "$x,$y";
        }

        return implode(' ', $pts);
    };
    // Larger area/line chart for the performance panel.
    $chart = function (array $days, string $field, int $w = 520, int $h = 120) {
        $vals = array_map(fn ($d) => (float) ($d[$field] ?? 0), $days);
        $n = count($vals);
        if ($n === 0) {
            return null;
        }
        if ($n === 1) {
            $vals[] = $vals[0];
            $n = 2;
        }
        $max = max($vals) ?: 1;
        $step = $w / ($n - 1);
        $line = [];
        foreach ($vals as $i => $v) {
            $x = round($i * $step, 1);
            $y = round($h - ($v / $max) * ($h - 6) - 3, 1);
            $line[] = "$x,$y";
        }
        $area = "0,$h ".implode(' ', $line)." $w,$h";

        return ['line' => implode(' ', $line), 'area' => $area, 'w' => $w, 'h' => $h, 'max' => (int) $max];
    };
@endphp

<div>
    <div class="mx-auto w-full max-w-6xl space-y-6">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-slate-100">{{ __('Content Tracker') }}</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Watch how the keywords your published articles target are performing in Google — day by day.') }}</p>
        </div>

        @if (! $hasWebsite)
            <div class="rounded-xl border border-slate-200 bg-white p-8 text-center dark:border-slate-800 dark:bg-slate-900">
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Select a website to see its tracked keywords.') }}</p>
            </div>
        @else
            {{-- Quota meter — the tracker's own capacity, isolated from everything else. --}}
            @php
                $pct = $limit > 0 ? min(100, (int) round($used / $limit * 100)) : 0;
                $barColor = $exhausted ? 'bg-rose-500' : ($nearCap ? 'bg-amber-500' : 'bg-orange-500');
            @endphp
            @if (session('tracker-status'))
                <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 8000)"
                    x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                    class="relative flex items-start gap-3 overflow-hidden rounded-2xl border border-orange-200 bg-white p-4 ps-5 shadow-sm ring-1 ring-orange-500/10 dark:border-orange-900 dark:bg-slate-900">
                    <span class="absolute inset-y-0 start-0 w-1 bg-gradient-to-b from-orange-500 to-orange-600"></span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-orange-100 text-orange-600 dark:bg-orange-950 dark:text-orange-300">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                    </span>
                    <div class="min-w-0 flex-1 pt-0.5">
                        <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ session('tracker-status') }}</p>
                    </div>
                    <button type="button" @click="show = false" class="shrink-0 rounded-lg p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800" aria-label="{{ __('Dismiss') }}">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            @endif

            <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Tracked keywords') }}</div>
                        <div class="mt-1 text-2xl font-extrabold text-slate-900 dark:text-slate-100">
                            {{ number_format($used) }}<span class="text-base font-semibold text-slate-400"> / {{ number_format($limit) }}</span>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-end gap-4">
                        {{-- Where the live SERP checks run from — saved per website. --}}
                        {{-- SERP country — globe inside the dropdown box. The global
                             forms reset already gives <select> its chevron + padding;
                             we only strip its border and lean on the wrapper's. --}}
                        <div>
                            <label title="{{ __('SERP country') }} — {{ __('the country your Google rank checks run from. Saved for this website.') }}"
                                class="flex w-60 cursor-pointer items-center rounded-xl border border-slate-300 bg-white ps-3 shadow-sm transition focus-within:border-orange-500 focus-within:ring-1 focus-within:ring-orange-500 dark:border-slate-700 dark:bg-slate-800">
                                <svg class="h-4 w-4 shrink-0 text-orange-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418"/></svg>
                                <select wire:model="serpCountry" wire:change="saveSerpCountry" wire:loading.attr="disabled" wire:target="saveSerpCountry"
                                    aria-label="{{ __('SERP country') }}"
                                    class="w-full cursor-pointer rounded-xl border-0 text-sm font-medium text-slate-800 focus:ring-0 disabled:opacity-50 dark:bg-slate-800 dark:text-slate-100">
                                    @foreach ($countryOptions as $code => $countryLabel)
                                        <option value="{{ $code }}">{{ $countryLabel }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <p class="mt-1 flex items-center gap-1 text-[11px] text-slate-400 dark:text-slate-500">
                                <svg wire:loading wire:target="saveSerpCountry" class="h-3 w-3 shrink-0 animate-spin text-orange-500" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                                <span wire:loading.remove wire:target="saveSerpCountry">{{ __('Positions are checked in this country\'s Google results and refresh every 7 days. Changing the country rechecks all keywords — new positions can take a few minutes to appear.') }}</span>
                                <span wire:loading wire:target="saveSerpCountry" class="font-semibold text-orange-600 dark:text-orange-400">{{ __('Updating…') }}</span>
                            </p>
                        </div>
                        <div class="text-sm font-medium text-slate-500 dark:text-slate-400">
                            {{ trans_choice('{0}No slots left|{1}:count slot left|[2,*]:count slots left', $remaining, ['count' => number_format($remaining)]) }}
                        </div>
                    </div>
                </div>
                <div class="mt-3 h-2.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                    <div class="h-full rounded-full {{ $barColor }}" style="width: {{ $pct }}%"></div>
                </div>
                @if ($exhausted)
                    <div class="mt-4 flex items-start gap-3 rounded-lg border border-rose-200 bg-rose-50 p-3 dark:border-rose-900 dark:bg-rose-950">
                        <svg class="mt-0.5 h-5 w-5 shrink-0 text-rose-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                        <p class="text-sm font-medium text-rose-700 dark:text-rose-300">{{ __('You\'ve reached your tracking limit. Remove a keyword below to make room for a new one.') }}</p>
                    </div>
                @elseif ($nearCap)
                    <p class="mt-3 text-sm font-medium text-amber-600 dark:text-amber-400">{{ __('You\'re close to your tracking limit.') }}</p>
                @endif
            </div>

            {{-- Connect prompts (self-gated: render only when not connected). --}}
            <x-content.connect-gsc :website="$website" />
            <x-content.connect-ga :website="$website" />

            @if (empty($groups))
                <div class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center dark:border-slate-700 dark:bg-slate-900">
                    <svg class="mx-auto h-10 w-10 text-slate-300 dark:text-slate-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h12M3.75 3h16.5M9 11.25v1.5M12 9v3.75m3-6v6"/></svg>
                    <p class="mt-3 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('Nothing tracked yet') }}</p>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Publish an article, or open an article and add its keywords, to start tracking performance here.') }}</p>
                </div>
            @else
                {{-- What your articles brought in — 30-day traffic value across all articles. --}}
                @if (($siteTotals['articles'] ?? 0) > 0)
                    <div class="relative overflow-hidden rounded-2xl border border-orange-200/70 bg-gradient-to-br from-orange-50 via-white to-white p-6 shadow-sm dark:border-orange-900/50 dark:from-orange-950/30 dark:via-slate-900 dark:to-slate-900">
                        <div class="pointer-events-none absolute -end-10 -top-10 h-40 w-40 rounded-full bg-orange-500/10 blur-2xl"></div>
                        <div class="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <div class="flex items-center gap-2 text-sm font-extrabold tracking-tight text-slate-900 dark:text-slate-100">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-md shadow-orange-600/25">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.306a11.95 11.95 0 015.814-5.518l2.256-1.011M21.75 6.75v5.25M21.75 6.75h-5.25"/></svg>
                                    </span>
                                    {{ __('What your articles brought you') }}
                                </div>
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ __('Combined Google performance of your published articles over the last 30 days.') }}</p>
                            </div>
                            {{-- Every tile names its data source on hover — clients
                                 shouldn't have to guess where a number comes from. --}}
                            <div class="flex flex-wrap gap-6">
                                <div class="cursor-help" title="{{ __('People who clicked through to your articles from Google search results in the last 30 days. Source: Google Search Console.') }}">
                                    <div class="text-2xl font-extrabold tabular-nums tracking-tight text-slate-900 dark:text-slate-100">{{ number_format($siteTotals['clicks']) }}</div>
                                    <div class="flex items-center gap-1 text-xs font-semibold text-slate-500 dark:text-slate-400">
                                        {{ __('Visits from Google') }}
                                        <svg class="h-3.5 w-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
                                    </div>
                                </div>
                                <div class="cursor-help" title="{{ __('How often your articles appeared in Google search results in the last 30 days. Source: Google Search Console.') }}">
                                    <div class="text-2xl font-extrabold tabular-nums tracking-tight text-slate-900 dark:text-slate-100">{{ number_format($siteTotals['impressions']) }}</div>
                                    <div class="flex items-center gap-1 text-xs font-semibold text-slate-500 dark:text-slate-400">
                                        {{ __('Times shown in Google') }}
                                        <svg class="h-3.5 w-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
                                    </div>
                                </div>
                                @if ($siteTotals['visitors'] > 0)
                                    <div class="cursor-help" title="{{ __('Visits your article pages received in the last 30 days. Source: Google Analytics.') }}">
                                        <div class="text-2xl font-extrabold tabular-nums tracking-tight text-slate-900 dark:text-slate-100">{{ number_format($siteTotals['visitors']) }}</div>
                                        <div class="flex items-center gap-1 text-xs font-semibold text-slate-500 dark:text-slate-400">
                                            {{ __('Visitors on your site') }}
                                            <svg class="h-3.5 w-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
                                        </div>
                                    </div>
                                @endif
                                <div class="cursor-help" title="{{ __('Your published articles that had Google data in the last 30 days.') }}">
                                    <div class="text-2xl font-extrabold tabular-nums tracking-tight text-slate-900 dark:text-slate-100">{{ number_format($siteTotals['articles']) }}</div>
                                    <div class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ __('Articles working for you') }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                <div class="space-y-4">
                    @foreach ($groups as $group)
                        @php
                            $topic = $group['topic'];
                            $gid = $group['topic_id'] ?? '_manual';
                            $isOpen = $selectedGroup && ($selectedGroup['topic_id'] ?? '_manual') === $gid && $selectedSeries;
                        @endphp
                        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900" wire:key="grp-{{ $gid }}">
                            {{-- Article header --}}
                            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-3.5 dark:border-slate-800">
                                <div class="min-w-0">
                                    @if ($topic)
                                        <a href="{{ route('content.review', $topic->id) }}" wire:navigate class="truncate text-sm font-bold text-slate-900 hover:text-orange-600 dark:text-slate-100 dark:hover:text-orange-400">{{ \Illuminate\Support\Str::limit($topic->title, 80) }}</a>
                                        @if ($topic->published_at)
                                            <div class="mt-0.5 text-xs text-slate-400">{{ __('Published') }} {{ $topic->published_at->diffForHumans() }}</div>
                                        @endif
                                    @else
                                        <div class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Other keywords') }}</div>
                                    @endif
                                </div>
                                @if (! empty($group['totals']) && ($group['totals']['clicks'] + $group['totals']['impressions']) > 0)
                                    {{-- The article's 30-day value at a glance. --}}
                                    <div class="flex flex-wrap items-center gap-2 text-xs">
                                        <span class="inline-flex items-center gap-1 rounded-full bg-orange-100 px-2.5 py-1 font-bold text-orange-700 dark:bg-orange-500/15 dark:text-orange-300" title="{{ __('People who clicked through to your articles from Google search results in the last 30 days. Source: Google Search Console.') }}">
                                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.042 21.672L13.684 16.6m0 0l-2.51 2.225.569-9.47 5.227 7.917-3.286-.672zM12 2.25V4.5m5.834.166l-1.591 1.591M20.25 10.5H18M7.757 14.743l-1.59 1.59M6 10.5H3.75m4.007-4.243l-1.59-1.59"/></svg>
                                            {{ trans_choice(':n visit/mo|:n visits/mo', $group['totals']['clicks'], ['n' => number_format($group['totals']['clicks'])]) }}
                                        </span>
                                        <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300" title="{{ __('How often this article appeared in Google search results in the last 30 days. Source: Google Search Console.') }}">
                                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                            {{ trans_choice(':n impression/mo|:n impressions/mo', $group['totals']['impressions'], ['n' => number_format($group['totals']['impressions'])]) }}
                                        </span>
                                        @if ($group['totals']['visitors'] > 0)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-1 font-semibold text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300" title="{{ __('Visits this article page received in the last 30 days. Source: Google Analytics.') }}">
                                                <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
                                                {{ trans_choice(':n visitor/mo|:n visitors/mo', $group['totals']['visitors'], ['n' => number_format($group['totals']['visitors'])]) }}
                                            </span>
                                        @endif
                                    </div>
                                @endif
                                @if ($group['page_url'])
                                    <button type="button" wire:click="togglePerformance('{{ $gid }}')"
                                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.306a11.95 11.95 0 015.814-5.518l2.256-1.011M21.75 6.75v5.25M21.75 6.75h-5.25"/></svg>
                                        {{ $isOpen ? __('Hide performance') : __('View performance') }}
                                    </button>
                                @endif
                            </div>

                            {{-- Performance panel (published article, GSC/GA daily) --}}
                            @if ($isOpen)
                                @php
                                    $days = $selectedSeries['days'];
                                    $totClicks = array_sum(array_column($days, 'clicks'));
                                    $totImpr = array_sum(array_column($days, 'impressions'));
                                    $totViews = array_sum(array_column($days, 'pageviews'));
                                    $clicksChart = $chart($days, 'clicks');
                                    $viewsChart = $chart($days, 'pageviews');
                                @endphp
                                <div class="border-b border-slate-100 bg-slate-50/60 px-5 py-4 dark:border-slate-800 dark:bg-slate-950/40">
                                    @if (empty($days) || (! $selectedSeries['has_gsc'] && ! $selectedSeries['has_ga']))
                                        <p class="py-6 text-center text-sm text-slate-500 dark:text-slate-400">{{ __('We\'re collecting performance data for this article. Check back soon.') }}</p>
                                    @else
                                        <div class="grid grid-cols-3 gap-3">
                                            <div class="rounded-lg bg-white p-3 dark:bg-slate-900">
                                                <div class="text-xs text-slate-400">{{ __('Clicks (30d)') }}</div>
                                                <div class="text-lg font-extrabold text-slate-900 dark:text-slate-100">{{ number_format($totClicks) }}</div>
                                            </div>
                                            <div class="rounded-lg bg-white p-3 dark:bg-slate-900">
                                                <div class="text-xs text-slate-400">{{ __('Impressions (30d)') }}</div>
                                                <div class="text-lg font-extrabold text-slate-900 dark:text-slate-100">{{ number_format($totImpr) }}</div>
                                            </div>
                                            <div class="rounded-lg bg-white p-3 dark:bg-slate-900">
                                                <div class="text-xs text-slate-400">{{ __('Visitors (30d)') }}</div>
                                                <div class="text-lg font-extrabold text-slate-900 dark:text-slate-100">{{ number_format($totViews) }}</div>
                                            </div>
                                        </div>
                                        <p class="mt-2 text-xs text-slate-400 dark:text-slate-500">
                                            {{ __('These totals count every Google search this article appears for — including phrases you aren\'t tracking. The keyword rows below only count searches of that exact phrase, so they can be lower.') }}
                                        </p>
                                        <div class="mt-4 grid gap-4 md:grid-cols-2">
                                            @if ($clicksChart)
                                                <div class="rounded-lg bg-white p-3 dark:bg-slate-900">
                                                    <div class="mb-2 text-xs font-semibold text-slate-500 dark:text-slate-400">{{ __('Search clicks') }}</div>
                                                    <svg viewBox="0 0 {{ $clicksChart['w'] }} {{ $clicksChart['h'] }}" preserveAspectRatio="none" class="h-24 w-full">
                                                        <polygon points="{{ $clicksChart['area'] }}" fill="rgb(249 115 22 / 0.12)"></polygon>
                                                        <polyline points="{{ $clicksChart['line'] }}" fill="none" stroke="rgb(249 115 22)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"></polyline>
                                                    </svg>
                                                </div>
                                            @endif
                                            @if ($viewsChart && $selectedSeries['has_ga'])
                                                <div class="rounded-lg bg-white p-3 dark:bg-slate-900">
                                                    <div class="mb-2 text-xs font-semibold text-slate-500 dark:text-slate-400">{{ __('Visitors from search') }}</div>
                                                    <svg viewBox="0 0 {{ $viewsChart['w'] }} {{ $viewsChart['h'] }}" preserveAspectRatio="none" class="h-24 w-full">
                                                        <polygon points="{{ $viewsChart['area'] }}" fill="rgb(16 185 129 / 0.12)"></polygon>
                                                        <polyline points="{{ $viewsChart['line'] }}" fill="none" stroke="rgb(16 185 129)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"></polyline>
                                                    </svg>
                                                </div>
                                            @elseif (! $selectedSeries['has_ga'])
                                                <div class="flex items-center justify-center rounded-lg border border-dashed border-slate-200 p-3 text-center text-xs text-slate-400 dark:border-slate-700">
                                                    {{ __('Connect Analytics to see visitors for this article.') }}
                                                </div>
                                            @endif
                                        </div>

                                    @endif
                                </div>
                            @endif

                            {{-- Keyword rows --}}
                            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                                @foreach ($group['keywords'] as $kw)
                                    @php
                                        $s = $summaries[$kw->normalized_keyword] ?? ['clicks' => 0, 'impressions' => 0, 'position' => null, 'spark' => [], 'has_data' => false];
                                    @endphp
                                    <div class="flex flex-wrap items-center gap-x-5 gap-y-2 px-5 py-3" wire:key="kw-{{ $kw->id }}">
                                        <div class="flex min-w-0 flex-1 items-center gap-2">
                                            <span class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $kw->keyword }}</span>
                                            @if ($kw->is_primary)
                                                <span class="shrink-0 rounded bg-orange-100 px-1.5 py-0.5 text-xs font-bold uppercase tracking-wide text-orange-700 dark:bg-orange-500/15 dark:text-orange-300">{{ __('Primary') }}</span>
                                            @endif
                                        </div>

                                        {{-- SERP: live Google rank (Serper), refreshed weekly. The
                                             position doubles as the entry point to the rank history. --}}
                                        <a href="{{ route('content.keyword-history', $kw->id) }}" wire:navigate
                                            class="w-14 text-center" title="{{ __('Live Google rank — open rank history') }}">
                                            <div class="text-xs text-slate-400">{{ __('SERP') }}</div>
                                            <div class="text-sm font-bold text-slate-900 hover:text-orange-600 dark:text-slate-100 dark:hover:text-orange-400">
                                                @if ($kw->serp_position)#{{ $kw->serp_position }}@elseif ($kw->serp_checked_at)100+@else<span class="text-slate-300 dark:text-slate-600">…</span>@endif
                                            </div>
                                        </a>
                                        {{-- GSC: Search Console average position --}}
                                        <div class="w-14 text-center" title="{{ __('Search Console average position') }}">
                                            <div class="text-xs text-slate-400">{{ __('GSC') }}</div>
                                            <div class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ $s['position'] !== null ? number_format($s['position'], 1) : '—' }}</div>
                                        </div>
                                        {{-- Clicks --}}
                                        <div class="w-14 text-center">
                                            <div class="text-xs text-slate-400">{{ __('Clicks') }}</div>
                                            <div class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ number_format($s['clicks']) }}</div>
                                        </div>
                                        {{-- Impressions --}}
                                        <div class="hidden w-20 text-center sm:block">
                                            <div class="text-xs text-slate-400">{{ __('Impressions') }}</div>
                                            <div class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ number_format($s['impressions']) }}</div>
                                        </div>
                                        {{-- Sparkline --}}
                                        <div class="hidden w-28 md:block">
                                            @if ($s['has_data'])
                                                <svg viewBox="0 0 110 28" preserveAspectRatio="none" class="h-7 w-full text-orange-500">
                                                    <polyline points="{{ $spark($s['spark']) }}" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" stroke-linecap="round"></polyline>
                                                </svg>
                                            @else
                                                <div class="text-center text-xs text-slate-300 dark:text-slate-600">—</div>
                                            @endif
                                        </div>

                                        {{-- Rank history: the row's own detail page (position over time). --}}
                                        <a href="{{ route('content.keyword-history', $kw->id) }}" wire:navigate
                                            class="shrink-0 rounded-lg p-2 text-slate-400 hover:bg-orange-50 hover:text-orange-600 dark:hover:bg-orange-500/10 dark:hover:text-orange-400"
                                            title="{{ __('View rank history') }}" aria-label="{{ __('View rank history') }}">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/></svg>
                                        </a>

                                        <button type="button" wire:click="untrack('{{ $kw->id }}')" wire:confirm="{{ __('Remove this keyword from your tracker?') }}"
                                            class="shrink-0 rounded-lg p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-950"
                                            title="{{ __('Remove') }}">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                                        </button>
                                    </div>
                                @endforeach
                            </div>

                            {{-- More searches this article shows up for — real Google
                                 phrases (last 4 weeks) not yet tracked, one-click add. --}}
                            @if (! empty($group['discovered']))
                                <div class="border-t border-slate-100 bg-slate-50/50 dark:border-slate-800 dark:bg-slate-950/30">
                                    <div class="flex flex-wrap items-center gap-2 px-5 pb-1 pt-3.5">
                                        <svg class="h-4 w-4 text-orange-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
                                        <span class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('More searches this article shows up for') }}</span>
                                        <span class="text-xs text-slate-500 dark:text-slate-400">— {{ __('real Google searches from the last 4 weeks. Track the ones that matter to you.') }}</span>
                                    </div>
                                    <div class="divide-y divide-slate-100 dark:divide-slate-800">
                                        @foreach ($group['discovered'] as $q)
                                            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 px-5 py-2.5 transition hover:bg-white dark:hover:bg-slate-900/60" wire:key="dq-{{ $gid }}-{{ md5($q['query']) }}">
                                                <span class="min-w-0 flex-1 truncate text-sm text-slate-700 dark:text-slate-300">{{ $q['query'] }}</span>
                                                <span class="w-24 text-center" title="{{ __('How often this article appeared in Google search results in the last 30 days. Source: Google Search Console.') }}">
                                                    <span class="block text-[10px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Impressions') }}</span>
                                                    <span class="text-sm font-bold tabular-nums text-slate-800 dark:text-slate-200">{{ number_format($q['impressions']) }}</span>
                                                </span>
                                                <span class="w-14 text-center">
                                                    <span class="block text-[10px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Clicks') }}</span>
                                                    <span class="text-sm tabular-nums text-slate-600 dark:text-slate-300">{{ number_format($q['clicks']) }}</span>
                                                </span>
                                                <span class="w-16 text-center">
                                                    <span class="block text-[10px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Position') }}</span>
                                                    <span class="text-sm tabular-nums text-slate-600 dark:text-slate-300">{{ $q['position'] !== null ? '#'.$q['position'] : '—' }}</span>
                                                </span>
                                                <span class="w-24 text-end">
                                                    @if ($exhausted)
                                                        <span class="text-xs text-slate-400" title="{{ __('You\'ve reached your tracking limit. Remove a keyword below to make room for a new one.') }}">{{ __('No slots') }}</span>
                                                    @else
                                                        <button type="button" wire:click="trackQuery({{ json_encode($q['query']) }}, '{{ $gid }}')" wire:loading.attr="disabled" wire:target="trackQuery"
                                                            class="inline-flex items-center gap-1 rounded-lg border border-orange-200 px-2.5 py-1 text-xs font-bold text-orange-600 transition hover:bg-orange-50 disabled:opacity-50 dark:border-orange-900 dark:text-orange-400 dark:hover:bg-orange-950">
                                                            <svg wire:loading.remove wire:target="trackQuery" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                                                            <svg wire:loading wire:target="trackQuery" class="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                                                            {{ __('Track') }}
                                                        </button>
                                                    @endif
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
    </div>
</div>
