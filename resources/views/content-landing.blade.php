@php
    /**
     * Content AI Autopilot — the public landing page.
     *
     * Rebuilt 2026-07-30 into a full sales page. The old version was hero →
     * feature grid → 4 steps → 2 price cards; visitors had no idea what the
     * product actually looked like, what it replaced, or why publishing
     * consistently matters. The page now walks the same path a buyer does:
     *   hero (with the real calendar screen) → live interactive demo →
     *   the quality objection ("is this AI slop?") → the cost comparison →
     *   what you get → the SEO platform cross-sell → pricing (incl. extra
     *   sites) → why consistency matters → FAQ → our own blog → CTA.
     *
     * Rules this page must keep:
     *  - Every price/limit comes from ContentAutopilotConfig, never hardcoded —
     *    the landing page, the pricing page and the in-app screens read one
     *    source and can never drift.
     *  - Client-facing copy only: outcomes, never our data vendors, model names
     *    or internal pipeline detail (pinned by PricingPagesTest).
     *  - Product visuals are REAL product UI rendered against invented sample
     *    data (see tests/Feature/Content/MarketingShotsTest.php) — no client's
     *    content, and no mocked-up screens that the product cannot produce.
     *  - Statistics are attributed to their published source. No invented
     *    percentages.
     *  - Every utility class must exist in the compiled Tailwind bundle: run
     *    `npm run build` after editing (see project memory: prebuilt bundle).
     */
    $cfg = \App\Support\ContentAutopilotConfig::class;
    $recaptcha = \App\Support\Recaptcha::isEnabled();

    $monthly  = $cfg::displayPrice('monthly');
    $annual   = $cfg::displayPrice('annual');
    $first    = $cfg::displayPrice('first_month');
    $addonM   = $cfg::displayPrice('addon_monthly');
    $addonA   = $cfg::displayPrice('addon_annual');
    $articles = $cfg::monthlyArticlesPerWebsite();
    $trialDays = $cfg::trialDays();
    $trialArticles = $cfg::trialArticles();
    $trackerKeywords = $cfg::trackerKeywords();

    // Yearly saving, derived — so a price change in admin updates the badge.
    $savePct = $monthly > 0 ? (int) round(($monthly - $annual) / $monthly * 100) : 0;
    // Worked example for the extra-sites table: one included site + two add-ons.
    $threeSitesMonthly = $monthly + (2 * $addonM);
    $threeSitesAnnual  = $annual + (2 * $addonA);

    // Our own blog runs on the very product this page sells, so the freshest
    // proof is three real published articles. The table lives in the delivery
    // package; a fresh install without it must not 500 the landing page.
    $latestPosts = rescue(
        fn () => \Serfix\ContentAi\Models\Article::query()->published()->latest('published_at')->take(3)->get(),
        collect(),
        false,
    );

    $faqs = [
        ['q' => __('Is this content good enough to publish?'), 'a' => __('That is the whole design. Every draft is researched against real search results, written long-form to a brief, put through a humanising pass, then scored on 30+ on-page checks and rewritten until it passes. You still read it before it goes live — and you can edit any word.')],
        ['q' => __('Can I edit or reject an article?'), 'a' => __('Yes. Every draft opens in a full editor with the live SEO score, AI rewriting on selected text, image tools and the complete set of meta fields. Turn auto-publish off and nothing goes live without your approval.')],
        ['q' => __('How does it get published to my website?'), 'a' => __('Connect WordPress with a secure application password, or any other platform through a webhook. Images upload into your own media library, and the SEO fields and schema travel with the post.')],
        ['q' => __('Do you write about my actual business?'), 'a' => __('We read your website first — what you sell, who you serve, how you talk — and you correct anything we got wrong before a word is written. Things you do not sell are never claimed, and competitor names are blocked automatically.')],
        ['q' => __('What if I want an article on a specific topic?'), 'a' => __('Add it to the calendar yourself and hit Write now. You can also reorder, reschedule or skip anything we planned.')],
        ['q' => __('How do I know it is working?'), 'a' => __('Each published article\'s keywords go into the tracker automatically — up to :n per website — with live Google positions, Search Console clicks and impressions, and Analytics visitors per article. We email you when rankings move up.', ['n' => number_format($trackerKeywords)])],
        ['q' => __('What does the free trial include?'), 'a' => __(':d days and :n articles on one website, with no card required. You see the research, the drafts and the scores before you decide.', ['d' => $trialDays, 'n' => $trialArticles])],
        ['q' => __('Can I cancel?'), 'a' => __('Anytime, with no contract. Articles already published stay on your site — they are yours.')],
    ];

    $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
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
    title="Content AI Autopilot — SEO Articles Written & Published For You"
    description="Serfix researches your niche, writes genuinely useful SEO articles, illustrates them, and publishes to your site on schedule — then tracks how they rank. Free trial, no card."
    active="content"
    :canonical="config('features.seo_platform_ui') ? null : url('/')"
>
    <x-slot:schema>
        <script type="application/ld+json">{!! json_encode($faqSchema, $jsonFlags) !!}</script>
    </x-slot:schema>

    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section id="start" class="relative overflow-hidden border-b border-slate-200 bg-gradient-to-b from-orange-50/70 via-white to-white">
        <div class="pointer-events-none absolute -top-32 start-1/2 h-72 w-[42rem] -translate-x-1/2 rounded-full bg-gradient-to-r from-orange-300 to-amber-200 opacity-40 blur-3xl"></div>

        <div class="mx-auto max-w-6xl px-6 pb-14 pt-10 lg:px-8 lg:pb-20 lg:pt-16">
            <div class="grid items-center gap-12 lg:grid-cols-2">
                {{-- Left: the pitch + domain capture --}}
                <div class="text-center lg:text-start">
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-orange-200 bg-white/70 px-3.5 py-1 text-[11px] font-bold uppercase tracking-[0.18em] text-orange-600 shadow-sm backdrop-blur">
                        <span class="h-1.5 w-1.5 rounded-full bg-orange-500"></span>{{ __('AI content writer') }}
                    </span>
                    <h1 class="mt-5 text-balance text-4xl font-extrabold tracking-tight text-slate-900 sm:text-5xl lg:text-[3.4rem] lg:leading-[1.05]">
                        {{ __('Expert SEO articles,') }}
                        <span class="bg-gradient-to-r from-orange-500 to-orange-600 bg-clip-text text-transparent">{{ __('written & published for you') }}</span>
                    </h1>
                    <p class="mt-5 max-w-xl text-balance text-lg leading-8 text-slate-600 lg:mx-0">
                        {{ __('Enter your website and we research your niche, write genuinely useful articles, optimize them for search, add images, and publish on your schedule — you review and approve everything.') }}
                    </p>

                    <form method="POST" action="{{ route('content.onboarding.begin') }}" class="mt-7 max-w-xl">
                        @csrf
                        <p class="mb-2 text-xs font-bold uppercase tracking-[0.16em] text-slate-500">{{ __('Your website') }}</p>
                        <div class="flex flex-col gap-2.5 rounded-2xl border border-slate-200 bg-white p-2 shadow-xl shadow-slate-900/5 sm:flex-row sm:items-center sm:rounded-full sm:p-2">
                            <div class="flex flex-1 items-center gap-2.5 ps-3">
                                <svg class="h-5 w-5 flex-none text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.5-2.5 3.75-5.75 3.75-9S14.5 5.5 12 3m0 18c-2.5-2.5-3.75-5.75-3.75-9S9.5 5.5 12 3M3.6 9h16.8M3.6 15h16.8" /></svg>
                                <input type="text" name="domain" value="{{ old('domain') }}" placeholder="yourwebsite.com" aria-label="{{ __('Your website') }}"
                                    required inputmode="url" autocomplete="url"
                                    class="w-full border-0 bg-transparent py-3 text-[15px] text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-0" />
                            </div>
                            <button type="submit" class="inline-flex items-center justify-center gap-1.5 rounded-full bg-gradient-to-r from-orange-500 to-orange-600 px-7 py-3.5 text-sm font-bold text-white shadow-lg shadow-orange-600/30 transition hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-orange-500/40">
                                {{ __('Get started') }}
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                            </button>
                        </div>
                        @error('domain') <p class="mt-2.5 text-sm font-medium text-red-600">{{ $message }}</p> @enderror
                        @error('g-recaptcha-response') <p class="mt-2.5 text-sm font-medium text-red-600">{{ $message }}</p> @enderror
                        @if ($recaptcha)
                            <div class="mt-4 flex justify-center lg:justify-start"><div class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.site_key') }}"></div></div>
                        @endif
                    </form>

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

            {{-- The pipeline, in four words --}}
            <div class="mt-14 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    [__('Research'), __('Your market')],
                    [__('Write'), __('Long-form drafts')],
                    [__('Optimize'), __('30+ SEO checks')],
                    [__('Publish'), __('Live on your site')],
                ] as $i => [$label, $sub])
                    <div class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white/70 px-4 py-3.5 shadow-sm backdrop-blur">
                        <span class="flex h-8 w-8 flex-none items-center justify-center rounded-full bg-orange-100 text-sm font-extrabold text-orange-700">{{ $i + 1 }}</span>
                        <span>
                            <span class="block text-sm font-bold text-slate-900">{{ $label }}</span>
                            <span class="block text-xs text-slate-500">{{ $sub }}</span>
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── See it in action (interactive product tour) ────────── --}}
    {{-- Desktop only. The walkthrough is a fixed 2.16 aspect ratio, so on a
         phone it collapses to a ~240px-tall pane of someone else's UI that
         cannot be clicked through usefully — worse than not offering it. The
         iframe is inside the hidden container, so mobile never pays to load
         the third-party embed either. --}}
    <section id="demo" class="hidden scroll-mt-20 border-b border-slate-200 bg-slate-50 lg:block">
        <div class="mx-auto max-w-5xl px-6 py-16 lg:px-8 lg:py-20">
            <div class="text-center">
                <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-orange-600">{{ __('Product tour') }}</p>
                <h2 class="mt-3 text-balance text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">{{ __('See it in action') }}</h2>
                <p class="mx-auto mt-3 max-w-xl text-base text-slate-600">{{ __('Click through the real setup, from your domain to a planned month of articles. No signup, no sales call.') }}</p>
            </div>

            {{-- Interactive walkthrough (external embed), framed like every other
                 product visual on this page: rounded, bordered, lifted.

                 The vendor snippet arrived with `padding: 40px 0` baked into the
                 wrapper, which pushed the embed 40px out of the page's spacing
                 scale at BOTH ends — 120px before the next section against 80px
                 everywhere else. The gap is a normal top margin now, and the
                 bottom is left to the section's own padding.

                 Corners are clipped on the WRAPPER (overflow-hidden), not the
                 iframe: an iframe's own border-radius does not clip what the
                 embedded document paints. Aspect ratio and the viewport cap stay
                 inline — they are the embed's sizing contract, not styling. --}}
            <div class="relative mt-12 w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl"
                 style="max-height: 80vh; max-height: 80svh; aspect-ratio: 2.16;">
                {{-- The vendor's snippet also carries the legacy webkit/moz fullscreen
                     attributes; both are obsolete (every current browser honours plain
                     `allowfullscreen`), and the `moz` one trips the supplier-name guard
                     in PricingPagesTest. Dropped deliberately — do not paste them back. --}}
                <iframe src="https://app.supademo.com/embed/cms6473jf32eyqmqqm19mxp9j?embed_v=2&utm_source=embed" loading="lazy" title="{{ __('Set up your content platform on Serfix') }}" allow="clipboard-write" frameborder="0" allowfullscreen class="absolute inset-0 h-full w-full rounded-2xl"></iframe>
            </div>
        </div>
    </section>

    {{-- ── The quality objection ─────────────────────────────── --}}
    <section class="border-b border-slate-200 bg-white">
        <div class="mx-auto max-w-6xl px-6 py-16 lg:px-8 lg:py-20">
            <div class="grid items-center gap-10 lg:grid-cols-2">
                <img src="{{ asset('images/content/article-score.webp') }}"
                     alt="{{ __('A finished draft next to its on-page SEO score and the checks behind it') }}"
                     width="1320" height="1000" loading="lazy"
                     class="w-full rounded-2xl border border-slate-200 shadow-xl">
                <div>
                    <h2 class="text-balance text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">
                        {{ __('Articles that don\'t read like "AI content".') }}
                    </h2>
                    <p class="mt-4 text-base leading-7 text-slate-600">
                        {{ __('Most AI writers produce generic, repetitive text. Ours is different: every draft starts from real research, gets a humanising pass that strips the machine tells, and is scored and rewritten until it clears the quality bar — before you ever see it.') }}
                    </p>
                    <ul class="mt-7 space-y-3.5">
                        @foreach ([
                            [__('Written in your voice'), __('Your tone, your rules, and phrases you never want used.')],
                            [__('Linked to your own pages'), __('Internal links to the relevant pages you already have.')],
                            [__('Grounded in your real offering'), __('Things you don\'t sell are never claimed, and competitor names are blocked.')],
                            [__('Scored before it reaches you'), __('30+ weighted on-page checks, with an automatic revision loop.')],
                        ] as [$title, $desc])
                            <li class="flex gap-3">
                                <svg class="mt-1 h-5 w-5 flex-none text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                <span>
                                    <span class="block text-[15px] font-bold text-slate-900">{{ $title }}</span>
                                    <span class="block text-sm leading-6 text-slate-600">{{ $desc }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Real results (anonymised case study) ───────────────── --}}
    {{-- Placed BEFORE the money sections on purpose: nobody weighs $39 against
         an agency's $3,000 until they believe the thing works.

         Rules this block must keep:
          - ANONYMISED. No client name, domain or logo. The four screenshots
            were checked before committing: none carries a property selector,
            URL bar or site name. The calendar's article titles reveal the
            niche, not the company.
          - Per-day rates are the headline. The two windows are unequal (21
            days before, 7 after), so raw totals mislead — clicks read as flat
            (40 → 38) when the daily rate nearly tripled.
          - The CTR FALL IS SHOWN, not buried. A page that prints only the
            numbers that went up is a page nobody believes.
          - Numbers are literal, typed from the Search Console UI. NEVER derive
            them from our own `search_console_data` table: query-level sampling
            makes our stored totals materially lower (our DB has 323
            impressions for the before window; Google's UI says 383), so a
            DB-driven figure would contradict the screenshot beside it. Same
            reason this is a before/after comparison and not a daily series. --}}    <section id="results" class="scroll-mt-20 border-b border-slate-200 bg-slate-50">
        @php
            $caseStart = \Illuminate\Support\Carbon::create(2026, 7, 23);
            $caseShots = [
                'month' => 'cases/gsc-28-days.webp',
                'before' => 'cases/gsc-before.webp',
                'after' => 'cases/gsc-after.webp',
                'calendar' => 'cases/calendar-run.webp',
            ];
            // Each screenshot is optional: a missing file degrades to the copy
            // rather than rendering a broken image.
            $caseHas = array_map(fn ($p) => is_file(public_path($p)), $caseShots);

            // The three metrics that GREW. before/after carry both the display
            // string and the number the bar is drawn from; each metric is scaled
            // to its OWN max, since the units are not comparable to each other.
            // Click-through rate is deliberately NOT in this list — it fell, and
            // burying it among the wins would read as either a miss or a dodge.
            // It gets its own explained panel below.
            $caseMetrics = [
                [
                    'label' => __('Impressions a day'),
                    'help' => __('How often the site appeared in Google.'),
                    'before' => ['text' => '18', 'n' => 18.2],
                    'after' => ['text' => '118', 'n' => 118.3],
                    'delta' => __(':n× more', ['n' => '6.5']),
                ],
                [
                    'label' => __('Clicks a day'),
                    'help' => __('How many people actually came through.'),
                    'before' => ['text' => '1.9', 'n' => 1.9],
                    'after' => ['text' => '5.4', 'n' => 5.4],
                    'delta' => __(':n× more', ['n' => '2.9']),
                ],
                [
                    'label' => __('Average position'),
                    'help' => __('Where the site ranks on average. Lower is better.'),
                    'before' => ['text' => '14.7', 'n' => 14.7],
                    'after' => ['text' => '10.8', 'n' => 10.8],
                    'delta' => __(':n places better', ['n' => '3.9']),
                ],
            ];
        @endphp

        <div class="mx-auto max-w-5xl px-6 py-16 lg:px-8 lg:py-20">
            <div class="mx-auto max-w-3xl text-center">
                <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-orange-600">{{ __('Real results') }}</p>
                <h2 class="mt-3 text-balance text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">{{ __('What happened on a real website') }}</h2>
                <p class="mt-3 text-base leading-7 text-slate-600">
                    {{ __('A Dubai brand-strategy consultancy put their blog on Content AI Autopilot. The first article went live on :date — here is their Search Console before and after, measured per day, because the two windows are not the same length.', ['date' => $caseStart->translatedFormat('j F Y')]) }}
                </p>
            </div>

            {{-- Step 1 — the receipt, FULL WIDTH. This is the money shot: one
                 chart where the flat "before" and the climb from the 24th are
                 obvious at a glance. It was previously in a half-width column
                 beside the numbers, which shrank a Search Console panel to
                 ~530px and made its own figures unreadable. --}}
            @if ($caseHas['month'])
                <figure class="mt-12 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <figcaption class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-slate-200 px-5 py-3">
                        <span class="h-1.5 w-1.5 flex-none rounded-full bg-orange-500"></span>
                        <span class="text-[11px] font-bold uppercase tracking-[0.16em] text-slate-500">{{ __('Their Search Console, unedited') }}</span>
                        <span class="text-xs text-slate-400">{{ __('28 days · publishing starts 24 July') }}</span>
                    </figcaption>
                    <a href="{{ asset($caseShots['month']) }}" target="_blank" rel="noopener" class="block bg-slate-50">
                        <img src="{{ asset($caseShots['month']) }}"
                             alt="{{ __('Search Console performance for the 28 days around the start date: clicks and impressions are flat, then climb sharply from 24 July.') }}"
                             width="1532" height="610" loading="lazy" decoding="async" class="w-full">
                    </a>
                    <div class="border-t border-slate-200 px-5 py-3 text-xs leading-5 text-slate-500">
                        {{ __('The flat stretch on the left is the three weeks before. The climb starts the day the articles did.') }}
                        <span class="mt-1 block text-slate-400 lg:hidden">{{ __('Tap the chart to open it full size.') }}</span>
                    </div>
                </figure>
            @endif

            {{-- Step 2 — what that chart adds up to, per day. --}}
            <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:p-8">
                <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                    <h3 class="text-base font-extrabold tracking-tight text-slate-900">{{ __('What that adds up to, per day') }}</h3>
                    <p class="text-xs font-medium text-slate-500">{{ __('21 days before → first 7 days') }}</p>
                </div>

                <div class="mt-6 grid gap-x-10 gap-y-6 sm:grid-cols-3">
                    @foreach ($caseMetrics as $m)
                        @php $max = max($m['before']['n'], $m['after']['n']) ?: 1; @endphp
                        <div>
                            <p class="text-sm font-bold text-slate-900">{{ $m['label'] }}</p>
                            <p class="mt-0.5 text-xs leading-5 text-slate-500">{{ $m['help'] }}</p>

                            <div class="mt-3 space-y-2">
                                @foreach ([
                                    ['label' => __('Before'), 'v' => $m['before'], 'color' => '#cbd5e1'],
                                    ['label' => __('After'), 'v' => $m['after'], 'color' => '#ea580c'],
                                ] as $row)
                                    <div class="flex items-center gap-2.5">
                                        <span class="w-12 flex-none text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ $row['label'] }}</span>
                                        <span class="w-12 flex-none text-end text-sm font-bold tabular-nums text-slate-900">{{ $row['v']['text'] }}</span>
                                        {{-- Only the bar geometry is SVG; labels and numbers stay
                                             real HTML so they survive translation and a 390px
                                             screen. viewBox height 14 === h-3.5 so nothing is
                                             squashed vertically, while x stretches freely because
                                             x IS the data. non-scaling-stroke keeps the round caps
                                             circular under that non-uniform scale; overflow-visible
                                             stops them being clipped at the ends. --}}
                                        <svg class="h-3 min-w-0 flex-1 overflow-visible" viewBox="0 0 100 14"
                                             preserveAspectRatio="none" aria-hidden="true" focusable="false">
                                            <line x1="0" y1="7" x2="100" y2="7" stroke="#f1f5f9" stroke-width="10" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                                            <line x1="0" y1="7" x2="{{ round($row['v']['n'] / $max * 100, 1) }}" y2="7" stroke="{{ $row['color'] }}" stroke-width="10" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                                        </svg>
                                    </div>
                                @endforeach
                            </div>

                            <p class="mt-3 inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-bold text-emerald-700">
                                <span aria-hidden="true">↑</span>{{ $m['delta'] }}
                            </p>
                        </div>
                    @endforeach
                </div>

                {{-- The one metric that fell, given its own explained panel rather
                     than a red-looking fourth column. It is not a miss: clicks a
                     day went UP 2.9× at the same time, so the falling percentage
                     is the arithmetic of a much bigger denominator. Shown, never
                     buried — a page that prints only the numbers that went up is
                     a page nobody believes. --}}
                <div class="mt-8 rounded-xl border border-sky-200 bg-sky-50/60 p-5">
                    <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                        <svg class="h-4 w-4 flex-none text-sky-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 16v-4m0-4h.01"/></svg>
                        <p class="text-sm font-bold text-slate-900">{{ __('One number went down — here is why that is a good sign') }}</p>
                        <span class="rounded-full bg-sky-100 px-2 py-0.5 text-[11px] font-bold text-sky-700">{{ __('Expected') }}</span>
                    </div>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        {{ __('Click-through rate went from 10.4% to 4.6%. That is a share, not a count: the site is now shown for far more searches, so the average share that clicks is smaller — while the actual clicks per day went UP 2.9×. More people are clicking, not fewer. A rising CTR on flat impressions would have meant the opposite: no new reach at all.') }}
                    </p>
                </div>

                <p class="mt-5 border-t border-slate-100 pt-4 text-xs leading-5 text-slate-400">
                    {{ __('The totals those rates come from: :bi impressions and :bc clicks over the 21 days before, then :ai impressions and :ac clicks over the first 7 days.', [
                        'bi' => '383', 'bc' => '40', 'ai' => '828', 'ac' => '38',
                    ]) }}
                </p>
            </div>

            {{-- Step 3 — the two windows the numbers were read from, side by side
                 so the comparison is immediate. Stacked on a phone; each panel
                 keeps its big KPI tiles, which stay legible at that width. --}}
            @if ($caseHas['before'] && $caseHas['after'])
                <div class="mt-6 grid gap-6 md:grid-cols-2">
                    @foreach ([
                        ['key' => 'before', 'label' => __('Before'), 'range' => __('4 – 24 July'), 'alt' => __('Search Console for 4 to 24 July: 40 clicks and 383 impressions, average position 14.7.')],
                        ['key' => 'after', 'label' => __('After'), 'range' => __('24 – 30 July'), 'alt' => __('Search Console for 24 to 30 July: 38 clicks and 828 impressions, average position 10.8.')],
                    ] as $shot)
                        <figure class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                            <figcaption class="flex items-baseline gap-2 border-b border-slate-200 px-5 py-3">
                                <span class="text-[11px] font-bold uppercase tracking-[0.16em] text-slate-500">{{ $shot['label'] }}</span>
                                <span class="text-xs text-slate-400">{{ $shot['range'] }}</span>
                            </figcaption>
                            <a href="{{ asset($caseShots[$shot['key']]) }}" target="_blank" rel="noopener" class="block bg-slate-50">
                                <img src="{{ asset($caseShots[$shot['key']]) }}" alt="{{ $shot['alt'] }}"
                                     width="1532" height="605" loading="lazy" decoding="async" class="w-full">
                            </a>
                        </figure>
                    @endforeach
                </div>
            @endif

            {{-- Step 4 — what was actually published to earn it. --}}
            <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:p-8">
                <div class="mx-auto max-w-2xl text-center">
                    <h3 class="text-base font-extrabold tracking-tight text-slate-900">{{ __('What we published to get there') }}</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('Eleven articles in the first ten days, each researched, written, scored and illustrated before it went live — no writer, no brief, no scheduling calls.') }}</p>
                </div>

                <dl class="mx-auto mt-6 grid max-w-3xl grid-cols-2 gap-4 text-center sm:grid-cols-4">
                    @foreach ([
                        ['11', __('articles')],
                        ['3,250', __('words each, on average')],
                        ['42', __('original images')],
                        ['90', __('average SEO score')],
                    ] as [$value, $label])
                        <div>
                            <dt class="text-2xl font-extrabold tracking-tight text-orange-600">{{ $value }}</dt>
                            <dd class="mt-0.5 text-xs leading-4 text-slate-500">{{ $label }}</dd>
                        </div>
                    @endforeach
                </dl>

                @if ($caseHas['calendar'])
                    <a href="{{ asset($caseShots['calendar']) }}" target="_blank" rel="noopener"
                       class="mt-6 block overflow-hidden rounded-xl border border-slate-200 bg-slate-50">
                        <img src="{{ asset($caseShots['calendar']) }}"
                             alt="{{ __('Their content calendar: empty until the 22nd, then a published article every day from the 23rd, each showing its SEO score, word count and images.') }}"
                             width="1502" height="650" loading="lazy" decoding="async" class="w-full">
                    </a>
                    <p class="mt-3 text-center text-xs leading-5 text-slate-500">{{ __('Their real calendar: nothing until the 22nd, then an article a day.') }}</p>
                @endif
            </div>

            <p class="mx-auto mt-8 max-w-3xl text-center text-xs leading-6 text-slate-500">
                {{ __('One website, one week. That is early movement rather than a settled result, and we are not claiming these articles were the only thing that changed on the site. Yours will behave differently.') }}
            </p>
        </div>
    </section>

    {{-- ── What this costs everywhere else ───────────────────── --}}
    <section class="border-b border-slate-800 bg-slate-900">
        <div class="mx-auto max-w-6xl px-6 py-16 lg:px-8 lg:py-20">
            <div class="text-center">
                <h2 class="text-balance text-3xl font-extrabold tracking-tight text-white sm:text-4xl">{{ __('What this normally costs') }}</h2>
                <p class="mx-auto mt-3 max-w-2xl text-base text-slate-400">{{ __('Getting :n researched, optimized articles published every month, the usual ways:', ['n' => $articles]) }}</p>
            </div>

            <div class="mt-12 grid gap-5 lg:grid-cols-3">
                @php
                    $costCards = [
                        [
                            'label' => __('Freelance writer'),
                            'price' => '$1,200+',
                            'unit'  => __('per month, typical'),
                            'points' => [__('You brief, chase and edit every piece'), __('Keyword research is on you'), __('You publish and format it yourself'), __('Nobody tracks whether it ranked')],
                            'highlight' => false,
                        ],
                        [
                            'label' => __('Content agency'),
                            'price' => '$3,000+',
                            'unit'  => __('per month, typical'),
                            'points' => [__('Long contracts and slow turnarounds'), __('Strategy calls before anything is written'), __('Extra line items for images'), __('Reporting arrives once a month')],
                            'highlight' => false,
                        ],
                        [
                            'label' => __('Serfix Content AI Autopilot'),
                            'price' => '$'.$monthly,
                            'unit'  => __('per month, per website'),
                            'points' => [__('Research, writing and images included'), __(':n articles every month', ['n' => $articles]), __('Publishes to your site automatically'), __('Rank tracking and reporting built in')],
                            'highlight' => true,
                        ],
                    ];
                @endphp
                @foreach ($costCards as $card)
                    <div @class([
                        'relative rounded-2xl p-7',
                        'border border-slate-700/70 bg-slate-800/40' => ! $card['highlight'],
                        'border-2 border-orange-500 bg-slate-800 shadow-2xl shadow-orange-600/20' => $card['highlight'],
                    ])>
                        @if ($card['highlight'])
                            <span class="absolute -top-3 start-7 rounded-full bg-orange-500 px-3 py-1 text-[10px] font-bold uppercase tracking-widest text-white">{{ __('This page') }}</span>
                        @endif
                        <p @class(['text-sm font-bold', 'text-orange-400' => $card['highlight'], 'text-slate-400' => ! $card['highlight']])>{{ $card['label'] }}</p>
                        <p @class(['mt-3 text-4xl font-extrabold tracking-tight', 'text-white' => $card['highlight'], 'text-slate-300' => ! $card['highlight']])>{{ $card['price'] }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ $card['unit'] }}</p>
                        <ul class="mt-6 space-y-2.5">
                            @foreach ($card['points'] as $point)
                                <li class="flex gap-2.5 text-sm text-slate-300">
                                    @if ($card['highlight'])
                                        <svg class="mt-0.5 h-4 w-4 flex-none text-orange-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                    @else
                                        <span class="mt-2 h-1 w-1 flex-none rounded-full bg-slate-600"></span>
                                    @endif
                                    <span>{{ $point }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
            <p class="mt-8 text-center text-xs text-slate-500">{{ __('Freelance and agency figures are typical market rates, shown for comparison only.') }}</p>
        </div>
    </section>

    {{-- ── Everything you need ───────────────────────────────── --}}
    <section class="border-b border-slate-200 bg-white">
        <div class="mx-auto max-w-6xl px-6 py-16 lg:px-8 lg:py-20">
            <div class="mx-auto max-w-2xl text-center">
                <h2 class="text-balance text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">{{ __('Everything you need for content SEO') }}</h2>
                <p class="mt-4 text-lg leading-8 text-slate-600">{{ __('From the first idea to a published, tracked article. You stay in control and approve every step.') }}</p>
            </div>

            {{-- Two feature panels, then a row of smaller ones --}}
            <div class="mt-12 grid gap-5 lg:grid-cols-2">
                <div class="rounded-2xl border border-slate-200 bg-white p-8">
                    <h3 class="text-xl font-extrabold tracking-tight text-slate-900">{{ __('Topics from real searches, not guesses') }}</h3>
                    <p class="mt-3 text-sm leading-6 text-slate-600">{{ __('We identify the competitors you actually compete with, the keywords they win, and the gaps you can realistically take — then turn them into a month of topics ordered by what you can win first.') }}</p>
                    <ul class="mt-5 grid gap-2 sm:grid-cols-2">
                        @foreach ([__('Competitor research'), __('Keyword gap analysis'), __('Search volume & intent'), __('Difficulty scored for your site')] as $chip)
                            <li class="flex items-center gap-2 text-sm text-slate-700">
                                <svg class="h-4 w-4 flex-none text-orange-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>{{ $chip }}
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-slate-900 p-8">
                    <h3 class="text-xl font-extrabold tracking-tight text-white">{{ __('Original images included') }}</h3>
                    <p class="mt-3 text-sm leading-6 text-slate-400">{{ __('Every article gets its own illustrations, generated to match the piece and placed inside it — with alt text written for each one, and uploaded straight into your own media library.') }}</p>
                    {{-- Genuine output: three images this pipeline generated for
                         articles on our own blog. Never placeholder gradients —
                         a reader judging "will the images be any good?" needs to
                         see the real thing. --}}
                    <div class="mt-6 grid grid-cols-3 gap-3">
                        @foreach ([1, 2, 3] as $n)
                            <img src="{{ asset('images/content/samples/sample-'.$n.'.webp') }}"
                                 alt="{{ __('An illustration generated for a published article') }}"
                                 width="520" height="293" loading="lazy"
                                 class="aspect-[16/9] w-full rounded-lg object-cover">
                        @endforeach
                    </div>
                    <p class="mt-4 text-xs text-slate-500">{{ __('No stock photos to hunt down, no extra licence to buy.') }}</p>
                </div>
            </div>

            <div class="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    ['M3 8.25V18a2.25 2.25 0 002.25 2.25h13.5A2.25 2.25 0 0021 18V8.25m-18 0V6a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 6v2.25m-18 0h18M8.25 3v3m7.5-3v3', __('One-click publishing'), __('WordPress or any platform via webhook — on the days and time window you choose.')],
                    ['M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.5-2.5 3.75-5.75 3.75-9S14.5 5.5 12 3m0 18c-2.5-2.5-3.75-5.75-3.75-9S9.5 5.5 12 3M3.6 9h16.8M3.6 15h16.8', __('English and Arabic'), __('Write for either market, with the right structure and tone for each.')],
                    ['M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z', __('Proof it worked'), __('Published keywords are tracked automatically, with positions, clicks and visitors per article.')],
                ] as [$icon, $title, $desc])
                    <div class="rounded-2xl border border-slate-200 bg-white p-6 transition hover:-translate-y-0.5 hover:border-orange-200 hover:shadow-lg hover:shadow-orange-600/5">
                        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-lg shadow-orange-600/25">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}" /></svg>
                        </div>
                        <h3 class="mt-4 text-base font-bold text-slate-900">{{ $title }}</h3>
                        <p class="mt-1.5 text-sm leading-6 text-slate-600">{{ $desc }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Cross-sell: the SEO platform (hidden with the product) ── --}}
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

    {{-- ── Pricing ───────────────────────────────────────────── --}}
    <section id="pricing" class="scroll-mt-20 border-b border-slate-200 bg-white">
        <div class="mx-auto max-w-5xl px-6 py-16 lg:px-8 lg:py-20" x-data="{ billing: 'annual' }">
            <div class="text-center">
                <h2 class="text-balance text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">{{ __('Simple, transparent pricing') }}</h2>
                <p class="mx-auto mt-3 max-w-xl text-base text-slate-600">{{ __('One plan, everything included. Start free for :days days — no card.', ['days' => $trialDays]) }}</p>
            </div>

            {{-- Billing switch --}}
            <div class="mt-8 flex justify-center">
                <div class="inline-flex items-center gap-1 rounded-full border border-slate-200 bg-slate-100 p-1">
                    <button type="button" @click="billing = 'monthly'"
                        :class="billing === 'monthly' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                        class="rounded-full px-5 py-2 text-sm font-semibold transition">{{ __('Monthly') }}</button>
                    <button type="button" @click="billing = 'annual'"
                        :class="billing === 'annual' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                        class="rounded-full px-5 py-2 text-sm font-semibold transition">
                        {{ __('Yearly') }}
                        @if ($savePct > 0)
                            <span class="ms-1.5 rounded-full bg-success/15 px-2 py-0.5 text-[10px] font-bold uppercase text-success">{{ __('save :p%', ['p' => $savePct]) }}</span>
                        @endif
                    </button>
                </div>
            </div>

            {{-- The plan --}}
            <div class="mx-auto mt-10 max-w-md">
                <div class="relative overflow-hidden rounded-3xl border-2 border-orange-500 bg-white p-8 text-center shadow-2xl shadow-orange-600/10">
                    <span class="absolute top-0 start-1/2 -translate-x-1/2 rounded-b-lg bg-orange-600 px-3 py-1 text-[10px] font-bold uppercase tracking-widest text-white">{{ __('Everything included') }}</span>
                    <p class="mt-4 text-[11px] font-bold uppercase tracking-[0.18em] text-slate-500">{{ __('Content AI Autopilot') }}</p>
                    <div class="mt-4 flex items-baseline justify-center gap-1.5">
                        <span class="text-5xl font-extrabold tracking-tight text-slate-900" x-text="billing === 'annual' ? '${{ $annual }}' : '${{ $monthly }}'">${{ $annual }}</span>
                        <span class="text-lg font-medium text-slate-500">/{{ __('mo') }}</span>
                    </div>
                    <p class="mt-2 text-sm text-slate-500">
                        <span x-show="billing === 'annual'">{{ __('per website, billed yearly') }}</span>
                        <span x-show="billing === 'monthly'" x-cloak>{{ __('per website, billed monthly') }}</span>
                    </p>
                    @if ($first && $first < $monthly)
                        <p class="mt-3" x-show="billing === 'monthly'" x-cloak>
                            <span class="inline-block rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-700">{{ __('First month $:f', ['f' => $first]) }}</span>
                        </p>
                    @endif

                    <ul class="mt-7 space-y-2.5 text-start">
                        @foreach ([
                            __(':n SEO articles per month', ['n' => $articles]),
                            __('Competitor and keyword research'),
                            __('Original images generated for every article'),
                            __('Automatic publishing to your site'),
                            __('Schema markup and full meta control'),
                            __(':n tracked keywords with rank history', ['n' => number_format($trackerKeywords)]),
                            __('Search Console and Analytics reporting'),
                        ] as $item)
                            <li class="flex gap-2.5 text-sm text-slate-700">
                                <svg class="mt-0.5 h-4 w-4 flex-none text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <a href="#start" class="mt-8 block rounded-full bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3.5 text-sm font-bold text-white shadow-lg shadow-orange-600/30 transition hover:brightness-110">{{ __('Get started now') }}</a>
                    <p class="mt-3 text-xs text-slate-500">{{ __('No card required · cancel anytime') }}</p>
                </div>
            </div>

            {{-- Additional websites --}}
            <div class="mx-auto mt-12 max-w-3xl">
                <h3 class="text-center text-lg font-extrabold tracking-tight text-slate-900">{{ __('Running more than one website?') }}</h3>
                <p class="mx-auto mt-2 max-w-xl text-center text-sm text-slate-600">{{ __('Your plan covers one website. Add as many more as you like — each one gets its own research, calendar, articles and tracker.') }}</p>

                {{-- One source for the numbers, rendered two ways: stacked cards
                     on a phone, the table from `sm` up. The table used to carry a
                     min-width and scroll sideways on mobile, which hid the price
                     column — the one thing this block exists to show. --}}
                @php
                    $siteRows = [
                        [
                            'label' => __('First website'),
                            'included' => true,
                            'articles' => number_format($articles),
                            'note' => __(':n articles per month', ['n' => number_format($articles)]),
                            'annual' => '$'.$annual,
                            'monthly' => '$'.$monthly,
                            'highlight' => false,
                        ],
                        [
                            'label' => __('Each additional website'),
                            'included' => false,
                            'articles' => __('+:n each', ['n' => number_format($articles)]),
                            'note' => __(':n more articles per month, per site', ['n' => number_format($articles)]),
                            'annual' => '$'.$addonA,
                            'monthly' => '$'.$addonM,
                            'highlight' => false,
                        ],
                        [
                            'label' => __('Example: 3 websites'),
                            'included' => false,
                            'articles' => number_format($articles * 3),
                            'note' => __(':n articles per month', ['n' => number_format($articles * 3)]),
                            'annual' => '$'.$threeSitesAnnual,
                            'monthly' => '$'.$threeSitesMonthly,
                            'highlight' => true,
                        ],
                    ];
                @endphp

                {{-- Phone: one card per row, nothing clipped --}}
                <div class="mt-6 space-y-3 sm:hidden">
                    @foreach ($siteRows as $row)
                        <div @class([
                            'rounded-2xl border border-slate-200 p-4',
                            'bg-white' => ! $row['highlight'],
                            'bg-slate-50' => $row['highlight'],
                        ])>
                            <div class="flex items-baseline justify-between gap-3">
                                <p class="text-sm font-semibold text-slate-900">
                                    {{ $row['label'] }}
                                    @if ($row['included'])
                                        <span class="ms-1.5 rounded bg-orange-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-orange-700">{{ __('Included') }}</span>
                                    @endif
                                </p>
                                <p class="whitespace-nowrap text-base font-bold text-slate-900">
                                    <span x-show="billing === 'annual'">{{ $row['annual'] }}</span><span x-show="billing === 'monthly'" x-cloak>{{ $row['monthly'] }}</span><span class="text-xs font-medium text-slate-500">/{{ __('mo') }}</span>
                                </p>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">{{ $row['note'] }}</p>
                        </div>
                    @endforeach
                </div>

                {{-- Tablet and up: the table --}}
                <div class="mt-6 hidden overflow-hidden rounded-2xl border border-slate-200 sm:block">
                    <table class="w-full border-collapse text-start text-sm">
                        <thead>
                            <tr class="bg-slate-50 text-slate-500">
                                <th scope="col" class="px-5 py-3 text-start text-xs font-bold uppercase tracking-wider">{{ __('Websites') }}</th>
                                <th scope="col" class="px-5 py-3 text-start text-xs font-bold uppercase tracking-wider">{{ __('Articles per month') }}</th>
                                <th scope="col" class="px-5 py-3 text-end text-xs font-bold uppercase tracking-wider">{{ __('Price') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 bg-white">
                            @foreach ($siteRows as $row)
                                <tr @class(['bg-slate-50/70' => $row['highlight']])>
                                    <th scope="row" class="px-5 py-4 text-start font-semibold text-slate-900">
                                        {{ $row['label'] }}
                                        @if ($row['included'])
                                            <span class="ms-2 rounded bg-orange-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-orange-700">{{ __('Included') }}</span>
                                        @endif
                                    </th>
                                    <td class="px-5 py-4 text-slate-600">{{ $row['articles'] }}</td>
                                    <td class="px-5 py-4 text-end font-bold text-slate-900">
                                        <span x-show="billing === 'annual'">{{ $row['annual'] }}</span><span x-show="billing === 'monthly'" x-cloak>{{ $row['monthly'] }}</span><span class="font-medium text-slate-500">/{{ __('mo') }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-4 text-center text-xs text-slate-500">{{ __('Prices in USD. Add or remove websites anytime. Content AI Autopilot is billed separately from the Serfix SEO platform.') }}</p>
                <p class="mt-4 text-center">
                    <a href="{{ route('content.pricing') }}" class="inline-flex items-center gap-1.5 text-sm font-bold text-orange-600 underline-offset-4 hover:underline">
                        {{ __('See everything included, feature by feature') }}
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                    </a>
                </p>
            </div>
        </div>
    </section>

    {{-- ── Why consistency matters ───────────────────────────── --}}
    {{-- Both figures are published, attributable research — never invent a
         statistic on a page that sells something. --}}
    <section class="border-b border-slate-200 bg-slate-50">
        <div class="mx-auto max-w-5xl px-6 py-16 lg:px-8 lg:py-20">
            <div class="text-center">
                <h2 class="text-balance text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">{{ __('Why consistency actually matters') }}</h2>
                <p class="mx-auto mt-3 max-w-xl text-base text-slate-600">{{ __('Publishing once in a while does very little. Publishing steadily, month after month, is what compounds.') }}</p>
            </div>
            <div class="mt-12 grid gap-6 sm:grid-cols-2">
                <div class="rounded-2xl border border-slate-200 bg-white p-8">
                    <p class="text-5xl font-extrabold tracking-tight text-orange-600">4.5×</p>
                    <h3 class="mt-3 text-lg font-bold text-slate-900">{{ __('More leads from publishing often') }}</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('Companies publishing 16 or more blog posts a month generated around 4.5× more leads than those publishing four or fewer.') }}</p>
                    <p class="mt-3 text-xs text-slate-400">{{ __('Source: HubSpot blogging benchmark') }}</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-8">
                    <p class="text-5xl font-extrabold tracking-tight text-orange-600">25%</p>
                    <h3 class="mt-3 text-lg font-bold text-slate-900">{{ __('Of search is moving to AI answers') }}</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('Gartner predicts traditional search engine volume will drop by 25% by 2026 as people ask AI assistants instead — and assistants answer from the pages they can find and cite.') }}</p>
                    <p class="mt-3 text-xs text-slate-400">{{ __('Source: Gartner, 2024') }}</p>
                </div>
            </div>
        </div>
    </section>

    {{-- ── FAQ ───────────────────────────────────────────────── --}}
    <section id="faq" class="scroll-mt-20 border-b border-slate-200 bg-white">
        <div class="mx-auto max-w-3xl px-6 py-16 lg:px-8 lg:py-20">
            <div class="text-center">
                <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-slate-500">{{ __('FAQ') }}</p>
                <h2 class="mt-3 text-balance text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">{{ __('Frequently asked questions') }}</h2>
            </div>
            <div class="mt-12 divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white">
                @foreach ($faqs as $faq)
                    <details class="group p-6 [&_summary::-webkit-details-marker]:hidden">
                        <summary class="flex cursor-pointer items-center justify-between gap-3 text-[15px] font-semibold text-slate-900">
                            <span>{{ $faq['q'] }}</span>
                            <span class="flex h-6 w-6 flex-none items-center justify-center rounded-full bg-slate-100 text-slate-600 transition group-open:rotate-45 group-open:bg-slate-900 group-open:text-white">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            </span>
                        </summary>
                        <p class="mt-3 text-[14px] leading-7 text-slate-600">{{ $faq['a'] }}</p>
                    </details>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Latest from our own blog ──────────────────────────── --}}
    @if ($latestPosts->isNotEmpty())
        <section class="border-b border-slate-200 bg-slate-50">
            <div class="mx-auto max-w-6xl px-6 py-16 lg:px-8 lg:py-20">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h2 class="text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">{{ __('Latest from the blog') }}</h2>
                        <p class="mt-2 text-sm text-slate-600">{{ __('Every article here was researched, written and published by Content AI Autopilot.') }}</p>
                    </div>
                    <a href="{{ route('content-ai.index') }}" class="inline-flex items-center gap-1.5 text-sm font-bold text-orange-600 underline-offset-4 hover:underline">
                        {{ __('View all') }}
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                    </a>
                </div>

                <div class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($latestPosts as $post)
                        <a href="{{ $post->url() }}" class="group flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:border-orange-200 hover:shadow-lg hover:shadow-orange-600/5">
                            @if ($image = $post->featuredImage())
                                <img src="{{ $image->url() }}" alt="{{ $image->alt_text ?? $post->title }}" loading="lazy" class="aspect-video w-full object-cover">
                            @else
                                <div class="flex aspect-video w-full items-center justify-center bg-gradient-to-br from-orange-500 to-orange-600">
                                    <span class="text-5xl font-extrabold text-white/90">{{ mb_substr($post->title, 0, 1) }}</span>
                                </div>
                            @endif
                            <div class="flex flex-1 flex-col p-6">
                                <h3 class="text-base font-bold tracking-tight text-slate-900">{{ $post->title }}</h3>
                                <p class="mt-2 flex-1 text-sm leading-6 text-slate-600 line-clamp-2">{{ $post->summary() }}</p>
                                <div class="mt-4 flex items-center gap-x-2 text-xs font-medium text-slate-500">
                                    @if ($post->published_at)
                                        <time datetime="{{ $post->published_at->toIso8601String() }}">{{ $post->published_at->toFormattedDateString() }}</time>
                                        <span aria-hidden="true">·</span>
                                    @endif
                                    <span>{{ __(':n min read', ['n' => $post->readingMinutes()]) }}</span>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- ── Closing CTA ───────────────────────────────────────── --}}
    <section class="bg-white">
        <div class="mx-auto max-w-4xl px-6 py-16 lg:px-8 lg:py-20">
            <div class="rounded-3xl border border-orange-200 bg-gradient-to-b from-orange-50 to-white px-8 py-14 text-center shadow-sm">
                <h2 class="text-balance text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">{{ __('Ready to put your content on autopilot?') }}</h2>
                <p class="mx-auto mt-4 max-w-xl text-lg text-slate-600">{{ __('See your competitors, your keyword gaps and a month of planned topics — before you pay a cent.') }}</p>
                <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
                    <a href="#start" class="inline-flex items-center justify-center gap-1.5 rounded-full bg-gradient-to-r from-orange-500 to-orange-600 px-8 py-4 text-base font-bold text-white shadow-lg shadow-orange-600/30 hover:brightness-110">
                        {{ __('Start your free trial') }}
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                    </a>
                    <a href="{{ route('contact') }}" class="inline-flex items-center justify-center rounded-full border border-slate-300 bg-white px-8 py-4 text-base font-semibold text-slate-700 transition hover:bg-slate-50">{{ __('Talk to us') }}</a>
                </div>
                <p class="mt-4 text-sm text-slate-500">{{ __(':days-day free trial · :n free articles · no card required', ['days' => $trialDays, 'n' => $trialArticles]) }}</p>
            </div>
        </div>
    </section>

    @if ($recaptcha)
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    @endif
</x-marketing.page>
