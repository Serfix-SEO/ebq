<div class="space-y-6" wire:key="keyword-detail-{{ $websiteId }}-{{ md5($query) }}">
    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <a href="{{ route('keywords.index') }}" wire:navigate
               class="inline-flex items-center gap-1 text-[11px] font-medium text-slate-500 transition hover:text-orange-600 dark:text-slate-400 dark:hover:text-orange-400">
                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/></svg>
                Back to keywords
            </a>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <h1 class="min-w-0 text-2xl font-bold tracking-tight text-slate-900 dark:text-slate-100">{{ $query ?: '—' }}</h1>
                @if (filled($language ?? null))
                    <span class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-slate-600 ring-1 ring-slate-200 dark:bg-slate-700 dark:text-slate-300 dark:ring-slate-600"
                          title="Detected language">{{ strtoupper($language) }}</span>
                @endif
                @if ($flags['striking_distance'])
                    <span class="inline-flex rounded-full bg-orange-50 px-2 py-0.5 text-[10px] font-semibold text-orange-700 ring-1 ring-orange-200 dark:bg-orange-500/10 dark:text-orange-400 dark:ring-orange-900/40">Striking distance</span>
                @endif
                @if ($flags['cannibalized'])
                    <span class="inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-900/40">Cannibalized</span>
                @endif
                @if ($flags['quick_win'])
                    <span class="inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-900/40">Quick win</span>
                @endif
                @if ($tracker)
                    <span class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-700 ring-1 ring-slate-200 dark:bg-slate-700 dark:text-slate-300 dark:ring-slate-600">Tracked</span>
                @endif
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($tracker)
                <a href="{{ route('rank-tracking.show', $tracker->id) }}" wire:navigate
                   class="inline-flex h-9 items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
                    Open in rank tracker
                </a>
            @else
                <button wire:click="addToRankTracker" wire:loading.attr="disabled" wire:target="addToRankTracker" type="button"
                        class="inline-flex h-9 items-center gap-1.5 rounded-md bg-orange-600 px-3 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-700 disabled:opacity-60">
                    <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    <span wire:loading.remove wire:target="addToRankTracker">Track this keyword</span>
                    <span wire:loading wire:target="addToRankTracker">Adding…</span>
                </button>
            @endif

            @php $_primaryPage = $top_pages[0]['page'] ?? null; @endphp
            <a href="{{ route('custom-audit.index') }}?{{ http_build_query(array_filter(['targetKeyword' => $query, 'pageUrl' => $_primaryPage])) }}"
               class="inline-flex h-9 items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
                Run custom audit
            </a>
        </div>
    </div>

    @if (session('keyword_detail_status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-800 dark:border-emerald-900/40 dark:bg-emerald-500/10 dark:text-emerald-300" role="status">
            {{ session('keyword_detail_status') }}
        </div>
    @endif

    @if (! $has_access)
        <div class="rounded-xl border border-slate-200 bg-white px-6 py-12 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-900">
            Select a website to view keyword details.
        </div>
    @else
        {{-- KPI cards: Volume · CPC · Competition · Value/mo --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {{-- Volume --}}
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Volume / mo</p>
                <div class="mt-2 flex items-baseline gap-2">
                    @if ($metric && $metric->search_volume !== null)
                        <span class="text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ number_format($metric->search_volume) }}</span>
                        @php $_trend = $metric->trend_class; @endphp
                        @if ($_trend === 'rising')
                            <span class="text-xs font-bold text-emerald-600 dark:text-emerald-400" title="Trend: rising over last 6 months">↑ rising</span>
                        @elseif ($_trend === 'falling')
                            <span class="text-xs font-bold text-rose-600 dark:text-rose-400" title="Trend: falling over last 6 months">↓ falling</span>
                        @elseif ($_trend === 'seasonal')
                            <span class="text-xs font-bold text-amber-600 dark:text-amber-400" title="Seasonal pattern">◐ seasonal</span>
                        @endif
                    @else
                        <span class="text-2xl font-bold text-slate-400">—</span>
                    @endif
                </div>
                @if ($metric && $metric->fetched_at)
                    <p class="mt-1.5 text-[10px] text-slate-400">updated {{ $metric->fetched_at->diffForHumans() }}</p>
                @else
                    <p class="mt-1.5 text-[10px] text-slate-400">pending fetch</p>
                @endif
            </div>

            {{-- CPC --}}
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">CPC</p>
                <div class="mt-2">
                    @if ($metric && $metric->cpc !== null)
                        <span class="text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ $metric->currency ?: 'USD' }} {{ number_format((float) $metric->cpc, 2) }}</span>
                    @else
                        <span class="text-2xl font-bold text-slate-400">—</span>
                    @endif
                </div>
                <p class="mt-1.5 text-[10px] text-slate-400">advertiser bid (Google Ads)</p>
            </div>

            {{-- Competition --}}
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Competition</p>
                <div class="mt-2">
                    @if ($metric && $metric->competition !== null)
                        @php $_comp = round((float) $metric->competition * 100); @endphp
                        <span class="text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ $_comp }}%</span>
                        <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                            <div class="h-full rounded-full {{ $_comp <= 40 ? 'bg-emerald-500' : ($_comp <= 70 ? 'bg-amber-500' : 'bg-rose-500') }}" style="width: {{ max(2, $_comp) }}%"></div>
                        </div>
                    @else
                        <span class="text-2xl font-bold text-slate-400">—</span>
                    @endif
                </div>
                <p class="mt-1.5 text-[10px] text-slate-400">lower is easier to win</p>
            </div>

            {{-- Projected value --}}
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Value / mo</p>
                <div class="mt-2">
                    @if ($projections['current_value'] !== null)
                        <span class="text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100">${{ number_format($projections['current_value'], 0) }}</span>
                    @else
                        <span class="text-2xl font-bold text-slate-400">—</span>
                    @endif
                </div>
                @if ($projections['upside_value'] !== null && $projections['upside_value'] > 0)
                    <p class="mt-1.5 text-[10px] font-semibold text-emerald-700 dark:text-emerald-400">
                        +${{ number_format($projections['upside_value'], 0) }}/mo upside at top of page 1
                    </p>
                @else
                    <p class="mt-1.5 text-[10px] text-slate-400">projected at current position</p>
                @endif
            </div>
        </div>

        {{-- GSC performance totals (28d) --}}
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Your performance · last 28 days</h2>
                <span class="text-[11px] text-slate-400">Google Search Console</span>
            </div>
            @if ($gsc_totals)
                <dl class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Clicks</dt>
                        <dd class="mt-1 text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ number_format($gsc_totals['clicks']) }}</dd>
                    </div>
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Impressions</dt>
                        <dd class="mt-1 text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ number_format($gsc_totals['impressions']) }}</dd>
                    </div>
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">CTR</dt>
                        <dd class="mt-1 text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ $gsc_totals['ctr'] }}%</dd>
                    </div>
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Avg position</dt>
                        <dd class="mt-1 flex items-baseline gap-2">
                            <span @class([
                                'inline-flex rounded-full px-2 py-0.5 text-base font-bold tabular-nums',
                                'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => $gsc_totals['position'] <= 3,
                                'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-400' => $gsc_totals['position'] > 3 && $gsc_totals['position'] <= 10,
                                'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400' => $gsc_totals['position'] > 10 && $gsc_totals['position'] <= 20,
                                'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300' => $gsc_totals['position'] > 20,
                            ])>#{{ $gsc_totals['position'] }}</span>
                        </dd>
                    </div>
                </dl>
            @else
                <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">No Search Console impressions on this query in the last 28 days.</p>
            @endif
        </div>

        {{-- 90-day clicks + position chart (inline SVG so no JS dep) --}}
        @if (! empty($gsc_daily))
            @php
                $maxClicks = max(1, max(array_column($gsc_daily, 'clicks')));
                $width = 100;
                $height = 30;
                $n = max(1, count($gsc_daily) - 1);
                $clicksPath = '';
                foreach ($gsc_daily as $i => $d) {
                    $x = $n > 0 ? round(($i / $n) * $width, 2) : 0;
                    $y = round($height - ($d['clicks'] / $maxClicks) * $height, 2);
                    $clicksPath .= ($i === 0 ? 'M' : ' L').$x.' '.$y;
                }
                $bestPos = 0;
                $worstPos = 0;
                foreach ($gsc_daily as $d) {
                    if ($d['position'] > 0) {
                        $bestPos = $bestPos === 0 ? $d['position'] : min($bestPos, $d['position']);
                        $worstPos = max($worstPos, $d['position']);
                    }
                }
                $posPath = '';
                $posRange = max(1, $worstPos - $bestPos);
                foreach ($gsc_daily as $i => $d) {
                    if ($d['position'] <= 0) continue;
                    $x = $n > 0 ? round(($i / $n) * $width, 2) : 0;
                    $y = round((($d['position'] - $bestPos) / $posRange) * $height, 2);
                    $posPath .= ($posPath === '' ? 'M' : ' L').$x.' '.$y;
                }
            @endphp
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Daily clicks · 90d</h3>
                        <span class="text-[11px] text-slate-400">peak {{ number_format($maxClicks) }}</span>
                    </div>
                    <svg viewBox="0 0 {{ $width }} {{ $height }}" preserveAspectRatio="none" class="mt-3 h-24 w-full">
                        <path d="{{ $clicksPath }}" fill="none" stroke="#F26419" stroke-width="0.8" vector-effect="non-scaling-stroke" />
                    </svg>
                    <div class="mt-2 flex justify-between text-[10px] text-slate-400">
                        <span>{{ $gsc_daily[0]['date'] }}</span>
                        <span>{{ end($gsc_daily)['date'] }}</span>
                    </div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Daily avg position · 90d</h3>
                        <span class="text-[11px] text-slate-400">best #{{ $bestPos ?: '—' }}</span>
                    </div>
                    <svg viewBox="0 0 {{ $width }} {{ $height }}" preserveAspectRatio="none" class="mt-3 h-24 w-full">
                        <path d="{{ $posPath }}" fill="none" stroke="#06b6d4" stroke-width="0.8" vector-effect="non-scaling-stroke" />
                    </svg>
                    <p class="mt-2 text-[10px] text-slate-400">Lower on the chart = worse rank. Y-axis range auto-fits observed positions.</p>
                </div>
            </div>
        @endif

        {{-- KE 12-month trend chart --}}
        @if ($metric && is_array($metric->trend_12m) && count($metric->trend_12m) > 0)
            @php
                $_trend = array_values(array_filter($metric->trend_12m, fn ($r) => is_array($r) && isset($r['value'])));
                $_maxVol = max(1, max(array_map(fn ($r) => (int) ($r['value'] ?? 0), $_trend)));
                $_n = max(1, count($_trend) - 1);
                $_path = '';
                foreach ($_trend as $i => $r) {
                    $x = $_n > 0 ? round(($i / $_n) * 100, 2) : 0;
                    $y = round(30 - ((int) $r['value'] / $_maxVol) * 30, 2);
                    $_path .= ($i === 0 ? 'M' : ' L').$x.' '.$y;
                }
            @endphp
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Search volume · 12 months</h3>
                    <span class="text-[11px] text-slate-400">peak {{ number_format($_maxVol) }}</span>
                </div>
                <svg viewBox="0 0 100 30" preserveAspectRatio="none" class="mt-3 h-24 w-full">
                    <path d="{{ $_path }}" fill="none" stroke="#8b5cf6" stroke-width="0.8" vector-effect="non-scaling-stroke" />
                </svg>
                <div class="mt-2 flex justify-between text-[10px] text-slate-400">
                    <span>{{ $_trend[0]['month'] ?? '' }} {{ $_trend[0]['year'] ?? '' }}</span>
                    <span>{{ end($_trend)['month'] ?? '' }} {{ end($_trend)['year'] ?? '' }}</span>
                </div>
            </div>
        @endif

        {{-- Top pages / country / device --}}
        <div class="grid gap-4 lg:grid-cols-2">
            {{-- Top pages --}}
            <div class="rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="border-b border-slate-200 px-5 py-3 dark:border-slate-800">
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Pages ranking for this query</h3>
                    <p class="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">Last 90 days, ordered by impressions. 2+ rows = candidate for consolidation.</p>
                </div>
                @if (empty($top_pages))
                    <p class="px-5 py-6 text-center text-sm text-slate-500 dark:text-slate-400">No pages of yours have served impressions for this query in the last 90 days.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead class="bg-slate-50 text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                                <tr>
                                    <th class="px-3 py-2 text-left">Page</th>
                                    <th class="px-3 py-2 text-right">Clicks</th>
                                    <th class="px-3 py-2 text-right">Impr.</th>
                                    <th class="px-3 py-2 text-right">CTR</th>
                                    <th class="px-3 py-2 text-right">Pos</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                @foreach ($top_pages as $p)
                                    <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                        <td class="max-w-[260px] truncate px-3 py-2">
                                            <a href="{{ route('pages.show', ['id' => urlencode($p['page'])]) }}" wire:navigate class="text-orange-600 hover:underline dark:text-orange-400" title="{{ $p['page'] }}">{{ $p['page'] }}</a>
                                        </td>
                                        <td class="px-3 py-2 text-right tabular-nums text-slate-700 dark:text-slate-300">{{ number_format($p['clicks']) }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums text-slate-500 dark:text-slate-400">{{ number_format($p['impressions']) }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums text-slate-500 dark:text-slate-400">{{ $p['ctr'] }}%</td>
                                        <td class="px-3 py-2 text-right tabular-nums font-semibold text-slate-900 dark:text-slate-100">{{ $p['position'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- Country + device breakdown --}}
            <div class="space-y-4">
                <div class="rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div class="border-b border-slate-200 px-5 py-3 dark:border-slate-800">
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">By country · 90d</h3>
                    </div>
                    @if (empty($countries))
                        <p class="px-5 py-4 text-center text-xs text-slate-500 dark:text-slate-400">No country data yet.</p>
                    @else
                        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach ($countries as $c)
                                <li class="flex items-center justify-between gap-3 px-5 py-2 text-xs">
                                    <span class="flex min-w-0 items-center gap-2">
                                        <span aria-hidden="true">{{ \App\Support\Countries::flag($c['country']) }}</span>
                                        <span class="min-w-0 truncate font-medium text-slate-800 dark:text-slate-100" title="{{ \App\Support\Countries::name($c['country']) }}">{{ \App\Support\Countries::name($c['country']) }}</span>
                                        <span class="shrink-0 font-mono text-[10px] text-slate-400">{{ $c['country'] }}</span>
                                    </span>
                                    <span class="flex shrink-0 items-center gap-3 tabular-nums text-slate-600 dark:text-slate-400">
                                        <span>{{ number_format($c['clicks']) }} clicks</span>
                                        <span class="text-slate-400">·</span>
                                        <span>#{{ $c['position'] }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div class="border-b border-slate-200 px-5 py-3 dark:border-slate-800">
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">By device · 90d</h3>
                    </div>
                    @if (empty($devices))
                        <p class="px-5 py-4 text-center text-xs text-slate-500 dark:text-slate-400">No device data yet.</p>
                    @else
                        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach ($devices as $d)
                                <li class="flex items-center justify-between gap-3 px-5 py-2 text-xs">
                                    <span class="font-medium capitalize text-slate-800 dark:text-slate-100">{{ strtolower($d['device']) }}</span>
                                    <span class="flex items-center gap-3 tabular-nums text-slate-600 dark:text-slate-400">
                                        <span>{{ number_format($d['clicks']) }} clicks</span>
                                        <span class="text-slate-400">·</span>
                                        <span>{{ number_format($d['impressions']) }} impr</span>
                                        <span class="text-slate-400">·</span>
                                        <span>#{{ $d['position'] }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>

        {{-- Rank tracker status --}}
        @if ($tracker)
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Rank tracker</h3>
                        <p class="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">Checked every {{ $tracker->check_interval_hours }}h · market {{ strtoupper($tracker->country) }} / {{ $tracker->device }} / {{ $tracker->language }}</p>
                    </div>
                    <a href="{{ route('rank-tracking.show', $tracker->id) }}" wire:navigate class="text-[11px] font-semibold text-orange-600 hover:underline dark:text-orange-400">Open detail →</a>
                </div>
                <dl class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Current</dt>
                        <dd class="mt-1 text-xl font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ $tracker->current_position ? '#'.$tracker->current_position : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Best</dt>
                        <dd class="mt-1 text-xl font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ $tracker->best_position ? '#'.$tracker->best_position : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Change</dt>
                        <dd class="mt-1 text-xl font-bold tabular-nums">
                            @if ($tracker->position_change > 0)
                                <span class="text-emerald-600 dark:text-emerald-400">▲ {{ $tracker->position_change }}</span>
                            @elseif ($tracker->position_change < 0)
                                <span class="text-rose-600 dark:text-rose-400">▼ {{ abs($tracker->position_change) }}</span>
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Last checked</dt>
                        <dd class="mt-1 text-xl font-bold text-slate-900 dark:text-slate-100">{{ $tracker->last_checked_at ? $tracker->last_checked_at->diffForHumans() : 'pending' }}</dd>
                    </div>
                </dl>
            </div>
        @endif

        {{-- People Also Ask + related searches --}}
        @if (! empty($paa) || ! empty($related_searches))
            <div class="grid gap-4 lg:grid-cols-2">
                @if (! empty($paa))
                    <div class="rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <div class="border-b border-slate-200 px-5 py-3 dark:border-slate-800">
                            <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">People also ask</h3>
                            <p class="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">From the latest SERP snapshot. Strong candidates for H2 / FAQ additions.</p>
                        </div>
                        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach ($paa as $q)
                                <li class="px-5 py-2.5 text-xs">
                                    <p class="font-medium text-slate-800 dark:text-slate-100">{{ $q['question'] ?? ($q['query'] ?? '—') }}</p>
                                    @if (! empty($q['snippet']))
                                        <p class="mt-0.5 text-slate-500 dark:text-slate-400">{{ \Illuminate\Support\Str::limit($q['snippet'], 180) }}</p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if (! empty($related_searches))
                    <div class="rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <div class="border-b border-slate-200 px-5 py-3 dark:border-slate-800">
                            <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Related searches</h3>
                            <p class="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">Queries Google suggested on the latest SERP. Useful for cluster expansion.</p>
                        </div>
                        <ul class="flex flex-wrap gap-1.5 p-4">
                            @foreach ($related_searches as $r)
                                @php $_rq = $r['query'] ?? ($r['q'] ?? ''); @endphp
                                @if ($_rq !== '')
                                    <li>
                                        <a href="{{ route('keywords.show', ['query' => rawurlencode($_rq)]) }}" wire:navigate
                                           class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] text-slate-700 transition hover:border-orange-300 hover:bg-orange-50 hover:text-orange-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                            {{ $_rq }}
                                        </a>
                                    </li>
                                @endif
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif
    @endif
</div>
