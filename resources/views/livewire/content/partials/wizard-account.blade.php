{{-- Final onboarding step: collect account details, then convert the session. --}}
@php $recaptcha = \App\Support\Recaptcha::isEnabled(); @endphp
<div class="mx-auto max-w-lg">
    <div class="text-center">
        <span class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-lg shadow-orange-600/25">
            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.5 20.25a8.25 8.25 0 0115 0"/></svg>
        </span>
        <p class="text-xs font-bold uppercase tracking-wide text-orange-600 dark:text-orange-400">{{ __('Last step') }}</p>
        <h2 class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ __('Create your account') }}</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Start your :days-day free trial — no card required. Already have an account? Sign in below and we\'ll attach this website to it.', ['days' => \App\Support\ContentAutopilotConfig::trialDays()]) }}</p>
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
            <input type="text" wire:model="phone" class="mt-1 w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
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
        @if ($recaptcha)
            <div wire:ignore class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.site_key') }}" data-callback="onContentCaptcha"></div>
        @endif
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
    </form>
</div>

@if ($recaptcha)
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <script>window.onContentCaptcha = (t) => { @this.set('recaptchaToken', t); };</script>
@endif
