{{-- FAQ. The questions come from $faqs in the shell, which also feeds the
     FAQPage JSON-LD — one source, so the rich result can never drift from the
     visible page. Two columns from md, per the new design. --}}
<section id="faq" class="scroll-mt-20 border-b border-slate-200 bg-slate-50">
    <div class="mx-auto max-w-6xl px-6 py-16 lg:px-8 lg:py-20">
        <div class="grid gap-10 lg:grid-cols-12 lg:gap-12">
            <div class="lg:col-span-4">
                <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-orange-600">{{ __('FAQ') }}</p>
                <h2 class="mt-3 text-balance text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">{{ __('Frequently asked questions.') }}</h2>
                <p class="mt-4 text-sm leading-6 text-slate-600">{{ __('Answers to the most common questions about Serfix.') }}</p>
                <a href="{{ route('contact') }}" class="mt-5 inline-flex items-center gap-1.5 text-sm font-bold text-orange-600 underline-offset-4 hover:underline">
                    {{ __('Ask us anything') }}
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                </a>
            </div>

            <div class="lg:col-span-8">
                <div class="grid gap-x-6 md:grid-cols-2">
                    @foreach ($faqs as $faq)
                        <details class="group mb-3 rounded-2xl border border-slate-200 bg-white p-5 [&_summary::-webkit-details-marker]:hidden">
                            <summary class="flex cursor-pointer items-start justify-between gap-3 text-sm font-semibold text-slate-900">
                                <span>{{ $faq['q'] }}</span>
                                <span class="flex h-6 w-6 flex-none items-center justify-center rounded-full bg-slate-100 text-slate-600 transition group-open:rotate-45 group-open:bg-slate-900 group-open:text-white">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                </span>
                            </summary>
                            <p class="mt-3 text-[13px] leading-6 text-slate-600">{{ $faq['a'] }}</p>
                        </details>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</section>
