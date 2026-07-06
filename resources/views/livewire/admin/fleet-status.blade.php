<div wire:poll.5s class="space-y-5">
    @php
        $badge = [
            'provisioning' => 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
            'active'       => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
            'draining'     => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
            'deleting'     => 'bg-slate-100 text-slate-600 dark:bg-slate-700/40 dark:text-slate-300',
            'failed'       => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
        ];
        $rel = fn ($w) => $w ? \Illuminate\Support\Carbon::parse($w)->diffForHumans() : '—';
    @endphp

    {{-- Summary cards --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        @foreach ([
            ['Autoscaler', $summary['enabled'] ? 'ON' : 'off', $summary['enabled'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400'],
            ['Crawl backlog', number_format($summary['backlog']), $summary['backlog'] > 500 ? 'text-amber-600 dark:text-amber-400' : 'text-slate-900 dark:text-slate-100'],
            ['Finalize backlog', number_format($summary['finalize_backlog']), 'text-slate-900 dark:text-slate-100'],
            ['Boxes (billable)', $summary['billable'].' → '.$summary['desired'], 'text-slate-900 dark:text-slate-100'],
            ['In-flight crawls', $summary['in_flight'], 'text-blue-600 dark:text-blue-400'],
            ['Est. cost / hr', '$'.number_format($summary['est_cost_hr'], 3), 'text-slate-900 dark:text-slate-100'],
        ] as [$label, $value, $color])
            <div class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-800">
                <div class="text-[11px] font-medium uppercase tracking-wide text-slate-400">{{ $label }}</div>
                <div class="mt-1 text-xl font-bold {{ $color }}">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    {{-- Node table --}}
    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
        <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
            <thead class="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-400 dark:bg-slate-900/40">
                <tr>
                    <th class="px-4 py-2.5 text-left font-medium">Node</th>
                    <th class="px-4 py-2.5 text-left font-medium">Status</th>
                    <th class="px-4 py-2.5 text-left font-medium">Private IP</th>
                    <th class="px-4 py-2.5 text-left font-medium">Type</th>
                    <th class="px-4 py-2.5 text-right font-medium" title="Jobs this box is processing right now — wait for 0 before draining">In-flight</th>
                    <th class="px-4 py-2.5 text-right font-medium" title="Jobs this box has completed since it started">Finished</th>
                    <th class="px-4 py-2.5 text-right font-medium" title="Jobs that failed on this box since it started">Failed</th>
                    <th class="px-4 py-2.5 text-right font-medium">Age</th>
                    <th class="px-4 py-2.5 text-left font-medium">Health</th>
                    <th class="px-4 py-2.5 text-left font-medium">Last seen</th>
                    <th class="px-4 py-2.5 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700/60">
                @forelse ($nodes as $n)
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-900/30">
                        <td class="px-4 py-2.5 font-medium text-slate-800 dark:text-slate-100">
                            {{ $n->name }}
                            @if ($n->is_pinned)<span class="ml-1 rounded bg-orange-100 px-1.5 py-0.5 text-[10px] font-semibold text-orange-700 dark:bg-orange-500/15 dark:text-orange-300">pinned</span>@endif
                        </td>
                        <td class="px-4 py-2.5">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $badge[$n->status] ?? $badge['deleting'] }}">{{ $n->status }}</span>
                        </td>
                        <td class="px-4 py-2.5 tabular-nums text-slate-600 dark:text-slate-300">{{ $n->private_ip ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-slate-600 dark:text-slate-300">{{ $n->server_type ?? '—' }}</td>
                        @php $m = $metrics[$n->id] ?? ['running' => 0, 'done' => 0, 'failed' => 0]; @endphp
                        <td class="px-4 py-2.5 text-right tabular-nums font-semibold {{ $m['running'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-slate-400' }}" title="{{ $m['running'] > 0 ? 'busy — draining now would wait for these to finish' : 'idle — safe to drain' }}">{{ $m['running'] }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ number_format($m['done']) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums {{ $m['failed'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-slate-400' }}">{{ number_format($m['failed']) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $n->ageMinutes() }}m</td>
                        <td class="px-4 py-2.5">
                            @if ($n->is_healthy === true)<span class="text-emerald-600 dark:text-emerald-400">healthy</span>
                            @elseif ($n->is_healthy === false)<span class="text-red-600 dark:text-red-400" title="{{ $n->last_error }}">unhealthy</span>
                            @else <span class="text-slate-400">—</span>@endif
                        </td>
                        <td class="px-4 py-2.5 text-xs text-slate-500">{{ $rel($n->last_seen_at) }}</td>
                        <td class="px-4 py-2.5 text-right">
                            @unless ($n->is_pinned)
                                @if ($n->status === 'active')
                                    <form method="POST" action="{{ route('admin.fleet.drain', $n) }}" class="inline" onsubmit="return confirm('Drain node {{ $n->id }}?')">@csrf
                                        <button class="rounded border border-amber-300 px-2 py-1 text-xs font-medium text-amber-700 hover:bg-amber-50 dark:border-amber-500/40 dark:text-amber-300">Drain</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('admin.fleet.destroy', $n) }}" class="inline" onsubmit="return confirm('Destroy node {{ $n->id }} (delete the server)?')">@csrf
                                    <button class="rounded border border-red-300 px-2 py-1 text-xs font-medium text-red-700 hover:bg-red-50 dark:border-red-500/40 dark:text-red-300">Destroy</button>
                                </form>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="px-4 py-8 text-center text-sm text-slate-400">No worker nodes.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="text-[11px] text-slate-400">Live — refreshes every 5s. The queue is central Redis, so a new box just pulls crawl jobs; no rebalancing. <span class="text-slate-500">In-flight</span> = jobs running on that box right now — wait for <span class="font-mono">0</span> before draining (a drain also lets any in-flight job finish via the 360s stop-grace). Finished/Failed are cumulative since the box started. Full per-queue metrics: <a href="{{ url('/horizon') }}" class="text-orange-600 hover:underline dark:text-orange-400">Horizon</a>.</p>
</div>
