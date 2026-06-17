<x-layouts.app>
    @php /** @var \Illuminate\Support\Collection $nodes @var array $cfg @var array $serverTypes */ @endphp
    <div class="space-y-5">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Database fleet</h1>
            <p class="text-sm text-slate-500">MariaDB shard nodes. Tenant data shards by owner (websites.db_node_id); crawl data shards by domain (crawl_sites.crawl_node_id). Identity/billing/catalogs stay on the central primary. Move tenants/crawl-sites between nodes below.</p>
        </div>

        @foreach (['status' => 'emerald', 'error' => 'red'] as $key => $c)
            @if (session($key))
                <div class="rounded-md border border-{{ $c }}-200 bg-{{ $c }}-50 px-3 py-2 text-xs font-medium text-{{ $c }}-800">{{ session($key) }}</div>
            @endif
        @endforeach
        @if ($errors->any())
            <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800">{{ $errors->first() }}</div>
        @endif

        {{-- Nodes --}}
        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-3 py-2">Node</th><th class="px-3 py-2">Role</th><th class="px-3 py-2">Status</th>
                        <th class="px-3 py-2">IP / DB</th><th class="px-3 py-2">Tenants</th><th class="px-3 py-2">Sites</th>
                        <th class="px-3 py-2">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($nodes as $n)
                        <tr>
                            <td class="px-3 py-2"><div class="font-medium">{{ $n->name }}</div><div class="font-mono text-[10px] text-slate-400">{{ $n->id }}</div>@if ($n->is_pinned)<span class="text-[10px] font-semibold text-indigo-600">PINNED PRIMARY</span>@endif</td>
                            <td class="px-3 py-2">{{ $n->role }}</td>
                            <td class="px-3 py-2"><span class="rounded px-1.5 py-0.5 text-[11px] font-semibold {{ $n->status === 'active' ? 'bg-emerald-100 text-emerald-700' : ($n->status === 'failed' ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-600') }}">{{ $n->status }}</span>@if ($n->last_error)<div class="text-[10px] text-red-500">{{ \Illuminate\Support\Str::limit($n->last_error, 60) }}</div>@endif</td>
                            <td class="px-3 py-2 text-xs">{{ $n->private_ip }}<br><span class="text-slate-400">{{ $n->db_name }}</span></td>
                            <td class="px-3 py-2">{{ $n->tenant_count }}</td>
                            <td class="px-3 py-2">{{ $n->site_count }}</td>
                            <td class="px-3 py-2">
                                @unless ($n->is_pinned)
                                    <div class="flex flex-wrap gap-1">
                                        <form method="POST" action="{{ route('admin.db-fleet.bootstrap', $n) }}" onsubmit="return confirm('Bootstrap (configure + migrate) this node?')">@csrf<button class="rounded border border-slate-300 px-2 py-0.5 text-[11px] hover:bg-slate-50">bootstrap</button></form>
                                        <form method="POST" action="{{ route('admin.db-fleet.migrate', $n) }}">@csrf<button class="rounded border border-slate-300 px-2 py-0.5 text-[11px] hover:bg-slate-50">migrate</button></form>
                                        <form method="POST" action="{{ route('admin.db-fleet.drain', $n) }}">@csrf<button class="rounded border border-slate-300 px-2 py-0.5 text-[11px] hover:bg-slate-50">drain</button></form>
                                        <form method="POST" action="{{ route('admin.db-fleet.destroy', $n) }}" onsubmit="return confirm('Destroy this node? (must be empty)')">@csrf<button class="rounded border border-red-300 px-2 py-0.5 text-[11px] text-red-600 hover:bg-red-50">destroy</button></form>
                                    </div>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-6 text-center text-sm text-slate-400">No nodes registered. Register the primary to begin.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Operator actions --}}
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('admin.db-fleet.register-primary') }}">@csrf<button class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Register primary</button></form>
            <form method="POST" action="{{ route('admin.db-fleet.provision') }}" onsubmit="return confirm('Provision a tenant-shard node on Hetzner?')">@csrf<input type="hidden" name="role" value="tenant-shard"><button class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50">+ Provision tenant node</button></form>
            <form method="POST" action="{{ route('admin.db-fleet.provision') }}" onsubmit="return confirm('Provision a crawl-shard node on Hetzner?')">@csrf<input type="hidden" name="role" value="crawl-shard"><button class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50">+ Provision crawl node</button></form>
        </div>

        {{-- Move a tenant / crawl-site --}}
        <form method="POST" action="{{ route('admin.db-fleet.move') }}" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm space-y-2" onsubmit="return confirm('Move this data to the target node now?')">
            @csrf
            <h2 class="text-sm font-semibold">Move data between nodes</h2>
            <div class="flex flex-wrap items-end gap-2">
                <label class="text-xs">Kind<select name="kind" class="mt-0.5 block rounded border-slate-300 text-xs"><option value="tenant">tenant (user id)</option><option value="crawl">crawl (crawl_site id)</option></select></label>
                <label class="text-xs">Id<input name="id" class="mt-0.5 block w-72 rounded border-slate-300 font-mono text-xs" placeholder="user id (ULID) or crawl_site id"></label>
                <label class="text-xs">Target node<select name="to" class="mt-0.5 block rounded border-slate-300 text-xs">@foreach ($nodes as $n)<option value="{{ $n->id }}">{{ $n->name }} ({{ $n->role }})</option>@endforeach</select></label>
                <button class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500">Move</button>
            </div>
        </form>

        {{-- Settings --}}
        <form method="POST" action="{{ route('admin.db-fleet.settings') }}" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm space-y-3">
            @csrf
            <h2 class="text-sm font-semibold">Provisioning defaults</h2>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-3">
                <label class="text-xs">Server type<select name="server_type" class="mt-0.5 block w-full rounded border-slate-300 text-xs">@foreach ($serverTypes as $k => $label)<option value="{{ $k }}" @selected($cfg['server_type'] === $k)>{{ $label }}</option>@endforeach</select></label>
                <label class="text-xs">DB snapshot id<input name="snapshot_id" value="{{ $cfg['snapshot_id'] }}" class="mt-0.5 block w-full rounded border-slate-300 text-xs"></label>
                <label class="text-xs">Placement<select name="placement" class="mt-0.5 block w-full rounded border-slate-300 text-xs"><option value="least_loaded" @selected($cfg['placement'] === 'least_loaded')>least_loaded</option><option value="round_robin" @selected($cfg['placement'] === 'round_robin')>round_robin</option></select></label>
                <label class="text-xs">Max tenants / node<input type="number" name="max_tenants_per_node" value="{{ $cfg['max_tenants_per_node'] }}" class="mt-0.5 block w-full rounded border-slate-300 text-xs"></label>
                <label class="text-xs">Max sites / node<input type="number" name="max_sites_per_node" value="{{ $cfg['max_sites_per_node'] }}" class="mt-0.5 block w-full rounded border-slate-300 text-xs"></label>
            </div>
            <button class="rounded-md bg-slate-800 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-700">Save</button>
        </form>
    </div>
</x-layouts.app>
