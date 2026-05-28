<x-marketing.page
    title="#1 Free SEO Analysis Tool With Detailed Audits"
    description="Analyze your website with the #1 free SEO audit tool. Get detailed tech insights, live GSC data, and actionable fixes to scale your search traffic."
>
    {{-- Page-specific structured data: the product itself. --}}
    <x-slot:schema>
        <script type="application/ld+json">@json([
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareApplication',
            'name' => 'EBQ',
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web, WordPress',
            'url' => route('landing'),
            'description' => 'Free SEO analysis and audit tool: detailed technical insights, live Google Search Console data, rank tracking, backlinks, and actionable fixes.',
            'offers' => [
                '@type' => 'Offer',
                'price' => '0',
                'priceCurrency' => 'USD',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
    </x-slot:schema>

    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section class="relative">
        <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 top-0 -z-10 h-[28rem] bg-[radial-gradient(ellipse_at_top,rgba(99,102,241,0.08),transparent_60%)]"></div>

        <div class="mx-auto max-w-6xl px-6 pb-20 pt-16 lg:px-8 lg:pb-28 lg:pt-24">
            <div class="grid items-center gap-10 lg:grid-cols-2 lg:gap-12">
                {{-- ── Hero copy ─────────────────────────────────── --}}
                <div class="max-w-xl text-center lg:text-left">
                    <a href="{{ route('features') }}" class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-600 transition hover:border-slate-300 hover:text-slate-900">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                        New: Anomaly alerts and backlink impact
                        <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                    </a>

                    <h1 class="mt-6 text-balance text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl">
                        The SEO command center for teams that ship.
                    </h1>

                    <p class="mt-6 text-balance text-[17px] leading-8 text-slate-600">
                        Unify Search Console, Analytics, ranking, audits, and backlinks into one quiet workspace. EBQ tells you what to fix this week, what to ship next, and what changed after release.
                    </p>

                    <div class="mt-9 flex flex-col items-center justify-center gap-3 sm:flex-row lg:justify-start">
                        <a href="{{ route('register') }}" class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">
                            Start free trial
                            <svg class="ml-1.5 h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5l7.5 7.5-7.5 7.5M21 12H3" /></svg>
                        </a>
                        <a href="{{ route('features') }}" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-900">
                            Explore product
                        </a>
                    </div>

                    <p class="mt-5 text-xs text-slate-500">1-month free trial · No credit card to view free plan · Cancel anytime</p>
                </div>

                {{-- ── Hero intro video ─────────────────────────────── --}}
                <div class="relative">
                    <div aria-hidden="true" class="pointer-events-none absolute -inset-x-6 -inset-y-6 -z-10 rounded-[28px] bg-gradient-to-b from-slate-100 to-transparent"></div>
                    <div class="relative overflow-hidden rounded-2xl border border-slate-200 bg-slate-900 shadow-[0_24px_60px_-24px_rgba(15,23,42,0.18)]" style="padding-top:56.25%">
                        <iframe
                            class="absolute inset-0 h-full w-full"
                            src="https://www.youtube-nocookie.com/embed/Rzme7QvSbLE"
                            title="EBQ intro video"
                            loading="lazy"
                            frameborder="0"
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                            referrerpolicy="strict-origin-when-cross-origin"
                            allowfullscreen></iframe>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Logo strip ───────────────────────────────────────── --}}
    <section class="border-y border-slate-200 bg-slate-50/60 py-10">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <p class="text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Connects with the tools you already trust</p>
            <div class="mt-6 flex flex-wrap items-center justify-center gap-x-10 gap-y-4 text-sm font-medium text-slate-400">
                <span>Google Search Console</span>
                <span class="hidden h-1 w-1 rounded-full bg-slate-300 sm:block"></span>
                <span>Google Analytics 4</span>
                <span class="hidden h-1 w-1 rounded-full bg-slate-300 sm:block"></span>
                <span>Google Indexing API</span>
                <span class="hidden h-1 w-1 rounded-full bg-slate-300 sm:block"></span>
                <span>WordPress</span>
                <span class="hidden h-1 w-1 rounded-full bg-slate-300 sm:block"></span>
                <span>SERP data</span>
                <span class="hidden h-1 w-1 rounded-full bg-slate-300 sm:block"></span>
                <span>Core Web Vitals</span>
            </div>
        </div>
    </section>

    {{-- ── Three benefits ───────────────────────────────────── --}}
    <section class="bg-white py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Built for SEO operators</p>
                <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">A workflow, not another dashboard.</h2>
                <p class="mt-4 text-base leading-7 text-slate-600">EBQ replaces tab-switching with a single decision surface. Every signal points to an action, every action measures itself.</p>
            </div>

            <div class="mx-auto mt-14 grid max-w-5xl gap-px overflow-hidden rounded-2xl border border-slate-200 bg-slate-200 sm:grid-cols-3">
                @foreach ([
                    ['icon' => 'M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z', 'title' => 'Spot what changed', 'desc' => 'Anomaly detection, content decay, and indexing regressions surface in seconds — not in your next monthly review.'],
                    ['icon' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z', 'title' => 'Prioritize like a PM', 'desc' => 'Striking-distance and cannibalization queries are scored by impact and ranked. Your backlog stops guessing.'],
                    ['icon' => 'M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12z', 'title' => 'Prove what shipped', 'desc' => 'Every fix is tracked against rank, click, and CWV deltas. Reports auto-attach the evidence stakeholders need.'],
                ] as $b)
                    <div class="flex flex-col bg-white p-7">
                        <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-slate-100 text-slate-700">
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $b['icon'] }}" /></svg>
                        </div>
                        <h3 class="mt-5 text-base font-semibold text-slate-900">{{ $b['title'] }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $b['desc'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Feature row 1: Cross-signal insights ─────────────── --}}
    <section class="bg-slate-50/60 py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="grid items-center gap-14 lg:grid-cols-2 lg:gap-20">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-indigo-600">Cross-signal insights</p>
                    <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-[2.25rem]">
                        Every signal becomes a task.
                    </h2>
                    <p class="mt-4 text-[15px] leading-7 text-slate-600">
                        Six insight boards — cannibalization, striking distance, content decay, indexing fails, audit vs traffic, and backlink impact — produce ranked action lists, not orphan numbers.
                    </p>
                    <ul class="mt-7 space-y-3 text-[14px] text-slate-700">
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Joins GSC × GA4 × audits × backlinks per page</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Per-country, per-device segmentation built in</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Updated daily with anomaly callouts</li>
                    </ul>
                </div>

                {{-- Mockup: insight cards grid --}}
                <div class="relative">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="grid grid-cols-2 gap-3">
                            @foreach ([
                                ['label' => 'Cannibalizations', 'value' => '14', 'caption' => '7 high impact', 'tone' => 'amber'],
                                ['label' => 'Striking distance', 'value' => '27', 'caption' => '12 ready to push', 'tone' => 'indigo'],
                                ['label' => 'Content decay', 'value' => '8', 'caption' => '-32% clicks 28d', 'tone' => 'slate'],
                                ['label' => 'Indexing fails', 'value' => '3', 'caption' => '120 lost impr', 'tone' => 'rose'],
                            ] as $c)
                                <div class="rounded-xl border border-slate-200 bg-white p-4">
                                    <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">{{ $c['label'] }}</p>
                                    <p @class([
                                        'mt-1.5 text-2xl font-semibold tabular-nums',
                                        'text-amber-600' => $c['tone'] === 'amber',
                                        'text-indigo-600' => $c['tone'] === 'indigo',
                                        'text-slate-900' => $c['tone'] === 'slate',
                                        'text-rose-600' => $c['tone'] === 'rose',
                                    ])>{{ $c['value'] }}</p>
                                    <p class="mt-1 text-[11px] text-slate-500">{{ $c['caption'] }}</p>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                            <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">Top striking-distance queries</p>
                            <ul class="mt-3 space-y-1.5 text-[12px]">
                                @foreach ([['best seo tools', '5.2', '12.8k', '1.2%'], ['on-page seo checklist', '7.1', '8.1k', '0.9%'], ['saas seo strategy', '11.4', '5.9k', '0.4%']] as [$q, $pos, $impr, $ctr])
                                    <li class="flex items-center justify-between rounded-md bg-white px-2.5 py-1.5 ring-1 ring-slate-200">
                                        <span class="truncate font-medium text-slate-800">{{ $q }}</span>
                                        <span class="flex shrink-0 items-center gap-3 tabular-nums text-slate-500">
                                            <span>#{{ $pos }}</span>
                                            <span>{{ $impr }}</span>
                                            <span>{{ $ctr }}</span>
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Feature row 2: Rank tracking ─────────────────────── --}}
    <section class="bg-white py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="grid items-center gap-14 lg:grid-cols-2 lg:gap-20">
                {{-- Mockup: keyword table with sparkline --}}
                <div class="order-last lg:order-first">
                    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
                            <div>
                                <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">Tracked keywords</p>
                                <p class="mt-0.5 text-sm font-semibold text-slate-900">United States · Mobile</p>
                            </div>
                            <span class="rounded-md bg-slate-100 px-2 py-1 text-[11px] font-semibold text-slate-700">128 active</span>
                        </div>
                        <table class="min-w-full text-[12px]">
                            <thead class="bg-slate-50/60 text-[10px] uppercase tracking-wider text-slate-500">
                                <tr>
                                    <th class="px-4 py-2 text-left font-semibold">Keyword</th>
                                    <th class="px-3 py-2 text-right font-semibold">Pos</th>
                                    <th class="px-3 py-2 text-right font-semibold">Δ</th>
                                    <th class="px-3 py-2 text-right font-semibold">Trend</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ([
                                    ['best seo tools', '2', '+3', 'M0 12 L8 10 L16 11 L24 8 L32 6 L40 4 L48 3', 'emerald'],
                                    ['saas content marketing', '8', '+1', 'M0 8 L8 9 L16 7 L24 7 L32 6 L40 5 L48 4', 'emerald'],
                                    ['seo audit checklist', '14', '-2', 'M0 4 L8 5 L16 7 L24 6 L32 8 L40 9 L48 11', 'rose'],
                                    ['keyword research guide', '6', '0', 'M0 6 L8 6 L16 5 L24 6 L32 7 L40 6 L48 6', 'slate'],
                                    ['featured snippet tips', '4', '+5', 'M0 11 L8 10 L16 9 L24 7 L32 6 L40 5 L48 3', 'emerald'],
                                ] as [$kw, $pos, $delta, $path, $tone])
                                    <tr class="hover:bg-slate-50/60">
                                        <td class="px-4 py-2.5 font-medium text-slate-800">{{ $kw }}</td>
                                        <td class="px-3 py-2.5 text-right tabular-nums text-slate-700">#{{ $pos }}</td>
                                        <td @class([
                                            'px-3 py-2.5 text-right tabular-nums font-semibold',
                                            'text-emerald-600' => $tone === 'emerald',
                                            'text-rose-600' => $tone === 'rose',
                                            'text-slate-500' => $tone === 'slate',
                                        ])>{{ $delta }}</td>
                                        <td class="px-3 py-2.5">
                                            <svg viewBox="0 0 48 14" class="ml-auto h-4 w-16" aria-hidden="true">
                                                <path d="{{ $path }}" fill="none" stroke="{{ $tone === 'emerald' ? '#059669' : ($tone === 'rose' ? '#e11d48' : '#94a3b8') }}" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-indigo-600">Rank tracking</p>
                    <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-[2.25rem]">
                        Rankings — and the clicks they actually earn.
                    </h2>
                    <p class="mt-4 text-[15px] leading-7 text-slate-600">
                        Most trackers show position. EBQ overlays GSC clicks for the exact query, so you instantly see when a rank gain stops producing traffic — and when SERP features are stealing it.
                    </p>
                    <ul class="mt-7 space-y-3 text-[14px] text-slate-700">
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Country, device, language, and city targeting</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Competitor positions captured every check</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>SERP-feature risk flags + PAA capture</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Feature row 3: Page audits ───────────────────────── --}}
    <section class="bg-slate-50/60 py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="grid items-center gap-14 lg:grid-cols-2 lg:gap-20">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-indigo-600">Page audits</p>
                    <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-[2.25rem]">
                        CWV, on-page, and content — in one pass.
                    </h2>
                    <p class="mt-4 text-[15px] leading-7 text-slate-600">
                        On-demand audits combine mobile + desktop Core Web Vitals with a deep HTML analyzer and keyword-strategy review. Every finding becomes a prioritized recommendation.
                    </p>
                    <ul class="mt-7 space-y-3 text-[14px] text-slate-700">
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Full CWV: LCP, CLS, INP, TBT, FCP, TTFB</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>SEO checks: meta, headings, schema, hreflang, alt</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>One-click resubmit via Google Indexing API</li>
                    </ul>
                </div>

                {{-- Mockup: CWV stat grid + checklist --}}
                <div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-center justify-between">
                            <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">/blog/saas-seo-guide</p>
                            <span class="rounded-md bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700 ring-1 ring-amber-100">Score 72</span>
                        </div>

                        <div class="mt-4 grid grid-cols-3 gap-2.5">
                            @foreach ([
                                ['LCP', '2.8s', 'amber'],
                                ['CLS', '0.04', 'emerald'],
                                ['INP', '180ms', 'emerald'],
                                ['TBT', '410ms', 'amber'],
                                ['FCP', '1.6s', 'emerald'],
                                ['TTFB', '720ms', 'amber'],
                            ] as [$lbl, $val, $tone])
                                <div class="rounded-lg border border-slate-200 bg-white p-3">
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ $lbl }}</p>
                                    <p @class([
                                        'mt-1 text-base font-semibold tabular-nums',
                                        'text-emerald-600' => $tone === 'emerald',
                                        'text-amber-600' => $tone === 'amber',
                                        'text-rose-600' => $tone === 'rose',
                                    ])>{{ $val }}</p>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                            <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">Top recommendations</p>
                            <ul class="mt-3 space-y-2 text-[12px]">
                                @foreach ([
                                    ['rose', 'Render-blocking CSS — split into critical + async (180KB)'],
                                    ['amber', 'Image alt missing on 7 hero/inline images'],
                                    ['amber', 'Canonical tag missing — set to self'],
                                    ['slate', 'Internal links: 3 orphaned, add 2 from /pricing'],
                                ] as [$tone, $text])
                                    <li class="flex items-start gap-2.5">
                                        <span @class([
                                            'mt-1 h-1.5 w-1.5 flex-none rounded-full',
                                            'bg-rose-500' => $tone === 'rose',
                                            'bg-amber-500' => $tone === 'amber',
                                            'bg-slate-400' => $tone === 'slate',
                                        ])></span>
                                        <span class="text-slate-700">{{ $text }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Feature row 4: Backlink impact ───────────────────── --}}
    <section class="bg-white py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="grid items-center gap-14 lg:grid-cols-2 lg:gap-20">
                {{-- Mockup: backlink impact table --}}
                <div class="order-last lg:order-first">
                    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
                            <div>
                                <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">Backlink impact · last 28d</p>
                                <p class="mt-0.5 text-sm font-semibold text-slate-900">Sorted by Δ clicks</p>
                            </div>
                        </div>
                        <table class="min-w-full text-[12px]">
                            <thead class="bg-slate-50/60 text-[10px] uppercase tracking-wider text-slate-500">
                                <tr>
                                    <th class="px-4 py-2 text-left font-semibold">Target page</th>
                                    <th class="px-3 py-2 text-right font-semibold">Links</th>
                                    <th class="px-3 py-2 text-right font-semibold">DA</th>
                                    <th class="px-3 py-2 text-right font-semibold">Δ clicks</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ([
                                    ['/pricing', 3, 58, '+412', 'emerald'],
                                    ['/blog/saas-seo', 7, 49, '+186', 'emerald'],
                                    ['/features', 2, 61, '+94', 'emerald'],
                                    ['/blog/keyword-research', 4, 42, '+38', 'emerald'],
                                    ['/product/ai-writer', 4, 41, '-22', 'rose'],
                                ] as [$p, $n, $da, $delta, $tone])
                                    <tr class="hover:bg-slate-50/60">
                                        <td class="px-4 py-2.5 font-medium text-slate-800">{{ $p }}</td>
                                        <td class="px-3 py-2.5 text-right tabular-nums text-slate-600">{{ $n }}</td>
                                        <td class="px-3 py-2.5 text-right tabular-nums text-slate-600">{{ $da }}</td>
                                        <td @class([
                                            'px-3 py-2.5 text-right tabular-nums font-semibold',
                                            'text-emerald-600' => $tone === 'emerald',
                                            'text-rose-600' => $tone === 'rose',
                                        ])>{{ $delta }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-indigo-600">Backlink impact</p>
                    <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-[2.25rem]">
                        Prove which links actually moved the needle.
                    </h2>
                    <p class="mt-4 text-[15px] leading-7 text-slate-600">
                        Upload, verify, and measure. EBQ shows you the click delta on every target page in the 28 days after a link goes live — sorted by biggest lift, so outreach proves itself.
                    </p>
                    <ul class="mt-7 space-y-3 text-[14px] text-slate-700">
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Live verification of presence, anchor, rel</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Bulk import or manual entry, deduped</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Filters by DA, spam, dofollow, anchor, date</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Workflow strip ───────────────────────────────────── --}}
    <section class="bg-slate-50/60 py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Workflow</p>
                <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">A weekly rhythm your team can keep.</h2>
            </div>

            <ol class="mt-14 grid gap-px overflow-hidden rounded-2xl border border-slate-200 bg-slate-200 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['01', 'Discover', 'Anomalies, content decay, indexing fails surface daily.'],
                    ['02', 'Prioritize', 'Striking-distance and cannibalization scored by impact.'],
                    ['03', 'Execute', 'Ship fixes from audits, briefs, or the WordPress sidebar.'],
                    ['04', 'Measure', 'Reports auto-attach rank, click, and CWV deltas.'],
                ] as [$n, $title, $desc])
                    <li class="relative bg-white p-7">
                        <p class="text-[11px] font-mono font-semibold tracking-wider text-slate-400">{{ $n }}</p>
                        <h3 class="mt-3 text-base font-semibold text-slate-900">{{ $title }}</h3>
                        <p class="mt-2 text-[13px] leading-6 text-slate-600">{{ $desc }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- ── Reporting + WordPress pair ───────────────────────── --}}
    <section id="wordpress" class="bg-white py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Reporting + WordPress</p>
                <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">Insights where stakeholders read them.</h2>
                <p class="mt-4 text-base leading-7 text-slate-600">Auto-sent executive reports for leadership. Editor-side context for content teams. No tab switching.</p>
            </div>

            <div class="mt-14 grid gap-6 lg:grid-cols-2">
                {{-- Report email mockup --}}
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-200 pb-4">
                        <div>
                            <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">Weekly Growth Report</p>
                            <p class="mt-1 text-sm font-semibold text-slate-900">example.com · Apr 13–19</p>
                        </div>
                        <span class="rounded-md bg-emerald-50 px-2 py-1 text-[11px] font-semibold text-emerald-700 ring-1 ring-emerald-100">+12% w/w</span>
                    </div>

                    <div class="mt-5 grid grid-cols-3 gap-2.5">
                        @foreach ([['Users', '8.4k', '+12%'], ['Clicks', '3.1k', '+8%'], ['Avg pos', '14.2', '-0.6']] as [$l, $v, $d])
                            <div class="rounded-lg border border-slate-200 bg-slate-50/60 p-3 text-center">
                                <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ $l }}</p>
                                <p class="mt-1 text-base font-semibold tabular-nums text-slate-900">{{ $v }}</p>
                                <p class="text-[10px] font-semibold text-emerald-600">{{ $d }}</p>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-4 rounded-xl border border-indigo-100 bg-indigo-50/60 p-4">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-indigo-700">Action insights</p>
                        <ul class="mt-2 space-y-1.5 text-[12px] text-slate-700">
                            <li>• 5 striking-distance keywords — push title + meta this sprint</li>
                            <li>• 3 pages cannibalizing on "saas seo guide"</li>
                            <li>• 1 indexing fail still pulling 120 impressions/wk</li>
                        </ul>
                    </div>
                </div>

                {{-- WordPress sidebar mockup --}}
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center gap-2 border-b border-slate-200 pb-3">
                        <span class="h-2 w-2 rounded-full bg-rose-400"></span>
                        <span class="h-2 w-2 rounded-full bg-amber-400"></span>
                        <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
                        <span class="ml-2 text-[11px] font-medium text-slate-500">Gutenberg · EBQ SEO</span>
                    </div>

                    <div class="mt-4 space-y-3 text-[12px]">
                        <div class="rounded-lg border border-slate-200 bg-slate-50/60 p-3">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Search performance · 30d</p>
                            <div class="mt-2 grid grid-cols-4 gap-1.5">
                                @foreach ([['Clicks', '1,284'], ['Impr', '21.4k'], ['Pos', '6.4'], ['CTR', '6.0%']] as [$l, $v])
                                    <div class="rounded bg-white px-2 py-1.5 text-center ring-1 ring-slate-200">
                                        <span class="block text-[9px] font-medium uppercase text-slate-500">{{ $l }}</span>
                                        <span class="block tabular-nums font-semibold text-slate-900">{{ $v }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="rounded-lg border border-emerald-100 bg-emerald-50/60 p-3">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-emerald-700">Rank tracking</p>
                            <div class="mt-1.5 flex items-center gap-2">
                                <span class="rounded-md bg-white px-1.5 py-0.5 text-[10px] font-bold text-slate-900 ring-1 ring-slate-200">#4</span>
                                <span class="text-[10px] font-semibold text-emerald-700">▲ 2</span>
                                <span class="text-[10px] text-slate-500">"best seo tools"</span>
                            </div>
                        </div>

                        <div class="rounded-lg border border-amber-100 bg-amber-50/60 p-3">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-amber-700">Cannibalization</p>
                            <p class="mt-1 text-[11px] text-slate-700">"best seo tools" splits with <span class="font-medium">/blog/seo-tools-guide</span></p>
                        </div>

                        <div class="rounded-lg border border-indigo-100 bg-indigo-50/60 p-3">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-indigo-700">Striking distance</p>
                            <p class="mt-1 text-[11px] text-slate-700">3 queries at pos 5–20 with below-curve CTR</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── FAQ ──────────────────────────────────────────────── --}}
    <section id="faq" class="bg-slate-50/60 py-20 sm:py-24">
        <div class="mx-auto max-w-3xl px-6 lg:px-8">
            <div class="text-center">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">FAQ</p>
                <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">Common questions before you switch.</h2>
            </div>

            <div class="mt-12 divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white">
                @foreach ([
                    ['How long does setup take?', 'Most teams connect Search Console + GA4 and run their first audit in under ten minutes.'],
                    ['Do you replace our weekly reporting docs?', 'Yes. EBQ sends scheduled reports with action insights, YoY comparisons, and trend deltas — ready for stakeholders.'],
                    ['Can I invite team members and clients?', 'Yes. Roles are website-scoped with feature-level permissions. Invitees auto-accept on signup.'],
                    ['Do you support WordPress?', 'Yes. The plugin surfaces ranking, click, and content insights directly in Gutenberg and WP admin.'],
                    ['Is there a free plan?', 'Yes. The Free plan covers one website, basic Search Console performance, and 10 audits per month.'],
                ] as [$q, $a])
                    <details class="group p-6 [&_summary::-webkit-details-marker]:hidden">
                        <summary class="flex cursor-pointer items-center justify-between gap-3 text-[15px] font-semibold text-slate-900">
                            <span>{{ $q }}</span>
                            <span class="flex h-6 w-6 flex-none items-center justify-center rounded-full bg-slate-100 text-slate-600 transition group-open:rotate-45 group-open:bg-slate-900 group-open:text-white">
                                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            </span>
                        </summary>
                        <p class="mt-3 text-[14px] leading-7 text-slate-600">{{ $a }}</p>
                    </details>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Final CTA ────────────────────────────────────────── --}}
    <section class="bg-white py-20 sm:py-24">
        <div class="mx-auto max-w-4xl px-6 lg:px-8">
            <div class="rounded-3xl border border-slate-200 bg-slate-50/60 px-6 py-14 text-center sm:px-12">
                <h2 class="text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">Run SEO like a product team.</h2>
                <p class="mx-auto mt-4 max-w-xl text-base leading-7 text-slate-600">Connect your data, see the next high-impact fix, and ship it before your next stand-up.</p>
                <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <a href="{{ route('register') }}" class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">
                        Start free trial
                    </a>
                    <a href="{{ route('pricing') }}" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-900">
                        See pricing
                    </a>
                </div>
            </div>
        </div>
    </section>
</x-marketing.page>
