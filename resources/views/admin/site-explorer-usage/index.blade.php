<x-layouts.app>
    @php
        /**
         * @var \Illuminate\Support\Carbon $startDate
         * @var \Illuminate\Support\Carbon $endDate
         * @var array $summary
         * @var array $byClient
         * @var \Illuminate\Support\Collection $clients
         * @var \Illuminate\Pagination\LengthAwarePaginator $queries
         * @var array<string, float> $costByDomain
         * @var \Illuminate\Support\Collection $users
         * @var string $preset
         */
        $fmtN = fn ($n) => number_format((int) $n);
        $fmtMoney = fn (float $usd) => '$' . number_format($usd, $usd >= 100 ? 0 : ($usd > 0 && $usd < 0.01 ? 4 : 2));
    @endphp

    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold">Site Explorer usage</h1>
                <p class="text-sm text-slate-500">
                    Backlink report queries by client — which domains, when, and whether the shared cache served it for free.
                </p>
            </div>
            <div class="text-xs text-slate-500">
                {{ $startDate->format('M j, Y') }} → {{ $endDate->format('M j, Y') }}
            </div>
        </div>

        {{-- Filters --}}
        <form method="GET" class="flex flex-wrap items-end gap-2 rounded border border-slate-200 bg-white p-3">
            <div class="flex flex-wrap gap-1">
                @foreach ([['7','Last 7d'], ['30','Last 30d'], ['90','Last 90d']] as [$val, $label])
                    <a href="{{ route('admin.site-explorer-usage.index', ['range' => $val, 'user_id' => $filters['user_id'] ?: null, 'domain' => $filters['domain'] ?: null, 'cache' => $filters['cache'] ?: null]) }}"
                       @class([
                           'rounded border px-3 py-1.5 text-xs font-semibold',
                           'border-orange-500 bg-orange-50 text-orange-700' => $preset === $val,
                           'border-slate-200 text-slate-600 hover:bg-slate-50' => $preset !== $val,
                       ])>{{ $label }}</a>
                @endforeach
            </div>

            <div class="flex items-end gap-2 border-l border-slate-200 pl-3">
                <label class="text-[10px] uppercase tracking-wider text-slate-500">From
                    <input type="date" name="from" value="{{ $filters['from'] }}"
                           class="block rounded border border-slate-300 px-2 py-1 text-xs" />
                </label>
                <label class="text-[10px] uppercase tracking-wider text-slate-500">To
                    <input type="date" name="to" value="{{ $filters['to'] }}"
                           class="block rounded border border-slate-300 px-2 py-1 text-xs" />
                </label>
                <input type="hidden" name="range" value="custom" />
            </div>

            <div class="flex items-end gap-2 border-l border-slate-200 pl-3">
                <label class="text-[10px] uppercase tracking-wider text-slate-500">Domain
                    <input type="text" name="domain" value="{{ $filters['domain'] }}" placeholder="example.com"
                           class="block w-40 rounded border border-slate-300 px-2 py-1 text-xs" />
                </label>
                <label class="text-[10px] uppercase tracking-wider text-slate-500">Result
                    <select name="cache" class="block rounded border border-slate-300 px-2 py-1 text-xs">
                        <option value="">All</option>
                        <option value="fresh" @selected($filters['cache'] === 'fresh')>Fresh (billed)</option>
                        <option value="cached" @selected($filters['cache'] === 'cached')>Cache hit (free)</option>
                    </select>
                </label>
                <label class="text-[10px] uppercase tracking-wider text-slate-500">Client
                    <select name="user_id" class="block rounded border border-slate-300 px-2 py-1 text-xs">
                        <option value="">All</option>
                        @foreach ($users as $u)
                            <option value="{{ $u->id }}" @selected($filters['user_id'] === $u->id)>{{ $u->email }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <button class="ml-auto rounded bg-slate-800 px-3 py-1.5 text-xs font-semibold text-white">Apply</button>
        </form>

        {{-- Summary cards --}}
        <div class="grid gap-3 md:grid-cols-5">
            @foreach ([
                ['label' => 'Total queries', 'value' => $fmtN($summary['total'])],
                ['label' => 'Unique domains', 'value' => $fmtN($summary['unique_domains'])],
                ['label' => 'Unique clients', 'value' => $fmtN($summary['unique_clients'])],
                ['label' => 'Cache hits (free)', 'value' => $fmtN($summary['cached'])],
                ['label' => 'Real generations', 'value' => $fmtN($summary['real_generations']), 'sub' => $fmtMoney($summary['real_cost']) . ' real cost'],
            ] as $card)
                <div class="rounded-lg border border-slate-200 bg-white p-4">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ $card['label'] }}</p>
                    <div class="mt-1 flex items-baseline gap-2">
                        <span class="text-2xl font-bold tabular-nums">{{ $card['value'] }}</span>
                    </div>
                    @if (! empty($card['sub']))
                        <p class="mt-1 text-xs text-slate-500">{{ $card['sub'] }}</p>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Per-client rollup --}}
        <div class="rounded-lg border border-slate-200 bg-white">
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <h2 class="text-sm font-semibold">By client</h2>
                <span class="text-[10px] uppercase tracking-wider text-slate-400">In selected period</span>
            </div>
            <div class="overflow-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs">
                        <tr>
                            <th class="px-3 py-2 font-medium text-slate-500">Client</th>
                            <th class="px-3 py-2 text-right font-medium text-slate-500">Queries</th>
                            <th class="px-3 py-2 text-right font-medium text-slate-500">Unique domains</th>
                            <th class="px-3 py-2 text-right font-medium text-slate-500">Fresh lookups</th>
                            <th class="px-3 py-2 text-right font-medium text-slate-500">Real cost</th>
                            <th class="px-3 py-2 font-medium text-slate-500">Last query</th>
                            <th class="px-3 py-2 text-right font-medium text-slate-500"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($byClient as $row)
                            @php $u = $clients[$row['user_id']] ?? null; @endphp
                            <tr class="border-t border-slate-100">
                                <td class="px-3 py-2">
                                    @if ($u)
                                        <div class="font-medium text-slate-800">{{ $u->name }}</div>
                                        <div class="text-xs text-slate-500">{{ $u->email }}</div>
                                    @else
                                        <span class="text-slate-400">User #{{ $row['user_id'] }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right font-mono text-xs tabular-nums">{{ $fmtN($row['total']) }}</td>
                                <td class="px-3 py-2 text-right font-mono text-xs tabular-nums">{{ $fmtN($row['unique_domains']) }}</td>
                                <td class="px-3 py-2 text-right font-mono text-xs tabular-nums">{{ $fmtN($row['fresh']) }}</td>
                                <td class="px-3 py-2 text-right"><span class="font-bold tabular-nums">{{ $fmtMoney($row['real_cost']) }}</span></td>
                                <td class="px-3 py-2 text-xs text-slate-500">{{ $row['last_at']?->diffForHumans() ?? '—' }}</td>
                                <td class="px-3 py-2 text-right">
                                    <a href="{{ route('admin.site-explorer-usage.index', array_merge(request()->query(), ['user_id' => $row['user_id']])) }}#query-details"
                                       class="text-xs font-medium text-orange-600 hover:underline">View queries</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-3 py-8 text-center text-sm text-slate-400">No Site Explorer queries in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Query details (paginated raw log) --}}
        <div id="query-details" class="scroll-mt-4 rounded-lg border border-slate-200 bg-white">
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <h2 class="text-sm font-semibold">Query details</h2>
                <span class="text-[10px] uppercase tracking-wider text-slate-400">{{ $fmtN($queries->total()) }} total</span>
            </div>
            <div class="overflow-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs">
                        <tr>
                            <th class="px-3 py-2 font-medium text-slate-500">Time</th>
                            <th class="px-3 py-2 font-medium text-slate-500">Client</th>
                            <th class="px-3 py-2 font-medium text-slate-500">Domain</th>
                            <th class="px-3 py-2 font-medium text-slate-500">Result</th>
                            <th class="px-3 py-2 text-right font-medium text-slate-500"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($queries as $q)
                            @php
                                $domain = (string) ($q->meta['domain'] ?? '');
                                $fresh = empty($q->meta['cache_hit']);
                                $sandbox = ! empty($q->meta['sandbox']);
                            @endphp
                            <tr class="border-t border-slate-100">
                                <td class="px-3 py-2 text-xs text-slate-500" title="{{ format_user_datetime($q->created_at, 'M j, Y g:i A T') }}">
                                    {{ format_user_datetime($q->created_at, 'M j H:i') }}
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    @if ($q->user)
                                        <div class="text-slate-800">{{ $q->user->name }}</div>
                                        <div class="text-slate-500">{{ $q->user->email }}</div>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-xs font-medium text-slate-700">
                                    {{ $domain ?: '—' }}
                                    @if ($sandbox)
                                        <span class="ml-1 inline-flex rounded border border-slate-200 bg-slate-50 px-1.5 py-px text-[9px] font-semibold uppercase tracking-wide text-slate-500">Sandbox</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    @if ($fresh)
                                        @php $realCost = $costByDomain[$domain] ?? null; @endphp
                                        <span class="inline-flex rounded border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700" title="Real DataForSEO cost of this domain's latest generation">
                                            Fresh · {{ $realCost !== null ? $fmtMoney($realCost) : 'cost pending' }}
                                        </span>
                                    @else
                                        <span class="inline-flex rounded border border-emerald-200 bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700">Cache hit</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right">
                                    @if ($domain !== '')
                                        <a href="{{ route('report.view', ['url' => $domain]) }}" target="_blank" rel="noopener"
                                           class="inline-flex items-center gap-1 rounded border border-orange-200 bg-orange-50 px-2 py-1 text-xs font-semibold text-orange-700 hover:bg-orange-100">
                                            View results
                                            <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-8 text-center text-sm text-slate-400">No Site Explorer queries in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($queries->hasPages())
                <div class="border-t border-slate-200 px-4 py-3">
                    {{ $queries->onEachSide(1)->links() }}
                </div>
            @endif
        </div>

        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-[11px] text-slate-500">
            Costs shown are the REAL amount DataForSEO's own API response reported for each generation (<code>tasks[0].cost</code>), not an estimate — bounded top-N pulls keep it roughly flat regardless of site size, but this is the actual number. "Cache hit" queries were served from the shared per-domain snapshot at $0 — a domain queried by multiple clients within its freshness window is billed once, not once per client. "Real cost" columns reflect each domain's LATEST generation (the snapshot only holds the most recent cost); a domain regenerated more than once in the period is under-counted by however many extra generations happened. "cost pending" means the generation job for that query hasn't completed yet, or ran before real-cost tracking shipped (2026-07-14).
        </div>
    </div>
</x-layouts.app>
