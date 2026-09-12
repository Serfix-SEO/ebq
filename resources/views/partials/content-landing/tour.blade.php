{{-- Product tour.

     Owner directive 2026-09-12: "this will be our current demo" — the
     interactive walkthrough stays exactly as it is, restyled into the dark
     card the new design calls for.

     DESKTOP ONLY (`hidden … lg:block`, pinned by ContentLandingPageTest). The
     walkthrough is a fixed 2.16 aspect ratio: on a phone it collapses to a
     ~240px pane of someone else's UI that cannot be clicked through usefully.
     Keeping the iframe inside the hidden container also means mobile never
     pays to load the third-party embed (verified: 0 requests at 390px).

     The heading block sits ABOVE the embed rather than beside it, unlike the
     static thumbnail in the design mockup: ours is a live walkthrough people
     actually click through, and a half-width column would shrink it to ~330px
     tall — unusable. Same dark card, same CTA, a screen that still works. --}}
<section id="demo" class="hidden scroll-mt-20 border-b border-slate-800 bg-slate-900 lg:block">
    <div class="mx-auto max-w-6xl px-6 py-16 lg:px-8 lg:py-20">
        <div class="overflow-hidden rounded-3xl border border-slate-700/70 bg-slate-800/40 p-8 lg:p-10">
            <div class="flex flex-wrap items-end justify-between gap-6">
                <div class="max-w-xl">
                    <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-orange-400">{{ __('Product tour') }}</p>
                    <h2 class="mt-3 text-balance text-3xl font-extrabold tracking-tight text-white sm:text-4xl">{{ __('See Serfix in action.') }}</h2>
                    <p class="mt-3 text-base leading-7 text-slate-400">{{ __('Click through the real setup, from your domain to a planned month of articles. No signup, no sales call.') }}</p>
                </div>
                <div class="flex flex-col gap-2">
                    <a href="#demo-embed" class="inline-flex items-center justify-center gap-1.5 rounded-full bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-orange-600/30 transition hover:brightness-110">
                        {{ __('Start interactive demo') }}
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                    </a>
                    <p class="flex items-center gap-1.5 text-xs text-slate-500">
                        <svg class="h-3.5 w-3.5 flex-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" d="M12 7.5V12l2.5 2.5" /></svg>
                        {{ __('Takes less than 2 minutes') }}
                    </p>
                </div>
            </div>

            {{-- Corners are clipped on the WRAPPER (overflow-hidden), not the
                 iframe: an iframe's own border-radius does not clip what the
                 embedded document paints. Aspect ratio and the viewport cap
                 stay inline — they are the embed's sizing contract, not
                 styling.

                 The vendor's snippet also carries legacy webkit/moz fullscreen
                 attributes; both are obsolete (every current browser honours
                 plain `allowfullscreen`), and the `moz` one trips the
                 supplier-name guard in PricingPagesTest. Dropped deliberately
                 — do not paste them back. --}}
            <div id="demo-embed" class="relative mt-10 w-full scroll-mt-24 overflow-hidden rounded-2xl border border-slate-700 bg-slate-900 shadow-2xl shadow-slate-950/50"
                 style="max-height: 80vh; max-height: 80svh; aspect-ratio: 2.16;">
                <iframe src="https://app.supademo.com/embed/cms6473jf32eyqmqqm19mxp9j?embed_v=2&utm_source=embed" loading="lazy" title="{{ __('Set up your content platform on Serfix') }}" allow="clipboard-write" frameborder="0" allowfullscreen class="absolute inset-0 h-full w-full rounded-2xl"></iframe>
            </div>
        </div>
    </div>
</section>

{{-- Phone: the embed is unusable at this width, but a dead end is worse than
     a link — the tour opens in its own tab, where it gets the full screen. --}}
<section class="border-b border-slate-800 bg-slate-900 lg:hidden">
    <div class="mx-auto max-w-6xl px-6 py-14">
        <div class="rounded-3xl border border-slate-700/70 bg-slate-800/40 p-7 text-center">
            <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-orange-400">{{ __('Product tour') }}</p>
            <h2 class="mt-3 text-balance text-2xl font-extrabold tracking-tight text-white">{{ __('See Serfix in action.') }}</h2>
            <p class="mt-3 text-sm leading-6 text-slate-400">{{ __('Click through the real setup, from your domain to a planned month of articles. No signup, no sales call.') }}</p>

            <a href="https://app.supademo.com/demo/cms6473jf32eyqmqqm19mxp9j" target="_blank" rel="noopener"
               class="group mt-6 block overflow-hidden rounded-2xl border border-slate-700 bg-slate-900">
                <span class="relative block">
                    <img src="{{ asset('images/content/calendar.webp') }}"
                         alt="{{ __('The Serfix content calendar: a month of articles planned, written and published') }}"
                         width="1500" height="1120" loading="lazy" class="w-full opacity-60">
                    <span class="absolute inset-0 flex items-center justify-center">
                        <span class="flex h-14 w-14 items-center justify-center rounded-full bg-orange-600 text-white shadow-lg shadow-orange-900/40">
                            <svg class="ms-0.5 h-6 w-6" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.14v13.72a.5.5 0 00.76.43l11.54-6.86a.5.5 0 000-.86L8.76 4.71a.5.5 0 00-.76.43z" /></svg>
                        </span>
                    </span>
                </span>
            </a>

            <a href="https://app.supademo.com/demo/cms6473jf32eyqmqqm19mxp9j" target="_blank" rel="noopener"
               class="mt-5 inline-flex items-center justify-center gap-1.5 rounded-full bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-orange-600/30">
                {{ __('Start interactive demo') }}
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
            </a>
            <p class="mt-3 text-xs text-slate-500">{{ __('Takes less than 2 minutes') }}</p>
        </div>
    </div>
</section>
