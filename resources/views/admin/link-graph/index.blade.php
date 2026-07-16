<x-layouts.app>
    @php
        $c = $crawler;
        $budgetPct = $c['budget_limit'] > 0 ? min(100, round(100 * $c['budget_spent'] / $c['budget_limit'])) : 0;
        $maxDay = max(1, max(array_column($series, 'count')));
        $srcColor = ['own_crawl' => '#F26419', 'enrichment' => '#0ea5e9', 'provider' => '#8b5cf6', 'cc_wat' => '#10b981'];
        $srcHelp = [
            'own_crawl' => 'Our crawler visiting a tracked domain',
            'enrichment' => 'Outbound links grabbed while building a report',
            'provider' => 'DataForSEO backlink rows, kept permanently',
            'cc_wat' => 'Common Crawl archive extraction (future)',
        ];
        $fmt = fn ($v) => number_format((int) $v);
        $statusLabel = ! $c['env_on'] ? ['Off', 'bg-slate-100 text-slate-500 dark:bg-slate-800', 'bg-slate-400']
            : ($c['paused'] ? ['Paused', 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300', 'bg-amber-500']
            : ($c['running'] ? ['Running', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300', 'bg-emerald-500 animate-pulse']
            : ['Idle', 'bg-slate-100 text-slate-500 dark:bg-slate-800', 'bg-slate-400']));
    @endphp

    <div class="mx-auto max-w-6xl space-y-6 p-6">
        {{-- ── Header ─────────────────────────────────────────── --}}
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">Link Graph</h1>
                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusLabel[1] }}">
                    <span class="h-1.5 w-1.5 rounded-full {{ $statusLabel[2] }}"></span> {{ $statusLabel[0] }}
                </span>
            </div>
            <div class="flex items-center gap-2">
                <form method="POST" action="{{ route('admin.link-graph.reseed') }}">@csrf
                    <button class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">Reseed</button>
                </form>
                <form method="POST" action="{{ route('admin.link-graph.toggle') }}">@csrf
                    <input type="hidden" name="enabled" value="{{ $c['enabled'] ? 0 : 1 }}">
                    <button @class(['rounded-lg px-3 py-1.5 text-xs font-semibold text-white transition', 'bg-rose-600 hover:bg-rose-700' => $c['enabled'], 'bg-emerald-600 hover:bg-emerald-700' => ! $c['enabled']]) @disabled(! $c['env_on'])>
                        {{ $c['enabled'] ? 'Pause' : 'Resume' }}
                    </button>
                </form>
            </div>
        </div>

        @if (session('status'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('status') }}</div>
        @endif

        {{-- ── KPI tiles ──────────────────────────────────────── --}}
        <div class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-slate-200 bg-slate-200 dark:border-slate-800 dark:bg-slate-800 sm:grid-cols-4 lg:grid-cols-6">
            @foreach ([
                ['Total edges', $fmt($totals['edges']), 'text-slate-900 dark:text-slate-100'],
                ['Domains', $fmt($totals['domains']), 'text-slate-900 dark:text-slate-100'],
                ['New today', $fmt($totals['discovered_today']), 'text-orange-600 dark:text-orange-400'],
                ['Crawled today', $fmt($c['crawled_today']), 'text-slate-900 dark:text-slate-100'],
                ['Frontier due', $fmt($c['frontier_due']), 'text-slate-900 dark:text-slate-100'],
                ['Queue', $c['queue_depth'] ?? '—', 'text-slate-900 dark:text-slate-100'],
            ] as [$label, $val, $color])
                <div class="bg-white p-4 dark:bg-slate-900">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ $label }}</p>
                    <p class="mt-1 text-xl font-bold tabular-nums {{ $color }}">{{ $val }}</p>
                </div>
            @endforeach
        </div>

        {{-- ── Budget bar ─────────────────────────────────────── --}}
        <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-center justify-between text-xs">
                <span class="font-semibold text-slate-600 dark:text-slate-300">Daily crawl budget</span>
                <span class="tabular-nums text-slate-500">{{ $fmt($c['budget_spent']) }} / {{ $fmt($c['budget_limit']) }} pages</span>
            </div>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"><div class="h-full rounded-full bg-orange-500 transition-all" style="width: {{ $budgetPct }}%"></div></div>
        </div>

        {{-- ── Filters ────────────────────────────────────────── --}}
        <form method="GET" class="flex flex-wrap items-center gap-3 text-sm">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">View</span>
            <select name="days" onchange="this.form.submit()" class="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                @foreach ([7, 14, 30, 90] as $d)<option value="{{ $d }}" @selected($filters['days'] === $d)>Last {{ $d }} days</option>@endforeach
            </select>
            <select name="source" onchange="this.form.submit()" class="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                <option value="all" @selected($filters['source'] === 'all')>All sources</option>
                @foreach ($sources as $s)<option value="{{ $s }}" @selected($filters['source'] === $s)>{{ $s }}</option>@endforeach
            </select>
            @if ($filters['source'] !== 'all' || $filters['days'] !== 30)
                <a href="{{ route('admin.link-graph.index') }}" class="text-xs text-orange-600 hover:underline dark:text-orange-400">Reset</a>
            @endif
        </form>

        {{-- ── Discovery chart ────────────────────────────────── --}}
        <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <div class="mb-1 flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">New links discovered / day</h2>
                <span class="text-xs text-slate-400">{{ $fmt($discovered_in_range) }} total · {{ $filters['source'] === 'all' ? 'all sources' : $filters['source'] }}</span>
            </div>
            <div class="relative mt-4 h-40">
                <div class="absolute inset-0 flex flex-col justify-between">
                    @foreach ([1, .66, .33, 0] as $g)<div class="border-t border-dashed border-slate-100 dark:border-slate-800"></div>@endforeach
                </div>
                <div class="relative flex h-full items-end gap-1">
                    @foreach ($series as $pt)
                        <div class="group relative flex flex-1 flex-col items-center justify-end">
                            <div class="w-full rounded-t bg-orange-400 transition group-hover:bg-orange-500" style="height: {{ max(1, round(100 * $pt['count'] / $maxDay)) }}%"></div>
                            <div class="pointer-events-none absolute -top-9 z-10 hidden whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-[10px] font-medium text-white shadow-lg group-hover:block">
                                {{ \Illuminate\Support\Carbon::parse($pt['date'])->format('M j') }}<br><span class="font-bold text-orange-300">{{ $fmt($pt['count']) }} links</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="mt-2 flex justify-between text-[10px] text-slate-400">
                <span>{{ \Illuminate\Support\Carbon::parse($series[0]['date'])->format('M j') }}</span>
                <span>peak {{ $fmt($maxDay) }}/day</span>
                <span>{{ \Illuminate\Support\Carbon::parse(end($series)['date'])->format('M j') }}</span>
            </div>
        </div>

        {{-- ── Sources + frontier + top targets ───────────────── --}}
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            {{-- Sources (clickable → filters feed) --}}
            <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Where links come from</h3>
                <p class="mt-0.5 text-xs text-slate-400">Click a source to filter the feed below.</p>
                @php $srcTotal = max(1, $by_source->sum()); @endphp
                <div class="mt-3 space-y-2.5">
                    @foreach ($by_source->sortDesc() as $src => $cnt)
                        <a href="{{ request()->fullUrlWithQuery(['source' => $src, 'feed' => null]) }}#feed"
                           class="block rounded-lg border p-2.5 transition hover:shadow-sm {{ $filters['source'] === $src ? 'border-orange-300 bg-orange-50/50 dark:border-orange-500/40 dark:bg-orange-500/5' : 'border-slate-100 hover:border-slate-200 dark:border-slate-800' }}">
                            <div class="flex items-center justify-between">
                                <span class="flex items-center gap-2 text-sm font-medium text-slate-700 dark:text-slate-200">
                                    <span class="h-2.5 w-2.5 rounded-full" style="background: {{ $srcColor[$src] ?? '#94a3b8' }}"></span>{{ $src }}
                                </span>
                                <span class="tabular-nums text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $fmt($cnt) }}</span>
                            </div>
                            <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"><div class="h-full rounded-full" style="width: {{ max(2, round(100 * $cnt / $srcTotal)) }}%; background: {{ $srcColor[$src] ?? '#94a3b8' }}"></div></div>
                            <p class="mt-1 text-[11px] text-slate-400">{{ $srcHelp[$src] ?? '' }}</p>
                        </a>
                    @endforeach
                </div>
                <div class="mt-4 grid grid-cols-2 gap-3 border-t border-slate-100 pt-3 text-center dark:border-slate-800">
                    @foreach (['done' => 'Crawled', 'pending' => 'Queued', 'blocked' => 'Blocked', 'failed' => 'Failed'] as $st => $lbl)
                        <div><p class="text-lg font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ $fmt($c['frontier'][$st] ?? 0) }}</p><p class="text-[10px] uppercase tracking-wider text-slate-400">{{ $lbl }}</p></div>
                    @endforeach
                </div>
            </div>

            {{-- Top discovered targets --}}
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="border-b border-slate-100 px-5 py-3 dark:border-slate-800">
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Most-linked-to domains <span class="font-normal text-slate-400">· {{ $filters['days'] }}d</span></h3>
                </div>
                <div class="max-h-96 divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
                    @forelse ($top_targets as $t)
                        <div class="flex items-center justify-between px-5 py-2.5">
                            <span class="flex items-center gap-2 truncate">
                                <img src="https://www.google.com/s2/favicons?domain={{ urlencode($t->name) }}&sz=32" alt="" class="h-4 w-4 flex-none rounded-sm bg-slate-100" loading="lazy" onerror="this.style.visibility='hidden'">
                                <span class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $t->name }}</span>
                            </span>
                            <span class="flex-none text-right text-xs">
                                <span class="font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ $fmt($t->c) }}</span>
                                <span class="text-slate-400"> links · {{ $fmt($t->df) }} df</span>
                            </span>
                        </div>
                    @empty
                        <div class="px-5 py-10 text-center text-sm text-slate-400">No links in this window yet.</div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- ── Discovery feed (paginated) ─────────────────────── --}}
        <div id="feed" class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-3 dark:border-slate-800">
                <h3 class="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-slate-100">
                    Discovery feed
                    @if ($filters['source'] !== 'all')
                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium" style="background: {{ ($srcColor[$filters['source']] ?? '#94a3b8') }}20; color: {{ $srcColor[$filters['source']] ?? '#64748b' }}">
                            {{ $filters['source'] }}
                            <a href="{{ request()->fullUrlWithQuery(['source' => 'all', 'feed' => null]) }}#feed" class="hover:opacity-70">✕</a>
                        </span>
                    @endif
                </h3>
                <span class="text-xs text-slate-400">{{ number_format($recent->total()) }} links · last {{ $filters['days'] }}d</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-[10px] uppercase tracking-wider text-slate-400 dark:bg-slate-800/50">
                        <tr>
                            <th class="px-5 py-2.5 font-medium">Source page</th>
                            <th class="px-3 py-2.5 font-medium">Links to</th>
                            <th class="px-3 py-2.5 text-right font-medium">Anchor</th>
                            <th class="px-3 py-2.5 text-right font-medium">Origin</th>
                            <th class="px-5 py-2.5 text-right font-medium">Found</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @forelse ($recent as $e)
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                <td class="px-5 py-2.5">
                                    <span class="flex items-center gap-2">
                                        <img src="https://www.google.com/s2/favicons?domain={{ urlencode($e->from_domain) }}&sz=32" alt="" class="h-4 w-4 flex-none rounded-sm bg-slate-100" loading="lazy" onerror="this.style.visibility='hidden'">
                                        <span class="truncate font-medium text-slate-700 dark:text-slate-300">{{ $e->from_domain }}{{ $e->from_path && $e->from_path !== '/' ? \Illuminate\Support\Str::limit($e->from_path, 32) : '' }}</span>
                                    </span>
                                </td>
                                <td class="px-3 py-2.5">
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="font-medium text-slate-900 dark:text-slate-100">{{ $e->to_domain }}</span>
                                        @if ($e->dofollow)<span class="rounded bg-emerald-50 px-1 text-[10px] font-semibold text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400">df</span>@endif
                                    </span>
                                </td>
                                <td class="px-3 py-2.5 text-right text-xs text-slate-400">{{ $e->anchor_class ?? '—' }}</td>
                                <td class="px-3 py-2.5 text-right">
                                    <span class="rounded px-1.5 py-0.5 text-[10px] font-medium" style="background: {{ ($srcColor[$e->source] ?? '#94a3b8') }}20; color: {{ $srcColor[$e->source] ?? '#64748b' }}">{{ $e->source }}</span>
                                </td>
                                <td class="px-5 py-2.5 text-right text-[11px] text-slate-400">{{ \Illuminate\Support\Carbon::parse($e->first_seen_at)->diffForHumans(short: true) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-5 py-12 text-center text-sm text-slate-400">No links in this window{{ $filters['source'] !== 'all' ? ' for '.$filters['source'] : '' }}. Widen the range or change the source.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($recent->hasPages())
                <div class="border-t border-slate-100 px-4 py-3 dark:border-slate-800">{{ $recent->links() }}</div>
            @endif
        </div>
    </div>
</x-layouts.app>
