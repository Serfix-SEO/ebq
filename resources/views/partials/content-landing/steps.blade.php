{{-- The whole product in four words, numbered. Sits directly under the hero
     as its own band (it used to be crammed into the hero's bottom edge, where
     it competed with the CTA for attention). --}}
<section class="border-b border-slate-200 bg-white">
    <div class="mx-auto max-w-6xl px-6 py-12 lg:px-8 lg:py-14">
        <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                [__('Understand'), __('Analyze your site, content and business.')],
                [__('Plan'), __('Find opportunities and build the strategy.')],
                [__('Publish'), __('Create, review and publish content.')],
                [__('Improve'), __('Track what is ranking and what needs attention.')],
            ] as $i => [$label, $sub])
                <div class="relative">
                    <p class="text-xs font-extrabold tracking-[0.18em] text-orange-600">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</p>
                    <div class="mt-3 h-px w-full bg-gradient-to-r from-orange-300 to-transparent"></div>
                    <h3 class="mt-4 text-lg font-extrabold tracking-tight text-slate-900">{{ $label }}</h3>
                    <p class="mt-1.5 text-sm leading-6 text-slate-500">{{ $sub }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>
