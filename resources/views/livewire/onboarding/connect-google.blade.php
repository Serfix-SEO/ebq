<div class="space-y-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-lg font-bold tracking-tight text-slate-900 dark:text-slate-100">{{ __('Get started') }}</h1>
            <p class="mt-1 text-xs text-slate-600 dark:text-slate-400">{{ __('Connect Google Analytics and/or Search Console — or skip and explore with the free tools.') }}</p>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit"
                class="inline-flex h-8 items-center rounded-md border border-slate-200 px-3 text-xs font-medium text-slate-600 transition hover:bg-slate-50 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-slate-100">
                {{ __('Log out') }}
            </button>
        </form>
    </div>

    {{-- Step indicator --}}
    <div class="flex items-center gap-3">
        <button wire:click="goToStep(1)" class="flex items-center gap-2">
            <span @class([
                'flex h-7 w-7 items-center justify-center rounded-full text-xs font-bold transition',
                'bg-orange-600 text-white' => $step === 1 && !$googleConnected,
                'bg-emerald-500 text-white' => $googleConnected,
                'bg-slate-200 text-slate-600' => $step !== 1 && !$googleConnected,
            ])>
                @if ($googleConnected)
                    <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                @else
                    1
                @endif
            </span>
            <span class="text-xs font-medium {{ $step === 1 ? 'text-slate-900 dark:text-slate-100' : 'text-slate-500 dark:text-slate-400' }}">{{ __('Connect') }}</span>
        </button>

        <div class="h-px flex-1 bg-slate-200 dark:bg-slate-700"></div>

        <button wire:click="goToStep(2)" @disabled(!$googleConnected && $step !== 2) class="flex items-center gap-2">
            <span @class([
                'flex h-7 w-7 items-center justify-center rounded-full text-xs font-bold transition',
                'bg-orange-600 text-white' => $step === 2,
                'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-400' => $step !== 2,
            ])>2</span>
            <span class="text-xs font-medium {{ $step === 2 ? 'text-slate-900 dark:text-slate-100' : 'text-slate-500 dark:text-slate-400' }}">{{ __('Add Site') }}</span>
        </button>
    </div>

    {{-- Step 1: Connect Google --}}
    @if ($step === 1)
        <div class="rounded-xl border border-slate-200 bg-white p-6 text-center shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="mx-auto mb-3 flex h-11 w-11 items-center justify-center rounded-xl bg-orange-50 dark:bg-orange-500/10">
                <svg class="h-5 w-5 text-orange-600 dark:text-orange-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m9.86-3.06a4.5 4.5 0 00-1.242-7.244l-4.5-4.5a4.5 4.5 0 00-6.364 6.364L4.34 8.374" /></svg>
            </div>

            <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Connect your Google account') }}</h2>
            <p class="mx-auto mt-1 max-w-sm text-xs text-slate-500 dark:text-slate-400">
                {{ __('We need read-only access to Google Analytics and Search Console to pull your website data. You only need one of them to get started — connect both for the full report.') }}
            </p>

            @if ($googleConnected)
                <div class="mt-4 inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
                    <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    {{ __('Google account connected') }}
                </div>
                <div class="mt-3">
                    <button wire:click="goToStep(2)"
                        class="inline-flex h-8 items-center gap-1.5 rounded-md bg-orange-600 px-3 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-700">
                        {{ __('Continue') }}
                        <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
                    </button>
                </div>
            @else
                <div class="mt-4">
                    <a href="{{ route('google.redirect', ['return' => 'onboarding']) }}"
                        class="inline-flex h-8 items-center gap-2 rounded-md bg-slate-900 px-3 text-xs font-semibold text-white shadow-sm transition hover:bg-slate-800 dark:bg-slate-700 dark:hover:bg-slate-600">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
                        {{ __('Sign in with Google') }}
                    </a>
                </div>
                <p class="mt-3 text-[11px] text-slate-400 dark:text-slate-500">{{ __('Read-only permissions. We never modify your data.') }}</p>

                {{-- Skip Google entirely — explore with the free tools and connect later --}}
                <div class="mt-5 border-t border-slate-100 pt-5 dark:border-slate-800">
                    <p class="mx-auto max-w-sm text-[11px] text-slate-500 dark:text-slate-400">
                        {{ __('Not ready to connect Google? Add your domain and explore the free tools (PageSpeed, site crawl, keyword research). You can connect Analytics & Search Console anytime in Settings.') }}
                    </p>
                    <div class="mx-auto mt-3 max-w-xs text-left">
                        <label for="skip-domain" class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">{{ __('Domain') }}</label>
                        <input id="skip-domain" wire:model="domain" type="text" placeholder="example.com"
                            class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm transition placeholder:text-slate-400 focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800" />
                        @error('domain') <p class="mt-0.5 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        <button type="button" wire:click="skipForNow"
                            class="mt-2 inline-flex h-8 w-full items-center justify-center gap-1 rounded-md border border-slate-200 px-3 text-xs font-medium text-slate-600 transition hover:bg-slate-50 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-slate-100">
                            {{ __('Skip — I\'ll connect Google later') }}
                        </button>
                    </div>
                </div>
            @endif
        </div>

    {{-- Step 2: Choose data sources --}}
    @else
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Connect your data sources') }}</h2>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('Pick a GA4 property and/or a Search Console site. They can be on different Google accounts — just connect each one.') }}</p>

            @if ($fetchError)
                <div class="mt-3 flex items-center gap-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                    <svg class="h-3.5 w-3.5 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                    {{ $fetchError }}
                </div>
            @endif

            <form wire:submit="saveWebsite" class="mt-4 space-y-3">
                {{-- Google Analytics --}}
                <div>
                    <label class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">{{ __('Google Analytics (GA4) Property') }}</label>
                    @if (count($gaOptions))
                        <select wire:model="gaSelection"
                            class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm transition focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800">
                            <option value="">{{ __('Skip Analytics for now') }}</option>
                            @foreach ($gaOptions as $opt)
                                <option value="{{ $opt['account_id'] }}|{{ $opt['id'] }}">{{ $opt['name'] }} ({{ $opt['id'] }}) — {{ $opt['account_label'] }}</option>
                            @endforeach
                        </select>
                    @else
                        <p class="rounded-md border border-dashed border-slate-200 bg-slate-50/60 px-3 py-2 text-[11px] text-slate-500 dark:border-slate-700 dark:bg-slate-800/40 dark:text-slate-400">
                            {{ __('No Analytics properties found on your connected account(s). If your GA is on a different Google login, connect it below.') }}
                        </p>
                    @endif
                    @error('gaSelection') <p class="mt-0.5 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                {{-- Search Console --}}
                <div>
                    <label class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">{{ __('Search Console Site') }}</label>
                    @if (count($gscOptions))
                        <select wire:model.live="gscSelection"
                            class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm transition focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800">
                            <option value="">{{ __('Skip Search Console for now') }}</option>
                            @foreach ($gscOptions as $opt)
                                <option value="{{ $opt['account_id'] }}|{{ $opt['siteUrl'] }}">{{ $opt['siteUrl'] }} — {{ $opt['account_label'] }}</option>
                            @endforeach
                        </select>
                    @else
                        <p class="rounded-md border border-dashed border-slate-200 bg-slate-50/60 px-3 py-2 text-[11px] text-slate-500 dark:border-slate-700 dark:bg-slate-800/40 dark:text-slate-400">
                            {{ __('No Search Console sites found on your connected account(s). If your GSC is on a different Google login, connect it below.') }}
                        </p>
                    @endif
                    @error('gscSelection') <p class="mt-0.5 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                {{-- Connect another account --}}
                <button type="button" wire:click="connectAnotherAccount"
                    class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-orange-600 transition hover:text-orange-700 dark:text-orange-400">
                    <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    {{ __('Connect another Google account') }}
                </button>

                {{-- Domain --}}
                <div>
                    <label class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">{{ __('Domain') }}</label>
                    <input wire:model="domain" type="text" placeholder="example.com"
                        class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm transition placeholder:text-slate-400 focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800" />
                    <p class="mt-0.5 text-[11px] text-slate-400">{{ __('Auto-filled from your Search Console selection.') }}</p>
                    @error('domain') <p class="mt-0.5 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center justify-between border-t border-slate-100 pt-3 dark:border-slate-800">
                    <button type="button" wire:click="skipForNow"
                        class="inline-flex items-center gap-1 text-xs font-medium text-slate-500 transition hover:text-slate-700 dark:hover:text-slate-300">
                        {{ __('Skip for now') }}
                    </button>
                    <button type="submit"
                        class="inline-flex h-8 items-center gap-1.5 rounded-md bg-orange-600 px-3 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-700">
                        {{ __('Finish Setup') }}
                        <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                    </button>
                </div>
            </form>
        </div>
    @endif
</div>
