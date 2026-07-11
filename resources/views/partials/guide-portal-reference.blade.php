{{-- Customer portal reference: real layout patterns from the app (light UI, same structure as Livewire views) --}}
<div class="not-prose space-y-20 border-b border-slate-200 pb-20">
    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">{{ __('Product reference') }}</p>
    <p class="text-[16px] leading-7 text-slate-600">
        {{ __('The following blocks use the same structure, labels, and table headers as the live app. Use them to see what each area shows; the text under each block explains every column and how to use it in your weekly workflow.') }}
    </p>

    {{-- ═══ Dashboard ═══ --}}
    <section id="dashboard" class="scroll-mt-24">
        <h2 class="text-3xl font-semibold tracking-tight text-slate-900">{{ __('Dashboard') }}</h2>
        <p class="mt-3 text-[16px] leading-7 text-slate-600">
            {{ __('The first screen after you open a website. Top metrics summarize search demand and on-site traffic for the last 30 days, with a change label versus the previous 30 days. Below that, the action insight cards count open opportunities and link into Reports for the full row-level lists.') }}
        </p>

        <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                [__('Clicks (30d)'), '12,480', '+4.2%', 'text-blue-600', 'bg-blue-100'],
                [__('Impressions (30d)'), '284,900', '-1.1%', 'text-emerald-600', 'bg-emerald-100'],
                [__('Users (30d)'), '38,200', '+2.0%', 'text-orange-600', 'bg-orange-100'],
                [__('Sessions (30d)'), '44,100', '0.0%', 'text-amber-600', 'bg-amber-100'],
            ] as [$label, $val, $chg, $c, $bg])
                <div class="group flex min-h-[142px] flex-col rounded-xl border border-slate-200 bg-white p-5 transition hover:shadow-md">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $label }}</p>
                        <span class="{{ $bg }} flex h-9 w-9 items-center justify-center rounded-lg">
                            <span class="h-4 w-4 {{ $c }} inline-block rounded-sm"></span>
                        </span>
                    </div>
                    <p class="mt-3 text-3xl font-bold tabular-nums {{ $c }}">{{ $val }}</p>
                    <p class="mt-auto pt-2 text-xs">
                        <span class="font-semibold tabular-nums text-slate-700">{{ $chg }}</span>
                        <span class="text-slate-400">{{ __('vs previous 30d') }}</span>
                    </p>
                </div>
            @endforeach
        </div>
        <dl class="mt-4 grid gap-3 sm:grid-cols-2 text-[13px] leading-6 text-slate-700">
            <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                <dt class="font-semibold text-slate-900">{{ __('Clicks (30d)') }}</dt>
                <dd class="mt-1">{{ __('Clicks from search to your site in the selected country filter, rolling 30 days. Use the trend to confirm whether demand is moving in the right direction before you dig into pages.') }}</dd>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                <dt class="font-semibold text-slate-900">{{ __('Impressions (30d)') }}</dt>
                <dd class="mt-1">{{ __('How often your pages appeared in search for queries in scope. Rising impressions with flat clicks can mean opportunity (CTR work); falling impressions can mean coverage or demand issues.') }}</dd>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                <dt class="font-semibold text-slate-900">{{ __('Users (30d)') }}</dt>
                <dd class="mt-1">{{ __('Distinct visitors from your connected analytics, same window. Compare with clicks to see whether on-site experience and search demand move together.') }}</dd>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                <dt class="font-semibold text-slate-900">{{ __('Sessions (30d)') }}</dt>
                <dd class="mt-1">{{ __('Visit sessions in analytics. Spikes or drops here that do not match search clicks can point to direct, referral, or campaign effects.') }}</dd>
            </div>
        </dl>

        <div class="mt-8 rounded-xl border border-orange-200 bg-orange-50/60 px-4 py-2.5 text-xs text-orange-900">
            <span class="font-semibold">{{ __('Organic value hint') }}</span> — {{ __('when enough data is available, a banner may show a rough monthly equivalent of your organic traffic. It is a planning number for stakeholders, not a bill or guarantee.') }}
        </div>

        <div class="mt-8 grid gap-5 lg:grid-cols-3">
            <div class="lg:col-span-2 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 px-4 py-2.5">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ __('Traffic trend') }}</p>
                </div>
                <div class="flex h-40 items-end gap-0.5 px-4 pb-4 pt-6">
                    @foreach ([40, 52, 48, 61, 55, 70, 66, 72, 68, 75, 80, 78] as $h)
                        <div class="min-w-0 flex-1 rounded-t bg-orange-200/80" style="height: {{ $h }}%"></div>
                    @endforeach
                </div>
                <p class="border-t border-slate-100 px-4 py-2 text-[11px] text-slate-500">{{ __('Sessions and search clicks over time (chart in the app is interactive).') }}</p>
            </div>
            <div class="space-y-4">
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ __('Top countries') }}</p>
                    <p class="mt-2 text-[12px] text-slate-600">{{ __('Share of demand by country. Use the country control in the app to align the rest of the dashboard and reports with one market.') }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ __('Seasonal peaks') }}</p>
                    <p class="mt-2 text-[12px] text-slate-600">{{ __('Highlights upcoming seasonal demand for your topics so you can schedule content before the peak.') }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ __('Quick wins') }}</p>
                    <p class="mt-2 text-[12px] text-slate-600">{{ __('Short list of high-leverage changes detected from your data. Useful when you need one or two actions to ship this week.') }}</p>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══ Action insight cards (anchor: same row as in app) ═══ --}}
    <span id="insight-cards" class="block scroll-mt-24"></span>
    <section class="scroll-mt-24">
        <h2 class="text-3xl font-semibold tracking-tight text-slate-900">{{ __('Action insight cards') }}</h2>
        <p class="mt-3 text-[16px] leading-7 text-slate-600">
            {{ __('The row under the KPIs matches the “Action insights” strip in the app. Each card shows how many items fall into that category for the last 28 days (subject to your country filter). Click a card to open Reports → Insights on the matching tab.') }}
        </p>
        <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                [__('Cannibalizations'), '14', __('Queries split across pages'), 'amber'],
                [__('Striking distance'), '27', __('Pos 5–20 with low CTR'), 'orange'],
                [__('Index fails w/ traffic'), '3', __('Non-PASS, still earning impressions'), 'red'],
                [__('Content decay'), '8', __('Losing clicks 28d/28d'), 'slate'],
            ] as [$lbl, $val, $help, $tone])
                <div class="flex min-h-[142px] flex-col rounded-xl border border-slate-200 bg-white p-5">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $lbl }}</p>
                        <span @class([
                            'flex h-9 w-9 items-center justify-center rounded-lg',
                            'bg-amber-100' => $tone === 'amber',
                            'bg-orange-100' => $tone === 'orange',
                            'bg-red-100' => $tone === 'red',
                            'bg-slate-100' => $tone === 'slate',
                        ])></span>
                    </div>
                    <p @class([
                        'mt-3 text-3xl font-bold tabular-nums',
                        'text-amber-600' => $tone === 'amber',
                        'text-orange-600' => $tone === 'orange',
                        'text-red-600' => $tone === 'red',
                        'text-slate-700' => $tone === 'slate',
                    ])>{{ $val }}</p>
                    <p class="mt-auto pt-2 text-xs text-slate-400">{{ $help }}</p>
                </div>
            @endforeach
        </div>
        <ul class="mt-6 space-y-2 text-[14px] leading-7 text-slate-700">
            <li><span class="font-semibold text-slate-900">{{ __('Cannibalizations') }}</span> — {{ __('multiple URLs earn clicks for the same query; consolidate or clarify intent.') }}</li>
            <li><span class="font-semibold text-slate-900">{{ __('Striking distance') }}</span> — {{ __('queries where you rank mid-page with meaningful impressions; small page upgrades often move these fastest.') }}</li>
            <li><span class="font-semibold text-slate-900">{{ __('Index fails w/ traffic') }}</span> — {{ __('indexing status is not fully passing while impressions still occur; fix coverage or blocking issues.') }}</li>
            <li><span class="font-semibold text-slate-900">{{ __('Content decay') }}</span> — {{ __('clicks declined versus the prior 28-day window while the page still shows demand.') }}</li>
        </ul>
    </section>

    {{-- ═══ Keywords ═══ --}}
    <section id="keywords" class="scroll-mt-24">
        <h2 class="text-3xl font-semibold tracking-tight text-slate-900">{{ __('Keywords workspace') }}</h2>
        <p class="mt-3 text-[16px] leading-7 text-slate-600">
            {{ __('Search performance by query from your connected search property. Filter by device and date range, narrow to a country, and switch between aggregated totals and daily breakdown.') }}
        </p>
        <div class="mt-6 mb-4 flex flex-wrap items-center gap-2 text-[11px]">
            <span class="rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-slate-600">{{ __('Search keywords…') }}</span>
            <span class="rounded-md border border-slate-200 bg-white px-2 py-1">{{ __('All devices') }}</span>
            <span class="rounded-md border border-slate-200 bg-white px-2 py-1">{{ __('Country') }}</span>
            <span class="inline-flex rounded-md border border-slate-200 bg-slate-50 p-0.5">
                <span class="rounded bg-white px-2 py-0.5 text-xs font-semibold shadow-sm">{{ __('Aggregated') }}</span>
                <span class="rounded px-2 py-0.5 text-xs text-slate-500">{{ __('By Date') }}</span>
            </span>
        </div>
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-[11px] font-semibold text-slate-500">
                            <th class="px-4 py-2.5 text-start">{{ __('Keyword') }}</th>
                            <th class="px-4 py-2.5 text-end">{{ __('Clicks') }}</th>
                            <th class="px-4 py-2.5 text-end">{{ __('Impressions') }}</th>
                            <th class="px-4 py-2.5 text-end">{{ __('CTR') }}</th>
                            <th class="px-4 py-2.5 text-end">{{ __('Position') }}</th>
                            <th class="px-4 py-2.5 text-end">{{ __('Volume') }}</th>
                            <th class="px-4 py-2.5 text-end">{{ __('Value/mo') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr class="hover:bg-slate-50/60">
                            <td class="whitespace-nowrap px-4 py-2.5 font-medium text-orange-600">{{ __('best seo tools') }} <span class="rounded-full bg-orange-50 px-1.5 py-px text-[10px] font-semibold text-orange-700">{{ __('tracked') }}</span></td>
                            <td class="px-4 py-2.5 text-end tabular-nums">1,284</td>
                            <td class="px-4 py-2.5 text-end tabular-nums">21,400</td>
                            <td class="px-4 py-2.5 text-end tabular-nums">6.0%</td>
                            <td class="px-4 py-2.5 text-end"><span class="inline-flex rounded-full bg-emerald-50 px-1.5 py-px text-[10px] font-semibold text-emerald-700">6.4</span></td>
                            <td class="px-4 py-2.5 text-end tabular-nums">49,500</td>
                            <td class="px-4 py-2.5 text-end font-semibold">$3,200</td>
                        </tr>
                        <tr class="hover:bg-slate-50/60">
                            <td class="whitespace-nowrap px-4 py-2.5 font-medium text-orange-600">{{ __('saas seo guide') }}</td>
                            <td class="px-4 py-2.5 text-end tabular-nums">218</td>
                            <td class="px-4 py-2.5 text-end tabular-nums">5,100</td>
                            <td class="px-4 py-2.5 text-end tabular-nums">4.3%</td>
                            <td class="px-4 py-2.5 text-end"><span class="inline-flex rounded-full bg-amber-50 px-1.5 py-px text-[10px] font-semibold text-amber-700">14.2</span></td>
                            <td class="px-4 py-2.5 text-end text-slate-400">—</td>
                            <td class="px-4 py-2.5 text-end text-slate-400">—</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <dl class="mt-6 grid gap-3 text-[13px] leading-6 text-slate-700">
            <div class="rounded-xl border border-slate-200 p-4"><dt class="font-semibold text-slate-900">{{ __('Keyword') }}</dt><dd class="mt-1">{{ __('The query text. Badges can show') }} <em>{{ __('cannibalized') }}</em> {{ __('(multiple URLs earning clicks) or') }} <em>{{ __('tracked') }}</em> {{ __('(also in Rank tracking). Click through to the keyword detail page.') }}</dd></div>
            <div class="rounded-xl border border-slate-200 p-4"><dt class="font-semibold text-slate-900">{{ __('Clicks / Impressions / CTR') }}</dt><dd class="mt-1">{{ __('Demand and engagement for that query in your filters. CTR highlights snippet appeal versus peers on the same SERP.') }}</dd></div>
            <div class="rounded-xl border border-slate-200 p-4"><dt class="font-semibold text-slate-900">{{ __('Position') }}</dt><dd class="mt-1">{{ __('Average rank where your URL appeared. Color bands mirror the app (top 3, page one, striking distance, deeper).') }}</dd></div>
            <div class="rounded-xl border border-slate-200 p-4"><dt class="font-semibold text-slate-900">{{ __('Volume') }}</dt><dd class="mt-1">{{ __('Estimated monthly search volume for planning; optional trend hints may appear when data refreshes.') }}</dd></div>
            <div class="rounded-xl border border-slate-200 p-4"><dt class="font-semibold text-slate-900">{{ __('Value/mo') }}</dt><dd class="mt-1">{{ __('A directional dollar estimate of organic value for that keyword at your current visibility—useful for prioritization, not accounting.') }}</dd></div>
        </dl>
    </section>

    {{-- ═══ Pages ═══ --}}
    <section id="pages" class="scroll-mt-24">
        <h2 class="text-3xl font-semibold tracking-tight text-slate-900">{{ __('Pages workspace') }}</h2>
        <p class="mt-3 text-[16px] leading-7 text-slate-600">
            {{ __('URL-level performance from search data plus indexing checks. Use the optional filter to focus on URLs that earn impressions while indexing status looks problematic.') }}
        </p>
        <div class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-[11px] font-semibold text-slate-500">
                            <th class="px-4 py-2.5 text-start">{{ __('Page') }}</th>
                            <th class="px-4 py-2.5 text-start">{{ __('Market') }}</th>
                            <th class="px-4 py-2.5 text-end">{{ __('Clicks') }}</th>
                            <th class="px-4 py-2.5 text-end">{{ __('Impressions') }}</th>
                            <th class="px-4 py-2.5 text-end">{{ __('Avg CTR') }}</th>
                            <th class="px-4 py-2.5 text-end">{{ __('Avg Position') }}</th>
                            <th class="px-4 py-2.5 text-end">{{ __('Google Indexing Status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr class="hover:bg-slate-50/60">
                            <td class="max-w-sm truncate px-4 py-2.5 font-medium text-orange-600">/blog/saas-seo-guide</td>
                            <td class="px-4 py-2.5 text-[11px] text-slate-600">en-US</td>
                            <td class="px-4 py-2.5 text-end tabular-nums">842</td>
                            <td class="px-4 py-2.5 text-end tabular-nums">18,200</td>
                            <td class="px-4 py-2.5 text-end tabular-nums">4.6%</td>
                            <td class="px-4 py-2.5 text-end"><span class="inline-flex rounded-full bg-blue-50 px-1.5 py-px text-[10px] font-semibold text-blue-700">8.1</span></td>
                            <td class="px-4 py-2.5 text-end">
                                <div class="text-xs font-semibold">PASS</div>
                                <div class="text-[11px] text-slate-500">May 1, 2026 9:12 AM</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <dl class="mt-6 grid gap-3 text-[13px] leading-6 text-slate-700">
            <div class="rounded-xl border border-slate-200 p-4"><dt class="font-semibold text-slate-900">{{ __('Page') }}</dt><dd class="mt-1">{{ __('Canonical URL path. Open it for the full page detail view.') }}</dd></div>
            <div class="rounded-xl border border-slate-200 p-4"><dt class="font-semibold text-slate-900">{{ __('Market') }}</dt><dd class="mt-1">{{ __('Detected locale context for the URL where available—helpful when the same path serves multiple languages.') }}</dd></div>
            <div class="rounded-xl border border-slate-200 p-4"><dt class="font-semibold text-slate-900">{{ __('Clicks / Impressions / Avg CTR / Avg Position') }}</dt><dd class="mt-1">{{ __('Aggregates across queries for that URL in the configured lookback window (aligned with Settings → Reports).') }}</dd></div>
            <div class="rounded-xl border border-slate-200 p-4"><dt class="font-semibold text-slate-900">{{ __('Google Indexing Status') }}</dt><dd class="mt-1">{{ __('Latest verdict from indexing checks, time of check, and crawl timestamp when present. Use this to prioritize URLs that earn demand but are not in a healthy state.') }}</dd></div>
        </dl>
    </section>

    {{-- ═══ Rank tracking ═══ --}}
    <section id="rank-tracking" class="scroll-mt-24">
        <h2 class="text-3xl font-semibold tracking-tight text-slate-900">{{ __('Rank tracking') }}</h2>
        <p class="mt-3 text-[16px] leading-7 text-slate-600">
            {{ __('Keywords you chose for scheduled SERP checks, with your target URL, market, and overlay of search clicks where the query matches your property data.') }}
        </p>
        <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            @foreach ([[__('Tracked'), '42', __('40 active')], [__('Avg position'), '#11', __('across ranked')], [__('Top 3'), '6', __('positions 1–3')], [__('Top 10'), '18', __('first page')], [__('Ranked'), '36', __('found in SERP')], [__('Unranked'), '6', __('outside tracked depth')]] as [$a, $b, $c])
                <div class="rounded-xl border border-slate-200 bg-white p-3.5">
                    <div class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ $a }}</div>
                    <div class="mt-1 text-xl font-bold tabular-nums text-slate-900">{{ $b }}</div>
                    <div class="mt-0.5 text-[10px] text-slate-400">{{ $c }}</div>
                </div>
            @endforeach
        </div>
        <div class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[960px] text-xs">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-[10px] font-semibold uppercase tracking-wider text-slate-500">
                            <th class="px-4 py-3 text-start">{{ __('Keyword') }}</th>
                            <th class="px-4 py-3 text-start">{{ __('Target') }}</th>
                            <th class="px-4 py-3 text-start">{{ __('Market') }}</th>
                            <th class="px-4 py-3 text-end">{{ __('Rank') }}</th>
                            <th class="px-4 py-3 text-end">Δ</th>
                            <th class="px-4 py-3 text-end">{{ __('Best') }}</th>
                            <th class="px-4 py-3 text-start">{{ __('GSC (30d)') }}</th>
                            <th class="px-4 py-3 text-start">{{ __('Volume') }}</th>
                            <th class="px-4 py-3 text-end">{{ __('Value/mo') }}</th>
                            <th class="px-4 py-3 text-start">{{ __('Last check') }}</th>
                            <th class="px-4 py-3 text-end">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr>
                            <td class="px-4 py-3">
                                <span class="font-semibold text-slate-900">{{ __('best seo tools') }}</span>
                                <div class="mt-0.5 flex flex-wrap gap-1">
                                    <span class="rounded bg-slate-100 px-1.5 py-px text-[9px] font-semibold uppercase text-slate-600">{{ __('organic') }}</span>
                                    <span class="rounded bg-amber-100 px-1.5 py-px text-[9px] font-semibold uppercase text-amber-700">{{ __('SERP risk') }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-slate-700">
                                <div class="font-medium">example.com</div>
                                <div class="max-w-[200px] truncate text-[10px] text-emerald-700">https://example.com/tools</div>
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                <span class="rounded bg-slate-100 px-1.5 py-px text-[10px] font-semibold">US</span>
                                <span class="text-[10px]">en · mobile</span>
                            </td>
                            <td class="px-4 py-3 text-end"><span class="inline-flex min-w-[44px] justify-center rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-bold text-emerald-700">#2</span></td>
                            <td class="px-4 py-3 text-end text-emerald-600">▲2</td>
                            <td class="px-4 py-3 text-end tabular-nums">#2</td>
                            <td class="px-4 py-3 text-[11px] text-slate-600">
                                <span class="rounded bg-emerald-50 px-1.5 py-0.5 text-[9px] font-semibold text-emerald-700">{{ __('GSC') }}</span>
                                1,284 clicks · avg #6 · 21k impr
                            </td>
                            <td class="px-4 py-3 text-[11px]">49,500/mo <span class="text-emerald-600">↑</span></td>
                            <td class="px-4 py-3 text-end font-semibold">$3,200</td>
                            <td class="px-4 py-3 text-slate-600">2 hours ago</td>
                            <td class="px-4 py-3 text-end text-orange-600">···</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <dl class="mt-6 grid gap-3 text-[13px] leading-6 text-slate-700">
            <div class="rounded-xl border border-slate-200 p-4"><dt class="font-semibold text-slate-900">{{ __('Keyword') }}</dt><dd class="mt-1">{{ __('Tracked phrase and search type. Tags can show Paused, Failed, SERP risk, lost feature, or your custom tags.') }}</dd></div>
            <div class="rounded-xl border border-slate-200 p-4"><dt class="font-semibold text-slate-900">{{ __('Target') }}</dt><dd class="mt-1">{{ __('Domain you track and the URL we last matched in the SERP—click out to verify the live result.') }}</dd></div>
            <div class="rounded-xl border border-slate-200 p-4"><dt class="font-semibold text-slate-900">{{ __('Market') }}</dt><dd class="mt-1">{{ __('Country, language, device, optional city—must match how you want to measure “position.”') }}</dd></div>
            <div class="rounded-xl border border-slate-200 p-4"><dt class="font-semibold text-slate-900">{{ __('Rank / Δ / Best') }}</dt><dd class="mt-1">{{ __('Latest position, movement since the prior check, and best position seen in the retention window.') }}</dd></div>
            <div class="rounded-xl border border-slate-200 p-4"><dt class="font-semibold text-slate-900">{{ __('GSC (30d)') }}</dt><dd class="mt-1">{{ __('When the query matches your property, we show clicks, average position, and impressions so rank changes always sit next to real traffic.') }}</dd></div>
            <div class="rounded-xl border border-slate-200 p-4"><dt class="font-semibold text-slate-900">{{ __('Volume / Value/mo') }}</dt><dd class="mt-1">{{ __('Planning volume and a directional value estimate at your current rank—use for prioritization.') }}</dd></div>
            <div class="rounded-xl border border-slate-200 p-4"><dt class="font-semibold text-slate-900">{{ __('Last check / Actions') }}</dt><dd class="mt-1">{{ __('Recency of the SERP capture and next scheduled run; actions include detail history, re-check, and edit targeting.') }}</dd></div>
        </dl>
    </section>

    {{-- ═══ Custom audit ═══ --}}
    <section id="custom-audit" class="scroll-mt-24">
        <h2 class="text-3xl font-semibold tracking-tight text-slate-900">{{ __('Custom page audit') }}</h2>
        <p class="mt-3 text-[16px] leading-7 text-slate-600">
            {{ __('Run a technical and content review for any URL on the selected site. You set the benchmark keyword for SERP and content sections—the flow does not guess it from search data.') }}
        </p>
        <div class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4">
                <p class="text-[11px] font-medium text-slate-500">{{ __('← Back to pages') }}</p>
                <p class="mt-2 text-xl font-bold text-slate-900">{{ __('Custom page audit') }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ __('Run an audit for any URL on the selected site and set the SERP benchmark keyword yourself.') }}</p>
            </div>
            <div class="border-b border-slate-100 px-5 py-3">
                <h2 class="text-sm font-bold text-slate-900">{{ __('Run audit') }}</h2>
                <p class="text-[11px] text-slate-500">{{ __('URLs must stay on the current site’s domain.') }}</p>
            </div>
            <div class="space-y-4 p-5">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-700">{{ __('Page URL') }}</label>
                    <div class="mt-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-400">https://example.com/pricing</div>
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-700">{{ __('SERP benchmark keyword') }}</label>
                    <div class="mt-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm">{{ __('pricing software') }}</div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══ Audit report sections ═══ --}}
    <span id="audit-report-sections" class="block scroll-mt-24"></span>
    <section class="scroll-mt-24">
        <h2 class="text-3xl font-semibold tracking-tight text-slate-900">{{ __('Page audit report layout') }}</h2>
        <p class="mt-3 text-[16px] leading-7 text-slate-600">
            {{ __('After a run completes, the audit detail view stacks the same sections in a fixed order. The miniature below mirrors the score strip and recommendation list styling from the app.') }}
        </p>
        <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-4">
                <div>
                    <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">{{ __('Audit') }} · /pricing</p>
                    <p class="text-sm font-semibold text-slate-900">{{ __('Mobile · Score 78 · Target: "pricing software"') }}</p>
                </div>
                <span class="rounded-md bg-emerald-50 px-2 py-1 text-[11px] font-semibold text-emerald-700 ring-1 ring-emerald-100">{{ __('Good') }}</span>
            </div>
            <div class="mt-4 grid grid-cols-3 gap-2 sm:grid-cols-6">
                @foreach ([['LCP', '2.4s'], ['CLS', '0.02'], ['INP', '140ms'], ['TBT', '280ms'], ['FCP', '1.4s'], ['TTFB', '520ms']] as [$x, $y])
                    <div class="rounded-lg border border-slate-200 p-2.5">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ $x }}</p>
                        <p class="mt-1 text-sm font-semibold tabular-nums text-slate-900">{{ $y }}</p>
                    </div>
                @endforeach
            </div>
            <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ __('Prioritized fixes') }}</p>
                <ul class="mt-2 space-y-1.5 text-[12px] text-slate-700">
                    <li>• {{ __('Compress hero image stack') }}</li>
                    <li>• {{ __('Add FAQ schema for benchmark keyword') }}</li>
                </ul>
            </div>
        </div>
        <ul class="mt-6 list-disc space-y-2 ps-5 text-[14px] leading-7 text-slate-700">
            <li><strong>{{ __('Summary') }}</strong> — {{ __('overall score, benchmark keyword, and device.') }}</li>
            <li><strong>{{ __('Core Web Vitals & lab timings') }}</strong> — {{ __('field and lab signals with thresholds.') }}</li>
            <li><strong>{{ __('On-page SEO') }}</strong> — {{ __('titles, meta, headings, canonical, schema, media, internal links.') }}</li>
            <li><strong>{{ __('SERP snapshot') }}</strong> — {{ __('competitors and readability context for the benchmark term.') }}</li>
            <li><strong>{{ __('Recommendations') }}</strong> — {{ __('ordered tasks; wire through to re-audit and URL refresh flows from the live screen.') }}</li>
        </ul>
    </section>

    {{-- ═══ Reports → Insights ═══ --}}
    <section id="insights-panel" class="scroll-mt-24">
        <h2 class="text-3xl font-semibold tracking-tight text-slate-900">{{ __('Reports → Insights') }}</h2>
        <p class="mt-3 text-[16px] leading-7 text-slate-600">
            {{ __('Seven categories cover cannibalization, striking distance, indexing issues, decay, quick wins, technical debt versus demand, and backlink outcomes. Each tab shows the same kind of table you use for sprint planning.') }}
        </p>
        <div class="mt-6 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Action lists · last 28 days') }}</p>
            <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7">
                @foreach ([
                    [__('Cannibalizations'), '14', 'amber'],
                    [__('Striking distance'), '27', 'orange'],
                    [__('Index fails w/ traffic'), '3', 'red'],
                    [__('Content decay'), '8', 'slate'],
                    [__('Quick wins'), __('View'), 'emerald'],
                    [__('Audit vs traffic'), __('View'), 'rose'],
                    [__('Backlink impact'), __('View'), 'emerald'],
                ] as [$lab, $num, $tone])
                    <div @class([
                        'flex flex-col rounded-lg border px-3 py-2.5 text-start',
                        'border-orange-300 bg-orange-50' => $lab === __('Cannibalizations'),
                        'border-slate-200 bg-white' => $lab !== __('Cannibalizations'),
                    ])>
                        <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ $lab }}</span>
                        <span @class([
                            'mt-1 text-xl font-bold tabular-nums',
                            'text-amber-600' => $tone === 'amber',
                            'text-orange-600' => $tone === 'orange',
                            'text-red-600' => $tone === 'red',
                            'text-slate-700' => $tone === 'slate',
                            'text-rose-600' => $tone === 'rose',
                            'text-emerald-600' => $tone === 'emerald',
                        ])>{{ $num }}</span>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="mt-8 space-y-8 text-[13px] leading-6 text-slate-700">
            <div>
                <h3 class="text-lg font-semibold text-slate-900">{{ __('Cannibalizations') }}</h3>
                <p class="mt-2">{{ __('Columns: Query, Primary page, Pages (count), Clicks, Impr., Volume/mo (monthly search volume), Competing pages with click share. Use this to pick a keeper URL and merge or redirect the rest.') }}</p>
            </div>
            <div>
                <h3 class="text-lg font-semibold text-slate-900">{{ __('Striking distance') }}</h3>
                <p class="mt-2">{{ __('Columns: Query, Volume/mo, Position, Impressions, Clicks, CTR, Upside/mo. Prioritize rows with strong impressions and realistic rank movement.') }}</p>
            </div>
            <div>
                <h3 class="text-lg font-semibold text-slate-900">{{ __('Index fails w/ traffic') }}</h3>
                <p class="mt-2">{{ __('Columns: Page, Verdict, Coverage, Clicks (14d), Impr. (14d), Last crawl. Fix blocking or quality issues while demand is still visible.') }}</p>
            </div>
            <div>
                <h3 class="text-lg font-semibold text-slate-900">{{ __('Content decay') }}</h3>
                <p class="mt-2">{{ __('Columns: Page (may tag market-wide demand shrink), Clicks (28d), Prev 28d, Δ 28d, YoY when history exists, Verdict. Distinguishes ranking decay from indexing problems.') }}</p>
            </div>
            <div>
                <h3 class="text-lg font-semibold text-slate-900">{{ __('Quick wins') }}</h3>
                <p class="mt-2">{{ __('Columns: Keyword, Volume/mo, Comp., Current pos, Upside/mo, Action (deep-links into an audit). Use for net-new topics or weak positions with strong volume.') }}</p>
            </div>
            <div>
                <h3 class="text-lg font-semibold text-slate-900">{{ __('Audit vs traffic') }}</h3>
                <p class="mt-2">{{ __('Columns: Page, Mobile/Desktop scores, LCP/CLS (mobile), Impr. (28d), Clicks (28d). Surfaces URLs where experience scores are poor but demand is high.') }}</p>
            </div>
            <div>
                <h3 class="text-lg font-semibold text-slate-900">{{ __('Backlink impact') }}</h3>
                <p class="mt-2">{{ __('Columns: Target page, Links, Avg DA, Latest link, Pre clicks, Post clicks, Δ. Highlights targets where tracked links coincide with click lift.') }}</p>
            </div>
        </div>
    </section>

    {{-- ═══ Growth reports (email) ═══ --}}
    <section id="growth-reports" class="scroll-mt-24">
        <h2 class="text-3xl font-semibold tracking-tight text-slate-900">{{ __('Custom growth reports (email)') }}</h2>
        <p class="mt-3 text-[16px] leading-7 text-slate-600">
            {{ __('Scheduled emails for stakeholders: pick recipients, cadence, time zone, and which sections to include. Anomaly notifications can share the same recipient list when enabled.') }}
        </p>
        <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">{{ __('Reports · schedule') }}</p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ __('Recipients') }}</p>
                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                        <span class="rounded-md bg-slate-100 px-2 py-0.5 text-slate-700">you@company.com</span>
                        <span class="rounded-md border border-dashed border-slate-300 px-2 py-0.5 text-slate-500">{{ __('+ add') }}</span>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ __('Cadence') }}</p>
                        <div class="mt-1.5 rounded-lg border border-slate-200 bg-slate-50/60 px-3 py-2 text-slate-700">{{ __('Weekly · Monday') }}</div>
                    </div>
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ __('Send time') }}</p>
                        <div class="mt-1.5 rounded-lg border border-slate-200 bg-slate-50/60 px-3 py-2 text-slate-700">{{ __('09:00 (site)') }}</div>
                    </div>
                </div>
            </div>
            <p class="mt-4 text-[12px] text-slate-600">{{ __('Toggle sections so the email only contains blocks your team acts on—KPIs, insights highlights, rank movers, or backlinks, depending on what you configured in the live builder.') }}</p>
        </div>
    </section>

    {{-- ═══ WordPress plugin ═══ --}}
    <span id="wordpress" class="block scroll-mt-24"></span>
    <span id="wordpress-plugin" class="block scroll-mt-24"></span>
    <section class="scroll-mt-24 border-t border-slate-200 pt-20">
        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">{{ __('CMS plugin') }}</p>
        <h2 class="mt-3 text-3xl font-semibold tracking-tight text-slate-900">{{ __('WordPress plugin surfaces') }}</h2>
        <p class="mt-3 text-[16px] leading-7 text-slate-600">
            {{ __('The Serfix SEO plugin brings your workspace into the WordPress admin—same numbers and actions as the web app, sized for editors and site managers working inside the CMS.') }}
        </p>

        <h3 class="mt-10 text-xl font-semibold text-slate-900">{{ __('Serfix Head Quarter') }}</h3>
        <p class="mt-2 text-[14px] leading-7 text-slate-600">
            {{ __('Main admin screen for workspace stats (typically visible to users who can manage site settings). Data matches what you see in Serfix in the browser. Use the horizontal sections to switch views:') }}
        </p>
        <div class="mt-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 pb-4">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-slate-900 text-sm font-bold text-white">E</span>
                    <div>
                        <p class="text-sm font-bold text-slate-900">{{ __('Serfix Head Quarter') }}</p>
                        <p class="text-[11px] text-slate-500"><strong>example.com</strong> · {{ __('Connected workspace') }}</p>
                    </div>
                </div>
                <span class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-[11px] font-semibold text-slate-700">{{ __('Open workspace ↗') }}</span>
            </div>
            <div class="mt-3 flex flex-wrap gap-2 text-[11px] font-semibold">
                @foreach ([
                    __('Overview'),
                    __('SEO Performance'),
                    __('Keywords'),
                    __('Rank Tracker'),
                    __('Pages'),
                    __('Index Status'),
                    __('Insights'),
                    __('Redirects (AI)'),
                    __('SERP Features'),
                    __('Benchmarks'),
                    __('Prospects'),
                    __('Topical Authority'),
                ] as $tab)
                    <span @class([
                        'rounded-lg border px-2.5 py-1.5',
                        'border-orange-400 bg-orange-50 text-orange-900' => $tab === __('Overview'),
                        'border-slate-200 bg-slate-50 text-slate-700' => $tab !== __('Overview'),
                    ])>{{ $tab }}</span>
                @endforeach
            </div>
            <p class="mt-4 text-[12px] leading-5 text-slate-600">
                <strong>{{ __('Overview') }}</strong> — {{ __('snapshot KPIs.') }} <strong>{{ __('SEO Performance') }}</strong> — {{ __('trend-style charts.') }} <strong>{{ __('Keywords') }}</strong> — {{ __('query table from synced search data.') }} <strong>{{ __('Rank Tracker') }}</strong> — {{ __('tracked keywords (toolbar shortcut and post row action can open the add-keyword flow here).') }} <strong>{{ __('Pages') }}</strong> / <strong>{{ __('Index Status') }}</strong> — {{ __('URL coverage and indexing health.') }} <strong>{{ __('Insights') }}</strong> — {{ __('same categories as workspace insights.') }} <strong>{{ __('Redirects (AI)') }}</strong>, <strong>{{ __('SERP Features') }}</strong>, <strong>{{ __('Benchmarks') }}</strong>, <strong>{{ __('Prospects') }}</strong>, {{ __('and') }} <strong>{{ __('Topical Authority') }}</strong> {{ __('extend planning when enabled for your site.') }}
            </p>
        </div>

        <h3 class="mt-10 text-xl font-semibold text-slate-900">{{ __('AI Writer') }}</h3>
        <p class="mt-2 text-[14px] leading-7 text-slate-600">
            {{ __('When your workspace includes') }} <strong>{{ __('AI Writer') }}</strong>, {{ __('a separate admin menu opens the long-form drafting experience so heavy writing work does not crowd the Head Quarter tabs.') }}
        </p>

        <h3 class="mt-10 text-xl font-semibold text-slate-900">{{ __('Settings (under Head Quarter)') }}</h3>
        <p class="mt-2 text-[14px] leading-7 text-slate-600">
            {{ __('Connect or disconnect your site to Serfix with a guided step—no secrets to paste—clear cached responses, adjust title separators, run optional migrations from prior SEO setups, and view diagnostics. Once connected, Head Quarter, the dashboard widget, posts list column, and editor panel all draw from your workspace.') }}
        </p>

        <h3 class="mt-10 text-xl font-semibold text-slate-900">{{ __('Block editor · Serfix SEO panel') }}</h3>
        <p class="mt-2 text-[14px] leading-7 text-slate-600">
            {{ __('Open') }} <strong>{{ __('Serfix SEO') }}</strong> {{ __('from the editor’s plugin sidebar list. The same panels also appear in the classic editor metabox area so you keep context whether the sidebar is pinned or not. Your workspace can turn individual tools on or off for this site—unused surfaces simply stay hidden.') }}
        </p>
        <div class="mt-4 rounded-xl border border-slate-200 bg-white p-4 text-[12px] shadow-sm">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ __('Editor · Serfix SEO') }}</p>
            <p class="mt-2 text-slate-600">{{ __('Focus keyphrase, on-page scores, schema and social tabs, and a search-performance snapshot for the URL—aligned with what your workspace allows for this property.') }}</p>
        </div>

        <h3 class="mt-10 text-xl font-semibold text-slate-900">{{ __('Posts / Pages list · Serfix column') }}</h3>
        <p class="mt-2 text-[14px] leading-7 text-slate-600">
            {{ __('When enabled, a sortable') }} <strong>{{ __('Serfix') }}</strong> {{ __('column appears on supported post types (posts and pages by default). You always see on-page SEO and readability pills plus schema-type chips from your editor settings; after the site is linked to Serfix, rank and performance lines load in the background for all visible rows so long lists stay responsive.') }}
        </p>
        <div class="mt-4 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full text-xs">
                <thead class="border-b border-slate-200 bg-slate-50 text-[10px] font-semibold uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-4 py-2 text-start">{{ __('Title') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Author') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Serfix') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Date') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr>
                        <td class="px-4 py-3 font-medium text-slate-900">{{ __('Product launch recap') }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ __('Alex') }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-col gap-2">
                                <div class="flex flex-wrap gap-1.5">
                                    <span class="inline-flex items-center gap-0.5 rounded border border-emerald-200 bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-800" title="{{ __('On-page SEO score') }}"><span class="tabular-nums">72</span><span class="text-[9px] opacity-80">{{ __('SEO') }}</span><span class="text-[9px]">{{ __('Good') }}</span></span>
                                    <span class="inline-flex items-center gap-0.5 rounded border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-900" title="{{ __('Readability') }}"><span class="tabular-nums">58</span><span class="text-[9px] opacity-80">{{ __('Read.') }}</span><span class="text-[9px]">{{ __('Needs work') }}</span></span>
                                </div>
                                <div class="flex flex-wrap gap-1">
                                    <span class="rounded bg-slate-100 px-1.5 py-px text-[9px] font-medium text-slate-600">Article</span>
                                    <span class="rounded bg-slate-100 px-1.5 py-px text-[9px] font-medium text-slate-600">FAQPage</span>
                                </div>
                                <div class="rounded border border-dashed border-slate-200 bg-slate-50 px-2 py-1.5 text-[10px] text-slate-400">{{ __('Hydrated rank · clicks · impressions…') }}</div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-slate-500">{{ __('Published') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <dl class="mt-4 grid gap-3 text-[13px] leading-6 text-slate-700">
            <div class="rounded-xl border border-slate-200 p-4"><dt class="font-semibold text-slate-900">{{ __('SEO / Read. pills') }}</dt><dd class="mt-1">{{ __('Scores from the editor analysis—open the post once so the analyzer can store them. Labels map to Good / Needs work / Bad bands.') }}</dd></div>
            <div class="rounded-xl border border-slate-200 p-4"><dt class="font-semibold text-slate-900">{{ __('Schema chips') }}</dt><dd class="mt-1">{{ __('Shows which structured-data types are enabled for the URL in the Schema tab.') }}</dd></div>
            <div class="rounded-xl border border-slate-200 p-4"><dt class="font-semibold text-slate-900">{{ __('Rank & performance strip') }}</dt><dd class="mt-1">{{ __('After background sync, shows rank and search-performance context for that URL when your site is connected.') }}</dd></div>
            <div class="rounded-xl border border-slate-200 p-4"><dt class="font-semibold text-slate-900">{{ __('Row action · Track keyphrase') }}</dt><dd class="mt-1">{{ __('When a focus keyphrase is saved, an extra action can send it to Rank Tracker without leaving the list; if the quick action is unavailable, use Head Quarter instead.') }}</dd></div>
        </dl>

        <h3 class="mt-10 text-xl font-semibold text-slate-900">{{ __('Dashboard widget · Serfix SEO insights') }}</h3>
        <p class="mt-2 text-[14px] leading-7 text-slate-600">
            {{ __('When enabled, appears on the main WordPress dashboard after login: four insight counts that mirror your workspace priorities, plus a shortcut to open full reports.') }}
        </p>
        <div class="mt-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="mb-3 flex flex-wrap items-center gap-2 text-[11px] text-slate-600">
                <span class="rounded-md bg-slate-900 px-2 py-0.5 text-[10px] font-bold text-white">{{ __('Serfix') }}</span>
                <span>example.com</span>
            </div>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                @foreach ([
                    [__('Cannibalizations'), '14', __('Pages competing for the same query')],
                    [__('Striking distance'), '27', __('Queries on positions 5–20')],
                    [__('Index fails + traffic'), '3', __('Indexed: false, but still visible')],
                    [__('Content decay'), '8', __('Pages losing organic clicks')],
                ] as [$t, $n, $h])
                    <div class="rounded-lg border border-slate-200 p-3">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ $t }}</p>
                        <p class="mt-1 text-xl font-bold tabular-nums text-slate-900">{{ $n }}</p>
                        <p class="mt-0.5 text-[10px] leading-snug text-slate-500">{{ $h }}</p>
                    </div>
                @endforeach
            </div>
            <p class="mt-3 text-[11px] font-semibold text-orange-700">{{ __('Open full Serfix reports →') }}</p>
        </div>

        <h3 class="mt-10 text-xl font-semibold text-slate-900">{{ __('Toolbar shortcut') }}</h3>
        <p class="mt-2 text-[14px] leading-7 text-slate-600">
            {{ __('Editors see') }} <strong>{{ __('Track keyword') }}</strong> {{ __('in the top toolbar on both the public site and wp-admin—it jumps to Rank Tracker with the add-keyword flow so new terms can be queued from anywhere.') }}
        </p>

        <h3 class="mt-10 text-xl font-semibold text-slate-900">{{ __('Feature availability') }}</h3>
        <p class="mt-2 text-[14px] leading-7 text-slate-600">
            {{ __('Workspace administrators can disable individual value-add features (HQ, dashboard widget, post column, chatbot, AI surfaces, live audit, redirects, etc.) without turning off core SEO output—sitemaps, meta tags, schema, and breadcrumbs stay active so discoverability is not silently removed.') }}
        </p>
    </section>
</div>
