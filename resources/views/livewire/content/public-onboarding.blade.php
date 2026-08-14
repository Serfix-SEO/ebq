{{-- Full wizard (business → … → first articles → account). Normally entered
     with a captured domain; without one, guests get the domain step below
     instead of a bounce to the homepage that read as "nothing happened". --}}
<div>
    @if ($capturingDomain)
        @php $recaptcha = \App\Support\Recaptcha::isEnabled() && ! auth()->check(); @endphp
        <div class="mx-auto max-w-3xl px-4 py-6 sm:px-6 sm:py-10">
            <div class="rounded-3xl border border-slate-200 bg-white p-6 text-center shadow-sm sm:p-14">
                <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-lg shadow-orange-600/25 sm:h-14 sm:w-14">
                    <svg class="h-6 w-6 sm:h-7 sm:w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                </span>
                <h1 class="mt-5 text-2xl font-extrabold tracking-tight text-slate-900 sm:mt-6 sm:text-3xl">{{ __('Let’s set up your website') }}</h1>
                <p class="mx-auto mt-3 max-w-lg text-sm leading-6 text-slate-500 sm:text-base">
                    {{ __('We write articles about your business and publish them on your website — so more people find you on Google.') }}
                </p>

                <div class="mx-auto mt-6 flex max-w-lg flex-col gap-2.5 text-start text-sm text-slate-600 sm:mt-8 sm:flex-row sm:justify-center sm:gap-8">
                    <span class="inline-flex items-center gap-2"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-orange-100 text-[11px] font-bold text-orange-700">1</span>{{ __('Enter your website') }}</span>
                    <span class="inline-flex items-center gap-2"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-orange-100 text-[11px] font-bold text-orange-700">2</span>{{ __('Answer a few questions') }}</span>
                    <span class="inline-flex items-center gap-2"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-orange-100 text-[11px] font-bold text-orange-700">3</span>{{ __('We start writing') }}</span>
                </div>

                {{-- Plain POST, zero Livewire interactions in this subtree: the
                     reCAPTCHA widget must never be DOM-diffed away, and every
                     controller error path returns via full redirect with
                     $errors + old('domain') intact. --}}
                <form method="POST" action="{{ route('content.onboarding.begin') }}" class="mx-auto mt-6 max-w-xl text-start sm:mt-8">
                    @csrf
                    <div class="flex flex-col gap-2.5 rounded-2xl border border-slate-200 bg-white p-2 shadow-xl shadow-slate-900/5 sm:flex-row sm:items-center sm:rounded-full sm:p-2">
                        <div class="flex flex-1 items-center gap-2.5 ps-3">
                            <svg class="h-5 w-5 flex-none text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.5-2.5 3.75-5.75 3.75-9S14.5 5.5 12 3m0 18c-2.5-2.5-3.75-5.75-3.75-9S9.5 5.5 12 3M3.6 9h16.8M3.6 15h16.8" /></svg>
                            <input type="text" name="domain" value="{{ old('domain') }}" placeholder="yourwebsite.com" aria-label="{{ __('Your website') }}"
                                required inputmode="url" autocomplete="url"
                                class="w-full border-0 bg-transparent py-3 text-[15px] text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-0" />
                        </div>
                        <button type="submit" class="inline-flex items-center justify-center gap-1.5 rounded-full bg-gradient-to-r from-orange-500 to-orange-600 px-7 py-3.5 text-sm font-bold text-white shadow-lg shadow-orange-600/30 transition hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-orange-500/40">
                            {{ __('Get started') }}
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                        </button>
                    </div>
                    @error('domain') <p class="mt-2.5 text-sm font-medium text-error">{{ $message }}</p> @enderror
                    @error('g-recaptcha-response') <p class="mt-2.5 text-sm font-medium text-error">{{ $message }}</p> @enderror
                    @if ($recaptcha)
                        <div class="mt-4 flex justify-center"><div class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.site_key') }}"></div></div>
                    @endif
                    <p class="mt-2.5 text-center text-xs text-slate-400">
                        {{ __('Takes about 2 minutes · Free trial, no card needed') }}
                    </p>
                </form>

                @guest
                    {{-- Existing accounts should not have to walk the wizard to
                         step 8 just to sign in. Google creates-or-logs-in; a
                         website-less account lands back on its own domain form. --}}
                    <div class="mt-6 flex flex-wrap items-center justify-center gap-x-4 gap-y-2 border-t border-slate-100 pt-5 text-sm text-slate-500 sm:mt-8 sm:pt-6">
                        <span>{{ __('Already have an account?') }}</span>
                        <a href="{{ route('login') }}" class="font-semibold text-slate-900 underline-offset-2 hover:underline">{{ __('Sign in') }}</a>
                        <a href="{{ route('google.sso.redirect', ['intent' => 'register']) }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-1.5 font-semibold text-slate-700 hover:bg-slate-50">
                            <svg class="h-4 w-4" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.27-4.74 3.27-8.1z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.1c-.22-.66-.35-1.36-.35-2.1s.13-1.44.35-2.1V7.06H2.18A10.96 10.96 0 001 12c0 1.77.43 3.45 1.18 4.94l3.66-2.84z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/></svg>
                            {{ __('Continue with Google') }}
                        </a>
                    </div>
                @endguest
            </div>
        </div>
        @if ($recaptcha)
            <script src="https://www.google.com/recaptcha/api.js" async defer></script>
        @endif
    @else
        @include('livewire.content.partials.wizard')
    @endif
</div>
