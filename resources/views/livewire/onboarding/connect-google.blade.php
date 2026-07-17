{{-- Domain-first onboarding (2026-07-17 redesign, full-width shell): step 1
     asks for the ONE thing every user has (their domain, usually pre-filled
     from the funnel) and starts analysis immediately; step 2 offers Google as
     an optional, value-framed booster with an always-visible dashboard exit.
     Rendered inside layouts.onboarding (logo topbar owns the logout button). --}}
<div class="mx-auto max-w-2xl">
    {{-- Heading --}}
    <div class="text-center">
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">
            {{ $step === 1 ? __('Welcome to Serfix') : __('One more thing (optional)') }}
        </h1>
        <p class="mx-auto mt-2 max-w-md text-sm text-slate-600">
            {{ $step === 1 ? __('Two quick steps and your SEO dashboard is ready.') : __('Connect Google to unlock your real search data — or skip straight to your dashboard.') }}
        </p>
    </div>

    {{-- Step indicator --}}
    <div class="mx-auto mt-8 flex max-w-md items-center gap-3">
        {{-- Completed step 1 is clickable — standard wizard affordance to go
             back and change the domain. --}}
        <button type="button" @if ($step > 1) wire:click="changeDomain" @else disabled @endif
            class="flex items-center gap-2.5 {{ $step > 1 ? 'cursor-pointer' : '' }}">
            <span @class([
                'flex h-9 w-9 items-center justify-center rounded-full text-sm font-bold shadow-sm',
                'bg-orange-600 text-white' => $step === 1,
                'bg-emerald-500 text-white' => $step > 1,
            ])>
                @if ($step > 1)
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                @else
                    1
                @endif
            </span>
            <span class="whitespace-nowrap text-sm font-semibold {{ $step === 1 ? 'text-slate-900' : 'text-emerald-600 hover:underline underline-offset-4' }}">{{ __('Your website') }}</span>
        </button>
        <div class="h-px flex-1 {{ $step > 1 ? 'bg-emerald-400' : 'bg-slate-200' }}"></div>
        <div class="flex items-center gap-2.5">
            <span @class([
                'flex h-9 w-9 items-center justify-center rounded-full text-sm font-bold',
                'bg-orange-600 text-white shadow-sm' => $step === 2,
                'bg-slate-200 text-slate-500' => $step !== 2,
            ])>2</span>
            <span class="whitespace-nowrap text-sm font-semibold {{ $step === 2 ? 'text-slate-900' : 'text-slate-500' }}">{{ __('Google data') }} <span class="font-normal text-slate-400">({{ __('optional') }})</span></span>
        </div>
    </div>

    @if ($step === 1)
        {{-- ── Step 1: the domain — the only required thing ─────────── --}}
        <div class="mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-md sm:p-8">
            <h2 class="text-lg font-bold text-slate-900">{{ __('Add your website') }}</h2>
            <p class="mt-1 text-sm text-slate-500">
                {{ __('Enter your website address — analysis starts right away.') }}
            </p>

            <form wire:submit="addWebsite" class="mt-6">
                <label for="onb-domain" class="mb-1.5 block text-sm font-medium text-slate-700">{{ __('Website address') }}</label>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <div class="relative w-full">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2">
                            <svg class="h-5 w-5 text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a9.004 9.004 0 018.716 6.747M12 3a9.004 9.004 0 00-8.716 6.747M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </span>
                        <input id="onb-domain" wire:model="domain" type="text" placeholder="example.com" autofocus
                            class="h-12 w-full rounded-xl border border-slate-200 bg-white pl-8 pr-3 text-base shadow-sm transition placeholder:text-slate-400 focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20" />
                    </div>
                    <button type="submit"
                        class="inline-flex h-12 shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-orange-600 px-6 text-sm font-semibold text-white shadow-sm transition hover:bg-orange-700">
                        {{ __('Start analyzing') }}
                        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
                    </button>
                </div>
                @error('domain') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
            </form>

            <div class="mt-7 grid gap-3 border-t border-slate-100 pt-6 sm:grid-cols-3">
                @foreach ([
                    ['M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z', __('Full site audit'), __('We crawl your pages and list what to fix')],
                    ['M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244', __('Backlink report'), __('With Trust & Citation scores')],
                    ['M15.75 15.75l-2.489-2.489m0 0a3.375 3.375 0 10-4.773-4.773 3.375 3.375 0 004.774 4.774zM21 12a9 9 0 11-18 0 9 9 0 0118 0z', __('Keyword tools'), __('Research and rank tracking')],
                ] as [$icon, $title, $sub])
                    <div class="rounded-xl border border-slate-100 bg-slate-50 p-4">
                        <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-orange-100">
                            <svg class="h-5 w-5 text-orange-700" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}" /></svg>
                        </div>
                        <p class="mt-2.5 text-sm font-semibold text-slate-800">{{ $title }}</p>
                        <p class="mt-0.5 text-sm text-slate-500">{{ $sub }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    @else
        {{-- ── Step 2: Google — optional booster, clearly skippable ── --}}
        <div class="mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-md sm:p-8">
            {{-- The site just added — favicon chip so users see THEIR site is in --}}
            @if ($domain !== '')
                <div class="mb-5 flex items-center gap-3 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3">
                    <img src="https://www.google.com/s2/favicons?domain={{ urlencode($domain) }}&sz=64" alt=""
                        width="28" height="28" class="h-7 w-7 flex-none rounded-lg bg-white ring-1 ring-slate-200"
                        loading="lazy" onerror="this.style.visibility='hidden'">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-slate-900">{{ $domain }}</p>
                        <p class="text-xs text-emerald-700">{{ __('Added — analysis is running') }}</p>
                    </div>
                    <button type="button" wire:click="changeDomain"
                        class="ml-auto inline-flex h-8 flex-none items-center gap-1 whitespace-nowrap rounded-lg border border-emerald-200 bg-white px-2.5 text-xs font-semibold text-slate-600 shadow-sm transition hover:bg-slate-50 hover:text-slate-900">
                        <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487z" /></svg>
                        {{ __('Change') }}
                    </button>
                </div>
            @endif

            <div class="flex items-center gap-2">
                <h2 class="text-lg font-bold text-slate-900">{{ __('Connect Google') }}</h2>
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">{{ __('Optional') }}</span>
            </div>
            <p class="mt-1 text-sm text-slate-500">
                {{ __('This unlocks data only Google has about :domain:', ['domain' => $domain !== '' ? $domain : __('your site')]) }}
            </p>

            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl border border-slate-100 bg-slate-50 p-4">
                    <p class="text-sm font-semibold text-slate-800">{{ __('Search Console') }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ __('The exact keywords people type into Google to find you — with clicks and positions.') }}</p>
                </div>
                <div class="rounded-xl border border-slate-100 bg-slate-50 p-4">
                    <p class="text-sm font-semibold text-slate-800">{{ __('Google Analytics') }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ __('Your real visitor numbers, so every insight is measured against actual traffic.') }}</p>
                </div>
            </div>

            @if (! $googleConnected)
                <div class="mt-6 flex flex-col items-center gap-3 border-t border-slate-100 pt-6 text-center">
                    <a href="{{ route('google.redirect', ['return' => 'onboarding']) }}"
                        class="inline-flex h-12 items-center gap-2.5 whitespace-nowrap rounded-xl bg-slate-900 px-6 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
                        {{ __('Connect with Google') }}
                    </a>
                    <p class="text-xs text-slate-400">{{ __('Read-only access. We can never change anything in your account.') }}</p>
                    <button type="button" wire:click="skipForNow"
                        class="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-600 underline-offset-4 transition hover:text-slate-900 hover:underline">
                        {{ __('Skip — take me to my dashboard') }}
                        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
                    </button>
                    <p class="text-xs text-slate-400">{{ __('You can connect Google anytime later in Settings.') }}</p>
                </div>
            @else
                <div class="mt-4 inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700">
                    <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    {{ __('Google account connected') }}
                </div>

                @if ($fetchError)
                    <div class="mt-3 flex items-center gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
                        <svg class="h-4 w-4 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                        {{ $fetchError }}
                    </div>
                @endif

                <form wire:submit="saveWebsite" class="mt-5 space-y-4">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">{{ __('Your site in Search Console') }}</label>
                        @if (count($gscOptions))
                            <select wire:model="gscSelection"
                                class="h-11 w-full truncate rounded-xl border border-slate-200 bg-white pl-3 pr-8 text-sm shadow-sm transition focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20">
                                <option value="">{{ __('Don’t connect Search Console') }}</option>
                                @foreach ($gscOptions as $opt)
                                    <option value="{{ $opt['account_id'] }}|{{ $opt['siteUrl'] }}">{{ $opt['label'] ?? $opt['siteUrl'] }}</option>
                                @endforeach
                            </select>
                        @else
                            <p class="rounded-xl border border-dashed border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-500">
                                {{ __('Nothing found on this Google account. If your Search Console lives on another login, add it below.') }}
                            </p>
                        @endif
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">{{ __('Your Google Analytics property') }}</label>
                        @if (count($gaOptions))
                            <select wire:model="gaSelection"
                                class="h-11 w-full truncate rounded-xl border border-slate-200 bg-white pl-3 pr-8 text-sm shadow-sm transition focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20">
                                <option value="">{{ __('Don’t connect Analytics') }}</option>
                                @foreach ($gaOptions as $opt)
                                    <option value="{{ $opt['account_id'] }}|{{ $opt['id'] }}">{{ $opt['label'] ?? $opt['name'] }}</option>
                                @endforeach
                            </select>
                        @else
                            <p class="rounded-xl border border-dashed border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-500">
                                {{ __('Nothing found on this Google account. If your Analytics lives on another login, add it below.') }}
                            </p>
                        @endif
                    </div>

                    <button type="button" wire:click="connectAnotherAccount"
                        class="inline-flex items-center gap-1.5 text-sm font-semibold text-orange-600 transition hover:text-orange-700">
                        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        {{ __('Add another Google account') }}
                    </button>

                    <div class="flex items-center justify-between gap-3 border-t border-slate-100 pt-4">
                        <button type="button" wire:click="skipForNow"
                            class="whitespace-nowrap text-sm font-medium text-slate-500 transition hover:text-slate-700">
                            {{ __('Skip for now') }}
                        </button>
                        <button type="submit"
                            class="inline-flex h-11 items-center gap-2 whitespace-nowrap rounded-xl bg-orange-600 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-orange-700">
                            {{ __('Save & finish') }}
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                        </button>
                    </div>
                </form>
            @endif
        </div>
    @endif
</div>
