<x-layouts.app>
    @php
        $c = $crawler;
        $budgetPct = $c['budget_limit'] > 0 ? min(100, round(100 * $c['budget_spent'] / $c['budget_limit'])) : 0;
        $maxDay = max(1, max(array_column($series, 'count')));
        $srcColor = ['own_crawl' => '#F26419', 'enrichment' => '#0ea5e9', 'provider' => '#8b5cf6', 'cc_wat' => '#10b981'];
        $fmt = fn ($v) => number_format((int) $v);
    @endphp

    <div class="mx-auto max-w-6xl space-y-6 p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">Link Graph — engine dashboard</h1>
                <p class="mt-1 text-sm text-slate-500">Live Tier-1.5 crawler status, new-backlink discovery per day, and the growing link asset.</p>
            </div>
            <div class="flex items-center gap-2">
                <form method="POST" action="{{ route('admin.link-graph.reseed') }}">@csrf
                    <button class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">Reseed frontier</button>
                </form>
                <form method="POST" action="{{ route('admin.link-graph.toggle') }}">@csrf
                    <input type="hidden" name="enabled" value="{{ $c['enabled'] ? 0 : 1 }}">
                    <button @class(['rounded-lg px-3 py-2 text-xs font-semibold text-white', 'bg-rose-600 hover:bg-rose-700' => $c['enabled'], 'bg-emerald-600 hover:bg-emerald-700' => ! $c['enabled']])
                            @disabled(! $c['env_on'])>
                        {{ $c['enabled'] ? 'Pause crawler' : 'Resume crawler' }}
                    </button>
                </form>
            </div>
        </div>

        @if (session('status'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('status') }}</div>
        @endif

        {{-- ── Live status strip ─────────────────────────────── --}}
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Crawler</p>
                <p class="mt-1 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-slate-100">
                    @if (! $c['env_on'])
                        <span class="h-2.5 w-2.5 rounded-full bg-slate-400"></span> Off (env)
                    @elseif ($c['paused'])
                        <span class="h-2.5 w-2.5 rounded-full bg-amber-500"></span> Paused
                    @elseif ($c['running'])
                        <span class="h-2.5 w-2.5 animate-pulse rounded-full bg-emerald-500"></span> Running
                    @else
                        <span class="h-2.5 w-2.5 rounded-full bg-slate-400"></span> Idle
                    @endif
                </p>
                <p class="mt-1 text-xs text-slate-400">Queue depth: {{ $c['queue_depth'] ?? '—' }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Crawled today</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ $fmt($c['crawled_today']) }}</p>
                <p class="mt-1 text-xs text-slate-400">domains</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">New links today</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-orange-600 dark:text-orange-400">{{ $fmt($totals['discovered_today']) }}</p>
                <p class="mt-1 text-xs text-slate-400">{{ $fmt($discovered_in_range) }} in {{ $filters['days'] }}d</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Daily budget</p>
                <p class="mt-1 text-lg font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ $fmt($c['budget_spent']) }}<span class="text-sm font-normal text-slate-400"> / {{ $fmt($c['budget_limit']) }}</span></p>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"><div class="h-full rounded-full bg-orange-500" style="width: {{ $budgetPct }}%"></div></div>
            </div>
        </div>

        {{-- ── Filters ────────────────────────────────────────── --}}
        <form method="GET" class="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Filters</span>
            <label class="flex items-center gap-1.5 text-sm text-slate-600 dark:text-slate-300">Range
                <select name="days" onchange="this.form.submit()" class="rounded-lg border border-slate-200 bg-white px-2 py-1 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                    @foreach ([7, 14, 30, 90] as $d)<option value="{{ $d }}" @selected($filters['days'] === $d)>{{ $d }} days</option>@endforeach
                </select>
            </label>
            <label class="flex items-center gap-1.5 text-sm text-slate-600 dark:text-slate-300">Source
                <select name="source" onchange="this.form.submit()" class="rounded-lg border border-slate-200 bg-white px-2 py-1 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                    <option value="all" @selected($filters['source'] === 'all')>All sources</option>
                    @foreach ($sources as $s)<option value="{{ $s }}" @selected($filters['source'] === $s)>{{ $s }}</option>@endforeach
                </select>
            </label>
            @if ($filters['source'] !== 'all' || $filters['days'] !== 30)
                <a href="{{ route('admin.link-graph.index') }}" class="text-xs text-orange-600 hover:underline dark:text-orange-400">Reset</a>
            @endif
        </form>

        {{-- ── New backlink discovery per day ─────────────────── --}}
        <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <div class="mb-4 flex items-baseline justify-between">
                <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">New backlinks discovered / day</h2>
                <span class="text-xs text-slate-400">{{ $filters['source'] === 'all' ? 'all sources' : $filters['source'] }} · {{ $filters['days'] }} days</span>
            </div>
            <div class="flex h-40 items-end gap-1">
                @foreach ($series as $pt)
                    <div class="group relative flex flex-1 flex-col items-center justify-end">
                        <div class="w-full rounded-t bg-orange-500 transition group-hover:bg-orange-600" style="height: {{ max(2, round(100 * $pt['count'] / $maxDay)) }}%"></div>
                        <div class="pointer-events-none absolute -top-8 z-10 hidden whitespace-nowrap rounded bg-slate-900 px-2 py-1 text-[10px] text-white group-hover:block">{{ \Illuminate\Support\Carbon::parse($pt['date'])->format('M j') }}: {{ $fmt($pt['count']) }}</div>
                    </div>
                @endforeach
            </div>
            <div class="mt-1 flex justify-between text-[10px] text-slate-400">
                <span>{{ \Illuminate\Support\Carbon::parse($series[0]['date'])->format('M j') }}</span>
                <span>{{ \Illuminate\Support\Carbon::parse(end($series)['date'])->format('M j') }}</span>
            </div>
        </div>

        {{-- ── Totals + source split + frontier ───────────────── --}}
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Graph totals</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">Total edges</dt><dd class="font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ $fmt($totals['edges']) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Domains</dt><dd class="font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ $fmt($totals['domains']) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Source URLs</dt><dd class="font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ $fmt($totals['urls']) }}</dd></div>
                </dl>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Edges by source</h3>
                <div class="mt-3 space-y-2">
                    @php $srcTotal = max(1, $by_source->sum()); @endphp
                    @foreach ($by_source->sortDesc() as $src => $cnt)
                        <div>
                            <div class="flex justify-between text-xs"><span class="font-medium text-slate-600 dark:text-slate-300">{{ $src }}</span><span class="tabular-nums text-slate-500">{{ $fmt($cnt) }}</span></div>
                            <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"><div class="h-full rounded-full" style="width: {{ max(2, round(100 * $cnt / $srcTotal)) }}%; background: {{ $srcColor[$src] ?? '#94a3b8' }}"></div></div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Crawl frontier</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">Due now</dt><dd class="font-semibold tabular-nums text-orange-600 dark:text-orange-400">{{ $fmt($c['frontier_due']) }}</dd></div>
                    @foreach (['pending', 'done', 'blocked', 'failed'] as $st)
                        <div class="flex justify-between"><dt class="text-slate-500 capitalize">{{ $st }}</dt><dd class="font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ $fmt($c['frontier'][$st] ?? 0) }}</dd></div>
                    @endforeach
                </dl>
            </div>
        </div>

        {{-- ── Top discovered targets + recent feed ───────────── --}}
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="border-b border-slate-100 px-5 py-3 dark:border-slate-800"><h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Most-linked-to domains ({{ $filters['days'] }}d)</h3></div>
                <div class="max-h-96 overflow-y-auto">
                    <table class="w-full text-sm">
                        <tbody>
                            @forelse ($top_targets as $t)
                                <tr class="border-t border-slate-100 dark:border-slate-800">
                                    <td class="px-5 py-2 font-medium text-slate-800 dark:text-slate-200">{{ $t->name }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $fmt($t->c) }} <span class="text-xs text-slate-400">links</span></td>
                                    <td class="px-5 py-2 text-right text-xs text-emerald-600 dark:text-emerald-400">{{ $fmt($t->df) }} dofollow</td>
                                </tr>
                            @empty
                                <tr><td class="px-5 py-6 text-center text-sm text-slate-400">No links discovered in this window yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3 dark:border-slate-800">
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Discovery feed</h3>
                    <span class="text-xs text-slate-400">{{ $filters['source'] === 'all' ? 'all sources' : $filters['source'] }} · {{ $filters['days'] }}d · {{ number_format($recent->total()) }} links</span>
                </div>
                <div class="overflow-y-auto">
                    <table class="w-full text-sm">
                        <tbody>
                            @forelse ($recent as $e)
                                <tr class="border-t border-slate-100 dark:border-slate-800">
                                    <td class="px-5 py-2">
                                        <div>
                                            <span class="text-slate-600 dark:text-slate-400">{{ \Illuminate\Support\Str::limit($e->from_domain, 24) }}</span>
                                            <span class="text-slate-400"> → </span>
                                            <span class="font-medium text-slate-900 dark:text-slate-100">{{ \Illuminate\Support\Str::limit($e->to_domain, 24) }}</span>
                                        </div>
                                        @if ($e->from_path && $e->from_path !== '/')<div class="truncate text-[10px] text-slate-400">{{ \Illuminate\Support\Str::limit($e->from_path, 48) }}</div>@endif
                                    </td>
                                    <td class="px-3 py-2 text-right text-[10px] text-slate-400">{{ $e->anchor_class ?? '—' }}</td>
                                    <td class="px-3 py-2 text-right">
                                        <span class="rounded px-1.5 py-0.5 text-[10px] font-medium" style="background: {{ ($srcColor[$e->source] ?? '#94a3b8') }}20; color: {{ $srcColor[$e->source] ?? '#64748b' }}">{{ $e->source }}</span>
                                        @if ($e->dofollow)<span class="ml-1 text-[10px] text-emerald-600">df</span>@endif
                                    </td>
                                    <td class="px-5 py-2 text-right text-[11px] text-slate-400">{{ \Illuminate\Support\Carbon::parse($e->first_seen_at)->diffForHumans(short: true) }}</td>
                                </tr>
                            @empty
                                <tr><td class="px-5 py-6 text-center text-sm text-slate-400">No links in this window.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($recent->hasPages())
                    <div class="border-t border-slate-100 px-4 py-3 dark:border-slate-800">{{ $recent->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</x-layouts.app>
