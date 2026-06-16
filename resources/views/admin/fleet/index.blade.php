<x-layouts.app>
    @php
        /** @var array $cfg @var array $serverTypes */
        $num = fn ($k, $v) => view('admin.fleet._num', ['k' => $k, 'v' => $v]);
    @endphp
    <div class="space-y-5">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Crawl-worker fleet</h1>
            <p class="text-sm text-slate-500">Elastic worker boxes on Hetzner. The crawl queue is central Redis — new boxes just pull jobs (no rebalancing). The autoscaler scales to match backlog.</p>
        </div>

        @if (session('status'))
            <div class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-800">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs font-medium text-red-800">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800">{{ $errors->first() }}</div>
        @endif

        {{-- Live status --}}
        <livewire:admin.fleet-status />

        {{-- Operator actions --}}
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('admin.fleet.provision') }}" onsubmit="return confirm('Provision + bootstrap a new worker box now?')">@csrf
                <button class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50">+ Provision a box</button>
            </form>
            <form method="POST" action="{{ route('admin.fleet.reconcile') }}">@csrf
                <button class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Reconcile (DB ↔ Hetzner)</button>
            </form>
        </div>

        {{-- Autoscaler settings --}}
        <form method="POST" action="{{ route('admin.fleet.settings') }}" class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
            @csrf
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Autoscaler settings</h2>
            <label class="mt-3 flex items-center gap-2 text-sm">
                <input type="checkbox" name="enabled" value="1" @checked($cfg['enabled']) class="rounded border-slate-300">
                <span class="font-medium">Enabled</span>
                <span class="text-xs text-slate-400">master kill-switch — leave off until the snapshot + HCLOUD_TOKEN are set up</span>
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
                    <input type="text" name="snapshot_id" value="{{ $cfg['snapshot_id'] }}" class="mt-1 w-full rounded-md border-slate-300 text-sm dark:bg-slate-900 dark:border-slate-600">
                </div>
                {{ $num('per_domain_rate', $cfg['per_domain_rate']) }}
                {{ $num('scale_up_cooldown_s', $cfg['scale_up_cooldown_s']) }}
                {{ $num('scale_down_idle_s', $cfg['scale_down_idle_s']) }}
                {{ $num('min_box_lifetime_s', $cfg['min_box_lifetime_s']) }}
            </div>
            <div class="mt-4">
                <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Save settings</button>
            </div>
        </form>
    </div>
</x-layouts.app>
