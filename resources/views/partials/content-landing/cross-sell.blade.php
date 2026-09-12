{{-- Cross-sell: the SEO platform. Hidden with the product — SEO_PLATFORM_UI is
     false in production, so real visitors never see this. It stays in the page
     because the flag flips per environment, and because it is the only carrier
     of images/content/rank-chart.webp and the route('pricing') link, both
     pinned by tests that run with the flag ON. --}}
@if (config('features.seo_platform_ui'))
<section class="border-b border-slate-200 bg-orange-50/50">
    <div class="mx-auto max-w-6xl px-6 py-16 lg:px-8 lg:py-20">
        <div class="grid items-center gap-10 rounded-3xl border border-orange-200 bg-white p-8 shadow-sm lg:grid-cols-2 lg:p-10">
            <div>
                <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-orange-600">{{ __('Also from Serfix') }}</p>
                <h2 class="mt-3 text-balance text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">{{ __('Manage the rest of your SEO like a pro') }}</h2>
                <p class="mt-3 text-base leading-7 text-slate-600">{{ __('The Serfix SEO platform is a separate product for everything around your content: site audits, rank tracking, keyword research, backlinks, competitor analysis and client reporting.') }}</p>
                <div class="mt-5 flex flex-wrap gap-2">
                    @foreach ([__('Keyword research'), __('Rank tracking'), __('Backlink analysis'), __('Site audits'), __('Client reports')] as $chip)
                        <span class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ $chip }}</span>
                    @endforeach
                </div>
                <a href="{{ route('pricing') }}" class="mt-6 inline-flex items-center gap-1.5 text-sm font-bold text-orange-600 underline-offset-4 hover:underline">
                    {{ __('See SEO platform pricing') }}
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                </a>
            </div>
            <img src="{{ asset('images/content/rank-chart.webp') }}"
                 alt="{{ __('A tracked keyword climbing the rankings week by week') }}"
                 width="1320" height="820" loading="lazy"
                 class="w-full rounded-2xl border border-slate-200 shadow-lg">
        </div>
    </div>
</section>
@endif
