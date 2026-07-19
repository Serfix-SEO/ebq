{{-- Final onboarding step: collect account details, then convert the session. --}}
<div class="mx-auto max-w-lg">
    <div class="text-center">
        <span class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-lg shadow-orange-600/30">
            <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </span>
        <p class="text-xs font-bold uppercase tracking-[0.15em] text-orange-600 dark:text-orange-400">{{ __('Last step') }}</p>
        <h2 class="mt-1 text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100 sm:text-3xl">{{ __('Save your content plan') }}</h2>
        <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500 dark:text-slate-400">
            @if (filled($domain ?? ''))
                {{ __('Your plan for :domain is ready.', ['domain' => $domain]) }}
            @endif
            {{ __('Create a free account to save it and start publishing. Already have one? Sign in and we\'ll attach this website to it.') }}
        </p>

        {{-- Reassurance chips --}}
        <div class="mt-4 flex flex-wrap items-center justify-center gap-2">
            @foreach ([
                __(':days-day free trial', ['days' => \App\Support\ContentAutopilotConfig::trialDays()]),
                __('No card required'),
                __('You approve everything'),
            ] as $chip)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                    <svg class="h-3.5 w-3.5 flex-none text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>{{ $chip }}
                </span>
            @endforeach
        </div>
    </div>

    {{-- Continue with Google (new users sign up, existing users log in) --}}
    <a href="{{ route('content.onboarding.google') }}"
       class="mt-7 inline-flex w-full items-center justify-center gap-2.5 rounded-xl border border-slate-300 bg-white px-6 py-3 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
        <svg class="h-5 w-5" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0012 23z"/><path fill="#FBBC05" d="M5.84 14.1a6.6 6.6 0 010-4.2V7.06H2.18a11 11 0 000 9.88l3.66-2.84z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84C6.71 7.31 9.14 5.38 12 5.38z"/></svg>
        {{ __('Continue with Google') }}
    </a>

    <div class="my-5 flex items-center gap-3 text-xs font-medium text-slate-400">
        <span class="h-px flex-1 bg-slate-200 dark:bg-slate-700"></span>{{ __('or with email') }}<span class="h-px flex-1 bg-slate-200 dark:bg-slate-700"></span>
    </div>

    <form wire:submit="createAccount" class="space-y-4 text-start">
        <div>
            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300">{{ __('Full name') }}</label>
            <input type="text" wire:model="name" class="mt-1 w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
            @error('name') <p class="mt-1.5 text-xs font-medium text-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300">{{ __('Email') }}</label>
            <input type="email" wire:model="email" class="mt-1 w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
            @error('email') <p class="mt-1.5 text-xs font-medium text-error">{{ $message }} <a href="{{ route('login') }}" class="underline">{{ __('Log in') }}</a></p> @enderror
        </div>
        <div>
            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300">{{ __('Phone') }} <span class="font-normal text-slate-400">({{ __('optional') }})</span></label>
            @php $dialCodes = \App\Support\DialCodes::all(); @endphp
            <div class="mt-1 flex gap-2" x-data="{ open: false, q: '' }" @click.outside="open = false">
                {{-- Searchable country dial-code picker (bound to $wire.dialCode) --}}
                <div class="relative w-28 flex-none">
                    <button type="button" @click="open = ! open"
                        class="flex w-full items-center justify-between gap-1 rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                        <span x-text="$wire.dialCode || '+1'"></span>
                        <svg class="h-4 w-4 flex-none text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                    </button>
                    <div x-show="open" x-cloak x-transition.opacity
                        class="absolute z-30 mt-1 w-64 rounded-xl border border-slate-200 bg-white shadow-xl dark:border-slate-700 dark:bg-slate-900">
                        <input type="text" x-model="q" placeholder="{{ __('Search country') }}" autocomplete="off"
                            class="w-full rounded-t-xl border-b border-slate-200 px-3 py-2 text-sm focus:outline-none dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                        <ul class="max-h-56 overflow-auto py-1">
                            @foreach ($dialCodes as $c)
                                <li x-show="q === '' || '{{ strtolower($c['name']).' '.$c['dial'] }}'.includes(q.toLowerCase())">
                                    <button type="button" @click="$wire.set('dialCode', '{{ $c['dial'] }}'); open = false; q = ''"
                                        class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm hover:bg-slate-50 dark:hover:bg-slate-800">
                                        <span class="flex-none">{{ $c['flag'] }}</span>
                                        <span class="flex-1 truncate text-slate-700 dark:text-slate-200">{{ $c['name'] }}</span>
                                        <span class="flex-none text-slate-400">{{ $c['dial'] }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
                <input type="tel" wire:model="phone" inputmode="tel" autocomplete="tel-national" placeholder="{{ __('Phone number') }}"
                    class="w-full flex-1 rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
            </div>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300">{{ __('Password') }}</label>
                <input type="password" wire:model="password" autocomplete="new-password" class="mt-1 w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                @error('password') <p class="mt-1.5 text-xs font-medium text-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300">{{ __('Confirm password') }}</label>
                <input type="password" wire:model="password_confirmation" autocomplete="new-password" class="mt-1 w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
            </div>
        </div>
        <div class="flex items-center justify-between gap-3 pt-1">
            <button type="button" wire:click="goToStep(7)" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                {{ __('Back') }}
            </button>
            <button type="submit" wire:loading.attr="disabled" wire:target="createAccount" class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110 disabled:opacity-70">
                <svg wire:loading wire:target="createAccount" class="-ms-1 me-1 h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                <span wire:loading.remove wire:target="createAccount">{{ __('Get started') }}</span>
                <span wire:loading wire:target="createAccount">{{ __('Creating your account…') }}</span>
            </button>
        </div>
        <p class="pt-1 text-center text-xs leading-5 text-slate-400">{{ __('No credit card now. Your :days-day trial starts when you finish — cancel anytime.', ['days' => \App\Support\ContentAutopilotConfig::trialDays()]) }}</p>
    </form>
</div>
