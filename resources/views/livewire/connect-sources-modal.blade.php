<div
    x-data="{ show: false }"
    x-on:open-connect-sources.window="show = true; $wire.open($event.detail?.websiteId ?? null)"
    x-on:keydown.escape.window="show = false"
>
    <div x-show="show" x-cloak class="fixed inset-0 z-50 flex items-center justify-center px-4">
        {{-- Backdrop --}}
        <div x-show="show" x-transition.opacity class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" x-on:click="show = false"></div>

        {{-- Panel --}}
        <div
            x-show="show"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-4 scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
            class="relative w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700"
            role="dialog"
            aria-modal="true"
        >
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3.5 dark:border-slate-800">
                <div>
                    <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">Connect your data sources</h2>
                    <p class="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">Attach a GA4 property and/or Search Console site to unlock the full report.</p>
                </div>
                <button type="button" x-on:click="show = false" class="rounded-md p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800" aria-label="Close">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                </button>
            </div>

            <div class="px-5 py-4">
                {{-- Loading state while the Google pool is fetched --}}
                <div wire:loading.flex wire:target="open" class="items-center justify-center gap-2 py-8 text-xs text-slate-500" role="status">
                    <svg class="h-4 w-4 animate-spin text-orange-500" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                    Loading your Google properties…
                </div>

                <div wire:loading.remove wire:target="open">
                    @if ($saved)
                        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-700 dark:border-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400">{{ $saved }}</div>
                    @elseif (! $loaded)
                        <p class="py-6 text-center text-xs text-slate-500">Preparing…</p>
                    @elseif (! $hasGoogle)
                        {{-- No Google account connected at all --}}
                        <p class="text-xs text-slate-600 dark:text-slate-300">Connect a Google account to pull Analytics and Search Console data.</p>
                        <a href="{{ route('google.redirect', ['return' => 'settings.integrations']) }}"
                            class="mt-3 inline-flex h-8 w-full items-center justify-center gap-2 rounded-md bg-slate-900 px-3 text-xs font-semibold text-white shadow-sm transition hover:bg-slate-800 dark:bg-slate-700 dark:hover:bg-slate-600">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
                            Sign in with Google
                        </a>
                    @else
                        @if ($fetchError)
                            <div class="mb-3 rounded-md bg-amber-50 px-3 py-2 text-[11px] text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">{{ $fetchError }}</div>
                        @endif

                        <form wire:submit="saveSources" class="space-y-3">
                            <div>
                                <label class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">Google Analytics (GA4) Property</label>
                                <select wire:model="gaSelection" class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800">
                                    <option value="">Not connected</option>
                                    @foreach ($gaOptions as $opt)
                                        <option value="{{ $opt['account_id'] }}|{{ $opt['id'] }}">{{ $opt['name'] }} ({{ $opt['id'] }}) — {{ $opt['account_label'] }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">Search Console Site</label>
                                <select wire:model="gscSelection" class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800">
                                    <option value="">Not connected</option>
                                    @foreach ($gscOptions as $opt)
                                        <option value="{{ $opt['account_id'] }}|{{ $opt['siteUrl'] }}">{{ $opt['siteUrl'] }} — {{ $opt['account_label'] }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <a href="{{ route('google.redirect', ['return' => 'settings.integrations']) }}" class="block text-[11px] font-semibold text-orange-600 hover:text-orange-700 dark:text-orange-400">
                                Property on a different Google account? Connect another →
                            </a>

                            <div class="flex items-center justify-end gap-2 border-t border-slate-100 pt-3 dark:border-slate-800">
                                <button type="button" x-on:click="show = false" class="inline-flex h-8 items-center rounded-md px-3 text-xs font-medium text-slate-500 transition hover:text-slate-700 dark:hover:text-slate-300">Cancel</button>
                                <button type="submit" wire:loading.attr="disabled" wire:target="saveSources" class="inline-flex h-8 items-center rounded-md bg-orange-600 px-3 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-700 disabled:opacity-60">
                                    <span wire:loading.remove wire:target="saveSources">Save &amp; sync</span>
                                    <span wire:loading wire:target="saveSources">Saving…</span>
                                </button>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
