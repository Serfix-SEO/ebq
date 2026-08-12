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
                <div class="rounded-xl border border-success/25 bg-success/5 px-4 py-3 text-sm font-semibold text-slate-900 dark:text-slate-100">{{ session('tracker-status') }}</div>
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
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('SERP country') }}</label>
                            <select wire:model="serpCountry" wire:change="saveSerpCountry"
                                class="mt-1 rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                                @foreach ($countryOptions as $code => $label)
                                    <option value="{{ $code }}">{{ $label }}</option>
                                @endforeach
                            </select>
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
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
    </div>
</div>
