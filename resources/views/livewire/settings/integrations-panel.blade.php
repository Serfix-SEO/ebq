<div class="space-y-4">
    {{-- Google account connection --}}
    <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div class="border-b border-slate-200 px-5 py-3.5 dark:border-slate-800">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ __('Google Accounts') }}</h2>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('Connect one or more Google accounts for Analytics and Search Console data.') }}</p>
        </div>
        <div class="px-5 py-4">
            @if ($googleAccount)
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2.5">
                        <div class="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-50 dark:bg-emerald-500/10">
                            <svg class="h-4 w-4 text-emerald-600 dark:text-emerald-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-emerald-700 dark:text-emerald-300">{{ count($accounts) > 1 ? __(':count accounts connected', ['count' => count($accounts)]) : __('Connected') }}</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400" title="{{ $googleAccount->expires_at ? format_user_datetime($googleAccount->expires_at) : '' }}">{{ __('Latest token expires :time', ['time' => $googleAccount->expires_at?->diffForHumans() ?? __('unknown')]) }}</p>
                        </div>
                    </div>
                    <a href="{{ route('google.redirect', ['return' => 'settings.integrations']) }}" class="inline-flex h-8 items-center rounded-md border border-slate-200 px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">{{ __('Connect another') }}</a>
                </div>

                @if (count($accounts) > 1)
                    <div class="mt-3 flex flex-wrap gap-1.5">
                        @foreach ($accounts as $acc)
                            <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] font-medium text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ $acc['label'] }}</span>
                        @endforeach
                    </div>
                @endif
            @else
                <a href="{{ route('google.redirect', ['return' => 'settings.integrations']) }}" class="inline-flex h-8 items-center gap-1.5 rounded-md bg-orange-600 px-3 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-700">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#fff"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#fff" fill-opacity=".7"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#fff" fill-opacity=".5"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#fff" fill-opacity=".8"/></svg>
                    {{ __('Connect Google Account') }}
                </a>
            @endif
        </div>
    </div>

    {{-- Per-website data sources --}}
    @if ($website)
        <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div class="border-b border-slate-200 px-5 py-3.5 dark:border-slate-800">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ __('Data sources for :website', ['website' => $website->domain ?: __('this website')]) }}</h2>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('Choose the GA4 property and Search Console site for this website. They can live on different Google accounts.') }}</p>
            </div>
            <div class="px-5 py-4">
                @if ($fetchError)
                    <div class="mb-3 flex items-center gap-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                        <svg class="h-3.5 w-3.5 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                        {{ $fetchError }}
                    </div>
                @endif

                @if ($saved)
                    <div class="mb-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-700 dark:border-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400">{{ $saved }}</div>
                @endif

                <form wire:submit="saveSources" class="space-y-3">
                    <div>
                        <label class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">{{ __('Google Analytics (GA4) Property') }}</label>
                        <select wire:model="gaSelection"
                            class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm transition focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800">
                            <option value="">{{ __('Not connected') }}</option>
                            @foreach ($gaOptions as $opt)
                                <option value="{{ $opt['account_id'] }}|{{ $opt['id'] }}">{{ $opt['name'] }} ({{ $opt['id'] }}) — {{ $opt['account_label'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">{{ __('Search Console Site') }}</label>
                        <select wire:model="gscSelection"
                            class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm transition focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800">
                            <option value="">{{ __('Not connected') }}</option>
                            @foreach ($gscOptions as $opt)
                                <option value="{{ $opt['account_id'] }}|{{ $opt['siteUrl'] }}">{{ $opt['siteUrl'] }} — {{ $opt['account_label'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex items-center justify-between border-t border-slate-100 pt-3 dark:border-slate-800">
                        <a href="{{ route('google.redirect', ['return' => 'settings.integrations']) }}" class="text-[11px] font-semibold text-orange-600 hover:text-orange-700 dark:text-orange-400">{{ __('Missing a property? Connect another account') }}</a>
                        <button type="submit" class="inline-flex h-8 items-center rounded-md bg-orange-600 px-3 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-700">{{ __('Save sources') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
