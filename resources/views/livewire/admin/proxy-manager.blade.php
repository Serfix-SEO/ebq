<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Crawler Proxies</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Anti-blocking proxy pool. Used to retry from a different IP when a site (Cloudflare/WAF) blocks the crawler.
        </p>
    </div>

    {{-- Pool status --}}
    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 text-sm shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium {{ $poolEnabled ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' }}">
            {{ $poolEnabled ? 'Pool enabled' : 'Pool disabled' }}
        </span>
        <span class="text-slate-500 dark:text-slate-400">Mode: <span class="font-medium text-slate-700 dark:text-slate-200">{{ $poolMode }}</span></span>
        <span class="text-slate-500 dark:text-slate-400">Active proxies: <span class="font-medium text-slate-700 dark:text-slate-200">{{ $activeCount }}</span></span>
        @unless ($poolEnabled)
            <span class="text-xs text-amber-600 dark:text-amber-400">Set <code>CRAWLER_PROXY_ENABLED=true</code> to use the pool.</span>
        @endunless

        <span class="ml-auto inline-flex items-center gap-2 text-xs">
            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium {{ $autoImportEnabled ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' }}">
                Auto-import (free lists): {{ $autoImportEnabled ? 'on, every 30min' : 'off' }}
            </span>
            <button wire:click="importNow" wire:loading.attr="disabled" wire:target="importNow"
                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-1.5 font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800">
                <span wire:loading wire:target="importNow" class="inline-flex h-3 w-3 animate-spin rounded-full border-2 border-slate-400 border-t-transparent"></span>
                Import now
            </button>
        </span>
    </div>
    <p class="-mt-3 text-[11px] text-slate-400 dark:text-slate-500">
        "Import now" queues a background fetch + live-test of new candidates from iplocate/free-proxy-list +
        proxifly/free-proxy-list — only passing ones get added. Existing proxies are checked separately, every
        15min, and removed the moment they fail (<code>ebq:proxy-pool-prune</code>, always on).
    </p>

    @if ($notice)
        <div class="rounded-lg bg-orange-50 px-4 py-2 text-sm text-orange-800 dark:bg-orange-500/10 dark:text-orange-300">{{ $notice }}</div>
    @endif

    {{-- Add proxies --}}
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Add proxies</h2>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
            One per line. Formats: <code>host:port</code>, <code>host:port:user:pass</code>,
            <code>user:pass@host:port</code>, <code>http(s)://…</code>, <code>socks5://…</code>.
        </p>
        <textarea wire:model="bulkInput" rows="4" placeholder="198.51.100.10:8080:user:pass"
            class="mt-3 w-full rounded-lg border-slate-300 font-mono text-xs focus:border-orange-500 focus:ring-orange-500 dark:border-slate-600 dark:bg-slate-950"></textarea>
        <div class="mt-3 flex flex-wrap gap-2">
            <button wire:click="addProxies" wire:loading.attr="disabled"
                class="inline-flex items-center rounded-lg bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-500 disabled:opacity-50">Add proxies</button>
        </div>
    </div>

    {{-- Proxy list --}}
    <div
        x-data="{
            running: false,
            done: 0,
            total: 0,
            failed: 0,
            passed: 0,
            current: null,
            // Sequential, not parallel: many proxy pool entries share one provider
            // account/credential (rotating-IP gateway with a per-account concurrent-
            // connection cap). Concurrency>1 here exceeds that cap, the extra connections
            // get 403'd by the provider, and deleteOnFail wipes proxies that are actually
            // fine — confirmed 2026-06-23 (single 'Test' passed, 'Retest all' deleted all).
            concurrency: 1,
            async retestAll(ids) {
                if (this.running || ! ids.length) return;
                if (! confirm(`Retest all ${ids.length} proxies? Any that fail will be removed immediately.`)) return;
                this.running = true; this.done = 0; this.total = ids.length; this.failed = 0; this.passed = 0;
                const queue = [...ids];
                const worker = async () => {
                    while (queue.length) {
                        const id = queue.shift();
                        this.current = id;
                        try {
                            await $wire.test(id, true);
                            const r = $wire.testResults[id] || '';
                            r.startsWith('ok') ? this.passed++ : this.failed++;
                        } catch (e) { this.failed++; }
                        this.done++;
                    }
                };
                await Promise.all(Array.from({ length: Math.min(this.concurrency, ids.length) }, worker));
                this.current = null;
                this.running = false;
            },
        }"
        class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900"
    >
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3 dark:border-slate-800 dark:bg-slate-800/50">
            <div class="text-xs text-slate-500 dark:text-slate-400">
                <span x-show="!running">{{ $proxies->count() }} proxies tracked.</span>
                <span x-show="running" x-cloak class="inline-flex items-center gap-2 font-medium text-orange-600 dark:text-orange-400">
                    <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                    Retesting <span x-text="done"></span>/<span x-text="total"></span>…
                    <span class="text-emerald-600 dark:text-emerald-400" x-text="passed + ' ok'"></span>
                    <span class="text-red-600 dark:text-red-400" x-text="failed + ' fail'"></span>
                </span>
                <span x-show="!running && total > 0" x-cloak class="font-medium text-slate-700 dark:text-slate-200">
                    Done — <span class="text-emerald-600 dark:text-emerald-400" x-text="passed + ' ok'"></span>,
                    <span class="text-red-600 dark:text-red-400" x-text="failed + ' fail'"></span>.
                </span>
            </div>
            <div class="flex items-center gap-3">
                <div x-show="running" x-cloak class="h-1.5 w-32 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                    <div class="h-full bg-orange-600 transition-all" :style="`width: ${total ? (done / total * 100) : 0}%`"></div>
                </div>
                <button
                    @click="retestAll(@js($proxies->pluck('id')))"
                    :disabled="running || {{ $proxies->isEmpty() ? 'true' : 'false' }}"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                >
                    <svg x-show="!running" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
                    <span x-text="running ? 'Retesting…' : 'Retest all'"></span>
                </button>
            </div>
        </div>
        <div class="border-b border-slate-100 bg-amber-50 px-4 py-1.5 text-[11px] text-amber-700 dark:border-slate-800 dark:bg-amber-500/10 dark:text-amber-300">
            "Retest all" deletes any proxy that fails, immediately — unlike the single row "Test" button, which only records the failure.
        </div>
        <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
            <thead class="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
                <tr>
                    <th class="px-4 py-2">Proxy</th>
                    <th class="px-4 py-2">Source</th>
                    <th class="px-4 py-2">Health</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($proxies as $p)
                    @php $masked = preg_replace('#://([^:/@]+):[^@]*@#', '://$1:***@', $p->url); @endphp
                    <tr :class="current === '{{ $p->id }}' ? 'bg-orange-50/60 dark:bg-orange-500/10' : ''">
                        <td class="px-4 py-2 font-mono text-xs text-slate-800 dark:text-slate-200">
                            <span class="inline-flex items-center gap-1.5">
                                <svg x-show="current === '{{ $p->id }}'" x-cloak class="h-3 w-3 flex-none animate-spin text-orange-500" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                {{ $masked }}
                            </span>
                            @if (isset($testResults[$p->id]))
                                <div class="mt-0.5 text-[11px] {{ str_starts_with($testResults[$p->id], 'ok') ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">{{ $testResults[$p->id] }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-xs text-slate-500 dark:text-slate-400">{{ $p->label }}</td>
                        <td class="px-4 py-2 text-xs text-slate-500 dark:text-slate-400">
                            <span class="text-emerald-600 dark:text-emerald-400">{{ $p->success_count }} ok</span> ·
                            <span class="{{ $p->fail_count ? 'text-red-600 dark:text-red-400' : '' }}">{{ $p->fail_count }} fail</span>
                            @if ($p->last_ok_at)<div class="text-[11px] text-slate-400">ok {{ $p->last_ok_at->diffForHumans() }}</div>@endif
                        </td>
                        <td class="px-4 py-2">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $p->active ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' }}">{{ $p->active ? 'active' : 'disabled' }}</span>
                        </td>
                        <td class="px-4 py-2 text-right text-xs">
                            <button wire:click="test('{{ $p->id }}')" wire:loading.attr="disabled" wire:target="test('{{ $p->id }}')" :disabled="running" class="font-medium text-orange-600 hover:underline dark:text-orange-400 disabled:opacity-50">Test</button>
                            <button wire:click="toggle('{{ $p->id }}')" :disabled="running" class="ml-3 font-medium text-slate-600 hover:underline dark:text-slate-300 disabled:opacity-50">{{ $p->active ? 'Disable' : 'Enable' }}</button>
                            <button wire:click="delete('{{ $p->id }}')" wire:confirm="Delete this proxy?" :disabled="running" class="ml-3 font-medium text-red-600 hover:underline dark:text-red-400 disabled:opacity-50">Delete</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-sm text-slate-400">No proxies yet. Add some above or import from proxylist.txt.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
