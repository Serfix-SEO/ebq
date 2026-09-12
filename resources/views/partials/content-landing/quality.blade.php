{{-- The quality objection — "is this AI slop?" — answered with the real
     editor screen plus the control the client keeps. Absorbs the old
     "Articles that don't read like AI content" section: same argument, one
     screen instead of two. --}}
<section class="border-b border-slate-200 bg-slate-50">
    <div class="mx-auto max-w-6xl px-6 py-16 lg:px-8 lg:py-20">
        <div class="grid items-center gap-12 lg:grid-cols-2">
            <div class="relative">
                <div class="pointer-events-none absolute -inset-2 sm:-inset-5 rounded-[2rem] bg-gradient-to-tr from-orange-500/15 via-amber-300/10 to-transparent blur-2xl"></div>
                <img src="{{ asset('images/content/article-score.webp') }}"
                     alt="{{ __('An article in the editor with its live SEO score, keywords and checks') }}"
                     width="1400" height="1000" loading="lazy" decoding="async"
                     class="relative w-full rounded-2xl border border-slate-200 bg-white shadow-xl">
            </div>

            <div>
                <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-orange-600">{{ __('Content quality') }}</p>
                <h2 class="mt-3 text-balance text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">{{ __('Content you can actually put your name on.') }}</h2>
                <p class="mt-4 text-base leading-7 text-slate-600">{{ __('Serfix automates the repetitive work, but you stay in control. Every draft is researched against real search results, written to a brief, put through a humanizing pass and scored before it reaches you.') }}</p>

                <ul class="mt-8 space-y-3.5">
                    @foreach ([
                        __('Review every article before publishing'),
                        __('Edit, request changes or reject'),
                        __('Original images for every article'),
                        __('Smart internal linking suggestions'),
                        __('On-page SEO checks and optimization'),
                        __('Brand-safe and quality-focused'),
                    ] as $point)
                        <li class="flex items-start gap-3">
                            <span class="mt-0.5 flex h-5 w-5 flex-none items-center justify-center rounded-full bg-success/10 text-success">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            </span>
                            <span class="text-[15px] leading-6 text-slate-700">{{ $point }}</span>
                        </li>
                    @endforeach
                </ul>

                <p class="mt-7 rounded-xl border border-slate-200 bg-white p-4 text-sm leading-6 text-slate-600">
                    {{ __('Things you do not sell are never claimed, and competitor names are blocked automatically. Turn auto-publish off and nothing goes live without your approval.') }}
                </p>
            </div>
        </div>
    </div>
</section>
