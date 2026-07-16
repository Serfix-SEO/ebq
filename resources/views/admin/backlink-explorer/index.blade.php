<x-layouts.app>
    @php
        $f = $filters;
        $fmt = fn ($v) => number_format((int) $v);
        $srcColor = ['own_crawl' => '#F26419', 'enrichment' => '#0ea5e9', 'provider' => '#8b5cf6', 'cc_wat' => '#10b981'];
        $dofollowPct = ($stats && $stats['edges'] > 0) ? round(100 * $stats['dofollow'] / $stats['edges']) : 0;
    @endphp

    <div class="mx-auto max-w-6xl space-y-6 p-6">
        <div>
            <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">Backlink Explorer</h1>
            <p class="mt-1 text-sm text-slate-500">Search every backlink we've stored for a domain — crawler-discovered <em>and</em> previously-processed DataForSEO. Reads our permanent link graph only; <strong>no new provider calls</strong>.</p>
        </div>

        {{-- Search + filters --}}
        <form method="GET" class="space-y-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <div class="flex flex-wrap items-end gap-3">
                <label class="flex-1 w-64">
                    <span class="block text-xs font-medium text-slate-500">Domain</span>
                    <input type="text" name="domain" value="{{ $f['domain_raw'] }}" placeholder="example.com" autofocus
                           class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                </label>
                <div>
                    <span class="block text-xs font-medium text-slate-500">Direction</span>
                    <div class="mt-1 inline-flex rounded-lg bg-slate-100 p-0.5 text-sm dark:bg-slate-800">
                        <label class="cursor-pointer rounded-md px-3 py-1.5 {{ $f['direction'] === 'inbound' ? 'bg-white shadow-sm dark:bg-slate-700' : 'text-slate-500' }}">
                            <input type="radio" name="direction" value="inbound" class="hidden" @checked($f['direction'] === 'inbound') onchange="this.form.submit()"> Links to it
                        </label>
                        <label class="cursor-pointer rounded-md px-3 py-1.5 {{ $f['direction'] === 'outbound' ? 'bg-white shadow-sm dark:bg-slate-700' : 'text-slate-500' }}">
                            <input type="radio" name="direction" value="outbound" class="hidden" @checked($f['direction'] === 'outbound') onchange="this.form.submit()"> Links from it
                        </label>
                    </div>
                </div>
                <label>
                    <span class="block text-xs font-medium text-slate-500">Source</span>
                    <select name="source" class="mt-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                        <option value="all" @selected($f['source'] === 'all')>All sources</option>
                        @foreach ($sources as $s)<option value="{{ $s }}" @selected($f['source'] === $s)>{{ $s }}</option>@endforeach
                    </select>
                </label>
                <label>
                    <span class="block text-xs font-medium text-slate-500">Anchor</span>
                    <select name="anchor" class="mt-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                        <option value="">Any</option>
                        @foreach (['naked', 'generic', 'text', 'empty'] as $a)<option value="{{ $a }}" @selected($f['anchor'] === $a)>{{ $a }}</option>@endforeach
                    </select>
                </label>
                <label class="flex items-center gap-1.5 pb-2 text-sm text-slate-600 dark:text-slate-300">
                    <input type="checkbox" name="dofollow" value="1" @checked($f['dofollow']) class="rounded border-slate-300"> Dofollow only
                </label>
                <button class="rounded-lg bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-700">Search</button>
                @if ($node)
                    <a href="{{ route('admin.backlink-explorer.export', request()->query()) }}" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">Export CSV</a>
                @endif
            </div>
        </form>

        @if ($f['domain'] === '')
            <div class="rounded-xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-400 dark:border-slate-800 dark:bg-slate-900">Enter a domain to search our stored backlinks.</div>
        @elseif ($node === null)
            <div class="rounded-xl border border-slate-200 bg-white p-10 text-center dark:border-slate-800 dark:bg-slate-900">
                <p class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $f['domain'] }}</p>
                <p class="mt-1 text-sm text-slate-400">Not in our link graph yet. It appears once the crawler reaches it or a backlink report is generated for it.</p>
            </div>
        @else
            {{-- Stats --}}
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ $f['direction'] === 'inbound' ? 'Backlinks' : 'Outbound links' }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ $fmt($stats['edges']) }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ $f['direction'] === 'inbound' ? 'Referring domains' : 'Target domains' }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ $fmt($stats['domains']) }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Dofollow</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $dofollowPct }}%</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Sources</p>
                    <div class="mt-1.5 space-y-1">
                        @foreach ($stats['by_source']->sortDesc() as $src => $c)
                            <div class="flex items-center gap-1.5 text-xs">
                                <span class="h-2 w-2 rounded-full" style="background: {{ $srcColor[$src] ?? '#94a3b8' }}"></span>
                                <span class="text-slate-600 dark:text-slate-300">{{ $src }}</span>
                                <span class="ml-auto tabular-nums text-slate-400">{{ $fmt($c) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Results --}}
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3 dark:border-slate-800">
                    <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                        {{ $f['direction'] === 'inbound' ? 'Links pointing at' : 'Links from' }} {{ $f['domain'] }}
                    </span>
                    <a href="{{ route('admin.domain-metrics.show', ['domainMetric' => \App\Models\DomainMetric::where('domain', $f['domain'])->value('id') ?? 0]) }}"
                       class="text-xs text-orange-600 hover:underline dark:text-orange-400">Domain profile →</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800/50"><tr class="text-left text-xs uppercase tracking-wide text-slate-400">
                            <th class="px-5 py-2.5 font-medium">{{ $f['direction'] === 'inbound' ? 'From' : 'To' }}</th>
                            <th class="px-3 py-2.5 font-medium">Source page</th>
                            <th class="px-3 py-2.5 text-right font-medium">Type</th>
                            <th class="px-3 py-2.5 text-right font-medium">Anchor</th>
                            <th class="px-3 py-2.5 text-right font-medium">Origin</th>
                            <th class="px-5 py-2.5 text-right font-medium">First seen</th>
                        </tr></thead>
                        <tbody>
                            @forelse ($rows as $r)
                                <tr class="border-t border-slate-100 dark:border-slate-800">
                                    <td class="px-5 py-2.5">
                                        <span class="inline-flex items-center gap-2">
                                            <img src="https://www.google.com/s2/favicons?domain={{ urlencode($r->other_domain) }}&sz=32" alt="" class="h-4 w-4 flex-none rounded-sm bg-slate-100" loading="lazy" onerror="this.style.visibility='hidden'">
                                            <span class="font-medium text-slate-800 dark:text-slate-200">{{ $r->other_domain }}</span>
                                        </span>
                                    </td>
                                    <td class="max-w-xs px-3 py-2.5 text-xs">
                                        @php
                                            // from_path is always the FROM domain's path. The FROM
                                            // domain is the referrer (inbound) or the searched site (outbound).
                                            $srcDomain = $f['direction'] === 'inbound' ? $r->other_domain : $f['domain'];
                                            $srcUrl = $r->from_path ? 'https://'.$srcDomain.$r->from_path : null;
                                        @endphp
                                        @if ($srcUrl)
                                            <a href="{{ $srcUrl }}" target="_blank" rel="nofollow noopener" title="{{ $srcUrl }}" class="inline-flex max-w-full items-center gap-1 truncate text-orange-600 hover:underline dark:text-orange-400">
                                                <span class="truncate">{{ $r->from_path }}</span>
                                                <svg class="h-3 w-3 flex-none" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                                            </a>
                                        @else
                                            <span class="text-slate-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 text-right">
                                        <span @class(['rounded-full px-2 py-0.5 text-[10px] font-medium', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => $r->dofollow, 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' => ! $r->dofollow])>{{ $r->dofollow ? 'dofollow' : 'nofollow' }}</span>
                                    </td>
                                    <td class="px-3 py-2.5 text-right text-xs text-slate-500">{{ $r->anchor_class ?? '—' }}</td>
                                    <td class="px-3 py-2.5 text-right">
                                        <span class="rounded px-1.5 py-0.5 text-[10px] font-medium" style="background: {{ ($srcColor[$r->source] ?? '#94a3b8') }}20; color: {{ $srcColor[$r->source] ?? '#64748b' }}">{{ $r->source }}</span>
                                    </td>
                                    <td class="px-5 py-2.5 text-right text-xs text-slate-400">{{ \Illuminate\Support\Carbon::parse($r->first_seen_at)->diffForHumans(short: true) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-5 py-10 text-center text-sm text-slate-400">No stored backlinks match these filters.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div>{{ $rows->links() }}</div>
        @endif
    </div>
</x-layouts.app>
