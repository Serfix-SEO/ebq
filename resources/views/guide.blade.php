<x-marketing.page
    title="Guide — Serfix"
    description="Customer documentation for the Serfix portal: dashboards, keywords, pages, rank tracking, audits, reports, alerts, and optional CMS integration—what each screen shows and how to use it."
    active="guide"
>
    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section class="border-b border-slate-200 bg-white">
        <div class="mx-auto max-w-3xl px-6 py-16 lg:px-8 lg:py-20">
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">User guide · full reference</p>
            <h1 class="mt-4 text-balance text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl">
                The complete Serfix guide.
            </h1>
            <p class="mt-5 text-balance text-[17px] leading-8 text-slate-600">
                Start with the product reference for dashboard, keywords, pages, rank tracking, audits, and reports—real UI layouts with every column explained—then follow the numbered setup path for integrations, tracking, and publishing workflows.
            </p>
            <div class="mt-8 flex flex-col items-center gap-3 sm:flex-row">
                <a href="#step-1" class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">Start with step 1</a>
                <a href="{{ route('register') }}" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-900">Create a free account</a>
            </div>
        </div>
    </section>

    {{-- ── Two-column body: TOC + content ───────────────────── --}}
    <section class="bg-white">
        <div class="mx-auto grid max-w-6xl gap-12 px-6 py-16 lg:grid-cols-[16rem_minmax(0,1fr)] lg:gap-16 lg:px-8 lg:py-20">

            {{-- Sticky TOC --}}
            <aside class="lg:sticky lg:top-24 lg:self-start">
                <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">In this guide</p>
                <nav aria-label="Guide sections" class="mt-3 flex flex-col gap-1 text-sm">
                    @php
                        $tocWorkspace = [
                            ['#dashboard', 'Dashboard overview'],
                            ['#insight-cards', 'Action insights cards'],
                            ['#keywords', 'Keywords workspace'],
                            ['#pages', 'Pages workspace'],
                            ['#rank-tracking', 'Rank tracking'],
                            ['#custom-audit', 'Custom page audit'],
                            ['#audit-report-sections', 'Page audit report sections'],
                            ['#insights-panel', 'Reports insights panel'],
                            ['#growth-reports', 'Custom growth reports'],
                            ['#wordpress-plugin', 'WordPress plugin'],
                        ];
                        $tocSetup = [
                            ['#step-1', '01', 'Add your first website'],
                            ['#step-2', '02', 'Connect search and analytics'],
                            ['#step-3', '03', 'Track keywords + competitors'],
                            ['#step-4', '04', 'Run a page audit'],
                            ['#step-5', '05', 'Import or track backlinks'],
                            ['#step-6', '06', 'Review the insight boards'],
                            ['#step-7', '07', 'Schedule reports + alerts'],
                            ['#step-8', '08', 'Install the WordPress plugin'],
                        ];
                        $tocReference = [
                            ['#metric-glossary', 'Metric glossary'],
                            ['#troubleshooting', 'Troubleshooting'],
                            ['#faq', 'FAQ'],
                            ['#weekly-rhythm', 'A weekly rhythm'],
                        ];
                    @endphp
                    @foreach ($tocWorkspace as [$href, $label])
                        <a href="{{ $href }}" class="flex items-center gap-3 rounded-lg px-2 py-1.5 text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">
                            <span class="font-mono text-[11px] text-slate-400">→</span>
                            <span>{{ $label }}</span>
                        </a>
                    @endforeach
                    <div class="my-3 h-px bg-slate-200"></div>
                    @foreach ($tocSetup as [$href, $n, $label])
                        <a href="{{ $href }}" class="flex items-center gap-3 rounded-lg px-2 py-1.5 text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">
                            <span class="font-mono text-[11px] text-slate-400">{{ $n }}</span>
                            <span>{{ $label }}</span>
                        </a>
                    @endforeach
                    <div class="my-3 h-px bg-slate-200"></div>
                    @foreach ($tocReference as [$href, $label])
                        <a href="{{ $href }}" class="flex items-center gap-3 rounded-lg px-2 py-1.5 text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">
                            <span class="font-mono text-[11px] text-slate-400">·</span>
                            <span>{{ $label }}</span>
                        </a>
                    @endforeach
                </nav>
            </aside>

            {{-- Main content --}}
            <div class="prose prose-slate max-w-none prose-headings:tracking-tight prose-h2:text-3xl prose-h2:font-semibold prose-h2:mt-0 prose-h3:text-lg prose-h3:font-semibold">

                @include('partials.guide-portal-reference')

                {{-- ── STEP 1 ─────────────────────────────────── --}}
                <span id="websites" class="block scroll-mt-24"></span>
                <section id="step-1" class="not-prose scroll-mt-24">
                    <div class="flex items-baseline gap-4">
                        <span class="font-mono text-sm font-semibold text-slate-400">01</span>
                        <span class="text-[11px] font-semibold uppercase tracking-[0.2em] text-emerald-600">~ 1 min</span>
                    </div>
                    <h2 class="mt-2 text-3xl font-semibold tracking-tight text-slate-900">Add your first website</h2>
                    <p class="mt-4 text-[16px] leading-7 text-slate-600">
                        Every signal in Serfix — clicks, ranks, audits, backlinks, alerts — is scoped to a website. Before anything else syncs, Serfix needs to know which property it should hold.
                    </p>

                    {{-- Mockup: add website form --}}
                    <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-center justify-between border-b border-slate-200 pb-3">
                            <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">Websites · Add website</p>
                            <span class="rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">New</span>
                        </div>
                        <div class="mt-4 space-y-3 text-[12px]">
                            <div>
                                <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Canonical URL</p>
                                <div class="mt-1.5 rounded-lg border border-slate-200 bg-slate-50/60 px-3 py-2 font-mono text-slate-700">https://example.com</div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Default country</p>
                                    <div class="mt-1.5 rounded-lg border border-slate-200 bg-slate-50/60 px-3 py-2 text-slate-700">United States</div>
                                </div>
                                <div>
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Timezone</p>
                                    <div class="mt-1.5 rounded-lg border border-slate-200 bg-slate-50/60 px-3 py-2 text-slate-700">America/New_York</div>
                                </div>
                            </div>
                            <div class="rounded-xl border border-emerald-100 bg-emerald-50/60 p-3 text-[12px] text-emerald-800">
                                <span class="font-semibold">robots.txt found</span> · sitemap.xml: 4 indices, 1,284 URLs · canonical scheme matches
                            </div>
                        </div>
                    </div>

                    <div class="mt-6 grid gap-4 sm:grid-cols-3">
                        <div class="rounded-xl border border-slate-200 bg-white p-4">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">What you'll see</p>
                            <p class="mt-2 text-[13px] leading-6 text-slate-700">A live probe of robots.txt, sitemaps, and canonical configuration appears below the form.</p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white p-4">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">What to do</p>
                            <p class="mt-2 text-[13px] leading-6 text-slate-700">Use the exact protocol and host registered in Search Console — trailing slashes and <code>www</code> matter for property matching.</p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white p-4">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Pitfall</p>
                            <p class="mt-2 text-[13px] leading-6 text-slate-700">Mismatched protocol (http vs https) or host (with vs without www) is the #1 reason search sync returns zero rows.</p>
                        </div>
                    </div>
                </section>

                {{-- ── STEP 2 ─────────────────────────────────── --}}
                <span id="integrations" class="block scroll-mt-24"></span>
                <span id="settings" class="block scroll-mt-24"></span>
                <section id="step-2" class="not-prose mt-20 scroll-mt-24">
                    <div class="flex items-baseline gap-4">
                        <span class="font-mono text-sm font-semibold text-slate-400">02</span>
                        <span class="text-[11px] font-semibold uppercase tracking-[0.2em] text-emerald-600">~ 2 min</span>
                    </div>
                    <h2 class="mt-2 text-3xl font-semibold tracking-tight text-slate-900">Connect search and analytics</h2>
                    <p class="mt-4 text-[16px] leading-7 text-slate-600">
                        In one authorization step you grant read access to your connected search and analytics properties, plus permission to request indexing when you use that workflow. Credentials are encrypted at rest and rotated automatically, and you can revoke access from the same screen.
                    </p>

                    {{-- Mockup: authorization consent --}}
                    <div class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div class="flex items-center gap-2 border-b border-slate-200 bg-slate-50 px-5 py-3">
                            <span class="h-2 w-2 rounded-full bg-rose-400"></span>
                            <span class="h-2 w-2 rounded-full bg-amber-400"></span>
                            <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
                            <span class="ml-2 text-[11px] font-medium text-slate-500">Authorization — grant access for Serfix</span>
                        </div>
                        <div class="px-5 py-5">
                            <p class="text-[13px] text-slate-700">Serfix would like to:</p>
                            <ul class="mt-3 space-y-2.5 text-[12px]">
                                @foreach ([
                                    ['Read-only access to your connected analytics property', 'sensitive'],
                                    ['Read-only access to your verified search property', 'standard'],
                                    ['Permission to request indexing for URLs you choose', 'standard'],
                                ] as [$desc, $tone])
                                    <li class="flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50/60 px-3 py-2.5">
                                        <span @class([
                                            'mt-0.5 h-4 w-4 flex-none rounded',
                                            'bg-amber-200' => $tone === 'sensitive',
                                            'bg-slate-200' => $tone === 'standard',
                                        ])></span>
                                        <div>
                                            <p class="font-medium text-slate-800">{{ $desc }}</p>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                            <div class="mt-4 flex items-center gap-2">
                                <span class="rounded-md bg-slate-200 px-3 py-1.5 text-[11px] font-semibold text-slate-700">Cancel</span>
                                <span class="rounded-md bg-orange-600 px-3 py-1.5 text-[11px] font-semibold text-white">Allow</span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6 grid gap-4 sm:grid-cols-3">
                        <div class="rounded-xl border border-slate-200 bg-white p-4">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Why three permissions</p>
                            <p class="mt-2 text-[13px] leading-6 text-slate-700">Analytics for sessions and users, search property data for queries and positions, and indexing so you can request a URL refresh from audit workflows.</p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white p-4">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Token safety</p>
                            <p class="mt-2 text-[13px] leading-6 text-slate-700">Integration credentials stay on Serfix servers and are encrypted; short-lived access is rotated automatically.</p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white p-4">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Partial grants</p>
                            <p class="mt-2 text-[13px] leading-6 text-slate-700">If you decline part of the access, Serfix keeps working with reduced coverage. You can approve more later from the same screen.</p>
                        </div>
                    </div>

                    <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50/60 px-5 py-4">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-amber-700">Heads up</p>
                        <p class="mt-1 text-[14px] leading-6 text-slate-700">Use the same account that owns the search property and analytics view you want to connect. If the right sites do not appear in the picker after consent, you are usually signed in with a different account.</p>
                    </div>
                </section>

                {{-- ── STEP 3 ─────────────────────────────────── --}}
                <section id="step-3" class="not-prose mt-20 scroll-mt-24">
                    <div class="flex items-baseline gap-4">
                        <span class="font-mono text-sm font-semibold text-slate-400">03</span>
                        <span class="text-[11px] font-semibold uppercase tracking-[0.2em] text-emerald-600">~ 2 min</span>
                    </div>
                    <h2 class="mt-2 text-3xl font-semibold tracking-tight text-slate-900">Track keywords and competitors</h2>
                    <p class="mt-4 text-[16px] leading-7 text-slate-600">
                        Live results pages are captured per device, country, language, and optional city. Serfix overlays search clicks for the same query when available so a rank gain is judged on traffic, not only position.
                    </p>

                    {{-- Mockup: keyword grid --}}
                    <div class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 px-5 py-3">
                            <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">Keywords · example.com</p>
                            <p class="mt-0.5 text-sm font-semibold text-slate-900">Targeting · United States · Mobile</p>
                        </div>
                        <table class="min-w-full text-[12px]">
                            <thead class="bg-slate-50/60 text-[10px] uppercase tracking-wider text-slate-500">
                                <tr>
                                    <th class="px-4 py-2 text-left font-semibold">Query</th>
                                    <th class="px-3 py-2 text-right font-semibold">Pos</th>
                                    <th class="px-3 py-2 text-right font-semibold">Δ 7d</th>
                                    <th class="px-3 py-2 text-right font-semibold">Clicks 30d</th>
                                    <th class="px-3 py-2 text-left font-semibold">SERP features</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ([
                                    ['best seo tools', '#2', '+2', '1,284', ['PAA', 'Sitelinks'], 'emerald'],
                                    ['saas seo guide', '#7', '-1', '218', ['PAA', 'Video'], 'amber'],
                                    ['keyword research tool', '#11', '+4', '94', ['AI overview'], 'emerald'],
                                    ['rank tracker', '#19', '0', '12', ['PAA'], 'slate'],
                                ] as [$q, $pos, $delta, $clicks, $features, $tone])
                                    <tr class="hover:bg-slate-50/60">
                                        <td class="px-4 py-2.5 font-medium text-slate-800">{{ $q }}</td>
                                        <td class="px-3 py-2.5 text-right tabular-nums text-slate-900">{{ $pos }}</td>
                                        <td @class([
                                            'px-3 py-2.5 text-right tabular-nums font-semibold',
                                            'text-emerald-600' => $tone === 'emerald',
                                            'text-amber-600' => $tone === 'amber',
                                            'text-slate-500' => $tone === 'slate',
                                        ])>{{ $delta }}</td>
                                        <td class="px-3 py-2.5 text-right tabular-nums text-slate-700">{{ $clicks }}</td>
                                        <td class="px-3 py-2.5">
                                            <div class="flex flex-wrap gap-1">
                                                @foreach ($features as $f)
                                                    <span class="rounded-md bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-600">{{ $f }}</span>
                                                @endforeach
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <h3 class="mt-8 text-lg font-semibold text-slate-900">How to add keywords</h3>
                    <ol class="mt-3 space-y-2 text-[14px] leading-7 text-slate-700">
                        <li><span class="font-mono text-slate-400">1.</span> Open <strong>Keywords</strong>. Click <em>Add keywords</em>.</li>
                        <li><span class="font-mono text-slate-400">2.</span> Paste one per line, or upload a CSV with columns <code>query, country, device, language</code>.</li>
                        <li><span class="font-mono text-slate-400">3.</span> Set the default targeting. Override per-row if you operate in multiple regions.</li>
                        <li><span class="font-mono text-slate-400">4.</span> (Optional) Add up to three competitor domains. Serfix will record their position on every check.</li>
                        <li><span class="font-mono text-slate-400">5.</span> First SERP capture starts within minutes. Subsequent captures run on your plan's interval.</li>
                    </ol>

                    <div class="mt-6 grid gap-4 sm:grid-cols-3">
                        <div class="rounded-xl border border-slate-200 bg-white p-4">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Start with intent</p>
                            <p class="mt-2 text-[13px] leading-6 text-slate-700">20–40 high-intent terms beat 500 head terms. You can add more later — empty rows aren't penalized.</p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white p-4">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Re-check after publish</p>
                            <p class="mt-2 text-[13px] leading-6 text-slate-700">After shipping a content update, hit <em>Re-check now</em> to capture rank movement faster than the daily cycle.</p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white p-4">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">SERP features matter</p>
                            <p class="mt-2 text-[13px] leading-6 text-slate-700">A #2 below an AI overview earns less than a #4 with sitelinks. Serfix flags the feature so the rank number isn't read in isolation.</p>
                        </div>
                    </div>
                </section>

                {{-- ── STEP 4 ─────────────────────────────────── --}}
                <span id="audits" class="block scroll-mt-24"></span>
                <section id="step-4" class="not-prose mt-20 scroll-mt-24">
                    <div class="flex items-baseline gap-4">
                        <span class="font-mono text-sm font-semibold text-slate-400">04</span>
                        <span class="text-[11px] font-semibold uppercase tracking-[0.2em] text-emerald-600">~ 1 min</span>
                    </div>
                    <h2 class="mt-2 text-3xl font-semibold tracking-tight text-slate-900">Run a page audit</h2>
                    <p class="mt-4 text-[16px] leading-7 text-slate-600">
                        Audits combine Core Web Vitals, on-page SEO, and content review in a single pass and finish in under 60 seconds. After you ship a fix, request indexing for the URL without leaving the audit view.
                    </p>

                    {{-- Mockup: audit scorecard --}}
                    <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">Audit · /blog/saas-seo-guide</p>
                                <p class="mt-0.5 text-sm font-semibold text-slate-900">Mobile · Score 72 · Target: "saas seo guide"</p>
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
                        <div class="mt-4 flex flex-wrap gap-2">
                            <span class="rounded-md bg-slate-900 px-3 py-1.5 text-[11px] font-semibold text-white">Request indexing</span>
                            <span class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-[11px] font-semibold text-slate-700">Re-audit</span>
                            <span class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-[11px] font-semibold text-slate-700">Download PDF</span>
                        </div>
                    </div>

                    <h3 class="mt-8 text-lg font-semibold text-slate-900">Reading the score</h3>
                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                        <div class="rounded-xl border border-emerald-100 bg-emerald-50/40 p-4">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-emerald-700">90–100 · Good</p>
                            <p class="mt-1.5 text-[13px] leading-6 text-slate-700">Ship-ready. Re-audit after the next deploy to detect regressions.</p>
                        </div>
                        <div class="rounded-xl border border-amber-100 bg-amber-50/40 p-4">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-amber-700">60–89 · Needs work</p>
                            <p class="mt-1.5 text-[13px] leading-6 text-slate-700">Pick the top two recommendations from the prioritized list.</p>
                        </div>
                        <div class="rounded-xl border border-rose-100 bg-rose-50/40 p-4">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-rose-700">0–59 · Poor</p>
                            <p class="mt-1.5 text-[13px] leading-6 text-slate-700">Treat as a sprint goal. Often a CWV regression or schema break.</p>
                        </div>
                    </div>

                    <div class="mt-6 rounded-xl border border-slate-200 bg-slate-50/60 px-5 py-4">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">Pro tip</p>
                        <p class="mt-1 text-[14px] leading-6 text-slate-700">Always provide a <em>target keyword</em>. Without it the keyword-strategy review and topical-gap analysis are skipped, and you lose half the value of an audit.</p>
                    </div>

                    <p class="mt-6 text-[14px] leading-7 text-slate-700">
                        The <strong>Page audit report layout</strong> section above matches the live audit detail screen—the same panels appear in the same order as the in-app <em>Guide to this report</em> link.
                    </p>
                </section>

                {{-- ── STEP 5 ─────────────────────────────────── --}}
                <span id="backlinks" class="block scroll-mt-24"></span>
                <section id="step-5" class="not-prose mt-20 scroll-mt-24">
                    <div class="flex items-baseline gap-4">
                        <span class="font-mono text-sm font-semibold text-slate-400">05</span>
                        <span class="text-[11px] font-semibold uppercase tracking-[0.2em] text-emerald-600">~ 2 min</span>
                    </div>
                    <h2 class="mt-2 text-3xl font-semibold tracking-tight text-slate-900">Import or track backlinks</h2>
                    <p class="mt-4 text-[16px] leading-7 text-slate-600">
                        Serfix verifies presence, anchor, and rel on every check, then measures the 28-day click delta on the target page so you can prove which links actually lifted traffic.
                    </p>

                    {{-- Mockup: backlinks table --}}
                    <div class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 px-5 py-3">
                            <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">Backlinks · example.com</p>
                            <p class="mt-0.5 text-sm font-semibold text-slate-900">Verified · 28-day click delta</p>
                        </div>
                        <table class="min-w-full text-[12px]">
                            <thead class="bg-slate-50/60 text-[10px] uppercase tracking-wider text-slate-500">
                                <tr>
                                    <th class="px-4 py-2 text-left font-semibold">Source → target</th>
                                    <th class="px-3 py-2 text-left font-semibold">Anchor</th>
                                    <th class="px-3 py-2 text-right font-semibold">Rel</th>
                                    <th class="px-3 py-2 text-right font-semibold">DA</th>
                                    <th class="px-3 py-2 text-right font-semibold">Δ clicks</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ([
                                    ['blog.partner.io → /pricing', 'best pricing for SEO', 'follow', 58, '+412', 'emerald'],
                                    ['news.example.org → /blog/saas-seo', 'SaaS SEO playbook', 'follow', 49, '+186', 'emerald'],
                                    ['forum.community.dev → /features', 'Serfix', 'ugc', 42, '+38', 'emerald'],
                                    ['low-quality.tld → /product/ai-writer', 'click here', 'nofollow', 14, '-22', 'rose'],
                                ] as [$row, $anchor, $rel, $da, $delta, $tone])
                                    <tr class="hover:bg-slate-50/60">
                                        <td class="px-4 py-2.5 font-medium text-slate-800">{{ $row }}</td>
                                        <td class="px-3 py-2.5 text-slate-600">{{ $anchor }}</td>
                                        <td class="px-3 py-2.5 text-right text-slate-600">{{ $rel }}</td>
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

                    <h3 class="mt-8 text-lg font-semibold text-slate-900">Two ways to add</h3>
                    <div class="mt-3 grid gap-4 sm:grid-cols-2">
                        <div class="rounded-xl border border-slate-200 bg-white p-5">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">CSV upload</p>
                            <p class="mt-2 text-[13px] leading-6 text-slate-700">Columns: <code>source_url, target_url, anchor</code>. Anchor is optional — Serfix extracts it on first verify.</p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white p-5">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Manual entry</p>
                            <p class="mt-2 text-[13px] leading-6 text-slate-700">Paste up to 50 source URLs at once. Useful for tracking outreach campaigns as they land.</p>
                        </div>
                    </div>

                    <div class="mt-6 grid gap-4 sm:grid-cols-3">
                        <div class="rounded-xl border border-slate-200 bg-white p-4">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Status icons</p>
                            <p class="mt-2 text-[13px] leading-6 text-slate-700"><span class="inline-block h-2 w-2 rounded-full bg-emerald-500"></span> live · <span class="inline-block h-2 w-2 rounded-full bg-amber-500"></span> anchor changed · <span class="inline-block h-2 w-2 rounded-full bg-rose-500"></span> removed</p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white p-4">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Click delta</p>
                            <p class="mt-2 text-[13px] leading-6 text-slate-700">Compares 28 days <em>after</em> first-seen against 28 days <em>before</em>, on the target page only.</p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white p-4">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Pro+ only</p>
                            <p class="mt-2 text-[13px] leading-6 text-slate-700">Competitor backlink prospecting surfaces sources linking to rivals but not to you.</p>
                        </div>
                    </div>
                </section>

                {{-- ── STEP 6 ─────────────────────────────────── --}}
                <span id="insights" class="block scroll-mt-24"></span>
                <section id="step-6" class="not-prose mt-20 scroll-mt-24">
                    <div class="flex items-baseline gap-4">
                        <span class="font-mono text-sm font-semibold text-slate-400">06</span>
                        <span class="text-[11px] font-semibold uppercase tracking-[0.2em] text-emerald-600">~ 2 min</span>
                    </div>
                    <h2 class="mt-2 text-3xl font-semibold tracking-tight text-slate-900">Review the insight boards</h2>
                    <p class="mt-4 text-[16px] leading-7 text-slate-600">
                        Six pre-built reports combine synced search and analytics signals with audits and backlinks into a ranked action list. Each row links straight to the offending page so the next move is one click away.
                    </p>

                    {{-- Mockup: 6 boards --}}
                    <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @foreach ([
                            ['Cannibalizations', '14', 'Two pages competing for one query', 'amber'],
                            ['Striking distance', '27', 'Keywords at pos 5–20 with below-curve CTR', 'orange'],
                            ['Content decay', '8', '90-day click decline beyond seasonality', 'slate'],
                            ['Indexing fails', '3', 'URLs earning impressions but not indexed', 'rose'],
                            ['Audit vs traffic', '11', 'High-traffic pages with poor audit scores', 'slate'],
                            ['Backlink impact', '9', 'Links with measurable lift on the target', 'emerald'],
                        ] as [$lbl, $val, $help, $tone])
                            <div class="rounded-xl border border-slate-200 bg-white p-4">
                                <div class="flex items-baseline justify-between">
                                    <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">{{ $lbl }}</p>
                                    <p @class([
                                        'text-2xl font-semibold tabular-nums',
                                        'text-amber-600' => $tone === 'amber',
                                        'text-orange-600' => $tone === 'orange',
                                        'text-slate-900' => $tone === 'slate',
                                        'text-rose-600' => $tone === 'rose',
                                        'text-emerald-600' => $tone === 'emerald',
                                    ])>{{ $val }}</p>
                                </div>
                                <p class="mt-2 text-[12px] leading-5 text-slate-600">{{ $help }}</p>
                            </div>
                        @endforeach
                    </div>

                    <h3 class="mt-8 text-lg font-semibold text-slate-900">How to triage</h3>
                    <ol class="mt-3 space-y-2 text-[14px] leading-7 text-slate-700">
                        <li><span class="font-mono text-slate-400">1.</span> Open <strong>Striking distance</strong> first — these are the fastest wins (small content tweak, internal link, FAQ addition).</li>
                        <li><span class="font-mono text-slate-400">2.</span> Then <strong>Cannibalization</strong> — usually a merge/redirect or canonical fix.</li>
                        <li><span class="font-mono text-slate-400">3.</span> <strong>Indexing fails</strong> for pages that already earn impressions: validate canonical, then resubmit.</li>
                        <li><span class="font-mono text-slate-400">4.</span> <strong>Content decay</strong> last — these are bigger rewrites and need a sprint allocation.</li>
                    </ol>

                    <div class="mt-6 rounded-xl border border-slate-200 bg-slate-50/60 px-5 py-4">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">Tip</p>
                        <p class="mt-1 text-[14px] leading-6 text-slate-700">Most teams ship 1–3 wins from striking-distance in the first week. If you see fewer than 5 candidates total, your keyword set is too narrow — go back to step 3 and broaden.</p>
                    </div>

                    <p class="mt-6 text-[14px] leading-7 text-slate-700">
                        For the dashboard insight row and what each card counts, see <strong>Dashboard</strong> and <strong>Action insight cards</strong> in the product reference at the top of this guide.
                    </p>
                </section>

                {{-- ── STEP 7 ─────────────────────────────────── --}}
                <span id="reports" class="block scroll-mt-24"></span>
                <span id="alerts" class="block scroll-mt-24"></span>
                <section id="step-7" class="not-prose mt-20 scroll-mt-24">
                    <div class="flex items-baseline gap-4">
                        <span class="font-mono text-sm font-semibold text-slate-400">07</span>
                        <span class="text-[11px] font-semibold uppercase tracking-[0.2em] text-emerald-600">~ 2 min</span>
                    </div>
                    <h2 class="mt-2 text-3xl font-semibold tracking-tight text-slate-900">Schedule reports + turn on alerts</h2>
                    <p class="mt-4 text-[16px] leading-7 text-slate-600">
                        Reports and alerts share one recipient list per website. Reports run on a fixed cadence; alerts fire only when Serfix detects an unusual change against your recent baseline—so normal volatility does not spam your inbox.
                    </p>

                    <div class="mt-6 grid gap-5 lg:grid-cols-2">
                        {{-- Mockup: schedule form --}}
                        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">Reports · New schedule</p>
                            <div class="mt-3 space-y-3 text-[12px]">
                                <div>
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Recipients</p>
                                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                                        <span class="rounded-md bg-slate-100 px-2 py-0.5 text-slate-700">you@example.com</span>
                                        <span class="rounded-md bg-slate-100 px-2 py-0.5 text-slate-700">cmo@example.com</span>
                                        <span class="rounded-md border border-dashed border-slate-300 px-2 py-0.5 text-slate-500">+ add</span>
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Cadence</p>
                                        <div class="mt-1.5 rounded-lg border border-slate-200 bg-slate-50/60 px-3 py-2 text-slate-700">Weekly · Monday</div>
                                    </div>
                                    <div>
                                        <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Time (website TZ)</p>
                                        <div class="mt-1.5 rounded-lg border border-slate-200 bg-slate-50/60 px-3 py-2 text-slate-700">09:00</div>
                                    </div>
                                </div>
                                <div class="rounded-lg border border-emerald-100 bg-emerald-50/60 px-3 py-2 text-emerald-800">Anomaly alerts · ON for the same recipients</div>
                            </div>
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
                                    <li class="flex items-center justify-between rounded-md bg-slate-50/60 px-3 py-1.5"><span>Search clicks</span><span class="font-mono text-rose-600">212 vs typical 820</span></li>
                                    <li class="flex items-center justify-between rounded-md bg-slate-50/60 px-3 py-1.5"><span>Sessions</span><span class="font-mono text-rose-600">480 vs typical 1,540</span></li>
                                    <li class="flex items-center justify-between rounded-md bg-slate-50/60 px-3 py-1.5"><span>Avg position</span><span class="font-mono text-amber-600">14.2 vs typical 11.4</span></li>
                                </ul>
                                <div class="mt-4 inline-flex rounded-md bg-slate-900 px-3 py-1.5 text-[11px] font-semibold text-white">Open Serfix →</div>
                            </div>
                        </div>
                    </div>

                    <h3 class="mt-8 text-lg font-semibold text-slate-900">When alerts fire</h3>
                    <p class="mt-3 text-[14px] leading-7 text-slate-700">Serfix compares recent daily totals to your rolling baseline and only notifies you when the drop is both material and unusual for that metric. Alerts are deduplicated so you do not get repeated noise for the same issue.</p>

                    <h3 class="mt-8 text-lg font-semibold text-slate-900">Reports and scheduled email</h3>
                    <p class="mt-3 text-[13px] leading-6 text-slate-700">
                        For the Insights tab (every column per category) and the custom growth report email builder, see <strong>Reports → Insights</strong> and <strong>Custom growth reports (email)</strong> in the product reference at the top of this guide.
                    </p>
                </section>

                {{-- ── STEP 8 ─────────────────────────────────── --}}
                <section id="step-8" class="not-prose mt-20 scroll-mt-24">
                    <div class="flex items-baseline gap-4">
                        <span class="font-mono text-sm font-semibold text-slate-400">08</span>
                        <span class="text-[11px] font-semibold uppercase tracking-[0.2em] text-emerald-600">~ 3 min</span>
                    </div>
                    <h2 class="mt-2 text-3xl font-semibold tracking-tight text-slate-900">Install the WordPress plugin</h2>
                    <p class="mt-4 text-[16px] leading-7 text-slate-600">
                        The Serfix SEO plugin adds HQ, the block-editor panel, the posts-list Serfix column, and the dashboard widget. Full layouts and column-by-column notes for each surface are in <strong><a href="#wordpress-plugin" class="text-orange-600 underline decoration-orange-600/30 underline-offset-2 hover:text-orange-800">WordPress plugin</a></strong> at the top of this guide.
                    </p>

                    {{-- ── 8.1 Plugin settings ───────────────────── --}}
                    <h3 class="mt-10 text-lg font-semibold text-slate-900">8.1 Plugin settings — connection, sync, and updates</h3>
                    <div class="mt-3 grid gap-4 sm:grid-cols-2">
                        <div class="rounded-xl border border-slate-200 bg-white p-5">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Connection</p>
                            <ul class="mt-2 space-y-1.5 text-[13px] leading-6 text-slate-700">
                                <li>One-click <em>Connect to Serfix</em> using a short verification step—no secrets to copy.</li>
                                <li>Live status badge (Connected / Reconnect needed / Revoked).</li>
                                <li><em>Disconnect</em> ends the link for this site in Serfix right away.</li>
                            </ul>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white p-5">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Sync controls</p>
                            <ul class="mt-2 space-y-1.5 text-[13px] leading-6 text-slate-700">
                                <li>Force-refresh sidebar data for the current post.</li>
                                <li>Toggle posts list column on/off.</li>
                                <li>Toggle dashboard widget on/off per user role.</li>
                            </ul>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white p-5">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Auto-updates</p>
                            <ul class="mt-2 space-y-1.5 text-[13px] leading-6 text-slate-700">
                                <li>The plugin checks Serfix for new releases on a routine schedule.</li>
                                <li>Optional auto-install for security/patch releases.</li>
                                <li>Manual update from <strong>Plugins</strong> at any time.</li>
                            </ul>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white p-5">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Permissions</p>
                            <ul class="mt-2 space-y-1.5 text-[13px] leading-6 text-slate-700">
                                <li>Sidebar visible to Editors+; widget configurable per role.</li>
                                <li>Connect and disconnect require administrator access.</li>
                                <li>Posts column respects post-type capabilities.</li>
                            </ul>
                        </div>
                    </div>

                    {{-- ── 8.2 Install in 4 steps ───────────────── --}}
                    <h3 class="mt-12 text-lg font-semibold text-slate-900">8.2 Install in four steps</h3>
                    <ol class="mt-3 space-y-2 text-[14px] leading-7 text-slate-700">
                        <li><span class="font-mono text-slate-400">1.</span> In Serfix, open <strong>Settings → WordPress</strong>. Click <em>Download plugin</em> — you'll get the latest packaged ZIP.</li>
                        <li><span class="font-mono text-slate-400">2.</span> In WordPress, go to <strong>Plugins → Add New → Upload Plugin</strong>. Upload the ZIP, then activate.</li>
                        <li><span class="font-mono text-slate-400">3.</span> In the WP plugin settings, click <em>Connect to Serfix</em>. You'll be redirected back to Serfix, pick the matching website, and approve.</li>
                        <li><span class="font-mono text-slate-400">4.</span> Open any post — the Serfix panel appears in the block editor sidebar. The dashboard widget and posts-list column fill in shortly after.</li>
                    </ol>

                    {{-- ── 8.3 Security ──────────────────────────── --}}
                    <h3 class="mt-12 text-lg font-semibold text-slate-900">8.3 Security model</h3>
                    <div class="mt-3 grid gap-4 sm:grid-cols-2">
                        <div class="rounded-xl border border-slate-200 bg-white p-5">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Scoped to one site</p>
                            <p class="mt-2 text-[13px] leading-6 text-slate-700">Each plugin connection applies to a single website in your Serfix account. A compromised site-specific link could only reach that site’s data—not your other properties or full account.</p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white p-5">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Stored on your server</p>
                            <p class="mt-2 text-[13px] leading-6 text-slate-700">Connection credentials stay on your server. They are not embedded in public pages or exposed to visitors—only Serfix’s plugin uses them to fetch your workspace data.</p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white p-5">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Verified connect flow</p>
                            <p class="mt-2 text-[13px] leading-6 text-slate-700">Connecting runs through a short, signed verification step so stray links cannot complete pairing. Someone without access to your site admin cannot bind your install to another workspace.</p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white p-5">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Instant revoke</p>
                            <p class="mt-2 text-[13px] leading-6 text-slate-700">Choose <em>Disconnect</em> in either your site admin or Serfix. The link stops working immediately on the next request.</p>
                        </div>
                    </div>
                </section>

                {{-- ── METRIC GLOSSARY ──────────────────────── --}}
                <section id="metric-glossary" class="not-prose mt-24 scroll-mt-24">
                    <h2 class="text-3xl font-semibold tracking-tight text-slate-900">Metric glossary</h2>
                    <p class="mt-4 text-[16px] leading-7 text-slate-600">Quick definitions for the metrics you'll see across Serfix. Open the table for the source and exact window.</p>

                    <div class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white">
                        <table class="min-w-full text-[13px]">
                            <thead class="bg-slate-50/60 text-[10.5px] uppercase tracking-wider text-slate-500">
                                <tr>
                                    <th class="px-4 py-2.5 text-left font-semibold">Metric</th>
                                    <th class="px-4 py-2.5 text-left font-semibold">Source</th>
                                    <th class="px-4 py-2.5 text-left font-semibold">Definition</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ([
                                    ['Clicks', 'Search sync', 'Times people clicked your listing in search results in the selected scope.'],
                                    ['Impressions', 'Search sync', 'Times your URL was shown for queries in scope.'],
                                    ['CTR', 'Search sync', 'Share of impressions that resulted in a click.'],
                                    ['Avg position', 'Search sync', 'Average rank where your URL appeared for queries in scope.'],
                                    ['Sessions', 'Analytics sync', 'Visit sessions from your connected analytics property.'],
                                    ['Users', 'Analytics sync', 'Distinct visitors from your connected analytics property.'],
                                    ['LCP', 'Lab / field', 'Largest Contentful Paint — when the main content renders.'],
                                    ['CLS', 'Lab / field', 'Cumulative Layout Shift — visual stability.'],
                                    ['INP', 'Lab / field', 'Interaction to Next Paint — input responsiveness.'],
                                    ['TBT', 'Lab', 'Total Blocking Time — main-thread blocking from scripts.'],
                                    ['FCP', 'Lab / field', 'First Contentful Paint — first paint of text or image.'],
                                    ['TTFB', 'Lab / field', 'Time To First Byte — server response latency.'],
                                    ['Δ clicks (28d)', 'Serfix', 'Backlink view: change in target-page clicks around when the link was first seen.'],
                                    ['Anomaly signal', 'Serfix', 'Indicates how far a daily metric sits from its usual range for your site (used for alerts).'],
                                ] as [$m, $src, $def])
                                    <tr>
                                        <td class="px-4 py-2.5 font-semibold text-slate-800">{{ $m }}</td>
                                        <td class="px-4 py-2.5 text-slate-600">
                                            <span class="rounded-md bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-600">{{ $src }}</span>
                                        </td>
                                        <td class="px-4 py-2.5 text-slate-700">{{ $def }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>

                {{-- ── TROUBLESHOOTING ──────────────────────── --}}
                <section id="troubleshooting" class="not-prose mt-24 scroll-mt-24">
                    <h2 class="text-3xl font-semibold tracking-tight text-slate-900">Troubleshooting</h2>
                    <p class="mt-4 text-[16px] leading-7 text-slate-600">The handful of issues that account for almost every support ticket — and the fastest way to resolve each.</p>

                    <div class="mt-6 space-y-4">
                        @foreach ([
                            [
                                'Search sync returns zero rows',
                                'Property mismatch — the URL prefix for your connected search property must exactly match the canonical URL in Serfix (protocol, host, trailing slash).',
                                'Open <strong>Settings → Integrations</strong>, click <em>Reselect property</em>, and pick the correct prefix. Force a sync from the same screen.',
                            ],
                            [
                                'Analytics property dropdown is empty after consent',
                                'The signed-in account does not have access to the analytics view you need, or the wrong account was used.',
                                'Sign out of the provider, sign back in with the account that owns the property, then reconnect from <strong>Settings → Integrations</strong>.',
                            ],
                            [
                                'Audit fails with "fetch blocked"',
                                'robots.txt or an edge firewall / bot challenge is blocking the Serfix audit fetch.',
                                'Allow <code>EBQAuditBot</code> in robots.txt and whitelist the Serfix IP range listed in <strong>Settings → Audit access</strong>.',
                            ],
                            [
                                'URL resubmit returns "permission denied"',
                                'Indexing permission was not granted, or the search property is not verified for the connected account.',
                                'Re-run the connection flow from <strong>Settings → Integrations</strong> and confirm indexing permission is enabled. Verify site ownership for the search property if needed.',
                            ],
                            [
                                'Backlink shows "removed" but the link is live',
                                'The source page renders the link via client-side script, which the default verifier does not execute.',
                                'In <strong>Backlinks → Edit</strong>, switch the verifier to the <em>enhanced</em> mode that loads the page the way a visitor would. It may use a small per-link credit on your plan.',
                            ],
                            [
                                'No anomaly alerts despite a clear drop',
                                'The site may still be building a baseline window, or the change did not meet the anomaly thresholds.',
                                'Check <strong>Reports → Alerts</strong> for the last evaluation. Alerts strengthen as more history accumulates.',
                            ],
                        ] as [$title, $cause, $fix])
                            <details class="group rounded-xl border border-slate-200 bg-white open:bg-slate-50/40">
                                <summary class="flex cursor-pointer items-center justify-between gap-3 px-5 py-4 text-[14px] font-semibold text-slate-900">
                                    {{ $title }}
                                    <span class="text-slate-400 transition group-open:rotate-180">⌄</span>
                                </summary>
                                <div class="border-t border-slate-200 px-5 py-4 text-[13px] leading-6 text-slate-700">
                                    <p><span class="font-semibold text-slate-900">Likely cause:</span> {{ $cause }}</p>
                                    <p class="mt-2"><span class="font-semibold text-slate-900">Fix:</span> {!! $fix !!}</p>
                                </div>
                            </details>
                        @endforeach
                    </div>
                </section>

                {{-- ── FAQ ──────────────────────────────────── --}}
                <section id="faq" class="not-prose mt-24 scroll-mt-24">
                    <h2 class="text-3xl font-semibold tracking-tight text-slate-900">FAQ</h2>

                    <div class="mt-6 space-y-3">
                        @foreach ([
                            [
                                'How often does data refresh?',
                                'Connected search and analytics data sync daily. Rank checks follow your plan interval. On-demand re-checks are available from keywords and audits.',
                            ],
                            [
                                'Can I add multiple websites?',
                                'Yes. Each plan includes a website allowance; add more in <strong>Settings → Plan</strong>. Each website has its own integrations, recipients, and timezone.',
                            ],
                            [
                                'Is my account data shared externally?',
                                'Integration credentials stay on Serfix servers and are encrypted at rest. Delivered reports are generated inside Serfix.',
                            ],
                            [
                                'How accurate are the ranks?',
                                'Checks use your chosen market (country, device, language, optional location). Position reflects where your URL appears in the live results for that market.',
                            ],
                            [
                                'Can I export raw data?',
                                'Yes. Every board offers spreadsheet export. Reports support printable summaries on eligible plans; advanced export options appear in <strong>Settings</strong> where your plan allows.',
                            ],
                            [
                                'What happens if I disconnect integrations?',
                                'Existing data is preserved. New syncs pause until you reconnect. Scheduled reports can use cached data; alerts pause to avoid false signals.',
                            ],
                            [
                                'Do you support team access?',
                                'Yes — invite teammates with role-based permissions (Owner / Editor / Viewer / Reports-only). Configure in <strong>Team</strong>.',
                            ],
                        ] as [$q, $a])
                            <details class="group rounded-xl border border-slate-200 bg-white open:bg-slate-50/40">
                                <summary class="flex cursor-pointer items-center justify-between gap-3 px-5 py-4 text-[14px] font-semibold text-slate-900">
                                    {{ $q }}
                                    <span class="text-slate-400 transition group-open:rotate-180">⌄</span>
                                </summary>
                                <div class="border-t border-slate-200 px-5 py-4 text-[13px] leading-6 text-slate-700">{!! $a !!}</div>
                            </details>
                        @endforeach
                    </div>
                </section>

                {{-- ── WEEKLY RHYTHM ────────────────────────── --}}
                <section id="weekly-rhythm" class="not-prose mt-24 scroll-mt-24">
                    <h2 class="text-3xl font-semibold tracking-tight text-slate-900">A repeatable weekly rhythm</h2>
                    <p class="mt-4 text-[16px] leading-7 text-slate-600">Once setup is done, the loop is what produces compounding gains. Most teams run this in 30–45 minutes a week.</p>

                    <ol class="mt-6 space-y-3 text-[14px]">
                        @foreach ([
                            ['Mon · 5 min', 'Open the dashboard. Read the action insights panel and yesterday\'s anomaly alerts (if any).'],
                            ['Mon · 10 min', 'Triage striking-distance and cannibalization. Pick 1–3 actions with one-click ticket export.'],
                            ['Tue–Thu', 'Ship fixes. Re-audit each page and request indexing from the audit view when you publish.'],
                            ['Thu · 5 min', 'Check new backlinks landed this week — verify and check the 28-day click delta on the target page.'],
                            ['Fri · 10 min', 'Read the scheduled weekly report. Note YoY direction and which actions actually moved the needle.'],
                            ['Fri · 5 min', 'Update the rolling SEO log with what shipped and the result. Builds a reviewable history.'],
                        ] as [$when, $what])
                            <li class="flex items-start gap-4 rounded-xl border border-slate-200 bg-white px-5 py-3.5">
                                <span class="mt-0.5 inline-flex w-28 flex-none rounded-md bg-slate-900 px-2 py-1 text-center text-[11px] font-semibold text-white">{{ $when }}</span>
                                <span class="text-slate-700">{{ $what }}</span>
                            </li>
                        @endforeach
                    </ol>
                </section>
            </div>
        </div>
    </section>

    {{-- ── CTA ──────────────────────────────────────────────── --}}
    <section class="bg-slate-50/60 py-20 sm:py-24">
        <div class="mx-auto max-w-3xl px-6 text-center lg:px-8">
            <h2 class="text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">Ready to run the loop on your data?</h2>
            <p class="mx-auto mt-4 max-w-xl text-base leading-7 text-slate-600">Connect your first website and complete the eight steps in under twenty minutes.</p>
            <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                <a href="{{ route('register') }}" class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">Start free</a>
                <a href="{{ route('features') }}" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-900">See features</a>
            </div>
        </div>
    </section>
</x-marketing.page>
