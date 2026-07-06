<x-marketing.page
    title="Features — Serfix"
    description="Cross-signal insights, rank tracking, page audits, backlink impact, anomaly alerts, reporting, and the WordPress plugin — all built into one workspace."
    active="features"
>
    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section class="border-b border-slate-200 bg-white">
        <div class="mx-auto max-w-6xl px-6 py-20 text-center lg:px-8 lg:py-24">
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Product features</p>
            <h1 class="mx-auto mt-4 max-w-3xl text-balance text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl lg:text-6xl">
                Every signal, every action, in one workspace.
            </h1>
            <p class="mx-auto mt-5 max-w-2xl text-balance text-[17px] leading-8 text-slate-600">
                Serfix joins Search Console, Analytics, ranking, audits, and backlinks into a single decision surface. Each module is built to answer: what should we ship next, and what changed after we did?
            </p>
            <div class="mt-9 flex flex-col items-center justify-center gap-3 sm:flex-row">
                <a href="{{ route('register') }}" class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">Start free trial</a>
                <a href="#insights" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-900">Explore features</a>
            </div>

            {{-- Anchor pill nav --}}
            <nav aria-label="Feature sections" class="mx-auto mt-12 flex max-w-4xl flex-wrap items-center justify-center gap-2 text-xs font-medium">
                @foreach ([
                    ['#insights', 'Insights'],
                    ['#rank-tracking', 'Rank tracking'],
                    ['#audits', 'Page audits'],
                    ['#backlinks', 'Backlinks'],
                    ['#alerts', 'Alerts'],
                    ['#reporting', 'Reporting'],
                    ['#wordpress', 'WordPress'],
                    ['#integrations', 'Integrations'],
                ] as [$href, $label])
                    <a href="{{ $href }}" class="rounded-full border border-slate-200 bg-white px-3 py-1.5 text-slate-600 transition hover:border-slate-300 hover:text-slate-900">{{ $label }}</a>
                @endforeach
            </nav>
        </div>
    </section>

    {{-- ── 1. Cross-signal insights ─────────────────────────── --}}
    <section id="insights" class="bg-white py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="grid items-center gap-14 lg:grid-cols-2 lg:gap-20">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-600">Cross-signal insights</p>
                    <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-[2.25rem]">Six insight boards that produce action lists.</h2>
                    <p class="mt-4 text-[15px] leading-7 text-slate-600">
                        Cannibalization, striking distance, content decay, indexing fails with traffic, audit-vs-traffic, and backlink impact. Each report ranks the highest-impact items so your sprint stays focused.
                    </p>
                    <ul class="mt-7 space-y-3 text-[14px] text-slate-700">
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Joins GSC × GA4 × audits × backlinks per page</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Per-country and per-device segmentation</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Daily refresh with anomaly callouts</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>One-click export to CSV or weekly report</li>
                    </ul>
                </div>

                {{-- Mockup --}}
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="grid grid-cols-2 gap-3">
                        @foreach ([
                            ['Cannibalizations', '14', 'amber'],
                            ['Striking distance', '27', 'orange'],
                            ['Content decay', '8', 'slate'],
                            ['Indexing fails', '3', 'rose'],
                            ['Audit vs traffic', '11', 'slate'],
                            ['Backlink impact', '9', 'emerald'],
                        ] as [$lbl, $val, $tone])
                            <div class="rounded-xl border border-slate-200 bg-white p-4">
                                <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">{{ $lbl }}</p>
                                <p @class([
                                    'mt-1.5 text-2xl font-semibold tabular-nums',
                                    'text-amber-600' => $tone === 'amber',
                                    'text-orange-600' => $tone === 'orange',
                                    'text-slate-900' => $tone === 'slate',
                                    'text-rose-600' => $tone === 'rose',
                                    'text-emerald-600' => $tone === 'emerald',
                                ])>{{ $val }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── 2. Rank tracking ──────────────────────────────────── --}}
    <section id="rank-tracking" class="bg-slate-50/60 py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="grid items-center gap-14 lg:grid-cols-2 lg:gap-20">
                <div class="order-last lg:order-first">
                    {{-- Mockup: keyword chart --}}
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">"best seo tools" · United States</p>
                                <p class="mt-0.5 text-sm font-semibold text-slate-900">Position over 90 days</p>
                            </div>
                            <span class="rounded-md bg-emerald-50 px-2 py-1 text-[11px] font-semibold text-emerald-700 ring-1 ring-emerald-100">#9 → #2</span>
                        </div>
                        <svg viewBox="0 0 320 110" class="mt-4 h-32 w-full" aria-hidden="true">
                            <defs>
                                <linearGradient id="rk-fill" x1="0" x2="0" y1="0" y2="1">
                                    <stop offset="0%" stop-color="#F26419" stop-opacity="0.18"/>
                                    <stop offset="100%" stop-color="#F26419" stop-opacity="0"/>
                                </linearGradient>
                            </defs>
                            <path d="M0 80 L40 75 L80 78 L120 60 L160 55 L200 42 L240 30 L280 22 L320 14" fill="none" stroke="#F26419" stroke-width="2" stroke-linejoin="round"/>
                            <path d="M0 80 L40 75 L80 78 L120 60 L160 55 L200 42 L240 30 L280 22 L320 14 L320 110 L0 110 Z" fill="url(#rk-fill)"/>
                        </svg>
                        <div class="mt-2 flex items-center justify-between text-[10px] text-slate-400">
                            <span>90 days ago</span>
                            <span>Today</span>
                        </div>

                        <div class="mt-5 grid grid-cols-3 gap-2">
                            @foreach ([['Position', '#2'], ['Avg CTR', '11.4%'], ['Clicks 30d', '1,284']] as [$l, $v])
                                <div class="rounded-lg border border-slate-200 bg-slate-50/60 p-2.5 text-center">
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ $l }}</p>
                                    <p class="mt-0.5 text-base font-semibold tabular-nums text-slate-900">{{ $v }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-600">Rank tracking</p>
                    <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-[2.25rem]">SERP-accurate ranks with click overlays.</h2>
                    <p class="mt-4 text-[15px] leading-7 text-slate-600">
                        Real positions captured per device and country. Serfix overlays GSC clicks for the same query so you instantly see when a rank gain stops producing traffic — and which SERP feature is to blame.
                    </p>
                    <ul class="mt-7 space-y-3 text-[14px] text-slate-700">
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Country, device, language, and city targeting</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Competitor positions captured every check</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>SERP-feature flags and PAA capture</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Custom intervals + on-demand re-checks</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- ── 3. Page audits ────────────────────────────────────── --}}
    <section id="audits" class="bg-white py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="grid items-center gap-14 lg:grid-cols-2 lg:gap-20">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-600">Page audits</p>
                    <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-[2.25rem]">Core Web Vitals, on-page, and content in one pass.</h2>
                    <p class="mt-4 text-[15px] leading-7 text-slate-600">
                        On-demand audits combine mobile + desktop CWV with a deep HTML analyzer and keyword-strategy review tailored to the page's target query. Output is a prioritized recommendation list — not a 200-row spreadsheet.
                    </p>
                    <ul class="mt-7 space-y-3 text-[14px] text-slate-700">
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Full CWV: LCP, CLS, INP, TBT, FCP, TTFB</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>SEO checks: meta, headings, schema, hreflang, alt</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Content: word count, reading grade, top keywords</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>One-click resubmit via Google Indexing API</li>
                    </ul>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">Audit · /blog/saas-seo-guide</p>
                            <p class="mt-0.5 text-sm font-semibold text-slate-900">Mobile · Score 72</p>
                        </div>
                        <span class="rounded-md bg-amber-50 px-2 py-1 text-[11px] font-semibold text-amber-700 ring-1 ring-amber-100">Needs work</span>
                    </div>
                    <div class="mt-4 grid grid-cols-3 gap-2.5">
                        @foreach ([
                            ['LCP', '2.8s', 'amber'],
                            ['CLS', '0.04', 'emerald'],
                            ['INP', '180ms', 'emerald'],
                            ['TBT', '410ms', 'amber'],
                            ['FCP', '1.6s', 'emerald'],
                            ['TTFB', '720ms', 'amber'],
                        ] as [$l, $v, $tone])
                            <div class="rounded-lg border border-slate-200 bg-white p-3">
                                <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ $l }}</p>
                                <p @class([
                                    'mt-1 text-base font-semibold tabular-nums',
                                    'text-emerald-600' => $tone === 'emerald',
                                    'text-amber-600' => $tone === 'amber',
                                    'text-rose-600' => $tone === 'rose',
                                ])>{{ $v }}</p>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Top recommendations</p>
                        <ul class="mt-3 space-y-2 text-[12px]">
                            @foreach ([
                                ['rose', 'Render-blocking CSS — split into critical + async (180KB)'],
                                ['amber', 'Image alt missing on 7 images'],
                                ['amber', 'Canonical tag missing'],
                                ['slate', 'Add 2 internal links from /pricing'],
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
    </section>

    {{-- ── 4. Backlinks ──────────────────────────────────────── --}}
    <section id="backlinks" class="bg-slate-50/60 py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="grid items-center gap-14 lg:grid-cols-2 lg:gap-20">
                <div class="order-last lg:order-first">
                    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 px-5 py-3">
                            <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">Backlink impact · 28d</p>
                            <p class="mt-0.5 text-sm font-semibold text-slate-900">Sorted by Δ clicks</p>
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
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-600">Backlinks</p>
                    <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-[2.25rem]">Track every link, prove every lift.</h2>
                    <p class="mt-4 text-[15px] leading-7 text-slate-600">
                        Bulk import or manual entry. Serfix verifies presence, anchor, and rel — then measures click delta on the target page in the 28 days after the link goes live.
                    </p>
                    <ul class="mt-7 space-y-3 text-[14px] text-slate-700">
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Live verification of presence + anchor + rel</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Pre/post 28-day click delta per target page</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Filters: DA, spam, dofollow, anchor, date</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Competitor backlink prospecting (Pro+)</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- ── 5. Anomaly alerts ─────────────────────────────────── --}}
    <section id="alerts" class="bg-white py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="grid items-center gap-14 lg:grid-cols-2 lg:gap-20">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-600">Anomaly alerts</p>
                    <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-[2.25rem]">Know within hours when something breaks.</h2>
                    <p class="mt-4 text-[15px] leading-7 text-slate-600">
                        Statistical detection compares yesterday against a 28-day baseline on clicks, sessions, and average tracked-keyword position. Two gates — relative drop and z-score — keep the inbox quiet.
                    </p>
                    <ul class="mt-7 space-y-3 text-[14px] text-slate-700">
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Per-metric diagnosis with current value, baseline, stddev</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>24-hour deduplication — one alert per anomaly</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Notifies all report recipients automatically</li>
                    </ul>
                </div>

                {{-- Mockup: alert email --}}
                <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-200 px-5 py-3">
                        <div class="flex items-center justify-between">
                            <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">Alert · example.com</p>
                            <span class="rounded-md bg-rose-50 px-2 py-0.5 text-[11px] font-semibold text-rose-700 ring-1 ring-rose-100">Anomaly</span>
                        </div>
                        <p class="mt-1 text-sm font-semibold text-slate-900">Search clicks dropped 74.9%</p>
                    </div>
                    <div class="px-5 py-5 text-[12px] text-slate-700">
                        <p>An unusual drop was detected on 2026-04-20.</p>
                        <ul class="mt-3 space-y-1.5">
                            <li class="flex items-center justify-between rounded-md bg-slate-50/60 px-3 py-1.5"><span>Search clicks</span><span class="font-mono text-rose-600">212 vs 844 (z=-3.2)</span></li>
                            <li class="flex items-center justify-between rounded-md bg-slate-50/60 px-3 py-1.5"><span>Sessions</span><span class="font-mono text-rose-600">480 vs 1,610 (z=-2.8)</span></li>
                            <li class="flex items-center justify-between rounded-md bg-slate-50/60 px-3 py-1.5"><span>Avg position</span><span class="font-mono text-amber-600">14.2 vs 11.6 (z=-1.9)</span></li>
                        </ul>
                        <div class="mt-4 inline-flex rounded-md bg-slate-900 px-3 py-1.5 text-[11px] font-semibold text-white">Open Serfix →</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── 6. Reporting ──────────────────────────────────────── --}}
    <section id="reporting" class="bg-slate-50/60 py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="grid items-center gap-14 lg:grid-cols-2 lg:gap-20">
                <div class="order-last lg:order-first">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-center justify-between border-b border-slate-200 pb-4">
                            <div>
                                <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">Weekly Growth Report</p>
                                <p class="mt-1 text-sm font-semibold text-slate-900">example.com · Apr 13–19</p>
                            </div>
                            <span class="rounded-md bg-emerald-50 px-2 py-1 text-[11px] font-semibold text-emerald-700 ring-1 ring-emerald-100">+12% w/w</span>
                        </div>
                        <div class="mt-4 grid grid-cols-3 gap-2.5">
                            @foreach ([['Users', '8.4k', '+12%'], ['Clicks', '3.1k', '+8%'], ['Avg pos', '14.2', '-0.6']] as [$l, $v, $d])
                                <div class="rounded-lg border border-slate-200 bg-slate-50/60 p-3 text-center">
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ $l }}</p>
                                    <p class="mt-1 text-base font-semibold tabular-nums text-slate-900">{{ $v }}</p>
                                    <p class="text-[10px] font-semibold text-emerald-600">{{ $d }}</p>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-4 rounded-xl border border-orange-100 bg-orange-50/60 p-4">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-orange-700">Action insights</p>
                            <ul class="mt-2 space-y-1.5 text-[12px] text-slate-700">
                                <li>• 5 striking-distance keywords ready to push</li>
                                <li>• 3 cannibalization conflicts on "saas seo guide"</li>
                                <li>• 1 indexing fail still earning impressions</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-600">Reporting</p>
                    <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-[2.25rem]">Executive-ready, zero rework.</h2>
                    <p class="mt-4 text-[15px] leading-7 text-slate-600">
                        Daily, weekly, or monthly. Every report includes YoY, top gainers/losers, traffic-source concentration, and the top-5 actionable insights.
                    </p>
                    <ul class="mt-7 space-y-3 text-[14px] text-slate-700">
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Per-website recipient lists, multi-stakeholder</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Custom date ranges with in-app preview</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Scheduled auto-send in website timezone</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>White-label PDF export (Agency plan)</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- ── 7. WordPress ──────────────────────────────────────── --}}
    <section id="wordpress" class="bg-white py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="grid items-center gap-14 lg:grid-cols-2 lg:gap-20">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-600">WordPress plugin</p>
                    <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-[2.25rem]">Surface insights where editors write.</h2>
                    <p class="mt-4 text-[15px] leading-7 text-slate-600">
                        The Serfix plugin embeds rank, click, and content opportunity context inside Gutenberg, the post list, and the WordPress dashboard. Connect with one click; tokens are website-scoped and never live in browser JS.
                    </p>
                    <ul class="mt-7 space-y-3 text-[14px] text-slate-700">
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Gutenberg sidebar with rank, clicks, opportunities</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Posts list column with 30-day clicks + position</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>WP dashboard widget with insight counts</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>Per-website Sanctum tokens, challenge-response</li>
                    </ul>
                </div>

                {{-- Mockup: WP sidebar --}}
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center gap-2 border-b border-slate-200 pb-3">
                        <span class="h-2 w-2 rounded-full bg-rose-400"></span>
                        <span class="h-2 w-2 rounded-full bg-amber-400"></span>
                        <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
                        <span class="ml-2 text-[11px] font-medium text-slate-500">Gutenberg · Serfix SEO</span>
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
                            <p class="mt-1 text-[11px] text-slate-700">Splits with /blog/seo-tools-guide</p>
                        </div>
                        <div class="rounded-lg border border-orange-100 bg-orange-50/60 p-3">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-orange-700">Striking distance</p>
                            <p class="mt-1 text-[11px] text-slate-700">3 queries at pos 5–20 with below-curve CTR</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── 8. Integrations ──────────────────────────────────── --}}
    <section id="integrations" class="bg-slate-50/60 py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Integrations</p>
                <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">Connected to the signals that matter.</h2>
                <p class="mt-4 text-base leading-7 text-slate-600">OAuth-authenticated, synced daily, no spreadsheets.</p>
            </div>
            <div class="mt-12 grid gap-px overflow-hidden rounded-2xl border border-slate-200 bg-slate-200 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    ['Google Search Console', 'Clicks, impressions, position, CTR by query × page × device × country.'],
                    ['Google Analytics 4', 'Users, sessions, bounce rate with source/medium attribution.'],
                    ['Google Indexing API', 'Per-page verdict, coverage, last-crawl. Resubmit from the UI.'],
                    ['Core Web Vitals', 'Mobile + desktop performance scores piped into audits.'],
                    ['SERP data', 'Live SERP capture for rank tracking with feature extraction.'],
                    ['Email + Slack', 'Reports and alerts via SMTP, Postmark, Resend, or Slack.'],
                ] as [$t, $d])
                    <article class="bg-white p-6">
                        <h3 class="text-base font-semibold text-slate-900">{{ $t }}</h3>
                        <p class="mt-2 text-[13px] leading-6 text-slate-600">{{ $d }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── CTA ──────────────────────────────────────────────── --}}
    <section class="bg-white py-20 sm:py-24">
        <div class="mx-auto max-w-4xl px-6 lg:px-8">
            <div class="rounded-3xl border border-slate-200 bg-slate-50/60 px-6 py-14 text-center sm:px-12">
                <h2 class="text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">See Serfix on your own data.</h2>
                <p class="mx-auto mt-4 max-w-xl text-base leading-7 text-slate-600">Connect your first website and run an action-ready report in minutes.</p>
                <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <a href="{{ route('register') }}" class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">Start free trial</a>
                    <a href="{{ route('pricing') }}" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-900">View pricing</a>
                </div>
            </div>
        </div>
    </section>
</x-marketing.page>
