<div wire:poll.5s class="space-y-5">
    @if (session('shard-move-status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('shard-move-status') }}</div>
    @endif

    {{-- Nodes with residents --}}
    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
        <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:bg-slate-900/40">
                <tr>
                    <th class="px-3 py-2">Node</th><th class="px-3 py-2">Role</th><th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2">IP / DB</th><th class="px-3 py-2">Tenants</th><th class="px-3 py-2">Crawl sites</th>
                    <th class="px-3 py-2">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700/60">
                @forelse ($nodes as $n)
                    @php
                        $nodeTenants = $tenants->get($n->id, collect());
                        $nodeSites = $crawlSites->get($n->id, collect());
                        $isOpen = in_array($n->id, $expanded, true);
                    @endphp
                    <tr class="{{ $isOpen ? 'bg-orange-50/40 dark:bg-orange-500/5' : '' }}">
                        <td class="px-3 py-2">
                            <div class="font-medium">{{ $n->name }}</div>
                            <div class="font-mono text-[10px] text-slate-400">{{ $n->id }}</div>
                            @if ($n->is_pinned)<span class="text-[10px] font-semibold text-orange-600">PINNED PRIMARY</span>@endif
                        </td>
                        <td class="px-3 py-2">{{ $n->role }}</td>
                        <td class="px-3 py-2">
                            <span class="rounded px-1.5 py-0.5 text-[11px] font-semibold {{ $n->status === 'active' ? 'bg-emerald-100 text-emerald-700' : ($n->status === 'failed' ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-600') }}">{{ $n->status }}</span>
                            @if ($n->last_error)<div class="text-[10px] text-red-500">{{ \Illuminate\Support\Str::limit($n->last_error, 60) }}</div>@endif
                        </td>
                        <td class="px-3 py-2 text-xs">{{ $n->private_ip }}<br><span class="text-slate-400">{{ $n->db_name }}</span></td>
                        <td class="px-3 py-2 tabular-nums">{{ $nodeTenants->count() }}</td>
                        <td class="px-3 py-2 tabular-nums">{{ $nodeSites->count() }}</td>
                        <td class="px-3 py-2">
                            <div class="flex flex-wrap items-center gap-1">
                                <button type="button" wire:click="toggleExpand('{{ $n->id }}')"
                                        class="rounded border border-orange-300 px-2 py-0.5 text-[11px] font-semibold text-orange-600 hover:bg-orange-50 disabled:opacity-50"
                                        wire:loading.attr="disabled" wire:target="toggleExpand">
                                    <span wire:loading.remove wire:target="toggleExpand('{{ $n->id }}')">{{ $isOpen ? 'hide residents' : 'residents' }}</span>
                                    <span wire:loading wire:target="toggleExpand('{{ $n->id }}')">counting…</span>
                                </button>
                                @unless ($n->is_pinned)
                                    <form method="POST" action="{{ route('admin.db-fleet.bootstrap', $n) }}" onsubmit="return confirm('Bootstrap (configure + migrate) this node?')">@csrf<button class="rounded border border-slate-300 px-2 py-0.5 text-[11px] hover:bg-slate-50">bootstrap</button></form>
                                    <form method="POST" action="{{ route('admin.db-fleet.migrate', $n) }}">@csrf<button class="rounded border border-slate-300 px-2 py-0.5 text-[11px] hover:bg-slate-50">migrate</button></form>
                                    <form method="POST" action="{{ route('admin.db-fleet.drain', $n) }}">@csrf<button class="rounded border border-slate-300 px-2 py-0.5 text-[11px] hover:bg-slate-50">drain</button></form>
                                    <form method="POST" action="{{ route('admin.db-fleet.destroy', $n) }}" onsubmit="return confirm('Destroy this node? (must be empty)')">@csrf<button class="rounded border border-red-300 px-2 py-0.5 text-[11px] text-red-600 hover:bg-red-50">destroy</button></form>
                                @endunless
                            </div>
                        </td>
                    </tr>
                    @if ($isOpen)
                        <tr class="bg-slate-50/60 dark:bg-slate-900/30">
                            <td colspan="7" class="px-4 py-3">
                                <div class="mb-2 flex items-center justify-between">
                                    <button type="button" wire:click="refreshNodeRowCounts('{{ $n->id }}')"
                                            class="rounded border border-orange-300 px-2 py-0.5 text-[11px] font-semibold text-orange-600 hover:bg-orange-50 disabled:opacity-50"
                                            wire:loading.attr="disabled">
                                        &#x21bb; recount all rows on this node
                                    </button>
                                    <span wire:loading wire:target="refreshNodeRowCounts, refreshRowCount, toggleExpand"
                                          class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-orange-600">
                                        <svg class="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                        counting rows…
                                    </span>
                                </div>
                                <div class="grid gap-4 md:grid-cols-2">
                                    <div>
                                        <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Tenants ({{ $nodeTenants->count() }})</p>
                                        @forelse ($nodeTenants as $t)
                                            <div class="mt-1.5 rounded border border-slate-200 bg-white px-2.5 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-800">
                                                <div class="flex items-center justify-between gap-2">
                                                    <span class="font-medium">{{ $t->email }}</span>
                                                    <span class="flex items-center gap-1 tabular-nums text-slate-400">
                                                        {{ number_format($rowCounts['tenant:'.$t->id] ?? 0) }} rows
                                                        <button type="button" wire:click="refreshRowCount('tenant', '{{ $t->id }}')" title="Recount now (bypasses the 1h cache)" class="text-slate-300 hover:text-orange-500">&#x21bb;</button>
                                                    </span>
                                                </div>
                                                <div class="mt-0.5 text-slate-400">
                                                    @foreach ($t->websites as $w) <span class="mr-2">{{ $w->domain }}</span> @endforeach
                                                </div>
                                            </div>
                                        @empty
                                            <p class="mt-1 text-xs text-slate-400">none</p>
                                        @endforelse
                                    </div>
                                    <div>
                                        <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Crawl sites ({{ $nodeSites->count() }})</p>
                                        @forelse ($nodeSites as $s)
                                            <div class="mt-1.5 flex items-center justify-between rounded border border-slate-200 bg-white px-2.5 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-800">
                                                <span class="font-medium">{{ $s->normalized_domain }} <span class="ml-1 text-[10px] text-slate-400">{{ $s->status }}</span></span>
                                                <span class="flex items-center gap-1 tabular-nums text-slate-400">
                                                    {{ number_format($rowCounts['crawl:'.$s->id] ?? 0) }} rows
                                                    <button type="button" wire:click="refreshRowCount('crawl', '{{ $s->id }}')" title="Recount now (bypasses the 1h cache)" class="text-slate-300 hover:text-orange-500">&#x21bb;</button>
                                                </span>
                                            </div>
                                        @empty
                                            <p class="mt-1 text-xs text-slate-400">none</p>
                                        @endforelse
                                    </div>
                                </div>
                                <p class="mt-2 text-[10px] text-slate-400">Row counts = every tenant/crawl-tier table summed on the hosting node, cached 1h.</p>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="7" class="px-3 py-6 text-center text-sm text-slate-400">No nodes registered. Register the primary to begin.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="text-[11px] text-slate-400">bootstrap = configure MariaDB + run migrations · migrate = re-run migrations on the node · drain = stop placing new tenants here · destroy = delete the (empty) Hetzner server. The pinned primary cannot be drained or destroyed.</p>

    {{-- Operator actions --}}
    <div class="flex flex-wrap gap-2">
        <form method="POST" action="{{ route('admin.db-fleet.register-primary') }}">@csrf<button class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Register primary</button></form>
        <form method="POST" action="{{ route('admin.db-fleet.provision') }}" onsubmit="return confirm('Provision a tenant-shard node on Hetzner?')">@csrf<input type="hidden" name="role" value="tenant-shard"><button class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50">+ Provision tenant node</button></form>
        <form method="POST" action="{{ route('admin.db-fleet.provision') }}" onsubmit="return confirm('Provision a crawl-shard node on Hetzner?')">@csrf<input type="hidden" name="role" value="crawl-shard"><button class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50">+ Provision crawl node</button></form>
    </div>

    {{-- Move a tenant / crawl-site --}}
    <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm space-y-2 dark:border-slate-700 dark:bg-slate-800">
        <h2 class="text-sm font-semibold">Move data between nodes</h2>
        <p class="text-xs text-slate-400">Pick <span class="font-medium">tenant</span> to move one user (all their websites' data) or <span class="font-medium">crawl</span> to move one site's crawl data. Runs in the background behind a migrating-lock; reversible until the source is purged.</p>
        <div class="flex flex-wrap items-end gap-2">
            <label class="text-xs">Kind
                <select wire:model.live="moveKind" class="mt-0.5 block rounded border-slate-300 text-xs">
                    <option value="tenant">tenant (user)</option>
                    <option value="crawl">crawl (crawl_site)</option>
                </select>
            </label>
            <label class="text-xs">Search
                <input wire:model.live.debounce.300ms="moveSearch" type="text" autocomplete="off" class="mt-0.5 block w-44 rounded border-slate-300 text-xs" placeholder="filter by name / domain…">
            </label>
            <label class="text-xs">Subject
                <select wire:model.live="moveSubjectId" class="mt-0.5 block w-72 rounded border-slate-300 text-xs">
                    <option value="">— pick —</option>
                    @foreach ($moveOptions as $o)
                        <option value="{{ $o['id'] }}">{{ $o['label'] }} · on {{ $nodeNames[$o['host']] ?? 'primary' }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-xs">Target node
                <select wire:model.live="moveTargetId" class="mt-0.5 block rounded border-slate-300 text-xs" @disabled($moveSubjectId === '')>
                    <option value="">— pick —</option>
                    @foreach ($nodes as $n)
                        @if ($subjectHost !== null && $n->id === $subjectHost)
                            <option value="" disabled>{{ $n->name }} — current host</option>
                        @else
                            <option value="{{ $n->id }}">{{ $n->name }} ({{ $n->role }})</option>
                        @endif
                    @endforeach
                </select>
            </label>
            <button type="button" wire:click="move" wire:confirm="Move this data to the target node now?"
                    class="rounded-md bg-orange-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-orange-700 disabled:opacity-40"
                    @disabled($moveSubjectId === '' || $moveTargetId === '')>
                Move
            </button>
        </div>
        @error('moveTargetId')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
        @if ($subjectHost !== null)
            <p class="text-xs text-slate-500">Currently hosted on <span class="font-semibold">{{ $nodeNames[$subjectHost] ?? $subjectHost }}</span>.</p>
        @endif
    </div>

    {{-- Live progress for tenant/crawl moves (ShardMover writes shard_moves rows) --}}
    <livewire:admin.shard-moves />
</div>
