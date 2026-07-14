<x-marketing.page
    title="Features — Serfix"
    description="Site Explorer backlink intelligence, cross-signal insights, keyword research, rank tracking, site audits, AI content, white-label reporting, and the WordPress plugin — all in one workspace."
    active="features"
>
    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section class="border-b border-slate-200 bg-white">
        <div class="mx-auto max-w-6xl px-6 py-20 text-center lg:px-8 lg:py-24">
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">{{ __('Product features') }}</p>
            <h1 class="mx-auto mt-4 max-w-3xl text-balance text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl lg:text-6xl">
                {{ __('Every signal, every action, in one workspace.') }}
            </h1>
            <p class="mx-auto mt-5 max-w-2xl text-balance text-[17px] leading-8 text-slate-600">
                {{ __('Serfix joins Search Console, Analytics, rankings, audits, backlinks, and AI content into a single decision surface. Each module answers the same question: what should we ship next, and what changed after we did?') }}
            </p>
            <div class="mt-9 flex flex-col items-center justify-center gap-3 sm:flex-row">
                <a href="{{ route('register') }}" class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">{{ __('Start free trial') }}</a>
                <a href="#site-explorer" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-900">{{ __('Explore features') }}</a>
            </div>

            {{-- Anchor pill nav --}}
            <nav aria-label="{{ __('Feature sections') }}" class="mx-auto mt-12 flex max-w-4xl flex-wrap items-center justify-center gap-2 text-xs font-medium">
                @foreach ([
                    ['#site-explorer', __('Site Explorer')],
                    ['#insights', __('Insights')],
                    ['#keywords', __('Keyword research')],
                    ['#rank-tracking', __('Rank tracking')],
                    ['#audits', __('Site audits')],
                    ['#backlinks', __('Backlinks')],
                    ['#ai-studio', __('AI Studio')],
                    ['#alerts', __('Alerts')],
                    ['#reporting', __('Reporting')],
                    ['#wordpress', __('WordPress')],
                    ['#integrations', __('Integrations')],
                ] as [$href, $label])
                    <a href="{{ $href }}" class="rounded-full border border-slate-200 bg-white px-3 py-1.5 text-slate-600 transition hover:border-slate-300 hover:text-slate-900">{{ $label }}</a>
                @endforeach
            </nav>
        </div>
    </section>

    {{-- ── 1. Site Explorer ─────────────────────────────────── --}}
    <section id="site-explorer" class="bg-slate-50/60 py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="grid items-center gap-14 lg:grid-cols-2 lg:gap-20">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-600">{{ __('Site Explorer') }} <span class="ms-2 inline-flex items-center rounded-full bg-orange-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-orange-700">{{ __('New') }}</span></p>
                    <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-[2.25rem]">{{ __('Any domain\'s full backlink & authority profile.') }}</h2>
                    <p class="mt-4 text-[15px] leading-7 text-slate-600">
                        {{ __('Type a domain — yours or a competitor\'s — and get Domain Authority, Page Authority, spam score, popularity rank, the complete backlink profile with anchors, and the organic competitors that share its keywords. One lookup, one page.') }}
                    </p>
                    <ul class="mt-7 space-y-3 text-[14px] text-slate-700">
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Domain Authority, Page Authority, spam & popularity scores') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Referring domains, IPs & subnets with active-vs-lost history') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Anchor-text breakdown: branded, naked, generic, exact') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Organic competitors ranked by shared keywords') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Share as a public link or a white-label PDF') }}</li>
                    </ul>
                </div>

                {{-- Mockup: Site Explorer report --}}
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-200 pb-3">
                        <div>
                            <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">{{ __('Site Explorer · competitor.com') }}</p>
                            <p class="mt-0.5 text-sm font-semibold text-slate-900">{{ __('Backlink & authority report') }}</p>
                        </div>
                        <span class="rounded-md bg-orange-50 px-2 py-1 text-[11px] font-semibold text-orange-700 ring-1 ring-orange-100">{{ __('Top 1M · 59.6%') }}</span>
                    </div>
                    <div class="mt-4 grid grid-cols-4 gap-2">
                        @foreach ([
                            [__('DA'), '41', 'orange'],
                            [__('PA'), '38', 'orange'],
                            [__('Authority'), '47', 'emerald'],
                            [__('Spam'), '3%', 'emerald'],
                        ] as [$l, $v, $tone])
                            <div class="rounded-lg border border-slate-200 bg-slate-50/60 p-2.5 text-center">
                                <p @class([
                                    'text-lg font-semibold tabular-nums',
                                    'text-orange-600' => $tone === 'orange',
                                    'text-emerald-600' => $tone === 'emerald',
                                ])>{{ $v }}</p>
                                <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ $l }}</p>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-2.5 grid grid-cols-3 gap-2">
                        @foreach ([[__('Backlinks'), '24,318'], [__('Ref. domains'), '1,842'], [__('Dofollow'), '22%']] as [$l, $v])
                            <div class="rounded-lg border border-slate-200 bg-white p-2.5 text-center">
                                <p class="text-sm font-semibold tabular-nums text-slate-900">{{ $v }}</p>
                                <p class="text-[10px] font-medium uppercase tracking-wider text-slate-500">{{ $l }}</p>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50/60 p-3.5">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ __('Organic competitors') }}</p>
                        <ul class="mt-2 space-y-1.5 text-[12px]">
                            @foreach ([
                                ['nickfinder.com', '477', '5.5'],
                                ['lingojam.com', '280', '11.4'],
                                ['stylish-names.net', '254', '10.0'],
                            ] as [$d, $kw, $pos])
                                <li class="flex items-center justify-between rounded-md bg-white px-3 py-1.5 ring-1 ring-slate-200">
                                    <span class="font-medium text-slate-800">{{ $d }}</span>
                                    <span class="tabular-nums text-slate-500">{{ $kw }} {{ __('shared kw') }} · {{ __('pos') }} {{ $pos }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── 2. Cross-signal insights ─────────────────────────── --}}
    <section id="insights" class="bg-white py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="grid items-center gap-14 lg:grid-cols-2 lg:gap-20">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-600">{{ __('Cross-signal insights') }}</p>
                    <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-[2.25rem]">{{ __('Six insight boards that produce action lists.') }}</h2>
                    <p class="mt-4 text-[15px] leading-7 text-slate-600">
                        {{ __('Cannibalization, striking distance, content decay, indexing fails with traffic, audit-vs-traffic, and backlink impact. Each report ranks the highest-impact items so your sprint stays focused.') }}
                    </p>
                    <ul class="mt-7 space-y-3 text-[14px] text-slate-700">
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Joins GSC × GA4 × audits × backlinks per page') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Per-country and per-device segmentation') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Daily refresh with anomaly callouts') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Every insight feeds the Priority Action Queue') }}</li>
                    </ul>
                </div>

                {{-- Mockup --}}
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="grid grid-cols-2 gap-3">
                        @foreach ([
                            [__('Cannibalizations'), '14', 'amber'],
                            [__('Striking distance'), '27', 'orange'],
                            [__('Content decay'), '8', 'slate'],
                            [__('Indexing fails'), '3', 'rose'],
                            [__('Audit vs traffic'), '11', 'slate'],
                            [__('Backlink impact'), '9', 'emerald'],
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

    {{-- ── 3. Keyword research ──────────────────────────────── --}}
    <section id="keywords" class="bg-slate-50/60 py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="grid items-center gap-14 lg:grid-cols-2 lg:gap-20">
                <div class="order-last lg:order-first">
                    {{-- Mockup: keyword table --}}
                    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 px-5 py-3">
                            <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">{{ __('Keyword ideas · "project management"') }}</p>
                            <p class="mt-0.5 text-sm font-semibold text-slate-900">{{ __('Live search volumes') }}</p>
                        </div>
                        <table class="min-w-full text-[12px]">
                            <thead class="bg-slate-50/60 text-[10px] uppercase tracking-wider text-slate-500">
                                <tr>
                                    <th class="px-4 py-2 text-left font-semibold">{{ __('Keyword') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('Volume') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('CPC') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('Comp.') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ([
                                    [__('project management software'), '74,000', '$18.40', __('High')],
                                    [__('best project management tools'), '22,200', '$14.10', __('High')],
                                    [__('free project management app'), '9,900', '$7.25', __('Med')],
                                    [__('project tracker template'), '5,400', '$3.60', __('Low')],
                                    [__('agile project planning'), '2,900', '$6.80', __('Med')],
                                ] as [$k, $v, $c, $comp])
                                    <tr class="hover:bg-slate-50/60">
                                        <td class="px-4 py-2.5 font-medium text-slate-800">{{ $k }}</td>
                                        <td class="px-3 py-2.5 text-right tabular-nums text-slate-600">{{ $v }}</td>
                                        <td class="px-3 py-2.5 text-right tabular-nums text-slate-600">{{ $c }}</td>
                                        <td class="px-3 py-2.5 text-right text-slate-600">{{ $comp }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-600">{{ __('Keyword research') }}</p>
                    <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-[2.25rem]">{{ __('Real volumes, gaps, and clusters — not guesses.') }}</h2>
                    <p class="mt-4 text-[15px] leading-7 text-slate-600">
                        {{ __('Expand seed keywords into ideas with live search volumes, CPC, and competition. Gap analysis shows the queries competitors rank for that you don\'t, and clustering groups everything into pages you can actually build.') }}
                    </p>
                    <ul class="mt-7 space-y-3 text-[14px] text-slate-700">
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Keyword ideas with volume, CPC & competition') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Competitor keyword-gap analysis with opportunity scoring') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Your own GSC queries enriched with volumes automatically') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('One-click: track it, brief it, or send it to AI Studio') }}</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- ── 4. Rank tracking ──────────────────────────────────── --}}
    <section id="rank-tracking" class="bg-white py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="grid items-center gap-14 lg:grid-cols-2 lg:gap-20">
                <div class="order-last lg:order-first">
                    {{-- Mockup: keyword chart --}}
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">{{ __('"best seo tools" · United States') }}</p>
                                <p class="mt-0.5 text-sm font-semibold text-slate-900">{{ __('Position over 90 days') }}</p>
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
                            <span>{{ __('90 days ago') }}</span>
                            <span>{{ __('Today') }}</span>
                        </div>

                        <div class="mt-5 grid grid-cols-3 gap-2">
                            @foreach ([[__('Position'), '#2'], [__('Avg CTR'), '11.4%'], [__('Clicks 30d'), '1,284']] as [$l, $v])
                                <div class="rounded-lg border border-slate-200 bg-slate-50/60 p-2.5 text-center">
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ $l }}</p>
                                    <p class="mt-0.5 text-base font-semibold tabular-nums text-slate-900">{{ $v }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-600">{{ __('Rank tracking') }}</p>
                    <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-[2.25rem]">{{ __('SERP-accurate ranks with click overlays.') }}</h2>
                    <p class="mt-4 text-[15px] leading-7 text-slate-600">
                        {{ __('Real positions captured from live SERPs per country and device. Serfix overlays GSC clicks for the same query so you instantly see when a rank gain stops producing traffic.') }}
                    </p>
                    <ul class="mt-7 space-y-3 text-[14px] text-slate-700">
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Country and device targeting per keyword') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Position history with GSC click & CTR overlays') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Custom check intervals + on-demand re-checks') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Rank drops feed the anomaly detector automatically') }}</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- ── 5. Site audits ────────────────────────────────────── --}}
    <section id="audits" class="bg-slate-50/60 py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="grid items-center gap-14 lg:grid-cols-2 lg:gap-20">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-600">{{ __('Site & page audits') }}</p>
                    <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-[2.25rem]">{{ __('A full-site crawler plus deep single-page audits.') }}</h2>
                    <p class="mt-4 text-[15px] leading-7 text-slate-600">
                        {{ __('Serfix crawls your whole site on an adaptive schedule, scores its health, and turns every issue into a ranked fix list. Page-level audits add mobile + desktop Core Web Vitals, on-page checks, and a keyword-strategy review for the target query.') }}
                    </p>
                    <ul class="mt-7 space-y-3 text-[14px] text-slate-700">
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Site health score with issues ranked by severity') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Orphan pages, broken links, redirects & internal-link map') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Full CWV: LCP, CLS, INP, TBT, FCP, TTFB') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Adaptive recrawl — busy sites more often, static less') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Everything lands in one Priority Action Queue') }}</li>
                    </ul>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">{{ __('Site audit · example.com') }}</p>
                            <p class="mt-0.5 text-sm font-semibold text-slate-900">{{ __('Health score 86 · 1,240 pages') }}</p>
                        </div>
                        <span class="rounded-md bg-emerald-50 px-2 py-1 text-[11px] font-semibold text-emerald-700 ring-1 ring-emerald-100">{{ __('Healthy') }}</span>
                    </div>
                    <div class="mt-4 grid grid-cols-3 gap-2.5">
                        @foreach ([
                            [__('Critical'), '3', 'rose'],
                            [__('Warnings'), '27', 'amber'],
                            [__('Notices'), '64', 'slate'],
                        ] as [$l, $v, $tone])
                            <div class="rounded-lg border border-slate-200 bg-white p-3 text-center">
                                <p @class([
                                    'text-xl font-semibold tabular-nums',
                                    'text-rose-600' => $tone === 'rose',
                                    'text-amber-600' => $tone === 'amber',
                                    'text-slate-900' => $tone === 'slate',
                                ])>{{ $v }}</p>
                                <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ $l }}</p>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ __('Priority action queue') }}</p>
                        <ul class="mt-3 space-y-2 text-[12px]">
                            @foreach ([
                                ['rose', __('12 pages return 404 but still receive internal links')],
                                ['amber', __('Duplicate titles across 8 category pages')],
                                ['amber', __('LCP over 2.5s on 5 top-traffic pages')],
                                ['slate', __('34 orphan pages with zero inbound links')],
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

    {{-- ── 6. Backlinks ──────────────────────────────────────── --}}
    <section id="backlinks" class="bg-white py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="grid items-center gap-14 lg:grid-cols-2 lg:gap-20">
                <div class="order-last lg:order-first">
                    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 px-5 py-3">
                            <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">{{ __('Backlink impact · 28d') }}</p>
                            <p class="mt-0.5 text-sm font-semibold text-slate-900">{{ __('Sorted by Δ clicks') }}</p>
                        </div>
                        <table class="min-w-full text-[12px]">
                            <thead class="bg-slate-50/60 text-[10px] uppercase tracking-wider text-slate-500">
                                <tr>
                                    <th class="px-4 py-2 text-left font-semibold">{{ __('Target page') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('Links') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('DA') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('Δ clicks') }}</th>
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
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-600">{{ __('Backlinks') }}</p>
                    <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-[2.25rem]">{{ __('Track every link, prove every lift, win the next one.') }}</h2>
                    <p class="mt-4 text-[15px] leading-7 text-slate-600">
                        {{ __('Serfix verifies each link\'s presence, anchor, and rel — then measures the click delta on the target page in the 28 days after it went live. Prospecting mines the domains linking to your competitors but not to you, and drafts the outreach email for you.') }}
                    </p>
                    <ul class="mt-7 space-y-3 text-[14px] text-slate-700">
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Live verification of presence + anchor + rel') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Pre/post 28-day click delta per target page') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Competitor backlink prospecting with a workflow: new → contacted → converted') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('AI-drafted outreach emails per prospect (Pro+)') }}</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- ── 7. AI Studio ──────────────────────────────────────── --}}
    <section id="ai-studio" class="bg-slate-50/60 py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="grid items-center gap-14 lg:grid-cols-2 lg:gap-20">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-600">{{ __('AI Studio') }}</p>
                    <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-[2.25rem]">{{ __('Content that starts from your search data.') }}</h2>
                    <p class="mt-4 text-[15px] leading-7 text-slate-600">
                        {{ __('The blog-post wizard plans, outlines, and drafts long-form articles in the background — seeded by the keywords you already rank for or want to. Dozens of focused writing tools handle titles, metas, rewrites, briefs, and internal-link suggestions.') }}
                    </p>
                    <ul class="mt-7 space-y-3 text-[14px] text-slate-700">
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Guided blog-post wizard: keyword → outline → full draft') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Drafts generate asynchronously — start one, keep working') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Internal-link suggestions from your own crawl graph') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Title, meta, rewrite & brief tools with usage pooling per plan') }}</li>
                    </ul>
                </div>

                {{-- Mockup: writer wizard --}}
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-200 pb-3">
                        <div>
                            <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">{{ __('AI Writer · blog post wizard') }}</p>
                            <p class="mt-0.5 text-sm font-semibold text-slate-900">{{ __('"agile project planning" · 1,800 words') }}</p>
                        </div>
                        <span class="rounded-md bg-orange-50 px-2 py-1 text-[11px] font-semibold text-orange-700 ring-1 ring-orange-100">{{ __('Drafting…') }}</span>
                    </div>
                    <ul class="mt-4 space-y-2 text-[12px]">
                        @foreach ([
                            ['done', __('Keyword & intent analysis')],
                            ['done', __('Competing SERP outline review')],
                            ['done', __('H2/H3 outline approved')],
                            ['active', __('Writing sections (4 of 7)')],
                            ['todo', __('Internal links & meta description')],
                        ] as [$state, $step])
                            <li class="flex items-center gap-2.5 rounded-lg border border-slate-200 bg-slate-50/60 px-3 py-2">
                                @if ($state === 'done')
                                    <span class="flex h-4 w-4 flex-none items-center justify-center rounded-full bg-emerald-100 text-[9px] font-bold text-emerald-700">✓</span>
                                @elseif ($state === 'active')
                                    <span class="h-4 w-4 flex-none animate-pulse rounded-full border-2 border-orange-400"></span>
                                @else
                                    <span class="h-4 w-4 flex-none rounded-full border-2 border-slate-200"></span>
                                @endif
                                <span @class(['text-slate-700', 'font-semibold text-slate-900' => $state === 'active'])>{{ $step }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <div class="mt-4 flex flex-wrap gap-1.5">
                        @foreach ([__('Titles'), __('Meta descriptions'), __('Rewrites'), __('Briefs'), __('FAQs'), __('Summaries')] as $chip)
                            <span class="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[10px] font-medium text-slate-600">{{ $chip }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── 8. Anomaly alerts ─────────────────────────────────── --}}
    <section id="alerts" class="bg-white py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="grid items-center gap-14 lg:grid-cols-2 lg:gap-20">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-600">{{ __('Anomaly alerts') }}</p>
                    <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-[2.25rem]">{{ __('Know within hours when something breaks.') }}</h2>
                    <p class="mt-4 text-[15px] leading-7 text-slate-600">
                        {{ __('Statistical detection compares yesterday against a 28-day baseline on clicks, sessions, and average tracked-keyword position. Two gates — relative drop and z-score — keep the inbox quiet.') }}
                    </p>
                    <ul class="mt-7 space-y-3 text-[14px] text-slate-700">
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Per-metric diagnosis with current value, baseline, stddev') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('24-hour deduplication — one alert per anomaly') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Notifies all report recipients automatically') }}</li>
                    </ul>
                </div>

                {{-- Mockup: alert email --}}
                <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-200 px-5 py-3">
                        <div class="flex items-center justify-between">
                            <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">{{ __('Alert · example.com') }}</p>
                            <span class="rounded-md bg-rose-50 px-2 py-0.5 text-[11px] font-semibold text-rose-700 ring-1 ring-rose-100">{{ __('Anomaly') }}</span>
                        </div>
                        <p class="mt-1 text-sm font-semibold text-slate-900">{{ __('Search clicks dropped 74.9%') }}</p>
                    </div>
                    <div class="px-5 py-5 text-[12px] text-slate-700">
                        <p>{{ __('An unusual drop was detected on 2026-04-20.') }}</p>
                        <ul class="mt-3 space-y-1.5">
                            <li class="flex items-center justify-between rounded-md bg-slate-50/60 px-3 py-1.5"><span>{{ __('Search clicks') }}</span><span class="font-mono text-rose-600">212 vs 844 (z=-3.2)</span></li>
                            <li class="flex items-center justify-between rounded-md bg-slate-50/60 px-3 py-1.5"><span>{{ __('Sessions') }}</span><span class="font-mono text-rose-600">480 vs 1,610 (z=-2.8)</span></li>
                            <li class="flex items-center justify-between rounded-md bg-slate-50/60 px-3 py-1.5"><span>{{ __('Avg position') }}</span><span class="font-mono text-amber-600">14.2 vs 11.6 (z=-1.9)</span></li>
                        </ul>
                        <div class="mt-4 inline-flex rounded-md bg-slate-900 px-3 py-1.5 text-[11px] font-semibold text-white">{{ __('Open Serfix →') }}</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── 9. Reporting ──────────────────────────────────────── --}}
    <section id="reporting" class="bg-slate-50/60 py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="grid items-center gap-14 lg:grid-cols-2 lg:gap-20">
                <div class="order-last lg:order-first">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-center justify-between border-b border-slate-200 pb-4">
                            <div>
                                <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">{{ __('Weekly Growth Report') }}</p>
                                <p class="mt-1 text-sm font-semibold text-slate-900">{{ __('example.com · Apr 13–19') }}</p>
                            </div>
                            <span class="rounded-md bg-emerald-50 px-2 py-1 text-[11px] font-semibold text-emerald-700 ring-1 ring-emerald-100">+12% w/w</span>
                        </div>
                        <div class="mt-4 grid grid-cols-3 gap-2.5">
                            @foreach ([[__('Users'), '8.4k', '+12%'], [__('Clicks'), '3.1k', '+8%'], [__('Avg pos'), '14.2', '-0.6']] as [$l, $v, $d])
                                <div class="rounded-lg border border-slate-200 bg-slate-50/60 p-3 text-center">
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ $l }}</p>
                                    <p class="mt-1 text-base font-semibold tabular-nums text-slate-900">{{ $v }}</p>
                                    <p class="text-[10px] font-semibold text-emerald-600">{{ $d }}</p>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-4 rounded-xl border border-orange-100 bg-orange-50/60 p-4">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-orange-700">{{ __('Action insights') }}</p>
                            <ul class="mt-2 space-y-1.5 text-[12px] text-slate-700">
                                <li>• {{ __('5 striking-distance keywords ready to push') }}</li>
                                <li>• {{ __('3 cannibalization conflicts on "saas seo guide"') }}</li>
                                <li>• {{ __('1 indexing fail still earning impressions') }}</li>
                            </ul>
                        </div>
                        <div class="mt-4 flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50/60 px-3 py-2 text-[11px] text-slate-500">
                            <svg class="h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" /></svg>
                            <span class="truncate font-mono">serfix.io/r/x8Tq…</span>
                            <span class="ms-auto rounded bg-white px-1.5 py-0.5 font-semibold text-slate-600 ring-1 ring-slate-200">{{ __('Copy link') }}</span>
                        </div>
                    </div>
                </div>

                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-600">{{ __('Reporting') }}</p>
                    <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-[2.25rem]">{{ __('Executive-ready. White-label. Shareable.') }}</h2>
                    <p class="mt-4 text-[15px] leading-7 text-slate-600">
                        {{ __('Daily, weekly, or monthly growth reports by email with a branded PDF attached — plus live report pages you can share with any client as a public link. Your logo, your colors, your domain story.') }}
                    </p>
                    <ul class="mt-7 space-y-3 text-[14px] text-slate-700">
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Scheduled email reports with branded PDF attachment') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Public share links — clients view reports without an account') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('White-label branding: logo, company name, accent color') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Send from your own Gmail, Outlook, or SMTP') }}</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- ── 10. WordPress ─────────────────────────────────────── --}}
    <section id="wordpress" class="bg-white py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="grid items-center gap-14 lg:grid-cols-2 lg:gap-20">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-600">{{ __('WordPress plugin') }}
                    @if (config('services.wordpress_plugin.coming_soon'))
                        <span class="ms-2 inline-flex items-center rounded-full bg-orange-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-orange-700">{{ __('Coming soon') }}</span>
                    @endif
                    </p>
                    <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-[2.25rem]">{{ __('A full Serfix HQ inside wp-admin.') }}</h2>
                    <p class="mt-4 text-[15px] leading-7 text-slate-600">
                        {{ __('The Serfix plugin brings rank, click, and opportunity context into Gutenberg and the post list — plus complete Site Audit and Keyword Finder tabs, so editors act on SEO without leaving WordPress. One-click connect; tokens are website-scoped and never live in browser JS.') }}
                    </p>
                    <ul class="mt-7 space-y-3 text-[14px] text-slate-700">
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Gutenberg sidebar with rank, clicks, opportunities') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Site Audit & Keyword Finder tabs inside wp-admin') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Posts list column with 30-day clicks + position') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Per-website Sanctum tokens, challenge-response pairing') }}</li>
                    </ul>
                </div>

                {{-- Mockup: WP sidebar --}}
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center gap-2 border-b border-slate-200 pb-3">
                        <span class="h-2 w-2 rounded-full bg-rose-400"></span>
                        <span class="h-2 w-2 rounded-full bg-amber-400"></span>
                        <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
                        <span class="ml-2 text-[11px] font-medium text-slate-500">{{ __('Gutenberg · Serfix SEO') }}</span>
                    </div>
                    <div class="mt-4 space-y-3 text-[12px]">
                        <div class="rounded-lg border border-slate-200 bg-slate-50/60 p-3">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ __('Search performance · 30d') }}</p>
                            <div class="mt-2 grid grid-cols-4 gap-1.5">
                                @foreach ([[__('Clicks'), '1,284'], [__('Impr'), '21.4k'], [__('Pos'), '6.4'], [__('CTR'), '6.0%']] as [$l, $v])
                                    <div class="rounded bg-white px-2 py-1.5 text-center ring-1 ring-slate-200">
                                        <span class="block text-[9px] font-medium uppercase text-slate-500">{{ $l }}</span>
                                        <span class="block tabular-nums font-semibold text-slate-900">{{ $v }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="rounded-lg border border-emerald-100 bg-emerald-50/60 p-3">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-emerald-700">{{ __('Rank tracking') }}</p>
                            <div class="mt-1.5 flex items-center gap-2">
                                <span class="rounded-md bg-white px-1.5 py-0.5 text-[10px] font-bold text-slate-900 ring-1 ring-slate-200">#4</span>
                                <span class="text-[10px] font-semibold text-emerald-700">▲ 2</span>
                                <span class="text-[10px] text-slate-500">{{ __('"best seo tools"') }}</span>
                            </div>
                        </div>
                        <div class="rounded-lg border border-amber-100 bg-amber-50/60 p-3">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-amber-700">{{ __('Site audit') }}</p>
                            <p class="mt-1 text-[11px] text-slate-700">{{ __('Health 86 · 3 critical issues to review') }}</p>
                        </div>
                        <div class="rounded-lg border border-orange-100 bg-orange-50/60 p-3">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-orange-700">{{ __('Striking distance') }}</p>
                            <p class="mt-1 text-[11px] text-slate-700">{{ __('3 queries at pos 5–20 with below-curve CTR') }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── 11. Integrations ──────────────────────────────────── --}}
    <section id="integrations" class="bg-slate-50/60 py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">{{ __('Integrations') }}</p>
                <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">{{ __('Connected to the signals that matter.') }}</h2>
                <p class="mt-4 text-base leading-7 text-slate-600">{{ __('OAuth-authenticated, synced daily, no spreadsheets.') }}</p>
            </div>
            <div class="mt-12 grid gap-px overflow-hidden rounded-2xl border border-slate-200 bg-slate-200 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    [__('Google Search Console'), __('Clicks, impressions, position, CTR by query × page × device × country.')],
                    [__('Google Analytics 4'), __('Users, sessions, bounce rate with source/medium attribution.')],
                    [__('Google Indexing API'), __('Per-page verdict, coverage, last-crawl. Resubmit from the UI.')],
                    [__('Backlink & authority index'), __('Domain Authority, backlink profiles, and popularity ranks from trusted third-party indexes.')],
                    [__('Core Web Vitals & SERP data'), __('Mobile + desktop performance scores and live SERP capture for rank tracking.')],
                    [__('Gmail, Outlook & SMTP'), __('Reports and alerts sent from your own mailbox or SMTP relay.')],
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
                <h2 class="text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">{{ __('See Serfix on your own data.') }}</h2>
                <p class="mx-auto mt-4 max-w-xl text-base leading-7 text-slate-600">{{ __('Analyze your website, connect Search Console, and get an action-ready report in minutes.') }}</p>
                <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <a href="{{ route('register') }}" class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">{{ __('Start free trial') }}</a>
                    <a href="{{ route('pricing') }}" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-900">{{ __('View pricing') }}</a>
                </div>
                <p class="mt-6 text-xs text-slate-500">
                    {{ __('Or try the free tools first:') }}
                    <a href="{{ url('/') }}" class="font-medium text-orange-600 hover:underline">{{ __('Site Explorer') }}</a> ·
                    <a href="{{ route('tools.audit') }}" class="font-medium text-orange-600 hover:underline">{{ __('SEO Audit') }}</a> ·
                    <a href="{{ route('tools.pagespeed') }}" class="font-medium text-orange-600 hover:underline">{{ __('PageSpeed') }}</a> ·
                    <a href="{{ route('tools.rank-tracker') }}" class="font-medium text-orange-600 hover:underline">{{ __('Rank Checker') }}</a> ·
                    <a href="{{ route('tools.keyword-volume') }}" class="font-medium text-orange-600 hover:underline">{{ __('Volume Checker') }}</a>
                </p>
            </div>
        </div>
    </section>
</x-marketing.page>
