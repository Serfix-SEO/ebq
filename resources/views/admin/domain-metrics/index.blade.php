<x-layouts.app>
    @php
        $fmt = fn ($v) => $v === null ? '—' : number_format((int) $v);
        $band = fn ($v) => ! is_numeric($v) ? 'text-slate-400'
            : ((int) $v >= 60 ? 'text-emerald-600 dark:text-emerald-400'
                : ((int) $v >= 30 ? 'text-amber-600 dark:text-amber-400' : 'text-rose-600 dark:text-rose-400'));
        $f = $filters;
        // Build a sortable-header link that preserves current filters.
        $sortLink = function ($col) use ($f) {
            $dir = ($f['sort'] === $col && $f['dir'] === 'desc') ? 'asc' : 'desc';
            return request()->fullUrlWithQuery(['sort' => $col, 'dir' => $dir, 'page' => null]);
        };
        $arrow = fn ($col) => $f['sort'] === $col ? ($f['dir'] === 'asc' ? '↑' : '↓') : '↕';
    @endphp

    <div class="mx-auto max-w-7xl space-y-6 p-6">
        <div>
            <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">Domain Intelligence</h1>
            <p class="mt-1 text-sm text-slate-500">The accumulating domain_metrics asset — every domain we've scored, classified, or crawled. Grows forever; never deleted on churn.</p>
        </div>

        @if (session('status'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('status') }}</div>
        @endif

        {{-- Stats --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            @foreach ([
                ['Total domains', $stats['total'], null],
                ['Active tier', $stats['active'], 'active'],
                ['With scores', $stats['scored'], 'scores'],
                ['Classified', $stats['classified'], 'topic'],
                ['Seed list', $stats['seeds'], 'seed'],
                ['In CC graph', $stats['cc'], 'cc'],
            ] as [$label, $val, $hasFilter])
                <a href="{{ $hasFilter ? request()->fullUrlWithQuery(($hasFilter === 'active' ? ['tier' => 'active'] : ['has' => $hasFilter]) + ['page' => null]) : route('admin.domain-metrics.index') }}"
                   class="rounded-xl border border-slate-200 bg-white p-4 transition hover:border-orange-300 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ number_format($val) }}</p>
                </a>
            @endforeach
        </div>

        {{-- Filters --}}
        <form method="GET" class="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <label class="flex-1 min-w-[200px]">
                <span class="block text-xs font-medium text-slate-500">Search domain</span>
                <input type="text" name="q" value="{{ $f['q'] }}" placeholder="example.com"
                       class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
            </label>
            <label>
                <span class="block text-xs font-medium text-slate-500">Tier</span>
                <select name="tier" class="mt-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                    <option value="">Any</option>
                    <option value="active" @selected($f['tier'] === 'active')>Active</option>
                    <option value="free" @selected($f['tier'] === 'free')>Free</option>
                </select>
            </label>
            <label>
                <span class="block text-xs font-medium text-slate-500">Topic</span>
                <select name="topic" class="mt-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                    <option value="">Any</option>
                    @foreach ($topics as $t => $c)<option value="{{ $t }}" @selected($f['topic'] === $t)>{{ $t }} ({{ $c }})</option>@endforeach
                </select>
            </label>
            <label>
                <span class="block text-xs font-medium text-slate-500">Has</span>
                <select name="has" class="mt-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                    <option value="">Anything</option>
                    <option value="scores" @selected($f['has'] === 'scores')>Scores</option>
                    <option value="topic" @selected($f['has'] === 'topic')>Topic</option>
                    <option value="seed" @selected($f['has'] === 'seed')>Seed flag</option>
                    <option value="cc" @selected($f['has'] === 'cc')>CC rank</option>
                </select>
            </label>
            <input type="hidden" name="sort" value="{{ $f['sort'] }}"><input type="hidden" name="dir" value="{{ $f['dir'] }}">
            <button class="rounded-lg bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-700">Apply</button>
            @if ($f['q'] || $f['tier'] || $f['topic'] || $f['has'])
                <a href="{{ route('admin.domain-metrics.index') }}" class="py-2 text-xs text-slate-500 hover:underline">Reset</a>
            @endif
        </form>

        {{-- Table --}}
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800/50">
                        <tr class="text-left text-xs uppercase tracking-wide text-slate-400">
                            @foreach ([
                                'domain' => 'Domain', 'tier' => 'Tier', 'trust_score' => 'Trust', 'citation_score' => 'Citation',
                                'spam_score' => 'Spam', 'opr_score' => 'OPR', 'cc_harmonic_rank' => 'CC rank',
                                'topic' => 'Topic', 'times_seen' => 'Seen', 'last_seen_at' => 'Last seen',
                            ] as $col => $label)
                                <th class="px-4 py-2.5 font-medium {{ $col === 'domain' ? '' : 'text-right' }}">
                                    <a href="{{ $sortLink($col) }}" class="inline-flex items-center gap-1 hover:text-slate-600 dark:hover:text-slate-300">
                                        {{ $label }} <span class="text-[10px] text-slate-300">{{ $arrow($col) }}</span>
                                    </a>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($domains as $d)
                            <tr class="border-t border-slate-100 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/40">
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('admin.domain-metrics.show', $d) }}" class="inline-flex items-center gap-2 font-medium text-slate-800 hover:text-orange-600 dark:text-slate-200">
                                        <img src="https://www.google.com/s2/favicons?domain={{ urlencode($d->domain) }}&sz=32" alt="" class="h-4 w-4 flex-none rounded-sm bg-slate-100" loading="lazy" onerror="this.style.visibility='hidden'">
                                        {{ $d->domain }}
                                        @if ($d->is_seed)<span class="rounded bg-sky-100 px-1 text-[10px] font-bold text-sky-700 dark:bg-sky-500/15 dark:text-sky-300">SEED</span>@endif
                                    </a>
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    <span @class(['rounded-full px-2 py-0.5 text-[10px] font-semibold', 'bg-orange-100 text-orange-700 dark:bg-orange-500/15 dark:text-orange-300' => $d->tier === 'active', 'text-slate-400' => $d->tier !== 'active'])>{{ $d->tier }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums {{ $band($d->trust_score) }}">{{ $d->trust_score ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums {{ $band($d->citation_score) }}">{{ $d->citation_score ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-slate-500">{{ $d->spam_score ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-slate-500">{{ $d->opr_score !== null ? number_format($d->opr_score, 1) : '—' }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-slate-500">{{ $fmt($d->cc_harmonic_rank) }}</td>
                                <td class="px-4 py-2.5 text-right text-xs text-slate-500">{{ $d->topic ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-slate-500">{{ $fmt($d->times_seen) }}</td>
                                <td class="px-4 py-2.5 text-right text-xs text-slate-400">{{ $d->last_seen_at?->diffForHumans(short: true) ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="px-4 py-10 text-center text-sm text-slate-400">No domains match these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div>{{ $domains->links() }}</div>
    </div>
</x-layouts.app>
