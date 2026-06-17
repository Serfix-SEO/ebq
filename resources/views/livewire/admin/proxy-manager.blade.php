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
    </div>

    @if ($notice)
        <div class="rounded-lg bg-indigo-50 px-4 py-2 text-sm text-indigo-800 dark:bg-indigo-500/10 dark:text-indigo-300">{{ $notice }}</div>
    @endif

    {{-- Add proxies --}}
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Add proxies</h2>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
            One per line. Formats: <code>host:port</code>, <code>host:port:user:pass</code>,
            <code>user:pass@host:port</code>, <code>http(s)://…</code>, <code>socks5://…</code>.
        </p>
        <textarea wire:model="bulkInput" rows="4" placeholder="198.51.100.10:8080:user:pass"
            class="mt-3 w-full rounded-lg border-slate-300 font-mono text-xs focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-950"></textarea>
        <div class="mt-3 flex flex-wrap gap-2">
            <button wire:click="addProxies" wire:loading.attr="disabled"
                class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50">Add proxies</button>
        </div>
    </div>

    {{-- Proxy list --}}
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
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
                    <tr>
                        <td class="px-4 py-2 font-mono text-xs text-slate-800 dark:text-slate-200">
                            {{ $masked }}
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
                            <button wire:click="test('{{ $p->id }}')" wire:loading.attr="disabled" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">Test</button>
                            <button wire:click="toggle('{{ $p->id }}')" class="ml-3 font-medium text-slate-600 hover:underline dark:text-slate-300">{{ $p->active ? 'Disable' : 'Enable' }}</button>
                            <button wire:click="delete('{{ $p->id }}')" wire:confirm="Delete this proxy?" class="ml-3 font-medium text-red-600 hover:underline dark:text-red-400">Delete</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-sm text-slate-400">No proxies yet. Add some above or import from proxylist.txt.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
