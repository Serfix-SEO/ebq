<div class="mx-auto max-w-3xl">
    @if (session('error'))
        <div class="mb-4 rounded-xl border border-error/25 bg-white p-3 text-sm font-medium text-error dark:bg-slate-900">{{ session('error') }}</div>
    @endif

    <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-lg shadow-orange-600/25">
            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
        </span>
        @if ($state === 'no_website')
            <h1 class="mt-5 text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ __('Let’s set up your website') }}</h1>
            <p class="mx-auto mt-2 max-w-md text-sm text-slate-500 dark:text-slate-400">
                {{ __('We write articles about your business and publish them on your website — so more people find you on Google.') }}
            </p>
        @else
            <h1 class="mt-5 text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ __('Content Autopilot') }}</h1>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                {{ __('An expert SEO article for :site — written, optimized, illustrated and published for you, on autopilot.', ['site' => $website?->domain ?? __('your website')]) }}
            </p>
        @endif

        @if ($state === 'no_website')
            {{-- Nothing to activate ON. Every other CTA here operates on a
                 website, so without one the button did nothing at all. --}}
            @if ($freeSlots > 0)
                <div class="mx-auto mt-6 max-w-md rounded-xl bg-slate-50 p-4 text-sm text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
                    {{ trans_choice('{1}You have 1 free website slot ready — add a website to use it.|[2,*]You have :count free website slots ready — add a website to use them.', $freeSlots, ['count' => $freeSlots]) }}
                </div>
            @else
                {{-- What happens after the form, in plain words — the form
                     itself already says "enter your website". --}}
                <div class="mx-auto mt-6 flex max-w-md flex-col gap-2 text-start text-sm text-slate-600 dark:text-slate-300 sm:flex-row sm:justify-center sm:gap-6">
                    <span class="inline-flex items-center gap-2"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-orange-100 text-[11px] font-bold text-orange-700 dark:bg-orange-950 dark:text-orange-300">1</span>{{ __('Enter your website') }}</span>
                    <span class="inline-flex items-center gap-2"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-orange-100 text-[11px] font-bold text-orange-700 dark:bg-orange-950 dark:text-orange-300">2</span>{{ __('Answer a few questions') }}</span>
                    <span class="inline-flex items-center gap-2"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-orange-100 text-[11px] font-bold text-orange-700 dark:bg-orange-950 dark:text-orange-300">3</span>{{ __('We start writing') }}</span>
                </div>
            @endif
            @if (config('features.seo_platform_ui'))
                <a href="{{ route('websites.index') }}" wire:navigate
                    class="mt-6 inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-8 py-3.5 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                    {{ __('Add your website') }}
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                </a>
            @else
                {{-- Content-only mode: enter the domain HERE and go straight into
                     the onboarding wizard. The old link led to /websites, which
                     EnsureOnboarded bounced back to this very page — a closed
                     loop where a no-website user could not add a website at all
                     (found 2026-08-08). Plain POST, same target as the landing
                     hero; reCAPTCHA is waived for signed-in users. --}}
                <form method="POST" action="{{ route('content.onboarding.begin') }}" class="mx-auto mt-6 max-w-md text-start">
                    @csrf
                    <div class="flex flex-col gap-2.5 rounded-2xl border border-slate-200 bg-white p-2 shadow-xl shadow-slate-900/5 sm:flex-row sm:items-center sm:rounded-full sm:p-2 dark:border-slate-700 dark:bg-slate-900">
                        <div class="flex flex-1 items-center gap-2.5 ps-3">
                            <svg class="h-5 w-5 flex-none text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.5-2.5 3.75-5.75 3.75-9S14.5 5.5 12 3m0 18c-2.5-2.5-3.75-5.75-3.75-9S9.5 5.5 12 3M3.6 9h16.8M3.6 15h16.8" /></svg>
                            <input type="text" name="domain" value="{{ old('domain') }}" placeholder="yourwebsite.com" aria-label="{{ __('Your website') }}"
                                required inputmode="url" autocomplete="url"
                                class="w-full border-0 bg-transparent py-3 text-[15px] text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-0 dark:text-slate-100" />
                        </div>
                        <button type="submit" class="inline-flex items-center justify-center gap-1.5 rounded-full bg-gradient-to-r from-orange-500 to-orange-600 px-7 py-3.5 text-sm font-bold text-white shadow-lg shadow-orange-600/30 transition hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-orange-500/40">
                            {{ __('Get started') }}
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                        </button>
                    </div>
                    @error('domain') <p class="mt-2.5 text-sm font-medium text-error">{{ $message }}</p> @enderror
                    <p class="mt-2.5 text-center text-xs text-slate-400 dark:text-slate-500">
                        {{ __('Takes about 2 minutes · Free trial, no card needed') }}
                    </p>
                </form>
            @endif

        @elseif ($state === 'trial')
            <div class="mx-auto mt-6 max-w-md rounded-xl bg-slate-50 p-4 text-sm text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
                {{ __(':days-day free trial · :n free articles · no card required', ['days' => $trialDays, 'n' => $trialArticles]) }}
            </div>
            <button wire:click="startTrial" wire:loading.attr="disabled"
                class="mt-6 inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-8 py-3.5 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                {{ __('Start free trial') }}
            </button>
            <p class="mt-4 text-xs text-slate-400">
                {{ __('After the trial: :first for your first month, then $:m/mo — or $:a/mo billed yearly.', ['first' => '$'.$prices['first_month'], 'm' => $prices['monthly'], 'a' => $prices['annual']]) }}
            </p>

        @elseif ($state === 'pricing')
            <div class="mt-8 grid gap-4 sm:grid-cols-2">
                <div class="rounded-2xl border-2 border-orange-300 bg-orange-50/40 p-6 text-start dark:border-orange-900 dark:bg-orange-950/30">
                    <div class="inline-flex rounded-full bg-orange-600 px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wide text-white">{{ __('$:p first month', ['p' => $prices['first_month']]) }}</div>
                    <div class="mt-3 text-3xl font-extrabold text-slate-900 dark:text-slate-100">${{ $prices['monthly'] }}<span class="text-base font-medium text-slate-500">/{{ __('mo') }}</span></div>
                    <div class="text-xs text-slate-500 dark:text-slate-400">{{ __('Billed monthly') }}</div>
                    <a href="{{ route('content.billing.checkout', ['interval' => 'monthly', 'website' => $website?->id]) }}"
                        class="mt-4 block rounded-xl bg-orange-600 px-4 py-2.5 text-center text-sm font-bold text-white hover:bg-orange-700">{{ __('Choose monthly') }}</a>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-6 text-start dark:border-slate-700 dark:bg-slate-900">
                    <div class="inline-flex rounded-full bg-success/15 px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wide text-success">{{ __('Best value') }}</div>
                    <div class="mt-3 text-3xl font-extrabold text-slate-900 dark:text-slate-100">${{ $prices['annual'] }}<span class="text-base font-medium text-slate-500">/{{ __('mo') }}</span></div>
                    <div class="text-xs text-slate-500 dark:text-slate-400">{{ __('Billed yearly') }}</div>
                    <a href="{{ route('content.billing.checkout', ['interval' => 'annual', 'website' => $website?->id]) }}"
                        class="mt-4 block rounded-xl border border-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800">{{ __('Choose annual') }}</a>
                </div>
            </div>
            <p class="mt-4 text-xs text-slate-400">{{ __('Each additional website: $:m/mo (or $:a/mo billed yearly).', ['m' => $prices['addon_monthly'], 'a' => $prices['addon_annual']]) }}</p>

        @elseif ($state === 'activate')
            {{-- Source-neutral: the slot may come from a subscription OR from
                 free sites granted by an operator, and the client-facing copy
                 must not claim a subscription they don't have. --}}
            <div class="mx-auto mt-6 max-w-md rounded-xl bg-success/10 p-4 text-sm text-success">
                {{ trans_choice('{1}You have 1 free website slot available.|[2,*]You have :count free website slots available.', $freeSlots, ['count' => $freeSlots]) }}
            </div>
            <button wire:click="activate" wire:loading.attr="disabled"
                class="mt-6 inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-8 py-3.5 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                {{ __('Activate on :site', ['site' => $website?->domain ?? __('this website')]) }}
            </button>

        @else {{-- add_website --}}
            <div class="mx-auto mt-6 max-w-md rounded-xl bg-slate-50 p-4 text-sm text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
                {{ __('All your website slots are in use. Add :site to your content plan.', ['site' => $website?->domain ?? __('this website')]) }}
            </div>
            <form method="POST" action="{{ route('content.billing.add-website') }}" class="mt-6">
                @csrf
                <input type="hidden" name="website" value="{{ $website?->id }}" />
                <button type="submit"
                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-8 py-3.5 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                    {{ __('Add this website — $:m/mo', ['m' => $prices['addon_monthly']]) }}
                </button>
            </form>
        @endif
    </div>
</div>
