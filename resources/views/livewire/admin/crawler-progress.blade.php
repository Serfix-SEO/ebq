<div wire:poll.5s class="space-y-5">
    @php
        /**
         * @var array $rows
         * @var array $summary
         */
        $relTime = function ($when): string {
            if (! $when) return '—';
            try { return \Illuminate\Support\Carbon::parse($when)->diffForHumans(); }
            catch (\Throwable) { return '—'; }
        };
        $statusBadge = [
            'running'    => 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
            'finalizing' => 'bg-orange-100 text-orange-700 dark:bg-orange-500/15 dark:text-orange-300',
            'completed'  => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
            'aborted'    => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
            'failed'     => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
            'never'      => 'bg-slate-100 text-slate-500 dark:bg-slate-700/40 dark:text-slate-400',
        ];
        $statusLabel = [
            'running' => 'Crawling', 'finalizing' => 'Computing', 'completed' => 'Ready',
            'aborted' => 'Aborted', 'failed' => 'Failed', 'never' => 'Never crawled',
        ];
    @endphp

    {{-- Summary cards --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        @foreach ([
            ['Domains', $summary['sites'], 'text-slate-900 dark:text-slate-100'],
            ['Crawling', $summary['running'], 'text-blue-600 dark:text-blue-400'],
            ['Ready', $summary['completed'], 'text-emerald-600 dark:text-emerald-400'],
            ['Queue backlog', number_format($summary['queue_depth']), $summary['queue_depth'] > 500 ? 'text-amber-600 dark:text-amber-400' : 'text-slate-900 dark:text-slate-100'],
            ['Pages crawled', number_format($summary['crawled_pages']), 'text-slate-900 dark:text-slate-100'],
            ['Open issues', number_format($summary['open_findings']), 'text-slate-900 dark:text-slate-100'],
        ] as [$label, $value, $color])
            <div class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-800">
                <div class="text-[11px] font-medium uppercase tracking-wide text-slate-400">{{ $label }}</div>
                <div class="mt-1 text-xl font-bold {{ $color }}">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    {{-- How to read this screen --}}
    <details class="rounded-lg border border-slate-200 bg-slate-50 text-sm dark:border-slate-700 dark:bg-slate-800/50">
        <summary class="cursor-pointer select-none px-4 py-2.5 text-[13px] font-semibold text-slate-700 dark:text-slate-200">
            ℹ️ How to read this screen
        </summary>
        <div class="space-y-3 border-t border-slate-200 px-4 py-3 text-[13px] leading-relaxed text-slate-600 dark:border-slate-700 dark:text-slate-300">
            <p>
                Serfix crawls each <strong>domain once</strong> and shares that one crawl with every client
                who added it. So a row here is a <strong>domain</strong>, not a client — the
                <strong>Website / Client</strong> column shows which client website(s) it serves. The crawl
                runs to the <strong>largest</strong> page cap among those clients; each client then sees only
                their own slice (their plan's page cap).
            </p>
            <div class="grid gap-x-6 gap-y-1.5 sm:grid-cols-2">
                <div><strong>Summary cards</strong></div><div></div>
                <div>· <strong>Domains / Crawling / Ready</strong></div><div>total domains, how many are crawling now, how many finished.</div>
                <div>· <strong>Queue backlog</strong></div><div>crawl jobs waiting to run (turns amber over 500 — a sign of congestion).</div>
                <div>· <strong>Pages crawled / Open issues</strong></div><div>totals across every domain.</div>
                <div class="mt-1.5"><strong>Columns</strong></div><div class="mt-1.5"></div>
                <div>· <strong>Status</strong></div><div><span class="font-medium text-blue-600 dark:text-blue-400">Crawling</span> = fetching pages · <span class="font-medium text-orange-600 dark:text-orange-400">Computing</span> = scoring + building issues · <span class="font-medium text-emerald-600 dark:text-emerald-400">Ready</span> = finished · <span class="font-medium text-red-600 dark:text-red-400">Aborted/Failed</span> = stopped (e.g. site blocked us) · <span class="text-slate-500">Never crawled</span>.</div>
                <div>· <strong>Progress</strong></div><div>pages crawled / total discovered for the domain (climbs as more pages are found).</div>
                <div>· <strong>Crawled / Errors / Issues</strong></div><div>pages fetched · pages returning 4xx/5xx · open SEO findings.</div>
                <div>· <strong>Health</strong></div><div>0–100 site-health score (green ≥ 80, amber 50–79, red &lt; 50).</div>
                <div>· <strong>Subs / Cap</strong></div><div>how many client websites share this crawl, and the effective page cap it runs to.</div>
                <div>· <strong>Last activity</strong></div><div>when the crawl last finished or last made progress.</div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400">
                Live — this screen refreshes every 5s. A stalled crawl is auto-recovered within ~15 min by the
                background watchdog (<code>ebq:crawl-supervisor</code>).
            </p>
        </div>
    </details>

    {{-- Per-domain table --}}
    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
        <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
            <thead class="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-400 dark:bg-slate-900/40">
                <tr>
                    <th class="px-4 py-2.5 text-left font-medium">Domain</th>
                    <th class="px-4 py-2.5 text-left font-medium">Website / Client</th>
                    <th class="px-4 py-2.5 text-left font-medium">Status</th>
                    <th class="px-4 py-2.5 text-left font-medium">Progress</th>
                    <th class="px-4 py-2.5 text-right font-medium">Crawled</th>
                    <th class="px-4 py-2.5 text-right font-medium">Errors</th>
                    <th class="px-4 py-2.5 text-right font-medium">Issues</th>
                    <th class="px-4 py-2.5 text-right font-medium">Health</th>
                    <th class="px-4 py-2.5 text-right font-medium">Subs / Cap</th>
                    <th class="px-4 py-2.5 text-left font-medium">Last activity</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700/60">
                @forelse ($rows as $r)
                    @php
                        $st = $r['status'];
                        $isLive = in_array($st, ['running', 'finalizing'], true);
                        // Progress = pages crawled / total discovered (the live inventory),
                        // same as the client banner. pages_seen is an internal per-pass
                        // counter (grows ~1k/pass with the fairness cap), NOT the true total.
                        $crawledN = (int) $r['crawled'];
                        $totalN = max((int) $r['frontier'], $crawledN);
                        $pct = $st === 'finalizing' ? 100
                            : ($totalN > 0 ? min(100, (int) round($crawledN / $totalN * 100)) : ($st === 'completed' ? 100 : 0));
                    @endphp
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-900/30">
                        <td class="px-4 py-2.5 font-medium text-slate-800 dark:text-slate-100">{{ $r['domain'] }}</td>
                        <td class="px-4 py-2.5">
                            @forelse ($r['clients'] as $c)
                                <div class="text-xs text-slate-700 dark:text-slate-300">
                                    {{ $c['website'] }} <span class="text-slate-400">· {{ $c['owner'] }}</span>
                                </div>
                            @empty
                                <span class="text-xs text-slate-400">—</span>
                            @endforelse
                        </td>
                        <td class="px-4 py-2.5">
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $statusBadge[$st] ?? $statusBadge['never'] }}">
                                @if ($isLive)<span class="h-1.5 w-1.5 animate-pulse rounded-full bg-current"></span>@endif
                                {{ $statusLabel[$st] ?? $st }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5">
                            @if ($isLive || $st === 'completed')
                                <div class="flex items-center gap-2">
                                    <div class="h-1.5 w-24 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                                        <div class="h-full rounded-full {{ $st === 'completed' ? 'bg-emerald-500' : 'bg-blue-500' }}" style="width: {{ $pct }}%"></div>
                                    </div>
                                    <span class="tabular-nums text-xs text-slate-500">{{ number_format($crawledN) }} / {{ number_format($totalN) }}</span>
                                </div>
                            @else
                                <span class="text-xs text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-slate-700 dark:text-slate-300">{{ number_format($r['crawled']) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums {{ $r['errors'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-slate-400' }}">{{ number_format($r['errors']) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-slate-700 dark:text-slate-300">{{ number_format($r['findings']) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">
                            @if ($r['health'] !== null)
                                <span @class([
                                    'font-semibold',
                                    'text-emerald-600 dark:text-emerald-400' => $r['health'] >= 80,
                                    'text-amber-600 dark:text-amber-400' => $r['health'] >= 50 && $r['health'] < 80,
                                    'text-red-600 dark:text-red-400' => $r['health'] < 50,
                                ])>{{ $r['health'] }}</span>
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-slate-500">{{ $r['subscribers'] }} / {{ number_format($r['cap']) }}</td>
                        <td class="px-4 py-2.5 text-xs text-slate-500">{{ $relTime($r['finished_at'] ?? $r['started_at']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-4 py-8 text-center text-sm text-slate-400">No crawl sites yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="text-[11px] text-slate-400">Live — refreshes every 5s. Queue backlog is the shared <code>crawl</code> queue depth across all domains.</p>
</div>
