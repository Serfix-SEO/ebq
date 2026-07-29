@php
    /**
     * Content AI Autopilot — its OWN pricing page.
     *
     * Split out of /pricing on 2026-07-29: the two products used to share one
     * grid behind a small pill toggle, so a first-time visitor never realised
     * there were two catalogues and read the mixed numbers as one confusing
     * list. /pricing is now the SEO platform only; this page is the content
     * product, priced and explained in full.
     *
     * Every number comes from ContentAutopilotConfig — the same source the
     * in-app Get started screen and the landing page read, so the three can
     * never drift.
     */
    $cfg      = \App\Support\ContentAutopilotConfig::class;
    $authed   = auth()->check();
    $monthly  = $cfg::displayPrice('monthly');
    $annual   = $cfg::displayPrice('annual');
    $first    = $cfg::displayPrice('first_month');
    $addonM   = $cfg::displayPrice('addon_monthly');
    $addonA   = $cfg::displayPrice('addon_annual');
    $trialDays = $cfg::trialDays();
    $trialArticles = $cfg::trialArticles();
    $articles = $cfg::monthlyArticlesPerWebsite();
    $trackerKeywords = $cfg::trackerKeywords();

    // Anonymous visitors start in the public onboarding (they see the whole
    // wizard and a real plan before signing up); signed-in users go to the
    // in-app Get started, which knows their entitlement state.
    $startUrl = $authed ? route('content.get-started') : route('content.landing');

    // ── What you actually get, grouped the way a buyer evaluates it ────────
    $featureGroups = [
        [
            'title' => __('Research'),
            'blurb' => __('Every article starts from evidence, not a blank prompt.'),
            'items' => [
                __('Your site is crawled and profiled — what you sell, who you serve, how you talk'),
                __('Real competitors discovered from the searches you actually compete in'),
                __('Keyword gap analysis: what they rank for and you don\'t'),
                __('Search volumes and intent for every planned topic'),
                __('A month of topics planned in advance, ordered by opportunity'),
            ],
        ],
        [
            'title' => __('Writing'),
            'blurb' => __('Long-form drafts that read like a person wrote them.'),
            'items' => [
                __(':n articles per website every month', ['n' => $articles]),
                __('Full-length drafts with headings, key takeaways, FAQ and internal links'),
                __('Written against a brief built from your offerings and your audience'),
                __('Humanising pass that strips the tells of machine writing'),
                __('Competitor mentions blocked automatically'),
                __('Your rules honoured — tone, banned phrases, things you don\'t sell'),
            ],
        ],
        [
            'title' => __('Optimisation'),
            'blurb' => __('Scored and revised before you ever see it.'),
            'items' => [
                __('On-page SEO score with 30+ weighted checks'),
                __('Automatic revision loop until the draft clears the quality bar'),
                __('Meta title, description, slug, canonical and robots per article'),
                __('Open Graph and Twitter cards with live Google and social previews'),
                __('Original images generated and placed inside the article'),
            ],
        ],
        [
            'title' => __('Publishing'),
            'blurb' => __('From draft to live page without a copy-paste step.'),
            'items' => [
                __('Publishes straight to WordPress, or any site via webhook'),
                __('Scheduled inside a publishing window you choose'),
                __('Review-first or fully automatic — your call, changeable anytime'),
                __('Images uploaded to your media library, not hotlinked'),
                __('New URLs submitted to Google for indexing when Search Console is connected'),
            ],
        ],
        [
            'title' => __('Proof it worked'),
            'blurb' => __('The part most AI writers leave out.'),
            'items' => [
                __('Up to :n tracked keywords per website', ['n' => number_format($trackerKeywords)]),
                __('Live Google position checks, kept as history you can chart'),
                __('Search Console clicks, impressions and average position per article'),
                __('Analytics visitors per published article, day by day'),
                __('Email alert when your rankings move up'),
            ],
        ],
    ];

    $steps = [
        ['n' => '01', 'title' => __('Tell us about your site'), 'body' => __('Enter your domain. We crawl it, work out what you sell and who you sell to, and you correct anything we got wrong.')],
        ['n' => '02', 'title' => __('We research your market'), 'body' => __('Real competitors, the keywords they win, and the gaps you can realistically take — turned into a month of planned topics.')],
        ['n' => '03', 'title' => __('Articles get written and scored'), 'body' => __('Each draft is researched, written, humanised, scored against 30+ on-page checks, and revised until it passes.')],
        ['n' => '04', 'title' => __('You approve, or it just ships'), 'body' => __('Review drafts in the editor, or leave auto-publish on and let them go live in your chosen window.')],
        ['n' => '05', 'title' => __('You watch it rank'), 'body' => __('Every published article\'s keywords are tracked. Positions, clicks and visitors land in one place, with an email when things move up.')],
    ];

    $faqs = [
        ['q' => __('Is this the same thing as the SEO platform?'), 'a' => __('No. Content AI Autopilot is a separate product with separate pricing — it plans, writes and publishes articles. The SEO platform handles audits, rank tracking, keyword research and reporting. You can buy either one on its own, or both.')],
        ['q' => __('Do I need the SEO platform to use this?'), 'a' => __('No. Content AI Autopilot works entirely on its own, including the keyword tracking and performance reporting for the articles it publishes.')],
        ['q' => __('What does the free trial include?'), 'a' => __(':d days and :n articles on one website, with no card required. You see the research, the drafts and the scores before you decide.', ['d' => $trialDays, 'n' => $trialArticles])],
        ['q' => __('Can I edit articles before they publish?'), 'a' => __('Yes. Every draft opens in a full editor with the live SEO score, AI edits on selected text, image tools and the complete set of meta fields. Turn auto-publish off and nothing goes live without your approval.')],
        ['q' => __('Where do the articles publish to?'), 'a' => __('WordPress via a secure application password, or any platform that accepts a webhook. Images are uploaded to your own media library.')],
        ['q' => __('What if the writing does not sound like us?'), 'a' => __('You set the tone, the things you never want mentioned, and phrases to avoid. There is also a thumbs-up/rewrite control on every article, and a fresh draft is one click away.')],
        ['q' => __('How many websites can I run?'), 'a' => __('One website is included. Add as many as you like at $:m/mo each (or $:a/mo billed yearly).', ['m' => $addonM, 'a' => $addonA])],
        ['q' => __('Can I cancel?'), 'a' => __('Anytime, with no long-term contract. Articles already published stay on your site — they are yours.')],
    ];

    $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $offerSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'Serfix Content AI Autopilot',
        'description' => 'Researches, writes, optimises and publishes SEO articles to your website on a schedule, then tracks how they rank.',
        'brand' => ['@type' => 'Brand', 'name' => 'Serfix'],
        'offers' => [
            '@type' => 'Offer',
            'price' => (string) $monthly,
            'priceCurrency' => 'USD',
            'url' => route('content.pricing'),
            'availability' => 'https://schema.org/InStock',
        ],
    ];
    $faqSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => array_map(fn ($f) => [
            '@type' => 'Question',
            'name' => $f['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
        ], $faqs),
    ];
@endphp

<x-marketing.page
    title="Content AI Autopilot Pricing | Articles Researched, Written & Published"
    description="Pricing for Serfix Content AI Autopilot: researched, written, optimised and auto-published SEO articles, with rank tracking that proves they worked. Free trial, no card."
    active="content"
>
    <x-slot:schema>
        <script type="application/ld+json">{!! json_encode($offerSchema, $jsonFlags) !!}</script>
        <script type="application/ld+json">{!! json_encode($faqSchema, $jsonFlags) !!}</script>
    </x-slot:schema>

    {{-- ── Hero ─────────────────────────────────────────────────── --}}
    <section class="relative overflow-hidden border-b border-slate-200 bg-gradient-to-b from-orange-50/70 via-white to-white">
        <div class="mx-auto max-w-6xl px-6 py-16 text-center lg:px-8 lg:py-20">
            <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-orange-600">{{ __('Content AI Autopilot pricing') }}</p>
            <h1 class="mx-auto mt-4 max-w-3xl text-balance text-4xl font-extrabold tracking-tight text-slate-900 sm:text-5xl">
                {{ __('One price. Articles researched, written, published and tracked.') }}
            </h1>
            <p class="mx-auto mt-5 max-w-2xl text-lg text-slate-600">
                {{ __('This is our content product, priced on its own page because it is a separate product from the Serfix SEO platform.') }}
                <a href="{{ route('pricing') }}" class="font-semibold text-orange-600 underline underline-offset-4 hover:text-orange-700">{{ __('Looking for SEO platform pricing?') }}</a>
            </p>
            <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
                <a href="{{ $startUrl }}" class="inline-flex items-center gap-2 rounded-xl bg-orange-600 px-7 py-3.5 text-sm font-bold text-white shadow-lg shadow-orange-600/25 transition hover:bg-orange-700">
                    {{ __('Start free — no card') }}
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                </a>
                <a href="#plan" class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-7 py-3.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">{{ __('See what\'s included') }}</a>
            </div>
            <p class="mt-4 text-sm text-slate-500">{{ __(':d-day free trial · :n articles · cancel anytime', ['d' => $trialDays, 'n' => $trialArticles]) }}</p>
        </div>
    </section>

    {{-- ── The plan ─────────────────────────────────────────────── --}}
    <section id="plan" class="border-b border-slate-200 bg-white py-16 sm:py-20">
        <div class="mx-auto max-w-5xl px-6 lg:px-8">
            <div class="grid gap-6 lg:grid-cols-3">

                {{-- Trial --}}
                <div class="rounded-2xl border border-slate-200 bg-white p-7">
                    <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-slate-500">{{ __('Free trial') }}</p>
                    <p class="mt-4 text-4xl font-extrabold tracking-tight text-slate-900">$0</p>
                    <p class="mt-2 text-sm text-slate-600">{{ __('No card required.') }}</p>
                    <ul class="mt-6 space-y-2.5 text-sm text-slate-600">
                        <li class="flex gap-2"><span class="text-success">✓</span>{{ __(':n full articles', ['n' => $trialArticles]) }}</li>
                        <li class="flex gap-2"><span class="text-success">✓</span>{{ __('One website') }}</li>
                        <li class="flex gap-2"><span class="text-success">✓</span>{{ __('The complete research and writing pipeline') }}</li>
                        <li class="flex gap-2"><span class="text-success">✓</span>{{ __(':d days to decide', ['d' => $trialDays]) }}</li>
                    </ul>
                    <a href="{{ $startUrl }}" class="mt-7 block rounded-xl border border-slate-300 px-5 py-3 text-center text-sm font-semibold text-slate-800 transition hover:bg-slate-50">{{ __('Start free') }}</a>
                </div>

                {{-- Main plan --}}
                <div class="relative rounded-2xl border-2 border-orange-500 bg-white p-7 shadow-xl shadow-orange-600/10">
                    <span class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-orange-600 px-3 py-1 text-[10px] font-bold uppercase tracking-widest text-white">{{ __('The plan') }}</span>
                    <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-slate-500">{{ __('Content AI Autopilot') }}</p>
                    <div class="mt-4 flex items-baseline gap-1.5">
                        <span class="text-4xl font-extrabold tracking-tight text-slate-900">${{ $monthly }}</span>
                        <span class="text-sm font-medium text-slate-500">{{ __('/mo per website') }}</span>
                    </div>
                    <p class="mt-2 text-sm text-slate-600">{{ __('or $:a/mo billed yearly', ['a' => $annual]) }}</p>
                    @if ($first && $first < $monthly)
                        <p class="mt-2">
                            <span class="inline-block rounded bg-emerald-100 px-2 py-1 text-xs font-bold text-emerald-700">{{ __('First month $:f', ['f' => $first]) }}</span>
                        </p>
                    @endif
                    <ul class="mt-6 space-y-2.5 text-sm text-slate-700">
                        <li class="flex gap-2"><span class="text-success">✓</span><strong>{{ __(':n articles / month', ['n' => $articles]) }}</strong></li>
                        <li class="flex gap-2"><span class="text-success">✓</span>{{ __('Market + competitor research every month') }}</li>
                        <li class="flex gap-2"><span class="text-success">✓</span>{{ __('Images generated and placed for you') }}</li>
                        <li class="flex gap-2"><span class="text-success">✓</span>{{ __('Auto-publishing to WordPress or webhook') }}</li>
                        <li class="flex gap-2"><span class="text-success">✓</span>{{ __(':n tracked keywords + rank history', ['n' => number_format($trackerKeywords)]) }}</li>
                        <li class="flex gap-2"><span class="text-success">✓</span>{{ __('Search Console + Analytics reporting') }}</li>
                    </ul>
                    <a href="{{ $startUrl }}" class="mt-7 block rounded-xl bg-orange-600 px-5 py-3 text-center text-sm font-bold text-white shadow-lg shadow-orange-600/25 transition hover:bg-orange-700">{{ __('Start free — no card') }}</a>
                </div>

                {{-- Extra sites --}}
                <div class="rounded-2xl border border-slate-200 bg-white p-7">
                    <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-slate-500">{{ __('Extra websites') }}</p>
                    <div class="mt-4 flex items-baseline gap-1.5">
                        <span class="text-4xl font-extrabold tracking-tight text-slate-900">${{ $addonM }}</span>
                        <span class="text-sm font-medium text-slate-500">{{ __('/mo each') }}</span>
                    </div>
                    <p class="mt-2 text-sm text-slate-600">{{ __('or $:a/mo billed yearly', ['a' => $addonA]) }}</p>
                    <ul class="mt-6 space-y-2.5 text-sm text-slate-600">
                        <li class="flex gap-2"><span class="text-success">✓</span>{{ __('Everything in the plan, per site') }}</li>
                        <li class="flex gap-2"><span class="text-success">✓</span>{{ __('Its own research, calendar and tracker') }}</li>
                        <li class="flex gap-2"><span class="text-success">✓</span>{{ __('Add or remove sites anytime') }}</li>
                        <li class="flex gap-2"><span class="text-success">✓</span>{{ __('Built for agencies and multi-brand owners') }}</li>
                    </ul>
                    <a href="{{ route('contact') }}" class="mt-7 block rounded-xl border border-slate-300 px-5 py-3 text-center text-sm font-semibold text-slate-800 transition hover:bg-slate-50">{{ __('Talk to us about volume') }}</a>
                </div>
            </div>

            <p class="mt-8 text-center text-xs text-slate-500">
                {{ __('Prices in USD. Content AI Autopilot is billed separately from the SEO platform — you can subscribe to either product on its own.') }}
            </p>
        </div>
    </section>

    {{-- ── Visual walkthrough ───────────────────────────────────── --}}
    <section class="border-b border-slate-200 bg-slate-50 py-16 sm:py-20">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="text-center">
                <h2 class="text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">{{ __('What you actually get') }}</h2>
                <p class="mx-auto mt-3 max-w-2xl text-base text-slate-600">{{ __('These are the real screens, with a sample brand\'s data.') }}</p>
            </div>

            {{-- 1. Calendar --}}
            <div class="mt-12 grid items-center gap-8 lg:grid-cols-2">
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-orange-600">{{ __('The calendar') }}</p>
                    <h3 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">{{ __('A month of articles, planned before anything is written') }}</h3>
                    <p class="mt-3 text-base text-slate-600">{{ __('Topics come from searches your audience already makes and gaps your competitors are winning — not from a generic list. Every card shows where it is in the pipeline, and you can reorder, skip or write one on demand.') }}</p>
                </div>
                <img src="{{ asset('images/content/calendar.webp') }}" alt="{{ __('Content calendar showing a month of planned and published articles') }}"
                     width="1200" height="960" loading="lazy" class="w-full rounded-2xl border border-slate-200 shadow-xl">
            </div>

            {{-- 2. Article + score --}}
            <div class="mt-16 grid items-center gap-8 lg:grid-cols-2">
                <img src="{{ asset('images/content/article-score.webp') }}" alt="{{ __('Article draft with its on-page SEO score and checks') }}"
                     width="1200" height="866" loading="lazy" class="w-full rounded-2xl border border-slate-200 shadow-xl lg:order-2">
                <div class="lg:order-1">
                    <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-orange-600">{{ __('The article') }}</p>
                    <h3 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">{{ __('Scored against 30+ checks, and revised until it passes') }}</h3>
                    <p class="mt-3 text-base text-slate-600">{{ __('Each draft is measured on keyphrase placement, structure, length, links, readability and more. If it falls short, it is rewritten before it reaches you — so what you review is already optimised, not a first attempt.') }}</p>
                </div>
            </div>

            {{-- 3. Tracker --}}
            <div class="mt-16 grid items-center gap-8 lg:grid-cols-2">
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-orange-600">{{ __('The tracker') }}</p>
                    <h3 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">{{ __('Every published article is tracked automatically') }}</h3>
                    <p class="mt-3 text-base text-slate-600">{{ __('Its keywords are added to the tracker the moment it goes live, with live Google positions alongside Search Console clicks, impressions and average position. No spreadsheet, no manual setup.') }}</p>
                </div>
                <img src="{{ asset('images/content/tracker.webp') }}" alt="{{ __('Keyword tracker showing positions, clicks and impressions per keyword') }}"
                     width="1200" height="489" loading="lazy" class="w-full rounded-2xl border border-slate-200 shadow-xl">
            </div>

            {{-- 4. Rank history --}}
            <div class="mt-16 grid items-center gap-8 lg:grid-cols-2">
                <img src="{{ asset('images/content/rank-chart.webp') }}" alt="{{ __('Chart of a keyword climbing from position 47 to position 4') }}"
                     width="1200" height="384" loading="lazy" class="w-full rounded-2xl border border-slate-200 shadow-xl lg:order-2">
                <div class="lg:order-1">
                    <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-orange-600">{{ __('The proof') }}</p>
                    <h3 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">{{ __('Watch each keyword climb, week by week') }}</h3>
                    <p class="mt-3 text-base text-slate-600">{{ __('Positions are recorded every week and kept as history, so you can show exactly what the content did over months — and we email you when rankings move up.') }}</p>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Everything included ──────────────────────────────────── --}}
    <section class="border-b border-slate-200 bg-white py-16 sm:py-20">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="text-center">
                <h2 class="text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">{{ __('Everything included in the plan') }}</h2>
                <p class="mx-auto mt-3 max-w-2xl text-base text-slate-600">{{ __('One price covers the whole pipeline — research through to reporting.') }}</p>
            </div>
            <div class="mt-12 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($featureGroups as $group)
                    <div class="rounded-2xl border border-slate-200 bg-white p-6">
                        <h3 class="text-lg font-bold text-slate-900">{{ $group['title'] }}</h3>
                        <p class="mt-1 text-sm text-slate-500">{{ $group['blurb'] }}</p>
                        <ul class="mt-4 space-y-2.5">
                            @foreach ($group['items'] as $item)
                                <li class="flex gap-2.5 text-sm text-slate-700">
                                    <svg class="mt-0.5 h-4 w-4 flex-none text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                    <span>{{ $item }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── How it works ─────────────────────────────────────────── --}}
    <section class="border-b border-slate-200 bg-slate-50 py-16 sm:py-20">
        <div class="mx-auto max-w-5xl px-6 lg:px-8">
            <h2 class="text-center text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">{{ __('How it works') }}</h2>
            <ol class="mt-12 space-y-6">
                @foreach ($steps as $step)
                    <li class="flex gap-5 rounded-2xl border border-slate-200 bg-white p-6">
                        <span class="text-2xl font-extrabold text-orange-500">{{ $step['n'] }}</span>
                        <div>
                            <h3 class="text-lg font-bold text-slate-900">{{ $step['title'] }}</h3>
                            <p class="mt-1 text-sm text-slate-600">{{ $step['body'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- ── Which product? ───────────────────────────────────────── --}}
    <section class="border-b border-slate-200 bg-white py-16 sm:py-20">
        <div class="mx-auto max-w-5xl px-6 lg:px-8">
            <h2 class="text-center text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">{{ __('Two products, priced separately') }}</h2>
            <p class="mx-auto mt-3 max-w-2xl text-center text-base text-slate-600">{{ __('Buy either on its own. Many clients run both, but nothing here requires the other.') }}</p>
            <div class="mt-10 grid gap-6 md:grid-cols-2">
                <div class="rounded-2xl border-2 border-orange-500 bg-orange-50/40 p-7">
                    <h3 class="text-lg font-bold text-slate-900">{{ __('Content AI Autopilot') }}</h3>
                    <p class="mt-1 text-sm font-semibold text-orange-700">{{ __('$:m/mo per website', ['m' => $monthly]) }} · {{ __('this page') }}</p>
                    <p class="mt-3 text-sm text-slate-600">{{ __('Plans, writes, optimises and publishes articles for you, then tracks how they rank.') }}</p>
                    <p class="mt-4 text-sm font-semibold text-slate-900">{{ __('Choose this if you need content produced.') }}</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-7">
                    <h3 class="text-lg font-bold text-slate-900">{{ __('SEO Platform') }}</h3>
                    <p class="mt-1 text-sm font-semibold text-slate-500">{{ __('From $19/mo') }}</p>
                    <p class="mt-3 text-sm text-slate-600">{{ __('Site audits, rank tracking, keyword research, backlink and competitor analysis, client reporting and the WordPress plugin.') }}</p>
                    <p class="mt-4 text-sm font-semibold text-slate-900">{{ __('Choose this if you need to measure and fix SEO.') }}</p>
                    <a href="{{ route('pricing') }}" class="mt-4 inline-flex items-center gap-1.5 text-sm font-bold text-slate-900 underline-offset-2 hover:underline">
                        {{ __('See SEO Platform pricing') }}
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                    </a>
                </div>
            </div>
        </div>
    </section>

    {{-- ── FAQ ──────────────────────────────────────────────────── --}}
    <section id="faq" class="border-b border-slate-200 bg-slate-50 py-16 sm:py-20">
        <div class="mx-auto max-w-3xl px-6 lg:px-8">
            <h2 class="text-center text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">{{ __('Questions') }}</h2>
            <dl class="mt-10 space-y-4">
                @foreach ($faqs as $faq)
                    <div class="rounded-2xl border border-slate-200 bg-white p-6">
                        <dt class="text-base font-bold text-slate-900">{{ $faq['q'] }}</dt>
                        <dd class="mt-2 text-sm leading-relaxed text-slate-600">{{ $faq['a'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </section>

    {{-- ── CTA ──────────────────────────────────────────────────── --}}
    <section class="bg-white py-16 sm:py-20">
        <div class="mx-auto max-w-3xl px-6 text-center lg:px-8">
            <h2 class="text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">{{ __('See your first articles before you pay') }}</h2>
            <p class="mx-auto mt-4 max-w-xl text-base text-slate-600">{{ __('Enter your domain and watch the research run: your competitors, your keyword gaps, and a month of topics — all before an account is created.') }}</p>
            <a href="{{ $startUrl }}" class="mt-8 inline-flex items-center gap-2 rounded-xl bg-orange-600 px-8 py-4 text-sm font-bold text-white shadow-lg shadow-orange-600/25 transition hover:bg-orange-700">
                {{ __('Start free — no card') }}
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
            </a>
        </div>
    </section>
</x-marketing.page>
