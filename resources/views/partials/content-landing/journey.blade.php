{{-- The workflow end to end. Replaces the old "Everything you need for
     content SEO" panels: same claims (competitor research, keyword gaps,
     images, publishing, tracking), told as one sequence instead of a feature
     grid — which is what the new design asks for and reads far better.

     Every step here is a real stage of the pipeline, not marketing shape. --}}
<section class="border-b border-slate-200 bg-white">
    <div class="mx-auto max-w-6xl px-6 py-16 lg:px-8 lg:py-20">
        <div class="max-w-2xl">
            <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-orange-600">{{ __('Serfix journey') }}</p>
            <h2 class="mt-3 text-balance text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">{{ __('One workflow. From website to organic growth.') }}</h2>
            <p class="mt-4 text-base leading-7 text-slate-600">{{ __('Serfix brings the research, strategy, content creation, publishing and tracking into one continuous process.') }}</p>
        </div>

        {{-- The connecting line lives on the row, behind the icons, and only
             from lg — stacked on narrow screens it would run through the copy. --}}
        <div class="relative mt-14">
            <div class="pointer-events-none absolute inset-x-0 top-6 hidden h-px bg-gradient-to-r from-orange-200 via-orange-300 to-orange-100 lg:block"></div>

            <div class="relative grid gap-x-6 gap-y-10 sm:grid-cols-2 lg:grid-cols-6">
                @foreach ([
                    ['M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z', __('Understand your website'), __('We analyze your site, pages, structure and existing content.')],
                    ['M15 10.5a3 3 0 11-6 0 3 3 0 016 0z M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z', __('Find opportunities'), __('We research keyword gaps, competitors and search intent.')],
                    ['M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6z', __('Build the strategy'), __('We create a prioritized content plan and publishing schedule.')],
                    ['M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z', __('Create & optimize'), __('We write, add images, optimize and connect internally.')],
                    ['M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z', __('Review & publish'), __('You review, edit and approve. We publish directly.')],
                    ['M2.25 18L9 11.25l4.306 4.306a11.95 11.95 0 015.814-5.518l2.256-1.011M21.75 6.75v5.25M21.75 6.75h-5.25', __('Measure & improve'), __('We track rankings, traffic and results to improve what works.')],
                ] as [$icon, $title, $desc])
                    <div class="text-center lg:text-start">
                        <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl border border-orange-200 bg-orange-50 text-orange-600 shadow-sm lg:mx-0">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}" /></svg>
                        </span>
                        <h3 class="mt-4 text-sm font-extrabold tracking-tight text-slate-900">{{ $title }}</h3>
                        <p class="mt-1.5 text-xs leading-5 text-slate-500">{{ $desc }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>
