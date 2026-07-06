<x-layouts.app>
    @php
        /** @var array $cfg @var array $serverTypes @var \Illuminate\Support\Collection $dbNodes @var array $dbCfg @var array $dbServerTypes*/
        $num = fn ($k, $v) => view('admin.fleet._num', ['k' => $k, 'v' => $v]);
    @endphp
    <div class="space-y-5">
        {{-- ── Header + overview ─────────────────────────────────────────────── --}}
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Fleet</h1>
            <p class="mt-1 max-w-3xl text-sm text-slate-500">
                Every Hetzner box Serfix runs, on one screen. Two independent pools:
                <span class="font-medium text-slate-700 dark:text-slate-200">crawl workers</span> (elastic compute that pulls jobs)
                and <span class="font-medium text-slate-700 dark:text-slate-200">database shards</span> (MariaDB nodes that hold the data).
                Pick a tab below.
            </p>
        </div>

        {{-- Flash + validation (both fleets post back here) --}}
        @if (session('status'))
            <div class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-800">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs font-medium text-red-800">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800">{{ $errors->first() }}</div>
        @endif

        {{-- ── Self-documentation (collapsible) ──────────────────────────────── --}}
        <details class="group rounded-lg border border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
            <summary class="cursor-pointer select-none px-4 py-3 font-semibold text-slate-700 marker:text-slate-400 dark:text-slate-200">
                How the fleet works — read me
            </summary>
            <div class="space-y-3 border-t border-slate-100 px-4 py-3 text-slate-600 dark:border-slate-700 dark:text-slate-300">
                <p><span class="font-semibold">Two fleets, two models.</span>
                    <span class="font-medium">Crawl workers</span> are <em>stateless compute</em> — they pull crawl jobs from a central Redis queue, so adding a box needs no rebalancing and the autoscaler can grow/shrink the pool freely.
                    <span class="font-medium">Database shards</span> are <em>stateful</em> — each row physically lives on one node, so moving data is an explicit copy → verify → flip operation, never automatic.</p>
                <p><span class="font-semibold">Sharding model.</span>
                    Tenant data shards by <span class="font-mono text-xs">owner</span> (<span class="font-mono text-xs">websites.db_node_id</span>);
                    crawl data shards by <span class="font-mono text-xs">domain</span> (<span class="font-mono text-xs">crawl_sites.crawl_node_id</span>).
                    Identity, billing, <span class="font-mono text-xs">websites</span>, <span class="font-mono text-xs">crawl_sites</span> and the global catalogs stay on the <span class="font-medium">central primary</span> (the pinned node).</p>
                <p><span class="font-semibold">Lifecycle.</span>
                    Boxes move through <span class="rounded bg-blue-100 px-1 text-[11px] font-semibold text-blue-700">provisioning</span> →
                    <span class="rounded bg-emerald-100 px-1 text-[11px] font-semibold text-emerald-700">active</span> →
                    <span class="rounded bg-amber-100 px-1 text-[11px] font-semibold text-amber-700">draining</span> →
                    <span class="rounded bg-slate-100 px-1 text-[11px] font-semibold text-slate-600">deleting</span>,
                    or <span class="rounded bg-red-100 px-1 text-[11px] font-semibold text-red-700">failed</span>.
                    DB nodes add <span class="font-medium">bootstrap</span> (configure MariaDB + run migrations) and <span class="font-medium">migrate</span> (re-run migrations) after provisioning.</p>
                <p><span class="font-semibold">Everything is async.</span>
                    Provision / bootstrap / migrate / move / drain / destroy are dispatched as background <span class="font-mono text-xs">App\Jobs\Fleet\*</span> jobs on the <span class="font-mono text-xs">fleet</span> queue, processed only by the root <span class="font-mono text-xs">ebq-queue-fleet</span> worker on the web box (they SSH/rsync and take minutes). The tables here update on their own as jobs progress.</p>
                <p><span class="font-semibold">Proof.</span> The full lifecycle is exercised end-to-end by a browser test — see the screenshot slideshow at
                    <a href="{{ route('admin.fleet-test') }}" class="font-medium text-orange-600 hover:underline dark:text-orange-400">/admin/fleet-test</a>.</p>
            </div>
        </details>

        {{-- ── Tabs ──────────────────────────────────────────────────────────── --}}
        <div class="border-b border-slate-200 dark:border-slate-700">
            <nav class="-mb-px flex gap-1" aria-label="Fleet sections">
                <button type="button" data-tab="workers" class="fleet-tab rounded-t-md border-b-2 px-4 py-2 text-sm font-semibold">
                    Crawl workers <span class="ml-1 rounded-full bg-slate-100 px-1.5 text-[11px] text-slate-500 dark:bg-slate-700 dark:text-slate-300">compute</span>
                </button>
                <button type="button" data-tab="data" class="fleet-tab rounded-t-md border-b-2 px-4 py-2 text-sm font-semibold">
                    Database shards <span class="ml-1 rounded-full bg-slate-100 px-1.5 text-[11px] text-slate-500 dark:bg-slate-700 dark:text-slate-300">data</span>
                </button>
            </nav>
        </div>

        {{-- ════════════════ TAB 1: CRAWL WORKERS ════════════════ --}}
        <section data-panel="workers" class="space-y-5">
            <p class="max-w-3xl text-sm text-slate-500">
                Elastic worker boxes on Hetzner. The crawl queue is central Redis — a new box just starts pulling jobs (no rebalancing). The autoscaler scales the pool to match backlog. Boxes here are <span class="font-medium">disposable</span>: nothing is lost when one is destroyed.
            </p>

            {{-- Live status (summary cards + node table, polls every 5s) --}}
            <livewire:admin.fleet-status />

            {{-- Operator actions --}}
            <div class="flex flex-wrap items-center gap-2">
                <form method="POST" action="{{ route('admin.fleet.provision') }}" onsubmit="return confirm('Provision + bootstrap a new worker box now?')">@csrf
                    <button class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50">+ Provision a box</button>
                </form>
                <form method="POST" action="{{ route('admin.fleet.reconcile') }}">@csrf
                    <button class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Reconcile (DB ↔ Hetzner)</button>
                </form>
                <span class="text-[11px] text-slate-400">Provision = add one box now · Drain = stop pulling new jobs, finish in-flight · Destroy = delete the Hetzner server · Reconcile = re-sync this list with what actually exists in Hetzner.</span>
            </div>

            {{-- Autoscaler settings --}}
            <form method="POST" action="{{ route('admin.fleet.settings') }}" class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                @csrf
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Autoscaler settings</h2>
                <p class="mt-0.5 text-xs text-slate-400">Controls how the pool grows/shrinks. The autoscaler keeps roughly <span class="font-mono">target_backlog_per_box</span> jobs queued per box, between <span class="font-mono">min</span> and <span class="font-mono">max_boxes</span>.</p>
                <label class="mt-3 flex items-center gap-2 text-sm">
                    <input type="checkbox" name="enabled" value="1" @checked($cfg['enabled']) class="rounded border-slate-300">
                    <span class="font-medium">Enabled</span>
                    <span class="text-xs text-slate-400">master kill-switch — leave off until the snapshot + HCLOUD_TOKEN are set up</span>
                </label>
                <label class="mt-2 flex items-center gap-2 text-sm">
                    <input type="checkbox" name="auto_snapshot" value="1" @checked($cfg['auto_snapshot']) class="rounded border-slate-300">
                    <span class="font-medium">Auto-rebuild snapshot on code change</span>
                    <span class="text-xs text-slate-400">rebuilds the worker snapshot in the background when git HEAD changes (current: <span class="font-mono">{{ \Illuminate\Support\Str::limit($cfg['snapshot_head'] ?? '—', 10, '') }}</span>). Turn OFF while working on the server to avoid repeated rebuilds.</span>
                </label>
                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {{ $num('min_boxes', $cfg['min_boxes']) }}
                    {{ $num('max_boxes', $cfg['max_boxes']) }}
                    {{ $num('target_backlog_per_box', $cfg['target_backlog_per_box']) }}
                    <div>
                        <label class="block text-xs font-medium text-slate-500">server_type</label>
                        <select name="server_type" class="mt-1 w-full rounded-md border-slate-300 text-sm dark:bg-slate-900 dark:border-slate-600">
                            @foreach ($serverTypes as $val => $label)
                                <option value="{{ $val }}" @selected($cfg['server_type'] === $val)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500">snapshot_id <span class="text-slate-400">(worker image)</span></label>
                        @php $snapFound = false; $snapAuto = (bool) ($cfg['auto_snapshot'] ?? false); @endphp
                        <select name="snapshot_id" @disabled($snapAuto) class="mt-1 w-full rounded-md border-slate-300 text-sm disabled:bg-slate-100 disabled:text-slate-500 disabled:cursor-not-allowed dark:bg-slate-900 dark:border-slate-600">
                            <option value="">(use HCLOUD_WORKER_IMAGE default)</option>
                            @foreach ($workerSnapshots as $s)
                                <option value="{{ $s['id'] }}" @selected((string) $cfg['snapshot_id'] === (string) $s['id'])>{{ $s['id'] }} — {{ $s['description'] ?: 'snapshot' }} ({{ $s['created'] }})</option>
                                @php $snapFound = $snapFound || (string) $cfg['snapshot_id'] === (string) $s['id']; @endphp
                            @endforeach
                            @if ($cfg['snapshot_id'] && ! $snapFound)
                                <option value="{{ $cfg['snapshot_id'] }}" selected>{{ $cfg['snapshot_id'] }} (current — not in Hetzner snapshot list ⚠)</option>
                            @endif
                        </select>
                        @if ($snapAuto)
                            {{-- Disabled selects don't submit; preserve the auto-managed value. --}}
                            <input type="hidden" name="snapshot_id" value="{{ $cfg['snapshot_id'] }}">
                            <p class="mt-0.5 text-[10px] text-slate-400">Auto-managed — rebuilt on code change. Turn off “Auto-rebuild snapshot” above to pick manually.</p>
                        @elseif (empty($workerSnapshots))
                            <p class="mt-0.5 text-[10px] text-amber-600">No snapshots returned from Hetzner (token/API?) — provisioning falls back to HCLOUD_WORKER_IMAGE.</p>
                        @endif
                    </div>
                    {{ $num('per_domain_rate', $cfg['per_domain_rate']) }}
                    {{ $num('scale_up_cooldown_s', $cfg['scale_up_cooldown_s']) }}
                    {{ $num('scale_down_idle_s', $cfg['scale_down_idle_s']) }}
                    {{ $num('min_box_lifetime_s', $cfg['min_box_lifetime_s']) }}
                </div>
                <div class="mt-4">
                    <button class="rounded-md bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-700">Save settings</button>
                </div>
            </form>
        </section>

        {{-- ════════════════ TAB 2: DATABASE SHARDS ════════════════ --}}
        <section data-panel="data" class="space-y-5" hidden>
            <p class="max-w-3xl text-sm text-slate-500">
                MariaDB shard nodes. Tenant data shards by owner (<span class="font-mono text-xs">websites.db_node_id</span>); crawl data shards by domain (<span class="font-mono text-xs">crawl_sites.crawl_node_id</span>). Identity / billing / catalogs stay on the central primary. Unlike workers, these hold <span class="font-medium">live data</span> — destroy only ever deletes an <em>empty</em> node.
            </p>

            {{-- Nodes + residents + move form + live move progress (Livewire, wire:poll —
                 replaces the old static table + window.location.reload loop) --}}
            <livewire:admin.db-shard-panel />

            {{-- Settings --}}
            <form method="POST" action="{{ route('admin.db-fleet.settings') }}" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm space-y-3 dark:border-slate-700 dark:bg-slate-800">
                @csrf
                <h2 class="text-sm font-semibold">Provisioning defaults</h2>
                <p class="text-xs text-slate-400">Applied to the next DB node you provision. Placement chooses which node new tenants/sites land on.</p>
                <div class="grid grid-cols-2 gap-3 md:grid-cols-3">
                    <label class="text-xs">Server type<select name="server_type" class="mt-0.5 block w-full rounded border-slate-300 text-xs">@foreach ($dbServerTypes as $k => $label)<option value="{{ $k }}" @selected($dbCfg['server_type'] === $k)>{{ $label }}</option>@endforeach</select></label>
                    <label class="text-xs">DB snapshot id
                        @php $dbSnapFound = false; @endphp
                        <select name="snapshot_id" class="mt-0.5 block w-full rounded border-slate-300 text-xs">
                            <option value="">(use HCLOUD_DB_IMAGE default)</option>
                            @foreach ($dbSnapshots as $s)
                                <option value="{{ $s['id'] }}" @selected((string) $dbCfg['snapshot_id'] === (string) $s['id'])>{{ $s['id'] }} — {{ $s['description'] ?: 'snapshot' }} ({{ $s['created'] }})</option>
                                @php $dbSnapFound = $dbSnapFound || (string) $dbCfg['snapshot_id'] === (string) $s['id']; @endphp
                            @endforeach
                            @if ($dbCfg['snapshot_id'] && ! $dbSnapFound)
                                <option value="{{ $dbCfg['snapshot_id'] }}" selected>{{ $dbCfg['snapshot_id'] }} (current ⚠)</option>
                            @endif
                        </select>
                    </label>
                    <label class="text-xs">Placement<select name="placement" class="mt-0.5 block w-full rounded border-slate-300 text-xs"><option value="least_loaded" @selected($dbCfg['placement'] === 'least_loaded')>least_loaded</option><option value="round_robin" @selected($dbCfg['placement'] === 'round_robin')>round_robin</option></select></label>
                    <label class="text-xs">Max tenants / node<input type="number" name="max_tenants_per_node" value="{{ $dbCfg['max_tenants_per_node'] }}" class="mt-0.5 block w-full rounded border-slate-300 text-xs"></label>
                    <label class="text-xs">Max sites / node<input type="number" name="max_sites_per_node" value="{{ $dbCfg['max_sites_per_node'] }}" class="mt-0.5 block w-full rounded border-slate-300 text-xs"></label>
                </div>
                <button class="rounded-md bg-slate-800 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-700">Save</button>
            </form>
        </section>
    </div>

    <script>
        (function () {
            var ACTIVE_CLS = ['border-orange-500', 'text-orange-600'];
            var IDLE_CLS = ['border-transparent', 'text-slate-500', 'hover:text-slate-700', 'hover:border-slate-300'];
            var tabs = Array.prototype.slice.call(document.querySelectorAll('.fleet-tab'));
            var panels = Array.prototype.slice.call(document.querySelectorAll('[data-panel]'));

            function show(name) {
                name = (name === 'data') ? 'data' : 'workers';
                panels.forEach(function (p) { p.hidden = (p.dataset.panel !== name); });
                tabs.forEach(function (t) {
                    var on = (t.dataset.tab === name);
                    ACTIVE_CLS.forEach(function (c) { t.classList.toggle(c, on); });
                    IDLE_CLS.forEach(function (c) { t.classList.toggle(c, !on); });
                });
                return name;
            }

            // Active tab persists in the URL hash so a full reload reopens it.
            var active = show((location.hash || '').replace('#', ''));
            tabs.forEach(function (t) {
                t.addEventListener('click', function () {
                    active = show(t.dataset.tab);
                    if (history.replaceState) history.replaceState(null, '', '#' + active);
                    else location.hash = active;
                });
            });

        })();
    </script>
</x-layouts.app>
