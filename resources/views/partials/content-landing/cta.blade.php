{{-- Closing CTA.

     It scrolls to the hero form rather than posting its own. reCAPTCHA is
     LIVE in production and the controller requires a token from guests: a
     second form would either need a second widget (Google renders them in
     document order, the duplicate silently fails to bind, and a failed submit
     re-renders the red error on BOTH forms) or would post without one and
     hard-fail every guest signup. One form, one widget, one error surface —
     and the visitor lands on a focused input either way. --}}
<section class="bg-white">
    <div class="mx-auto max-w-5xl px-6 py-16 lg:px-8 lg:py-20">
        <div class="overflow-hidden rounded-3xl border border-orange-200 bg-gradient-to-br from-orange-50 via-white to-white px-8 py-14 shadow-sm">
            <div class="grid items-center gap-8 lg:grid-cols-2">
                <div>
                    <h2 class="text-balance text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">{{ __('Ready to take content SEO off your weekly to-do list?') }}</h2>
                    <p class="mt-4 max-w-lg text-base leading-7 text-slate-600">{{ __('See your competitors, your keyword gaps and a month of planned topics — before you pay anything.') }}</p>
                </div>

                <div>
                    <a href="#start"
                       class="flex items-center gap-2 rounded-full border border-slate-200 bg-white p-2 shadow-xl shadow-slate-900/5 transition hover:border-orange-300">
                        <span class="flex flex-1 items-center gap-2.5 ps-3 text-[15px] text-slate-400">
                            <svg class="h-5 w-5 flex-none text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.5-2.5 3.75-5.75 3.75-9S14.5 5.5 12 3m0 18c-2.5-2.5-3.75-5.75-3.75-9S9.5 5.5 12 3M3.6 9h16.8M3.6 15h16.8" /></svg>
                            {{ __('Enter your website') }}
                        </span>
                        <span class="inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-full bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3.5 text-sm font-bold text-white shadow-lg shadow-orange-600/30">
                            {{ __('Analyze My Website') }}
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                        </span>
                    </a>
                    <p class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-slate-500">
                        @foreach ([
                            __(':days-day free trial', ['days' => $trialDays]),
                            __('No card required'),
                            __('You approve everything'),
                        ] as $point)
                            <span class="inline-flex items-center gap-1.5">
                                <svg class="h-3.5 w-3.5 flex-none text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>{{ $point }}
                            </span>
                        @endforeach
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>
