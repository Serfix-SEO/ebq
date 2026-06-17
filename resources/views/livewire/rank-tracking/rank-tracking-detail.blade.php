<div>
    @if (! $keyword)
        <div class="rounded-xl border border-slate-200 bg-white p-10 text-center dark:border-slate-800 dark:bg-slate-900">
            <svg class="mx-auto h-10 w-10 text-slate-300 dark:text-slate-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
            <p class="mt-3 text-sm font-semibold text-slate-700 dark:text-slate-200">Keyword not found</p>
            <p class="mt-1 text-xs text-slate-500">It may have been deleted or belong to another website you don't have access to.</p>
            <a href="{{ route('rank-tracking.index') }}" wire:navigate class="mt-4 inline-flex h-8 items-center gap-1 rounded-md bg-indigo-600 px-3 text-xs font-semibold text-white hover:bg-indigo-700">
                ← Back to Rank Tracking
            </a>
        </div>
    @else
        @if (session('rank_tracking_status'))
            <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)"
                class="mb-4 flex items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-xs font-medium text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
                <div class="flex items-center gap-2">
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    {{ session('rank_tracking_status') }}
                </div>
                <button @click="show = false" class="text-emerald-700/70 hover:text-emerald-700">×</button>
            </div>
        @endif

        {{-- Breadcrumb + header --}}
        <nav class="mb-3 flex items-center gap-1.5 text-[11px] text-slate-500 dark:text-slate-400">
            <a href="{{ route('rank-tracking.index') }}" wire:navigate class="font-semibold uppercase tracking-wider hover:text-indigo-600 dark:hover:text-indigo-400">Rank Tracking</a>
            <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            <span class="truncate uppercase tracking-wider">{{ $keyword->keyword }}</span>
        </nav>

        <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-slate-100">{{ $keyword->keyword }}</h1>
                <div class="mt-2 flex flex-wrap items-center gap-1.5">
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ $keyword->search_type }}</span>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ $keyword->country }}</span>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ $keyword->language }}</span>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ $keyword->device }}</span>
                    <span class="rounded-full bg-indigo-50 px-2 py-0.5 text-[10px] font-semibold text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300">Top {{ $keyword->depth }}</span>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-700 dark:bg-slate-800 dark:text-slate-300">Every {{ $keyword->check_interval_hours }}h</span>
                    @if (! $keyword->is_active)<span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">Paused</span>@endif
                </div>
                <div class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                    Tracking <span class="font-semibold text-slate-700 dark:text-slate-300">{{ $keyword->target_domain }}</span>
                    @if ($keyword->target_url)<span>· URL-specific: <a href="{{ $keyword->target_url }}" target="_blank" rel="noopener" class="text-indigo-600 hover:underline">{{ \Illuminate\Support\Str::limit($keyword->target_url, 60) }}</a></span>@endif
                </div>
            </div>
            <button wire:click="recheck"
                wire:loading.attr="disabled"
                wire:target="recheck"
                type="button"
                class="inline-flex h-9 items-center gap-1.5 rounded-md bg-indigo-600 px-3.5 text-xs font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:opacity-60">
                <svg wire:loading.remove wire:target="recheck" class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                <svg wire:loading wire:target="recheck" class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"></circle><path fill="currentColor" class="opacity-75" d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z"></path></svg>
                <span wire:loading.remove wire:target="recheck">Force re-check</span>
                <span wire:loading wire:target="recheck">Queuing…</span>
            </button>
        </div>

        {{-- Summary --}}
        <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <div class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Current rank</div>
                <div class="mt-1 flex items-baseline gap-2">
                    @php
                        $curPos = $keyword->current_position;
                        $curColor = 'text-slate-400';
                        if ($curPos !== null) {
                            $curColor = $curPos <= 3 ? 'text-emerald-600 dark:text-emerald-400' : ($curPos <= 10 ? 'text-blue-600 dark:text-blue-400' : ($curPos <= 20 ? 'text-amber-600 dark:text-amber-400' : 'text-slate-700 dark:text-slate-300'));
                        }
                    @endphp
                    <div class="text-3xl font-bold tabular-nums {{ $curColor }}">{{ $curPos ? '#'.$curPos : '—' }}</div>
                    @if ($keyword->position_change > 0)
                        <span class="text-xs font-semibold text-emerald-600 dark:text-emerald-400">▲ {{ $keyword->position_change }}</span>
                    @elseif ($keyword->position_change < 0)
                        <span class="text-xs font-semibold text-red-600 dark:text-red-400">▼ {{ abs($keyword->position_change) }}</span>
                    @endif
                </div>
                <div class="mt-0.5 text-[10px] text-slate-400">vs previous check</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <div class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Best ever</div>
                <div class="mt-1 text-3xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $keyword->best_position ? '#'.$keyword->best_position : '—' }}</div>
                <div class="mt-0.5 text-[10px] text-slate-400">historical high</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <div class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Initial rank</div>
                <div class="mt-1 text-3xl font-bold tabular-nums text-slate-700 dark:text-slate-300">{{ $keyword->initial_position ? '#'.$keyword->initial_position : '—' }}</div>
                <div class="mt-0.5 text-[10px] text-slate-400">first recorded</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <div class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Last checked</div>
                <div class="mt-1 text-base font-semibold text-slate-700 dark:text-slate-300">{{ $keyword->last_checked_at ? $keyword->last_checked_at->diffForHumans() : 'Never' }}</div>
                <div class="mt-0.5 text-[10px] text-slate-400">Next: {{ $keyword->next_check_at ? $keyword->next_check_at->diffForHumans() : '—' }}</div>
            </div>
        </div>

        {{-- GSC cross-reference --}}
        @if (! empty($gsc['matched']))
            @php
                $gscPos = $gsc['totals']['position'];
                $serpPos = $keyword->current_position;
                $diff = ($gscPos !== null && $serpPos !== null) ? round($gscPos - (float) $serpPos, 1) : null;
            @endphp
            <div class="mb-5 rounded-xl border border-emerald-200 bg-gradient-to-br from-emerald-50 to-white p-5 dark:border-emerald-500/30 dark:from-emerald-500/5 dark:to-slate-900">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="inline-flex h-6 items-center gap-1 rounded-full bg-emerald-600 px-2 text-[10px] font-semibold uppercase tracking-wider text-white">
                            <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            Matched in GSC
                        </span>
                        <span class="text-xs font-semibold text-slate-700 dark:text-slate-300">Google Search Console · last 30 days</span>
                    </div>
                    <a href="{{ route('keywords.index') }}" wire:navigate class="text-[11px] font-semibold text-emerald-700 hover:underline dark:text-emerald-400">Open in Keywords →</a>
                </div>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
                        <div class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">GSC clicks</div>
                        <div class="mt-1 text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ number_format($gsc['totals']['clicks']) }}</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
                        <div class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Impressions</div>
                        <div class="mt-1 text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ number_format($gsc['totals']['impressions']) }}</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
                        <div class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">CTR</div>
                        <div class="mt-1 text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ $gsc['totals']['ctr'] !== null ? $gsc['totals']['ctr'].'%' : '—' }}</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
                        <div class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">GSC avg position</div>
                        <div class="mt-1 flex items-baseline gap-2">
                            <div class="text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ $gscPos !== null ? '#'.$gscPos : '—' }}</div>
                            @if ($diff !== null)
                                @if ($diff > 0.5)
                                    <span class="text-[10px] font-semibold text-amber-600 dark:text-amber-400" title="GSC position is {{ abs($diff) }} lower than SERP API">vs SERP +{{ $diff }}</span>
                                @elseif ($diff < -0.5)
                                    <span class="text-[10px] font-semibold text-emerald-600 dark:text-emerald-400" title="GSC position is {{ abs($diff) }} higher than SERP API">vs SERP {{ $diff }}</span>
                                @else
                                    <span class="text-[10px] font-semibold text-slate-500">vs SERP ≈</span>
                                @endif
                            @endif
                        </div>
                    </div>
                </div>

                @if (! empty($gsc['by_device']))
                    <div class="mt-4">
                        <div class="mb-2 text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">By device</div>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                            @foreach ($gsc['by_device'] as $d)
                                <div class="flex items-center justify-between rounded-md border border-slate-200 bg-white px-3 py-2 text-xs dark:border-slate-800 dark:bg-slate-900">
                                    <div>
                                        <div class="font-semibold capitalize text-slate-800 dark:text-slate-200">{{ strtolower($d['device']) }}</div>
                                        <div class="text-[10px] text-slate-400">{{ number_format($d['clicks']) }} clicks · {{ number_format($d['impressions']) }} impr</div>
                                    </div>
                                    <span class="rounded-full bg-slate-100 px-2 py-px text-[10px] font-semibold tabular-nums text-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ $d['position'] !== null ? '#'.$d['position'] : '—' }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if (! empty($gsc['top_pages']))
                    <div class="mt-4">
                        <div class="mb-2 text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Top ranking pages (GSC)</div>
                        <ul class="space-y-1">
                            @foreach ($gsc['top_pages'] as $p)
                                <li class="flex items-center justify-between gap-3 rounded-md border border-slate-200 bg-white px-3 py-2 text-xs dark:border-slate-800 dark:bg-slate-900">
                                    <a href="{{ $p['page'] }}" target="_blank" rel="noopener" class="min-w-0 flex-1 truncate text-emerald-700 hover:underline dark:text-emerald-400">{{ $p['page'] }}</a>
                                    <div class="flex shrink-0 items-center gap-2 text-[10px] text-slate-500">
                                        <span class="tabular-nums font-semibold text-slate-800 dark:text-slate-200">{{ number_format($p['clicks']) }}</span>
                                        <span>clicks</span>
                                        <span class="rounded-full bg-slate-100 px-1.5 py-px font-semibold tabular-nums text-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ $p['position'] !== null ? '#'.$p['position'] : '—' }}</span>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @else
            <div class="mb-5 flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 text-xs text-slate-500 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400">
                <svg class="h-4 w-4 text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" /></svg>
                <span>No Google Search Console match yet for <span class="font-semibold">"{{ $keyword->keyword }}"</span> in the last 30 days. Once GSC reports impressions for this exact query on this website, stats will appear here automatically.</span>
            </div>
        @endif

        {{-- Chart --}}
        @if (count($chartPoints) > 1)
            @php
                $positions = array_values(array_filter(array_column($chartPoints, 'y'), fn ($v) => $v !== null));
                $maxY = $positions ? max(max($positions), 10) : 10;
                $width = 800;
                $height = 160;
                $pad = ['t' => 14, 'r' => 10, 'b' => 24, 'l' => 36];
                $plotW = $width - $pad['l'] - $pad['r'];
                $plotH = $height - $pad['t'] - $pad['b'];
                $count = count($chartPoints);
                $xStep = $count > 1 ? $plotW / ($count - 1) : $plotW;
                $gridLines = [1, (int) ceil($maxY / 4), (int) ceil($maxY / 2), (int) ceil($maxY * 0.75), $maxY];
                $gridLines = array_values(array_unique($gridLines));
                $points = [];
                foreach ($chartPoints as $idx => $p) {
                    if ($p['y'] === null) { continue; }
                    $x = $pad['l'] + $idx * $xStep;
                    $y = $pad['t'] + ($p['y'] / $maxY) * $plotH;
                    $points[] = ['x' => round($x, 2), 'y' => round($y, 2), 'pos' => $p['y'], 'date' => $p['x']];
                }
                $firstLabel = $count > 0 ? \Carbon\Carbon::parse($chartPoints[0]['x'])->format('M j') : '';
                $lastLabel = $count > 0 ? \Carbon\Carbon::parse($chartPoints[$count - 1]['x'])->format('M j') : '';
                $linePath = '';
                foreach ($points as $i => $pt) {
                    $linePath .= ($i === 0 ? 'M' : 'L').$pt['x'].' '.$pt['y'].' ';
                }
                $areaPath = $linePath;
                if (! empty($points)) {
                    $last = end($points);
                    $first = reset($points);
                    $areaPath .= 'L'.$last['x'].' '.($pad['t'] + $plotH).' L'.$first['x'].' '.($pad['t'] + $plotH).' Z';
                }
            @endphp
            <div class="mb-5 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <div class="mb-2 flex items-center justify-between">
                    <div>
                        <div class="text-xs font-semibold text-slate-900 dark:text-slate-100">Position history</div>
                        <div class="text-[10px] text-slate-400">Lower = better · last {{ $count }} checks</div>
                    </div>
                    <div class="flex items-center gap-2 text-[10px] text-slate-400">
                        <span class="inline-flex items-center gap-1"><span class="inline-block h-2 w-2 rounded-full bg-emerald-500"></span>Top 3</span>
                        <span class="inline-flex items-center gap-1"><span class="inline-block h-2 w-2 rounded-full bg-blue-500"></span>Top 10</span>
                        <span class="inline-flex items-center gap-1"><span class="inline-block h-2 w-2 rounded-full bg-amber-500"></span>Top 20</span>
                    </div>
                </div>
                <div class="relative w-full">
                    <svg viewBox="0 0 {{ $width }} {{ $height }}" preserveAspectRatio="none" class="h-44 w-full">
                        {{-- Ranking zones --}}
                        <rect x="{{ $pad['l'] }}" y="{{ $pad['t'] }}" width="{{ $plotW }}" height="{{ max(0, ($plotH * 3 / $maxY)) }}" class="fill-emerald-500/5" />
                        <rect x="{{ $pad['l'] }}" y="{{ $pad['t'] + ($plotH * 3 / $maxY) }}" width="{{ $plotW }}" height="{{ max(0, ($plotH * 7 / $maxY)) }}" class="fill-blue-500/5" />
                        <rect x="{{ $pad['l'] }}" y="{{ $pad['t'] + ($plotH * 10 / $maxY) }}" width="{{ $plotW }}" height="{{ max(0, ($plotH * 10 / $maxY)) }}" class="fill-amber-500/5" />

                        {{-- Grid + Y axis --}}
                        @foreach ($gridLines as $g)
                            @php $gy = $pad['t'] + ($g / $maxY) * $plotH; @endphp
                            <line x1="{{ $pad['l'] }}" x2="{{ $pad['l'] + $plotW }}" y1="{{ $gy }}" y2="{{ $gy }}" stroke="currentColor" stroke-width="0.5" class="text-slate-200 dark:text-slate-700" stroke-dasharray="2 3" />
                            <text x="{{ $pad['l'] - 6 }}" y="{{ $gy + 3 }}" text-anchor="end" class="fill-slate-400 text-[9px]">#{{ $g }}</text>
                        @endforeach

                        {{-- Area --}}
                        <path d="{{ $areaPath }}" class="fill-indigo-500/15" />
                        {{-- Line --}}
                        <path d="{{ $linePath }}" fill="none" stroke="currentColor" stroke-width="1.5" class="text-indigo-500" stroke-linejoin="round" stroke-linecap="round" />
                        {{-- Points --}}
                        @foreach ($points as $pt)
                            <circle cx="{{ $pt['x'] }}" cy="{{ $pt['y'] }}" r="2.5" class="fill-white stroke-indigo-500" stroke-width="1.5">
                                <title>#{{ $pt['pos'] }} · {{ \Carbon\Carbon::parse($pt['date'])->format('M j, H:i') }}</title>
                            </circle>
                        @endforeach

                        {{-- X axis labels --}}
                        <text x="{{ $pad['l'] }}" y="{{ $height - 6 }}" class="fill-slate-400 text-[9px]">{{ $firstLabel }}</text>
                        <text x="{{ $pad['l'] + $plotW }}" y="{{ $height - 6 }}" text-anchor="end" class="fill-slate-400 text-[9px]">{{ $lastLabel }}</text>
                    </svg>
                </div>
            </div>
        @endif

        {{-- Clicks overlay (GSC 90-day daily clicks for the matched query) --}}
        @if (!empty($gsc['matched']) && !empty($gsc['series']) && count($gsc['series']) > 1)
            @php
                $clickSeries = array_values(array_filter($gsc['series'], fn ($r) => $r['clicks'] !== null));
                $maxClicks = $clickSeries ? max(array_column($clickSeries, 'clicks')) : 0;
                $maxClicks = $maxClicks > 0 ? $maxClicks : 1;
                $cWidth = 800; $cHeight = 120;
                $cPad = ['t' => 10, 'r' => 10, 'b' => 20, 'l' => 36];
                $cPlotW = $cWidth - $cPad['l'] - $cPad['r'];
                $cPlotH = $cHeight - $cPad['t'] - $cPad['b'];
                $cCount = count($clickSeries);
                $cStep = $cCount > 1 ? $cPlotW / ($cCount - 1) : $cPlotW;
                $cPoints = [];
                foreach ($clickSeries as $idx => $p) {
                    $x = $cPad['l'] + $idx * $cStep;
                    $y = $cPad['t'] + $cPlotH - (((int) $p['clicks']) / $maxClicks) * $cPlotH;
                    $cPoints[] = ['x' => round($x, 2), 'y' => round($y, 2), 'clicks' => (int) $p['clicks'], 'date' => $p['date']];
                }
                $cLine = '';
                foreach ($cPoints as $i => $pt) {
                    $cLine .= ($i === 0 ? 'M' : 'L').$pt['x'].' '.$pt['y'].' ';
                }
                $cArea = $cLine;
                if (! empty($cPoints)) {
                    $last = end($cPoints); $first = reset($cPoints);
                    $cArea .= 'L'.$last['x'].' '.($cPad['t'] + $cPlotH).' L'.$first['x'].' '.($cPad['t'] + $cPlotH).' Z';
                }
                $cFirstLabel = $cCount > 0 ? \Carbon\Carbon::parse($clickSeries[0]['date'])->format('M j') : '';
                $cLastLabel = $cCount > 0 ? \Carbon\Carbon::parse($clickSeries[$cCount - 1]['date'])->format('M j') : '';
            @endphp
            <div class="mb-5 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <div class="mb-2 flex items-center justify-between">
                    <div>
                        <div class="text-xs font-semibold text-slate-900 dark:text-slate-100">Search clicks (last 90d)</div>
                        <div class="text-[10px] text-slate-400">From Google Search Console for "{{ $keyword->keyword }}"</div>
                    </div>
                    <div class="text-[10px] text-slate-400">Peak: <span class="tabular-nums font-semibold text-slate-600 dark:text-slate-300">{{ number_format($maxClicks) }}</span>/day</div>
                </div>
                <div class="relative w-full">
                    <svg viewBox="0 0 {{ $cWidth }} {{ $cHeight }}" preserveAspectRatio="none" class="h-28 w-full">
                        <line x1="{{ $cPad['l'] }}" x2="{{ $cPad['l'] + $cPlotW }}" y1="{{ $cPad['t'] + $cPlotH }}" y2="{{ $cPad['t'] + $cPlotH }}" stroke="currentColor" stroke-width="0.5" class="text-slate-300 dark:text-slate-600" />
                        <path d="{{ $cArea }}" class="fill-emerald-500/15" />
                        <path d="{{ $cLine }}" fill="none" stroke="currentColor" stroke-width="1.5" class="text-emerald-500" stroke-linejoin="round" stroke-linecap="round" />
                        @foreach ($cPoints as $pt)
                            <circle cx="{{ $pt['x'] }}" cy="{{ $pt['y'] }}" r="1.8" class="fill-emerald-500"><title>{{ $pt['clicks'] }} clicks · {{ $pt['date'] }}</title></circle>
                        @endforeach
                        <text x="{{ $cPad['l'] }}" y="{{ $cHeight - 4 }}" class="fill-slate-400 text-[9px]">{{ $cFirstLabel }}</text>
                        <text x="{{ $cPad['l'] + $cPlotW }}" y="{{ $cHeight - 4 }}" text-anchor="end" class="fill-slate-400 text-[9px]">{{ $cLastLabel }}</text>
                    </svg>
                </div>
                <div class="mt-1 text-[10px] text-slate-400">Compare against the position history above — rank improvements without a corresponding clicks lift may signal a SERP-feature shift or a tracking mismatch.</div>
            </div>
        @endif

        {{-- History + snapshot --}}
        <div class="grid grid-cols-1 gap-5 lg:grid-cols-5">
            <div class="lg:col-span-2">
                <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                        <div class="text-xs font-semibold text-slate-900 dark:text-slate-100">Check history</div>
                        <span class="text-[10px] text-slate-400">{{ $snapshots->total() ?? 0 }} total</span>
                    </div>
                    @if ($snapshots->isEmpty())
                        <div class="px-4 py-10 text-center">
                            <svg class="mx-auto h-8 w-8 text-slate-300 dark:text-slate-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                            <p class="mt-2 text-xs font-medium text-slate-500">No checks yet</p>
                            <p class="mt-1 text-[11px] text-slate-400">Hit "Force re-check" to run the first one.</p>
                        </div>
                    @else
                        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach ($snapshots as $snap)
                                <li wire:key="snap-{{ $snap->id }}">
                                    <button wire:click="selectSnapshot('{{ $snap->id }}')" type="button"
                                        @class([
                                            'flex w-full items-center justify-between gap-3 px-4 py-2.5 text-left text-xs transition',
                                            'bg-indigo-50/60 dark:bg-indigo-500/10' => $selected && $selected->id === $snap->id,
                                            'hover:bg-slate-50 dark:hover:bg-slate-800/50' => ! $selected || $selected->id !== $snap->id,
                                        ])>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2">
                                                <div class="font-medium text-slate-900 dark:text-slate-100">{{ $snap->checked_at->format('M d, H:i') }}</div>
                                                @if ($snap->forced)<span class="rounded bg-amber-100 px-1 text-[9px] font-semibold uppercase text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">forced</span>@endif
                                            </div>
                                            <div class="mt-0.5 text-[10px] text-slate-500">{{ $snap->checked_at->diffForHumans() }}</div>
                                        </div>
                                        <div class="shrink-0 text-right">
                                            @if ($snap->status === 'ok')
                                                @if ($snap->position)
                                                    <span @class([
                                                        'inline-flex min-w-[40px] justify-center rounded-full px-2 py-0.5 text-[11px] font-bold tabular-nums',
                                                        'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400' => $snap->position <= 3,
                                                        'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-400' => $snap->position > 3 && $snap->position <= 10,
                                                        'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-400' => $snap->position > 10 && $snap->position <= 20,
                                                        'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300' => $snap->position > 20,
                                                    ])>#{{ $snap->position }}</span>
                                                @else
                                                    <span class="text-[10px] text-slate-400">not in top {{ $keyword->depth }}</span>
                                                @endif
                                            @else
                                                <span class="rounded bg-red-50 px-1.5 py-0.5 text-[9px] font-semibold uppercase text-red-600 dark:bg-red-500/10 dark:text-red-400">Failed</span>
                                            @endif
                                        </div>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
                <div class="mt-3">{{ $snapshots->links() }}</div>
            </div>

            <div class="lg:col-span-3">
                @if (! $selected)
                    <div class="rounded-xl border border-dashed border-slate-300 bg-white p-12 text-center dark:border-slate-700 dark:bg-slate-900">
                        <p class="text-xs text-slate-500">Pick a check on the left to see the SERP snapshot.</p>
                    </div>
                @else
                    @php
                        $top = (array) ($selected->top_results ?? []);
                        $targetDomain = strtolower(preg_replace('/^www\./', '', (string) $keyword->target_domain));
                        $competitorDomains = collect((array) ($keyword->competitors ?? []))
                            ->map(fn ($d) => strtolower(preg_replace('/^www\./', '', (string) $d)))
                            ->filter()->values()->all();
                    @endphp
                    <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        {{-- Snapshot header --}}
                        <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-3.5 dark:border-slate-800">
                            <div>
                                <div class="text-xs font-semibold text-slate-900 dark:text-slate-100">
                                    SERP snapshot · {{ $selected->checked_at->format('M j, Y · H:i') }}
                                </div>
                                <div class="mt-1 flex flex-wrap items-center gap-2 text-[10px] text-slate-500 dark:text-slate-400">
                                    @if ($selected->total_results)<span>{{ number_format($selected->total_results) }} results</span>@endif
                                    @if ($selected->search_time)<span>· {{ $selected->search_time }}s</span>@endif
                                    @foreach ((array) ($selected->serp_features ?? []) as $feat)
                                        <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $feat }}</span>
                                    @endforeach
                                </div>
                            </div>
                            @if ($selected->forced)
                                <span class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[9px] font-semibold uppercase text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">Forced re-check</span>
                            @endif
                        </div>

                        @if ($selected->status !== 'ok')
                            <div class="px-5 py-6">
                                <div class="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-xs text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-400">
                                    <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                                    <div>
                                        <div class="font-semibold">Check failed</div>
                                        <div class="mt-0.5">{{ $selected->error ?? 'Unknown error' }}</div>
                                    </div>
                                </div>
                            </div>
                        @else
                            {{-- Your listing --}}
                            @if ($selected->position && $selected->url)
                                <div class="border-b border-slate-200 px-5 py-3 dark:border-slate-800">
                                    <div class="mb-2 text-[10px] font-semibold uppercase tracking-wider text-slate-400">Your listing</div>
                                    <div class="flex items-start gap-3 rounded-lg border border-indigo-200 bg-indigo-50/50 p-3 dark:border-indigo-500/30 dark:bg-indigo-500/5">
                                        <span class="mt-0.5 inline-flex h-6 w-12 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-[11px] font-bold text-white">#{{ $selected->position }}</span>
                                        <div class="min-w-0 flex-1">
                                            <div class="truncate text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $selected->title ?? '—' }}</div>
                                            <a href="{{ $selected->url }}" target="_blank" rel="noopener" class="truncate text-[11px] text-emerald-700 hover:underline dark:text-emerald-400">{{ $selected->url }}</a>
                                            @if ($selected->snippet)
                                                <div class="mt-1 text-[11px] text-slate-600 dark:text-slate-400">{{ $selected->snippet }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @elseif (! $selected->position)
                                <div class="border-b border-slate-200 px-5 py-3 dark:border-slate-800">
                                    <div class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-400">
                                        <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.008v.008H12v-.008Z" /></svg>
                                        Not ranked in the top {{ $keyword->depth }} for this check.
                                    </div>
                                </div>
                            @endif

                            {{-- All sites ranked --}}
                            @if (! empty($top))
                                <div class="px-5 py-3">
                                    <div class="mb-2 flex items-center justify-between">
                                        <div class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">All sites ranked for this keyword</div>
                                        <span class="text-[10px] text-slate-400">Showing top {{ count($top) }}</span>
                                    </div>
                                    <ol class="space-y-1.5">
                                        @foreach ($top as $row)
                                            @php
                                                $link = (string) ($row['link'] ?? '');
                                                $host = '';
                                                if ($link !== '') {
                                                    $h = parse_url($link, PHP_URL_HOST);
                                                    if (is_string($h)) {
                                                        $host = strtolower(preg_replace('/^www\./', '', $h));
                                                    }
                                                }
                                                $isYou = $host !== '' && $host === $targetDomain;
                                                $isCompetitor = ! $isYou && in_array($host, $competitorDomains, true);
                                            @endphp
                                            <li @class([
                                                'flex items-start gap-3 rounded-lg border px-3 py-2 transition',
                                                'border-indigo-200 bg-indigo-50 dark:border-indigo-500/40 dark:bg-indigo-500/10' => $isYou,
                                                'border-amber-200 bg-amber-50 dark:border-amber-500/40 dark:bg-amber-500/10' => $isCompetitor,
                                                'border-slate-200 bg-white hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:hover:bg-slate-800/50' => ! $isYou && ! $isCompetitor,
                                            ])>
                                                <span @class([
                                                    'mt-0.5 inline-flex h-6 w-10 shrink-0 items-center justify-center rounded-full text-[11px] font-bold tabular-nums',
                                                    'bg-indigo-600 text-white' => $isYou,
                                                    'bg-amber-500 text-white' => $isCompetitor,
                                                    'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200' => ! $isYou && ! $isCompetitor,
                                                ])>#{{ $row['position'] ?? '—' }}</span>
                                                <div class="min-w-0 flex-1">
                                                    <div class="flex flex-wrap items-center gap-1.5">
                                                        <span class="truncate text-xs font-semibold text-slate-900 dark:text-slate-100">{{ $row['title'] ?? '—' }}</span>
                                                        @if ($isYou)<span class="rounded bg-indigo-600 px-1.5 py-px text-[9px] font-bold uppercase tracking-wide text-white">You</span>@endif
                                                        @if ($isCompetitor)<span class="rounded bg-amber-500 px-1.5 py-px text-[9px] font-bold uppercase tracking-wide text-white">Competitor</span>@endif
                                                    </div>
                                                    @if ($link)
                                                        <a href="{{ $link }}" target="_blank" rel="noopener" class="block truncate text-[10px] text-emerald-700 hover:underline dark:text-emerald-400">{{ $link }}</a>
                                                    @endif
                                                    @if (! empty($row['snippet']))
                                                        <div class="mt-0.5 line-clamp-2 text-[11px] text-slate-600 dark:text-slate-400">{{ $row['snippet'] }}</div>
                                                    @endif
                                                </div>
                                            </li>
                                        @endforeach
                                    </ol>
                                </div>
                            @endif

                            {{-- Competitors --}}
                            @if (! empty($selected->competitor_positions))
                                <div class="border-t border-slate-200 px-5 py-3 dark:border-slate-800">
                                    <div class="mb-2 text-[10px] font-semibold uppercase tracking-wider text-slate-400">Competitors you're tracking</div>
                                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                        @foreach ((array) $selected->competitor_positions as $c)
                                            <div class="flex items-center justify-between rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs dark:border-slate-800 dark:bg-slate-900">
                                                <span class="truncate text-slate-700 dark:text-slate-300">{{ $c['domain'] ?? '—' }}</span>
                                                @if (! empty($c['position']))
                                                    <span class="rounded-full bg-amber-50 px-2 py-px text-[10px] font-semibold text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">#{{ $c['position'] }}</span>
                                                @else
                                                    <span class="text-[10px] text-slate-400">not ranked</span>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- PAA --}}
                            @if (! empty($selected->people_also_ask))
                                <div class="border-t border-slate-200 px-5 py-3 dark:border-slate-800">
                                    <div class="mb-2 text-[10px] font-semibold uppercase tracking-wider text-slate-400">People also ask</div>
                                    <ul class="space-y-1 text-xs text-slate-700 dark:text-slate-300">
                                        @foreach ((array) $selected->people_also_ask as $paa)
                                            <li class="flex gap-2"><span class="text-slate-400">›</span>{{ $paa['question'] ?? ($paa['title'] ?? '—') }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            {{-- Related --}}
                            @if (! empty($selected->related_searches))
                                <div class="border-t border-slate-200 px-5 py-3 dark:border-slate-800">
                                    <div class="mb-2 text-[10px] font-semibold uppercase tracking-wider text-slate-400">Related searches</div>
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach ((array) $selected->related_searches as $rel)
                                            <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] text-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ $rel['query'] ?? '—' }}</span>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
