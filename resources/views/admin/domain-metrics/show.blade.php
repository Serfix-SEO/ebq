<x-layouts.app>
    @php
        $fmt = fn ($v) => $v === null ? '—' : number_format((int) $v);
        $band = fn ($v) => ! is_numeric($v) ? '#94a3b8' : ((int) $v >= 60 ? '#10b981' : ((int) $v >= 30 ? '#f59e0b' : '#f43f5e'));
        $srcLabel = ['cc_harmonic' => 'Trust (harmonic rank)', 'cc_pagerank' => 'Citation (PageRank)', 'opr' => 'Open PageRank', 'dfs_rank' => 'DataForSEO rank'];
        // Rank sources are "lower is better" → invert for the sparkline direction only.
        $sparkline = function ($points, $invert = false) {
            $vals = array_map(fn ($p) => $p['value'], $points);
            if (count($vals) < 2) return null;
            $min = min($vals); $max = max($vals); $range = max(1e-9, $max - $min);
            $w = 220; $h = 40; $step = $w / (count($vals) - 1);
            $pts = [];
            foreach ($vals as $i => $v) {
                $norm = ($v - $min) / $range; if ($invert) $norm = 1 - $norm;
                $pts[] = round($i * $step, 1).','.round($h - $norm * ($h - 4) - 2, 1);
            }
            return ['line' => implode(' ', $pts), 'w' => $w, 'h' => $h];
        };
    @endphp

    <div class="mx-auto max-w-5xl space-y-6 p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <a href="{{ route('admin.domain-metrics.index') }}" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400">←</a>
                <img src="https://www.google.com/s2/favicons?domain={{ urlencode($m->domain) }}&sz=64" alt="" class="h-9 w-9 rounded-lg bg-slate-100 ring-1 ring-slate-200" onerror="this.style.visibility='hidden'">
                <div>
                    <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">{{ $m->domain }}</h1>
                    <p class="text-xs text-slate-500">
                        <span @class(['rounded px-1.5 font-semibold', 'bg-orange-100 text-orange-700' => $m->tier === 'active', 'text-slate-500' => $m->tier !== 'active'])>{{ $m->tier }} tier</span>
                        · seen {{ $fmt($m->times_seen) }}× · first {{ $m->first_seen_at?->diffForHumans() ?? '—' }}
                        @if ($m->is_seed) · <span class="font-semibold text-sky-600">trusted seed</span>@endif
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                @if ($hasReport)
                    <a href="{{ route('report.view', ['url' => $m->domain]) }}" target="_blank" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">View report</a>
                @endif
                <form method="POST" action="{{ route('admin.domain-metrics.refresh', $m) }}">@csrf
                    <button class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">Refresh feeds</button>
                </form>
                <form method="POST" action="{{ route('admin.domain-metrics.reclassify', $m) }}">@csrf
                    <button class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">Reclassify topic</button>
                </form>
            </div>
        </div>

        @if (session('status'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('status') }}</div>
        @endif

        {{-- Score cards --}}
        <div class="grid grid-cols-3 gap-4">
            @foreach ([['Trust', $m->trust_score], ['Citation', $m->citation_score], ['Spam', $m->spam_score]] as [$lbl, $val])
                <div class="rounded-xl border border-slate-200 bg-white p-5 text-center dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ $lbl }}</p>
                    <p class="mt-1 text-3xl font-bold tabular-nums" style="color: {{ $lbl === 'Spam' ? ($val !== null && $val > 30 ? '#f43f5e' : '#64748b') : $band($val) }}">{{ $val ?? '—' }}</p>
                </div>
            @endforeach
        </div>

        {{-- Raw metrics --}}
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            @foreach ([
                ['Open PageRank', $m->opr_score !== null ? number_format($m->opr_score, 2).'/10' : '—'],
                ['DataForSEO rank', $fmt($m->dfs_rank)],
                ['CC harmonic rank', $fmt($m->cc_harmonic_rank)],
                ['CC PageRank rank', $fmt($m->cc_pagerank_rank)],
                ['Topic', $m->topic ?? '—'],
                ['Classified', $m->topic_classified_at?->diffForHumans() ?? 'never'],
                ['OPR refreshed', $m->opr_refreshed_at?->diffForHumans() ?? 'never'],
                ['CC refreshed', $m->cc_refreshed_at?->diffForHumans() ?? 'never'],
            ] as [$lbl, $val])
                <div class="rounded-lg bg-slate-50 p-3 dark:bg-slate-800/50">
                    <p class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ $val }}</p>
                    <p class="text-xs text-slate-500">{{ $lbl }}</p>
                </div>
            @endforeach
        </div>

        {{-- History sparklines --}}
        @if ($history->isNotEmpty())
            <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="mb-4 text-sm font-semibold text-slate-900 dark:text-slate-100">Metric history</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    @foreach ($history as $source => $points)
                        @php $spark = $sparkline($points->toArray(), invert: str_contains($source, 'rank')); @endphp
                        <div class="rounded-lg border border-slate-100 p-3 dark:border-slate-800">
                            <div class="flex items-baseline justify-between">
                                <span class="text-xs font-medium text-slate-600 dark:text-slate-300">{{ $srcLabel[$source] ?? $source }}</span>
                                <span class="text-xs tabular-nums text-slate-400">{{ $points->count() }} pts</span>
                            </div>
                            @if ($spark)
                                <svg viewBox="0 0 {{ $spark['w'] }} {{ $spark['h'] }}" class="mt-2 block h-10 w-full" preserveAspectRatio="none">
                                    <polyline points="{{ $spark['line'] }}" fill="none" stroke="#F26419" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            @else
                                <p class="mt-2 text-xs text-slate-400">latest: {{ number_format($points->last()['value'], 1) }} (need ≥2 points for trend)</p>
                            @endif
                            <p class="mt-1 text-[10px] text-slate-400">{{ $points->first()['date'] }} → {{ $points->last()['date'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Link-graph presence --}}
        <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <div class="mb-4 flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Link graph presence</h2>
                <span class="text-xs text-slate-400">{{ $fmt($graph['inbound']) }} inbound · {{ $fmt($graph['outbound']) }} outbound edges</span>
            </div>
            @if ($graph['inbound'] === 0 && $graph['outbound'] === 0)
                <p class="text-sm text-slate-400">Not in the link graph yet — appears after a crawl or backlink report touches it.</p>
            @else
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Recent links TO this domain</p>
                        <div class="space-y-1">
                            @forelse ($graph['top_inbound'] as $e)
                                <div class="flex items-center justify-between rounded bg-slate-50 px-2 py-1 text-xs dark:bg-slate-800/50">
                                    <span class="truncate text-slate-700 dark:text-slate-300">{{ $e->name }}</span>
                                    <span class="flex-none text-[10px] text-slate-400">{{ $e->source }}{{ $e->dofollow ? ' · df' : '' }}</span>
                                </div>
                            @empty
                                <p class="text-xs text-slate-400">none</p>
                            @endforelse
                        </div>
                    </div>
                    <div>
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Recent links FROM this domain</p>
                        <div class="space-y-1">
                            @forelse ($graph['top_outbound'] as $e)
                                <div class="flex items-center justify-between rounded bg-slate-50 px-2 py-1 text-xs dark:bg-slate-800/50">
                                    <span class="truncate text-slate-700 dark:text-slate-300">{{ $e->name }}</span>
                                    <span class="flex-none text-[10px] text-slate-400">{{ $e->source }}{{ $e->dofollow ? ' · df' : '' }}</span>
                                </div>
                            @empty
                                <p class="text-xs text-slate-400">none</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>
