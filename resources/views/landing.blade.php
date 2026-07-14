<x-marketing.page
    title="#1 Free SEO Analysis Tool With Detailed Audits"
    description="Analyze your website with the #1 free SEO audit tool. Get detailed tech insights, live GSC data, and actionable fixes to scale your search traffic."
>
    {{-- Page-specific structured data: the product itself. --}}
    <x-slot:schema>
        @php
            $landingSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'SoftwareApplication',
                'name' => 'Serfix',
                'applicationCategory' => 'BusinessApplication',
                'operatingSystem' => 'Web, WordPress',
                'url' => route('landing'),
                'description' => 'Free SEO analysis and audit tool: detailed technical insights, live Google Search Console data, rank tracking, backlinks, and actionable fixes.',
                'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'],
            ];
        @endphp
        <script type="application/ld+json">{!! json_encode($landingSchema, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    </x-slot:schema>

    @php
        // Trial-winback (2026-07-14: extended to every new signup, not just
        // logged-in trial users) — same strikethrough pricing as /pricing
        // and /billing (checkout auto-applies the code). A guest sees it too
        // since every signup starts on the Trial plan and will be eligible
        // the moment they register; only suppressed for a logged-in user
        // who's ALREADY ineligible (subscribed/comped). Authed CTAs go
        // straight to checkout instead of /register.
        $wbAuthed  = auth()->check();
        $wbCode    = (string) config('services.stripe.winback_promo_code');
        $wbPercent = (int) config('services.stripe.winback_promo_percent');
        $wbActive  = $wbCode !== '' && (! $wbAuthed || \App\Support\TrialStatus::isWinbackEligible(auth()->user()));
        $wbMoney   = fn (float $v) => '$' . rtrim(rtrim(number_format($v * (100 - $wbPercent) / 100, 2, '.', ''), '0'), '.');
        $wbCta     = fn (string $slug, string $interval) => $wbAuthed
            ? route('billing.checkout', array_filter(['plan' => $slug, 'interval' => $interval !== 'annual' ? $interval : null]))
            : route('register', array_filter(['plan' => $slug, 'interval' => $interval !== 'annual' ? $interval : null]));

        // Feature bullets: pull the SAME authentic, admin-editable list
        // /pricing renders (Plan::publicFeatures() — the `plans.features`
        // DB column via /admin/plans/{plan}/edit), instead of a hardcoded
        // array here that silently drifts out of sync whenever plans change
        // (exactly what happened — this page's bullets no longer matched
        // /pricing). Empty array (missing plan row) renders no bullets
        // rather than a fatal error.
        $planFeatures = \App\Models\Plan::ordered()->get()->keyBy('slug')
            ->map(fn (\App\Models\Plan $p) => $p->publicFeatures());
        $featuresFor  = fn (string $slug) => $planFeatures[$slug] ?? [];
    @endphp

    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section class="relative">
        <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 top-0 -z-10 h-[28rem] bg-[radial-gradient(ellipse_at_top,rgba(242,100,25,0.08),transparent_60%)]"></div>

        <div class="mx-auto max-w-4xl px-6 pb-20 pt-16 text-center lg:px-8 lg:pb-28 lg:pt-24">
            <h1 class="mx-auto max-w-3xl text-balance text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl lg:text-6xl">
                {{ __('The SEO command center for teams that ship.') }}
            </h1>

            <p class="mx-auto mt-6 max-w-2xl text-balance text-[17px] leading-8 text-slate-600">
                {{ __('Unify Search Console, Analytics, ranking, audits, and backlinks into one quiet workspace. Serfix tells you what to fix this week, what to ship next, and what changed after release.') }}
            </p>

            {{-- ── Free tools ── --}}
            <div class="mt-6 flex flex-wrap items-center justify-center gap-2">
                <span class="text-xs font-semibold text-slate-500">{{ __('Free tools:') }}</span>
                <a href="{{ route('tools.pagespeed') }}" class="inline-flex items-center gap-1.5 rounded-full border border-orange-200 bg-orange-50 px-3 py-1.5 text-xs font-semibold text-orange-700 shadow-sm transition hover:border-orange-400 hover:bg-orange-100 hover:shadow-md hover:-translate-y-0.5 active:translate-y-0">
                    <svg class="h-3.5 w-3.5 text-orange-500" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>
                    {{ __('PageSpeed Test') }}
                    <svg class="h-3 w-3 text-orange-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                </a>
                <a href="{{ route('tools.audit') }}" class="inline-flex items-center gap-1.5 rounded-full border border-orange-200 bg-orange-50 px-3 py-1.5 text-xs font-semibold text-orange-700 shadow-sm transition hover:border-orange-400 hover:bg-orange-100 hover:shadow-md hover:-translate-y-0.5 active:translate-y-0">
                    <svg class="h-3.5 w-3.5 text-orange-500" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z" /></svg>
                    {{ __('SEO Audit') }}
                    <svg class="h-3 w-3 text-orange-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                </a>
                <a href="{{ route('tools.rank-tracker') }}" class="inline-flex items-center gap-1.5 rounded-full border border-orange-200 bg-orange-50 px-3 py-1.5 text-xs font-semibold text-orange-700 shadow-sm transition hover:border-orange-400 hover:bg-orange-100 hover:shadow-md hover:-translate-y-0.5 active:translate-y-0">
                    <svg class="h-3.5 w-3.5 text-orange-500" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
                    {{ __('Rank Checker') }}
                    <svg class="h-3 w-3 text-orange-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                </a>
                <a href="{{ route('tools.keyword-volume') }}" class="inline-flex items-center gap-1.5 rounded-full border border-orange-200 bg-orange-50 px-3 py-1.5 text-xs font-semibold text-orange-700 shadow-sm transition hover:border-orange-400 hover:bg-orange-100 hover:shadow-md hover:-translate-y-0.5 active:translate-y-0">
                    <svg class="h-3.5 w-3.5 text-orange-500" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
                    {{ __('Volume Checker') }}
                    <svg class="h-3 w-3 text-orange-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                </a>
            </div>

            {{-- ── Hero: free instant audit (full-width search bar) ── --}}
            <div class="relative mx-auto mt-10 max-w-3xl">
                <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 -inset-y-10 sm:-inset-x-8 -z-10 bg-[radial-gradient(55%_60%_at_50%_0%,rgba(242,100,25,0.20),transparent_70%)] blur-2xl"></div>

                @include('partials.analyze-hero')


                
            </div>
        </div>
    </section>
    

    {{-- ── Signup gate modal (3rd audit) ──────────────────────── --}}
    <div id="ga-signup-modal" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
        <div id="ga-signup-backdrop" class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm"></div>
        <div role="dialog" aria-modal="true" aria-labelledby="ga-signup-title" class="relative w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-slate-900/5">
            <div class="bg-orange-600 px-7 py-6 text-white">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-orange-100">{{ __('Keep auditing — free') }}</p>
                <h2 id="ga-signup-title" class="mt-2 text-2xl font-semibold tracking-tight">{{ __('Create your free account') }}</h2>
            </div>
            <div class="px-7 py-6">
                <p id="ga-signup-msg" class="text-sm leading-6 text-slate-600">{{ __('You\'ve used your free audits. Create a free account to keep going — no credit card required.') }}</p>
                <ul class="mt-5 space-y-2.5 text-sm text-slate-700">
                    @foreach ([
                        __('Unlimited page audits — on-page, keyword & competitor benchmarks'),
                        __('Connect Search Console + Analytics for live keyword positions & click data'),
                        __('Core Web Vitals, rank tracking, and ranked fixes across your whole site'),
                        __('Free plan covers one website · no credit card'),
                    ] as $perk)
                        <li class="flex items-start gap-2.5">
                            <svg class="mt-0.5 h-4 w-4 flex-none text-emerald-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" /></svg>
                            <span>{{ $perk }}</span>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-7 flex flex-col gap-2 sm:flex-row">
                    <a href="{{ route('register') }}" class="inline-flex flex-1 items-center justify-center gap-2 rounded-xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">
                        {{ __('Create free account →') }}
                    </a>
                    <button type="button" id="ga-signup-close" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-600 transition hover:border-slate-300 hover:text-slate-900">
                        {{ __('Maybe later') }}
                    </button>
                </div>
                <p class="mt-3 text-center text-xs text-slate-400">{{ __('Already have an account?') }} <a href="{{ route('login') }}" class="font-medium text-orange-600 hover:underline">{{ __('Sign in') }}</a></p>
            </div>
        </div>
    </div>

    {{-- ── Features Section ────────────────────────────────────── --}}
    <section class="bg-surface-container-low py-xxl px-gutter" style="background:#f2f4f7;padding:48px 24px">
        <div class="mx-auto max-w-6xl">
            <div class="text-center mb-8">
                <h2 class="text-3xl font-bold text-slate-900 mb-2">{{ __('Everything you need to rank.') }}</h2>
                <div class="h-1 w-20 bg-orange-600 mx-auto rounded"></div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach ([
                    ['icon' => 'M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z', 'title' => __('Keyword Research'), 'desc' => __('Discover high-intent queries with striking-distance and cannibalization scoring. Know exactly what to write next.')],
                    ['icon' => 'M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z', 'title' => __('Technical Site Audit'), 'desc' => __('Deep-crawl your site for CWV, indexing, schema, and on-page issues. Every finding comes with a ranked fix.')],
                    ['icon' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z', 'title' => __('Rank Tracking'), 'desc' => __('Daily rankings overlaid with GSC clicks per query. See when a position gain stops producing traffic — and why.')],
                    ['icon' => 'M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244', 'title' => __('Backlink Analysis'), 'desc' => __('Track which links actually moved GSC clicks. Prove outreach ROI with 28-day delta reports per target page.')],
                    ['icon' => 'M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10', 'title' => __('Content Scoring'), 'desc' => __('AI-powered analysis for your articles. Understand how your content competes and what to improve for better rankings.')],
                    ['icon' => 'M7.5 14.25v2.25m3-4.5v4.5m3-6.75v6.75m3-9v9M6 20.25h12A2.25 2.25 0 0020.25 18V6A2.25 2.25 0 0018 3.75H6A2.25 2.25 0 003.75 6v12A2.25 2.25 0 006 20.25z', 'title' => __('GA4 + GSC Integration'), 'desc' => __('Connect Search Console and Analytics for a unified view of traffic, rankings, and on-page performance per URL.')],
                ] as $f)
                    <div class="bg-white p-6 border border-slate-200 rounded-xl hover:shadow-md transition-shadow">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-orange-50 text-orange-600 mb-4">
                            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $f['icon'] }}" /></svg>
                        </div>
                        <h3 class="text-base font-semibold text-slate-900 mb-2">{{ $f['title'] }}</h3>
                        <p class="text-sm leading-6 text-slate-600">{{ $f['desc'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── CTA Banner 1 ────────────────────────────────────────── --}}
    <section class="bg-orange-600 py-12 px-6 text-center">
        <h2 class="text-2xl font-semibold text-white mb-4">{{ __('Same features. A fraction of the price.') }}</h2>
        <a href="{{ route('register') }}" class="inline-flex items-center justify-center bg-white text-orange-700 px-8 py-3 rounded-xl font-semibold text-sm hover:shadow-xl transition-all">{{ __('Start Your 14-Day Free Trial') }}</a>
    </section>

    {{-- ── Why Serfix ─────────────────────────────────────────────── --}}
    <section class="bg-white py-20 sm:py-24 px-6">
        <div class="mx-auto max-w-6xl">
            <div class="text-center mb-10">
                <h2 class="text-3xl font-bold text-slate-900 mb-4">{{ __('Built for the way modern SEO actually works.') }}</h2>
                <p class="text-base leading-7 text-slate-600 max-w-2xl mx-auto">{{ __('Most tools show you data. Serfix turns every signal into a ranked action — so you always know what to work on next.') }}</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                @foreach ([
                    ['icon' => 'M7.5 14.25v2.25m3-4.5v4.5m3-6.75v6.75m3-9v9M6 20.25h12A2.25 2.25 0 0020.25 18V6A2.25 2.25 0 0018 3.75H6A2.25 2.25 0 003.75 6v12A2.25 2.25 0 006 20.25z', 'title' => __('Cross-signal intelligence'), 'desc' => __('Joins GSC × GA4 × audits × backlinks per page. Six insight boards — cannibalization, striking distance, decay, indexing fails, audit vs traffic, backlink impact — all ranked by revenue potential.')],
                    ['icon' => 'M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z', 'title' => __('Impact-scored actions'), 'desc' => __('Every issue gets a priority score. Anomaly detection, content decay, and indexing regressions surface in seconds — not in your next monthly review. Your backlog stops guessing.')],
                    ['icon' => 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z', 'title' => __('Proof after every change'), 'desc' => __('Every fix is tracked against rank, click, and CWV deltas. Reports auto-attach the evidence your stakeholders need — without you building a deck.')],
                ] as $w)
                    <div class="p-6 border border-slate-200 rounded-xl bg-slate-50/60">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-orange-600 text-white mb-4">
                            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $w['icon'] }}" /></svg>
                        </div>
                        <h3 class="text-base font-semibold text-slate-900 mb-2">{{ $w['title'] }}</h3>
                        <p class="text-sm leading-6 text-slate-600">{{ $w['desc'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Comparison Table ────────────────────────────────────── --}}
    <section class="bg-slate-50/60 py-20 sm:py-24 px-6">
        <div class="mx-auto max-w-6xl">
            <div class="text-center mb-10">
                <h2 class="text-3xl font-bold text-slate-900 mb-3">{{ __('More features. Lower price.') }}</h2>
                <p class="text-base text-slate-600">{{ __('Every plan includes bilingual audit support — full, not limited.') }}</p>
            </div>
            {{-- The scroll container must be INSIDE the rounded card: with
                 overflow-x-auto up on the <section> and overflow-hidden here,
                 phones just clipped/crushed the 4 columns with no way to
                 scroll. min-w keeps cells readable; narrow screens swipe. --}}
            <div class="overflow-hidden rounded-2xl border border-slate-200 shadow-sm">
                <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] border-collapse bg-white text-sm">
                    <thead>
                        <tr class="border-b border-slate-200">
                            <th class="py-3.5 pl-6 pr-4 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ __('Feature') }}</th>
                            <th class="px-4 py-3.5 text-center text-[11px] font-semibold uppercase tracking-wider text-slate-500">SEMrush</th>
                            <th class="px-4 py-3.5 text-center text-[11px] font-semibold uppercase tracking-wider text-slate-500">Ahrefs</th>
                            <th class="bg-orange-50 px-4 py-3.5 text-center text-[11px] font-semibold uppercase tracking-wider text-orange-700">Serfix</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @php
                            $checkSvg  = '<svg class="mx-auto h-4 w-4 text-slate-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" /></svg>';
                            // Not-available: rose X on a soft pill (matches the pricing
                            // compare table) so the features competitors lack stand out.
                            $crossSvg  = '<span class="mx-auto flex h-6 w-6 items-center justify-center rounded-full bg-rose-50"><svg class="h-3.5 w-3.5 text-rose-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-label="'.e(__('Not included')).'"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" /></svg></span>';
                            $ebqCheck  = '<svg class="mx-auto h-4 w-4 text-orange-600" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" /></svg>';
                            $rows = [
                                [__('Keyword Research'), 'check', 'check', 'ebq'],
                                [__('Technical Site Audit'), 'check', 'check', 'ebq'],
                                [__('Rank Tracking'), 'check', 'check', 'ebq'],
                                [__('Backlink Analysis'), 'check', 'check', 'ebq'],
                                [__('Cannibalization Detection'), 'cross', 'cross', 'ebq'],
                                [__('Striking-Distance Finder'), 'cross', 'cross', 'ebq'],
                                [__('Anomaly Alerts'), 'cross', 'cross', 'ebq'],
                                [__('Backlink Impact Measurement'), 'cross', 'cross', 'ebq'],
                                [__('Action Priority Queue'), 'cross', 'cross', 'ebq'],
                                [__('AI Content Studio'), 'check', 'cross', 'ebq'],
                                [__('Scheduled Reports'), 'paid',  'cross', 'ebq'],
                                [__('White-label Reports'), 'paid',  'cross', 'agency'],
                                [__('GA4 + GSC Integration'), 'check', 'partial','ebq'],
                                [__('WordPress Plugin'), 'check', 'check', config('services.wordpress_plugin.coming_soon') ? 'soon' : 'ebq'],
                            ];
                        @endphp
                        @foreach ($rows as [$feature, $sem, $ahr, $ebq])
                            <tr class="hover:bg-slate-50/40 transition-colors">
                                <td class="py-3 pl-6 pr-4 text-[15px] font-medium text-slate-800 sm:text-[13px]">{{ $feature }}</td>
                                @foreach (['sem' => $sem, 'ahr' => $ahr] as $col => $val)
                                    <td class="px-4 py-3 text-center">
                                        @if ($val === 'check') {!! $checkSvg !!}
                                        @elseif ($val === 'partial') <span class="text-xs font-medium text-slate-400 sm:text-[11px]">{{ __('Partial') }}</span>
                                        @elseif ($val === 'paid') <span class="text-xs font-medium text-slate-400 sm:text-[11px]">{{ __('Paid+') }}</span>
                                        @else {!! $crossSvg !!}
                                        @endif
                                    </td>
                                @endforeach
                                <td class="bg-orange-50/60 px-4 py-3 text-center">
                                    @if ($ebq === 'soon') <span class="text-xs font-bold uppercase tracking-wider text-orange-600 sm:text-[11px]">{{ __('Soon') }}</span>
                                    @elseif ($ebq === 'agency') <span class="text-xs font-medium text-orange-600 sm:text-[11px]">{{ __('Agency+') }}</span>
                                    @else {!! $ebqCheck !!}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        <tr class="border-t-2 border-slate-200 font-semibold">
                            <td class="py-4 pl-6 pr-4 text-[14px] text-slate-900">{{ __('Starting Price') }}</td>
                            <td class="px-4 py-4 text-center text-[13px] text-slate-400 line-through">$117/mo</td>
                            <td class="px-4 py-4 text-center text-[13px] text-slate-400 line-through">$108/mo</td>
                            {{-- Month-to-month price, apples-to-apples with the competitor columns (the $14 annual-equivalent lives only in the billing toggle). --}}
                            <td class="bg-orange-50/60 px-4 py-4 text-center text-base font-bold text-orange-600">$19/mo</td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </section>

    {{-- ── CTA Banner 2 (dark) ─────────────────────────────────── --}}
    <section class="bg-slate-900 py-12 px-6 text-center">
        <h2 class="text-2xl font-semibold text-white mb-3">{{ __('14 days. Full access. No card.') }}</h2>
        <p class="text-slate-400 text-sm mb-6">{{ __('Experience the full power of Serfix for zero risk.') }}</p>
        <a href="{{ route('register') }}" class="inline-flex items-center justify-center bg-orange-600 text-white px-8 py-3 rounded-xl font-semibold text-sm hover:bg-orange-500 transition-all">{{ __('Claim Your Trial Now') }}</a>
    </section>

    {{-- ── Who is this for ─────────────────────────────────────── --}}
    <section class="bg-white py-20 sm:py-24 px-6">
        <div class="mx-auto max-w-6xl">
            <div class="text-center mb-10">
                <h2 class="text-3xl font-bold text-slate-900">{{ __('Who is this for?') }}</h2>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                @foreach ([
                    ['icon' => 'M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21', 'title' => __('In-house SEO Manager'), 'desc' => __('Stop fighting with tools that don\'t surface what matters. Get ranked action lists and auto-sent reports for leadership — in one workspace.')],
                    ['icon' => 'M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z', 'title' => __('Marketing Agency'), 'desc' => __('Scale your client list without scaling your tool budget. Scheduled reports, white-label exports, and website-scoped team access as standard.')],
                    ['icon' => 'M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349m-16.5 11.65V9.35m0 0a3.001 3.001 0 003.75-.615A2.993 2.993 0 009.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 002.25 1.016c.896 0 1.7-.393 2.25-1.016a3.001 3.001 0 003.75.614m-16.5 0a3.004 3.004 0 01-.621-4.72L4.318 3.44A1.5 1.5 0 015.378 3h13.243a1.5 1.5 0 011.06.44l1.19 1.189a3 3 0 01-.621 4.72m-13.5 8.65h3.75a.75.75 0 00.75-.75V13.5a.75.75 0 00-.75-.75H6.75a.75.75 0 00-.75.75v3.75c0 .415.336.75.75.75z', 'title' => __('SME Business Owner'), 'desc' => __('Powerful enough for experts, simple enough for founders. High-end SEO features at a price that makes sense for your stage — free plan included.')],
                ] as $p)
                    <div class="flex flex-col items-center rounded-2xl border border-slate-200 p-8 text-center bg-slate-50/60 hover:-translate-y-1 transition-transform">
                        <div class="flex h-14 w-14 items-center justify-center rounded-full bg-orange-50 text-orange-600 mb-5">
                            <svg class="h-7 w-7" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $p['icon'] }}" /></svg>
                        </div>
                        <h3 class="text-base font-semibold text-slate-900 mb-3">{{ $p['title'] }}</h3>
                        <p class="text-sm leading-6 text-slate-600">{{ $p['desc'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Pricing ──────────────────────────────────────────────── --}}
    @php
        $ck = function (string $path): string {
            static $svgCheck = '<svg class="h-4 w-4 flex-none text-emerald-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" /></svg>';
            return $svgCheck;
        };
    @endphp
    <section id="pricing" class="bg-slate-50/60 py-20 sm:py-24 px-6" x-data="{ billing: 'annual' }">
        <div class="mx-auto max-w-6xl">
            <div class="text-center mb-8">
                <h2 class="text-3xl font-bold text-slate-900 mb-3">{{ __('Transparent Pricing') }}</h2>
                <p class="text-base text-slate-600 mb-5">{{ __('From free to enterprise — scale your SEO without switching tools.') }}</p>
                {{-- Billing toggle --}}
                <div class="inline-flex rounded-full border border-slate-200 bg-white p-1 shadow-sm">
                    <button
                        @click="billing = 'monthly'"
                        :class="billing === 'monthly' ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                        class="rounded-full px-5 py-1.5 text-sm font-semibold transition">
                        {{ __('Monthly') }}
                    </button>
                    <button
                        @click="billing = 'annual'"
                        :class="billing === 'annual' ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                        class="inline-flex items-center gap-2 rounded-full px-5 py-1.5 text-sm font-semibold transition">
                        {{ __('Annual') }}
                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-emerald-700">{{ __('Save 26%') }}</span>
                    </button>
                </div>
            </div>

            @if ($wbActive)
                <div class="mb-6 flex flex-col items-center justify-between gap-3 rounded-2xl bg-gradient-to-r from-orange-600 to-orange-500 px-6 py-4 text-white shadow-lg sm:flex-row">
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-widest text-orange-100">{{ __('Limited-time offer') }}</p>
                        <p class="text-xl font-extrabold sm:text-2xl">{{ $wbPercent }}{{ __('% OFF any plan — applied automatically at checkout') }}</p>
                    </div>
                    <span class="inline-block shrink-0 rounded-xl border-2 border-dashed border-white/70 bg-white/15 px-4 py-2 text-base font-extrabold tracking-widest">{{ $wbCode }}</span>
                </div>
            @endif
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-5 items-start">

                {{-- Trial (free) --}}
                <div class="bg-white p-6 rounded-2xl border border-slate-200 flex flex-col shadow-sm text-center sm:text-start">
                    <p class="text-[11px] font-semibold uppercase tracking-widest text-slate-500 mb-3">{{ __('Trial') }}</p>
                    <div class="flex items-baseline justify-center gap-1 mb-1 sm:justify-start">
                        <span class="text-4xl font-bold text-slate-900">$0</span>
                        <span class="text-slate-500 text-sm">/mo</span>
                    </div>
                    <p class="text-xs text-slate-500 mb-5">{{ __('Free forever. No card required.') }}</p>
                    <ul class="space-y-2 mb-7 flex-grow text-[15px] text-slate-700 sm:text-sm">
                        @foreach ($featuresFor('trial') as $item)
                            <li class="flex items-center justify-center gap-2 sm:justify-start">{!! $ck('') !!}{{ __($item) }}</li>
                        @endforeach
                    </ul>
                    <a href="{{ route('register') }}" class="block w-full py-2.5 text-center rounded-xl border border-slate-300 text-slate-700 font-semibold text-sm hover:border-slate-400 hover:text-slate-900 transition-all">{{ __('Start free') }}</a>
                </div>

                {{-- Solo --}}
                <div class="bg-white p-6 rounded-2xl border border-slate-200 flex flex-col shadow-sm text-center sm:text-start">
                    <p class="text-[11px] font-semibold uppercase tracking-widest text-slate-500 mb-3">{{ __('Solo') }}</p>
                    <div class="flex items-baseline justify-center gap-1 mb-1 sm:justify-start">
                        @if ($wbActive)
                            <span x-show="billing === 'annual'" class="flex items-baseline gap-1.5">
                                <span class="text-lg font-semibold text-slate-400 line-through">$14</span>
                                <span class="text-4xl font-bold text-orange-600">{{ $wbMoney(168.0 / 12) }}</span>
                            </span>
                            <span x-show="billing === 'monthly'" style="display:none" class="flex items-baseline gap-1.5">
                                <span class="text-lg font-semibold text-slate-400 line-through">$19</span>
                                <span class="text-4xl font-bold text-orange-600">{{ $wbMoney(19) }}</span>
                            </span>
                        @else
                            <span class="text-4xl font-bold text-slate-900" x-show="billing === 'annual'">$14</span>
                            <span class="text-4xl font-bold text-slate-900" x-show="billing === 'monthly'" style="display:none">$19</span>
                        @endif
                        <span class="text-slate-500 text-sm">/mo</span>
                    </div>
                    @if ($wbActive)
                        <p class="text-xs font-semibold text-orange-700 mb-5" x-show="billing === 'annual'"><span class="line-through">$168</span> {{ $wbMoney(168) }} {{ __('first year with') }} {{ $wbCode }}</p>
                        <p class="text-xs font-semibold text-orange-700 mb-5" x-show="billing === 'monthly'" style="display:none">{{ __('First month with') }} {{ $wbCode }}{{ __(', then $19/mo.') }}</p>
                    @else
                        <p class="text-xs text-slate-500 mb-5" x-show="billing === 'annual'">{{ __('$168 billed annually') }}</p>
                        <p class="text-xs text-slate-500 mb-5" x-show="billing === 'monthly'" style="display:none">{{ __('Billed monthly.') }}</p>
                    @endif
                    <ul class="space-y-2 mb-7 flex-grow text-[15px] text-slate-700 sm:text-sm">
                        @foreach ($featuresFor('solo') as $item)
                            <li class="flex items-center justify-center gap-2 sm:justify-start">{!! $ck('') !!}{{ __($item) }}</li>
                        @endforeach
                    </ul>
                    <a data-url-annual="{{ $wbCta('solo', 'annual') }}"
                       data-url-monthly="{{ $wbCta('solo', 'monthly') }}"
                       :href="billing === 'monthly' ? $el.dataset.urlMonthly : $el.dataset.urlAnnual"
                       class="block w-full py-2.5 text-center rounded-xl border border-orange-600 text-orange-600 font-semibold text-sm hover:bg-orange-50 transition-all">{{ __('Get started') }}</a>
                </div>

                {{-- Pro (Most Popular) --}}
                <div class="relative bg-white p-6 rounded-2xl border-2 border-orange-600 flex flex-col shadow-xl lg:-translate-y-3 text-center sm:text-start">
                    <span class="absolute -top-3 left-1/2 inline-flex -translate-x-1/2 items-center whitespace-nowrap rounded-full bg-orange-600 px-3 py-1 text-[10px] font-bold uppercase tracking-wider text-white sm:left-5 sm:translate-x-0">{{ __('Most Popular') }}</span>
                    <p class="text-[11px] font-semibold uppercase tracking-widest text-slate-500 mb-3">{{ __('Pro') }}</p>
                    <div class="flex items-baseline justify-center gap-1 mb-1 sm:justify-start">
                        @if ($wbActive)
                            <span x-show="billing === 'annual'" class="flex items-baseline gap-1.5">
                                <span class="text-lg font-semibold text-slate-400 line-through">$37</span>
                                <span class="text-4xl font-bold text-orange-600">{{ $wbMoney(444.0 / 12) }}</span>
                            </span>
                            <span x-show="billing === 'monthly'" style="display:none" class="flex items-baseline gap-1.5">
                                <span class="text-lg font-semibold text-slate-400 line-through">$49</span>
                                <span class="text-4xl font-bold text-orange-600">{{ $wbMoney(49) }}</span>
                            </span>
                        @else
                            <span class="text-4xl font-bold text-slate-900" x-show="billing === 'annual'">$37</span>
                            <span class="text-4xl font-bold text-slate-900" x-show="billing === 'monthly'" style="display:none">$49</span>
                        @endif
                        <span class="text-slate-500 text-sm">/mo</span>
                    </div>
                    @if ($wbActive)
                        <p class="text-xs font-semibold text-orange-700 mb-5" x-show="billing === 'annual'"><span class="line-through">$444</span> {{ $wbMoney(444) }} {{ __('first year with') }} {{ $wbCode }}</p>
                        <p class="text-xs font-semibold text-orange-700 mb-5" x-show="billing === 'monthly'" style="display:none">{{ __('First month with') }} {{ $wbCode }}{{ __(', then $49/mo.') }}</p>
                    @else
                        <p class="text-xs text-slate-500 mb-5" x-show="billing === 'annual'">{{ __('$444 billed annually') }}</p>
                        <p class="text-xs text-slate-500 mb-5" x-show="billing === 'monthly'" style="display:none">{{ __('Billed monthly.') }}</p>
                    @endif
                    <ul class="space-y-2 mb-7 flex-grow text-[15px] text-slate-700 sm:text-sm">
                        @foreach ($featuresFor('pro') as $item)
                            <li class="flex items-center justify-center gap-2 sm:justify-start">{!! $ck('') !!}{{ __($item) }}</li>
                        @endforeach
                    </ul>
                    <a data-url-annual="{{ $wbCta('pro', 'annual') }}"
                       data-url-monthly="{{ $wbCta('pro', 'monthly') }}"
                       :href="billing === 'monthly' ? $el.dataset.urlMonthly : $el.dataset.urlAnnual"
                       class="block w-full py-2.5 text-center rounded-xl bg-orange-600 text-white font-semibold text-sm hover:bg-orange-500 transition-all">{{ __('Get started') }}</a>
                </div>

                {{-- Agency --}}
                <div class="bg-white p-6 rounded-2xl border border-slate-200 flex flex-col shadow-sm text-center sm:text-start">
                    <p class="text-[11px] font-semibold uppercase tracking-widest text-slate-500 mb-3">{{ __('Agency') }}</p>
                    <div class="flex items-baseline justify-center gap-1 mb-1 sm:justify-start">
                        @if ($wbActive)
                            <span x-show="billing === 'annual'" class="flex items-baseline gap-1.5">
                                <span class="text-lg font-semibold text-slate-400 line-through">$74</span>
                                <span class="text-4xl font-bold text-orange-600">{{ $wbMoney(888.0 / 12) }}</span>
                            </span>
                            <span x-show="billing === 'monthly'" style="display:none" class="flex items-baseline gap-1.5">
                                <span class="text-lg font-semibold text-slate-400 line-through">$99</span>
                                <span class="text-4xl font-bold text-orange-600">{{ $wbMoney(99) }}</span>
                            </span>
                        @else
                            <span class="text-4xl font-bold text-slate-900" x-show="billing === 'annual'">$74</span>
                            <span class="text-4xl font-bold text-slate-900" x-show="billing === 'monthly'" style="display:none">$99</span>
                        @endif
                        <span class="text-slate-500 text-sm">/mo</span>
                    </div>
                    @if ($wbActive)
                        <p class="text-xs font-semibold text-orange-700 mb-5" x-show="billing === 'annual'"><span class="line-through">$888</span> {{ $wbMoney(888) }} {{ __('first year with') }} {{ $wbCode }}</p>
                        <p class="text-xs font-semibold text-orange-700 mb-5" x-show="billing === 'monthly'" style="display:none">{{ __('First month with') }} {{ $wbCode }}{{ __(', then $99/mo.') }}</p>
                    @else
                        <p class="text-xs text-slate-500 mb-5" x-show="billing === 'annual'">{{ __('$888 billed annually') }}</p>
                        <p class="text-xs text-slate-500 mb-5" x-show="billing === 'monthly'" style="display:none">{{ __('Billed monthly.') }}</p>
                    @endif
                    <ul class="space-y-2 mb-7 flex-grow text-[15px] text-slate-700 sm:text-sm">
                        @foreach ($featuresFor('agency') as $item)
                            <li class="flex items-center justify-center gap-2 sm:justify-start">{!! $ck('') !!}{{ __($item) }}</li>
                        @endforeach
                    </ul>
                    <a data-url-annual="{{ $wbCta('agency', 'annual') }}"
                       data-url-monthly="{{ $wbCta('agency', 'monthly') }}"
                       :href="billing === 'monthly' ? $el.dataset.urlMonthly : $el.dataset.urlAnnual"
                       class="block w-full py-2.5 text-center rounded-xl border border-orange-600 text-orange-600 font-semibold text-sm hover:bg-orange-50 transition-all">{{ __('Get started') }}</a>
                </div>

                {{-- Enterprise --}}
                <div class="bg-white p-6 rounded-2xl border border-slate-200 flex flex-col shadow-sm text-center sm:text-start">
                    <p class="text-[11px] font-semibold uppercase tracking-widest text-slate-500 mb-3">{{ __('Enterprise') }}</p>
                    <div class="flex items-baseline justify-center gap-1 mb-1 sm:justify-start">
                        <span class="text-4xl font-bold text-slate-900">{{ __('Custom') }}</span>
                    </div>
                    <p class="text-xs text-slate-500 mb-5">{{ __('Contact us for pricing.') }}</p>
                    <ul class="space-y-2 mb-7 flex-grow text-[15px] text-slate-700 sm:text-sm">
                        @foreach ($featuresFor('enterprise') as $item)
                            <li class="flex items-center justify-center gap-2 sm:justify-start">{!! $ck('') !!}{{ __($item) }}</li>
                        @endforeach
                    </ul>
                    <a href="{{ route('contact') }}" class="block w-full py-2.5 text-center rounded-xl border border-slate-300 text-slate-700 font-semibold text-sm hover:border-slate-400 hover:text-slate-900 transition-all">{{ __('Contact us') }}</a>
                </div>
            </div>

            <div class="mt-8 text-center space-y-2">
                <p class="text-xs font-semibold text-orange-600">{{ __('SEMrush starts at $117/mo. Ahrefs at $108/mo. You do the math.') }}</p>
                <p class="text-xs text-slate-500">{{ __('Start free — no credit card. Upgrade anytime.') }} <a href="{{ route('pricing') }}" class="font-semibold text-slate-700 hover:underline">{{ __('View full pricing + Enterprise →') }}</a></p>
            </div>
        </div>
    </section>

    {{-- ── Final CTA ────────────────────────────────────────────── --}}
    <section class="bg-white py-20 sm:py-24 px-6">
        <div class="mx-auto max-w-4xl text-center">
            <h2 class="text-3xl font-bold text-slate-900 mb-4">{{ __('Ready to dominate search results?') }}</h2>
            <p class="text-base leading-7 text-slate-600 mb-8">{{ __('The SEO platform built for teams that ship. Every feature. One price. Start free.') }}</p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center items-center">
                <a href="{{ route('register') }}" class="inline-flex items-center justify-center bg-orange-600 text-white px-8 py-3 rounded-xl font-semibold text-sm hover:bg-orange-500 transition-all shadow-lg w-full sm:w-auto">{{ __('Start Free Trial Now') }}</a>
                <a href="{{ route('contact') }}" class="inline-flex items-center justify-center border border-orange-600 text-orange-600 px-8 py-3 rounded-xl font-semibold text-sm hover:bg-orange-50 transition-all w-full sm:w-auto">{{ __('Request Demo') }}</a>
            </div>
        </div>
    </section>

    @if (\App\Support\Recaptcha::isEnabled())
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    @endif
    <script>
        (function () {
            var form = document.getElementById('guest-audit-form');
            if (!form) return;

            var btn = document.getElementById('ga-submit');
            var label = document.getElementById('ga-label');
            var spinner = document.getElementById('ga-spinner');
            var arrow = document.getElementById('ga-arrow');
            var errorBox = document.getElementById('ga-error');
            var csrf = document.querySelector('meta[name="csrf-token"]');

            var signupModal = document.getElementById('ga-signup-modal');
            var emailModal = document.getElementById('ga-email-modal');
            var emailForm = document.getElementById('ga-email-form');
            var nameInput = document.getElementById('ga-name');
            var emailInput = document.getElementById('ga-email');
            var emailModalMsg = document.getElementById('ga-email-modal-msg');
            var emailError = document.getElementById('ga-email-error');
            var emailSubmit = document.getElementById('ga-email-submit');
            var emailLabel = document.getElementById('ga-email-label');
            var emailSpinner = document.getElementById('ga-email-spinner');
            var successCard = document.getElementById('ga-success');
            var successMsg = document.getElementById('ga-success-msg');

            // Lead details captured via the email modal on the 2nd audit.
            var capturedName = '';
            var capturedEmail = '';

            function showError(msg) { errorBox.textContent = msg; errorBox.classList.remove('hidden'); }
            function clearError() { errorBox.textContent = ''; errorBox.classList.add('hidden'); }
            function setLoading(on) {
                btn.disabled = on;
                form.setAttribute('aria-busy', on ? 'true' : 'false');
                label.textContent = on ? 'Auditing your page…' : 'Run free audit';
                spinner.classList.toggle('hidden', !on);
                arrow.classList.toggle('hidden', on);
            }
            function setEmailLoading(on) {
                if (emailSubmit) { emailSubmit.disabled = on; }
                if (emailSpinner) { emailSpinner.classList.toggle('hidden', !on); }
                if (emailLabel) { emailLabel.textContent = on ? 'Sending…' : 'Email me my audit'; }
            }
            function resetCaptcha() {
                if (window.grecaptcha && typeof window.grecaptcha.reset === 'function') {
                    try { window.grecaptcha.reset(); } catch (e) {}
                }
            }
            function captchaToken() {
                // The widget can live in the hero form or the email modal, so query
                // document-wide rather than scoping to the form.
                var c = document.querySelector('textarea[name="g-recaptcha-response"]');
                return c ? c.value : null;
            }

            // The single captcha widget gets relocated between the hero form and the
            // email modal so it's always reachable — never stranded behind the popup.
            var captchaWidget = document.querySelector('.g-recaptcha');
            var heroCaptchaSlot = document.getElementById('ga-captcha-hero-slot');
            var modalCaptchaSlot = document.getElementById('ga-captcha-modal-slot');
            function moveCaptchaTo(slot) {
                if (captchaWidget && slot && captchaWidget.parentNode !== slot) {
                    slot.appendChild(captchaWidget);
                }
            }
            function toggleModal(el, on) {
                if (!el) return;
                el.classList.toggle('hidden', !on);
                el.classList.toggle('flex', on);
            }
            function openEmailModal(msg) {
                if (!emailModal) return;
                if (emailModalMsg && msg) { emailModalMsg.textContent = msg; }
                if (emailError) { emailError.classList.add('hidden'); }
                moveCaptchaTo(modalCaptchaSlot);
                toggleModal(emailModal, true);
                if (nameInput) { nameInput.focus(); }
            }
            function openSignupModal(msg) {
                if (!signupModal) { window.location.href = '{{ route('register') }}'; return; }
                var m = document.getElementById('ga-signup-msg');
                if (m && msg) { m.textContent = msg; }
                toggleModal(signupModal, true);
            }
            function showSuccess(msg) {
                if (successMsg && msg) { successMsg.textContent = msg; }
                form.classList.add('hidden');
                if (successCard) { successCard.classList.remove('hidden'); }
            }

            // Wire modal dismissals.
            if (signupModal) {
                var sClose = document.getElementById('ga-signup-close');
                var sBack = document.getElementById('ga-signup-backdrop');
                if (sClose) { sClose.addEventListener('click', function () { toggleModal(signupModal, false); }); }
                if (sBack) { sBack.addEventListener('click', function () { toggleModal(signupModal, false); }); }
            }
            if (emailModal) {
                var eCancel = document.getElementById('ga-email-cancel');
                var eBack = document.getElementById('ga-email-backdrop');
                var dismiss = function () { toggleModal(emailModal, false); moveCaptchaTo(heroCaptchaSlot); setLoading(false); setEmailLoading(false); };
                if (eCancel) { eCancel.addEventListener('click', dismiss); }
                if (eBack) { eBack.addEventListener('click', dismiss); }
            }

            function runAudit() {
                clearError();
                var url = (document.getElementById('ga-url').value || '').trim();
                var keyword = (document.getElementById('ga-keyword').value || '').trim();
                if (!url) { showError('Please enter your page URL.'); return; }
                if (!keyword) { showError('Please enter a target keyword.'); return; }

                var payload = { url: url, keyword: keyword };
                var countryEl = document.getElementById('ga-country');
                if (countryEl && countryEl.value) { payload.country = countryEl.value; }
                if (capturedEmail) { payload.email = capturedEmail; payload.name = capturedName; }
                var token = captchaToken();
                if (token) { payload['g-recaptcha-response'] = token; }

                setLoading(true);
                if (emailModal && emailModal.classList.contains('flex')) { setEmailLoading(true); }

                fetch(form.getAttribute('data-action'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrf ? csrf.getAttribute('content') : ''
                    },
                    body: JSON.stringify(payload)
                }).then(function (res) {
                    return res.json().catch(function () { return {}; }).then(function (data) {
                        return { status: res.status, data: data };
                    });
                }).then(function (r) {
                    // 2nd audit → emailed only, never shown on screen.
                    if (r.status === 202 && r.data.emailed) {
                        toggleModal(emailModal, false);
                        showSuccess(r.data.message);
                        return;
                    }
                    // 1st audit → show the report.
                    if (r.status === 202 && r.data.results_url) {
                        window.location.href = r.data.results_url;
                        return;
                    }
                    if (r.data && r.data.require === 'email') {
                        setLoading(false); setEmailLoading(false);
                        openEmailModal(r.data.message);
                        return;
                    }
                    if (r.data && r.data.require === 'signup') {
                        setLoading(false); setEmailLoading(false);
                        toggleModal(emailModal, false);
                        openSignupModal(r.data.message);
                        return;
                    }
                    // Errors.
                    var msg = r.data.message;
                    var errs = r.data.errors || {};
                    if (!msg) {
                        var first = Object.keys(errs)[0];
                        if (first && errs[first] && errs[first][0]) { msg = errs[first][0]; }
                    }
                    msg = msg || 'Something went wrong. Please try again.';
                    // Only re-run the captcha if it was the thing that failed.
                    if (errs['g-recaptcha-response']) { resetCaptcha(); }
                    if (emailModal && emailModal.classList.contains('flex')) {
                        if (emailError) { emailError.textContent = msg; emailError.classList.remove('hidden'); }
                    } else {
                        showError(msg);
                    }
                    setLoading(false); setEmailLoading(false);
                }).catch(function () {
                    showError('Network error. Please check your connection and try again.');
                    setLoading(false); setEmailLoading(false);
                });
            }

            form.addEventListener('submit', function (e) { e.preventDefault(); runAudit(); });

            if (emailForm) {
                emailForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    if (emailError) { emailError.classList.add('hidden'); }
                    var nm = (nameInput.value || '').trim();
                    var em = (emailInput.value || '').trim();
                    if (!nm) { emailError.textContent = 'Please enter your name.'; emailError.classList.remove('hidden'); nameInput.focus(); return; }
                    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(em)) { emailError.textContent = 'Please enter a valid email address.'; emailError.classList.remove('hidden'); emailInput.focus(); return; }
                    capturedName = nm;
                    capturedEmail = em;
                    runAudit();
                });
            }
        })();
    </script>
</x-marketing.page>
