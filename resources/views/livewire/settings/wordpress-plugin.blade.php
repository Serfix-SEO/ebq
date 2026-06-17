<div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <h2 class="text-sm font-semibold">WordPress plugin</h2>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                Download the EBQ SEO plugin, upload it to your WordPress site, then click <span class="font-semibold">Connect to EBQ</span> — you'll be bounced here to approve. No codes or tokens to copy.
            </p>
        </div>
        <a href="{{ route('wordpress.plugin.download') }}"
            class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-md bg-indigo-600 px-3 text-[11px] font-semibold text-white transition hover:bg-indigo-700">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
            Download plugin
        </a>
    </div>

    @if ($statusMessage)
        <div class="mt-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-700 dark:border-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400" role="status">
            {{ $statusMessage }}
        </div>
    @endif

    @if ($tokens->isEmpty())
        <div class="mt-5 flex flex-col items-start gap-2 rounded-lg border border-dashed border-slate-200 bg-slate-50/60 p-4 dark:border-slate-700 dark:bg-slate-800/40">
            <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500 dark:text-slate-400">Not connected yet</p>
            <p class="text-xs text-slate-600 dark:text-slate-300">
                <span class="font-semibold">Download plugin</span> above, upload the ZIP to your site via <span class="font-semibold">Plugins → Add New → Upload</span>, activate <span class="font-semibold">EBQ SEO</span>, then click <span class="font-semibold">Connect to EBQ</span> — you'll be bounced back here to approve. Active connections will appear in this list.
            </p>
        </div>
    @else
        <div class="mt-5">
            <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500 dark:text-slate-400">Active WordPress connections</p>
            <ul class="mt-2 divide-y divide-slate-100 rounded-md border border-slate-200 dark:divide-slate-800 dark:border-slate-700">
                @foreach ($tokens as $token)
                    <li class="flex items-center justify-between gap-3 px-3 py-2 text-xs">
                        <div class="min-w-0">
                            <p class="truncate font-medium text-slate-800 dark:text-slate-100">{{ $token->name }}</p>
                            <p class="text-[10px] text-slate-500 dark:text-slate-400">Connected {{ $token->created_at?->diffForHumans() }} · Last used {{ $token->last_used_at?->diffForHumans() ?? 'never' }}</p>
                        </div>
                        <button type="button" wire:click="revokeToken('{{ $token->id }}')"
                            wire:confirm="Revoke this connection? The plugin on that site will immediately lose access."
                            class="inline-flex h-7 items-center rounded-md border border-red-200 bg-white px-2.5 text-[11px] font-semibold text-red-600 transition hover:bg-red-50 dark:border-red-800 dark:bg-slate-900 dark:text-red-400 dark:hover:bg-red-900/20">Revoke</button>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
