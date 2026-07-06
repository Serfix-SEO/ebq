<div wire:poll.3s class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-800">
    <div class="flex items-center justify-between">
        <h2 class="text-sm font-semibold">Data moves</h2>
        @if ($anyRunning)
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-orange-600">
                <span class="h-2 w-2 animate-pulse rounded-full bg-orange-500"></span> move in progress
            </span>
        @endif
    </div>

    @if ($moves->isEmpty())
        <p class="mt-2 text-xs text-slate-400">No tenant/crawl moves yet. Progress will appear here live when one starts.</p>
    @else
        <div class="mt-3 space-y-3">
            @foreach ($moves as $move)
                @php
                    $pct = $move->progressPercent();
                    $statusColor = match ($move->status) {
                        \App\Models\ShardMove::STATUS_COMPLETED => 'bg-emerald-100 text-emerald-700',
                        \App\Models\ShardMove::STATUS_FAILED => 'bg-rose-100 text-rose-700',
                        default => 'bg-orange-100 text-orange-700',
                    };
                @endphp
                <div class="rounded border border-slate-100 p-3 text-xs dark:border-slate-700">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <span class="font-semibold text-slate-800 dark:text-slate-100">{{ $move->kind }}</span>
                            <span class="text-slate-500">{{ $move->subject_label ?? $move->subject_id }}</span>
                            <span class="text-slate-400">
                                → {{ $nodeNames[$move->target_node_id] ?? $move->target_node_id }}
                                @if ($move->source_node_id)
                                    (from {{ $nodeNames[$move->source_node_id] ?? $move->source_node_id }})
                                @endif
                            </span>
                        </div>
                        <span class="rounded px-1.5 py-0.5 font-semibold {{ $statusColor }}">{{ $move->status }}</span>
                    </div>

                    @if ($move->isRunning())
                        <div class="mt-2">
                            <div class="h-1.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700">
                                <div class="h-full rounded-full bg-orange-500 transition-all" style="width: {{ $pct }}%"></div>
                            </div>
                            <p class="mt-1 text-slate-400">
                                {{ $pct }}% —
                                {{ number_format($move->rows_copied) }} / {{ number_format($move->rows_total) }} rows
                                · table {{ $move->tables_done }}/{{ $move->tables_total }}
                                @if ($move->current_table) (<span class="font-mono">{{ $move->current_table }}</span>) @endif
                                · started {{ $move->started_at?->diffForHumans() }}
                            </p>
                        </div>
                    @elseif ($move->status === \App\Models\ShardMove::STATUS_FAILED)
                        <p class="mt-1 break-all font-mono text-rose-600">{{ \Illuminate\Support\Str::limit($move->error, 220) }}</p>
                        <p class="mt-0.5 text-slate-400">Source data is intact until the purge phase — a re-run is idempotent (insertOrIgnore).</p>
                    @else
                        <p class="mt-1 text-slate-400">
                            {{ number_format($move->rows_copied) }} rows in {{ $move->tables_total }} tables
                            · finished {{ $move->finished_at?->diffForHumans() }}
                            ({{ $move->started_at && $move->finished_at ? $move->started_at->diffInSeconds($move->finished_at).'s' : '—' }})
                        </p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
