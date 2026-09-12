{{-- Hero — the pitch, the domain capture, and the real product.

     The screenshot is the REAL content calendar rendered against invented
     sample data (MarketingShotsTest), never a mockup: a visitor deciding
     whether this is a toy needs to see the actual screen. Owner directive
     2026-09-12: keep this image. --}}
<section id="start" class="relative overflow-hidden border-b border-slate-200 bg-gradient-to-b from-orange-50/70 via-white to-white">
    <div class="pointer-events-none absolute -top-32 start-1/2 h-72 w-[42rem] -translate-x-1/2 rounded-full bg-gradient-to-r from-orange-300 to-amber-200 opacity-40 blur-3xl"></div>

    <div class="mx-auto max-w-6xl px-6 pb-14 pt-10 lg:px-8 lg:pb-20 lg:pt-16">
        <div class="grid items-center gap-12 lg:grid-cols-2">
            {{-- Left: the pitch + domain capture --}}
            <div class="text-center lg:text-start">
                <span class="inline-flex items-center gap-1.5 rounded-full border border-orange-200 bg-white/70 px-3.5 py-1 text-[11px] font-bold uppercase tracking-[0.18em] text-orange-600 shadow-sm backdrop-blur">
                    <span class="h-1.5 w-1.5 rounded-full bg-orange-500"></span>{{ __('SEO content autopilot') }}
                </span>
                <h1 class="mt-5 text-balance text-4xl font-extrabold tracking-tight text-slate-900 sm:text-5xl lg:text-[3.4rem] lg:leading-[1.05]">
                    {{ __('Keep your content SEO moving') }}
                    <span class="bg-gradient-to-r from-orange-500 to-orange-600 bg-clip-text text-transparent">{{ __('without managing it every day') }}</span>
                </h1>
                <p class="mt-5 max-w-xl text-balance text-lg leading-8 text-slate-600 lg:mx-0">
                    {{ __('Serfix researches what your customers are searching for, builds your content strategy, writes and optimizes the articles, and publishes them to your website.') }}
                </p>

                @include('partials.content-landing.domain-form', [
                    'withRecaptcha' => true,
                    'buttonLabel' => __('Analyze My Website'),
                    'formClass' => 'mt-7 max-w-xl',
                ])

                {{-- 2x2 on wide screens: as a single wrapping row the fourth
                     point orphans onto its own line and the block reads as a
                     layout bug. --}}
                <div class="mt-5 flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-sm text-slate-500 sm:mx-auto sm:grid sm:max-w-md sm:grid-cols-2 sm:justify-items-start lg:mx-0">
                    @foreach ([
                        __(':days-day free trial', ['days' => $trialDays]),
                        __(':n free articles', ['n' => $trialArticles]),
                        __('No card required'),
                        __('You approve everything'),
                    ] as $point)
                        <span class="inline-flex items-center gap-1.5">
                            <svg class="h-4 w-4 flex-none text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>{{ $point }}
                        </span>
                    @endforeach
                </div>
            </div>

            {{-- Right: the real content calendar, in a browser frame --}}
            <div class="relative">
                <div class="pointer-events-none absolute -inset-2 sm:-inset-6 rounded-[2rem] bg-gradient-to-tr from-orange-500/20 via-amber-300/10 to-transparent blur-2xl"></div>
                <div class="relative overflow-hidden rounded-2xl border border-slate-800 bg-slate-900 shadow-2xl shadow-slate-900/30">
                    <div class="flex items-center gap-2 border-b border-slate-800 px-4 py-3">
                        <span class="h-2.5 w-2.5 rounded-full bg-slate-600"></span>
                        <span class="h-2.5 w-2.5 rounded-full bg-slate-600"></span>
                        <span class="h-2.5 w-2.5 rounded-full bg-slate-600"></span>
                        <span class="ms-3 truncate rounded-md bg-slate-800 px-3 py-1 text-[11px] font-medium text-slate-400">{{ __('Your content calendar') }}</span>
                    </div>
                    <img src="{{ asset('images/content/calendar.webp') }}"
                         alt="{{ __('The Serfix content calendar: a month of articles planned, written and published') }}"
                         width="1500" height="1120" loading="eager" fetchpriority="high"
                         class="w-full bg-white">
                </div>
            </div>
        </div>
    </div>
</section>
