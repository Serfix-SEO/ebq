<x-marketing.page
    title="TrustSignal, CiteSignal & TopicSignal — how Serfix measures authority — Serfix"
    description="What the Serfix TrustSignal, CiteSignal and TopicSignal scores mean, the signals behind them, the score bands, and how the formulas are versioned."
    active="features"
>
    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section class="border-b border-slate-200 bg-white">
        <div class="mx-auto max-w-6xl px-6 py-20 text-center lg:px-8 lg:py-24">
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">{{ __('Methodology') }}</p>
            <h1 class="mx-auto mt-4 max-w-3xl text-balance text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl">
                {{ __('How TrustSignal, CiteSignal & TopicSignal work') }}
            </h1>
            <p class="mx-auto mt-5 max-w-2xl text-balance text-[17px] leading-8 text-slate-600">
                {{ __('Three proprietary 0–100 metrics that answer the questions every backlink profile raises: are these links trustworthy, how widely is this site referenced across the web — and do the links actually come from your topic?') }}
            </p>
        </div>
    </section>

    {{-- ── The three scores ──────────────────────────────────── --}}
    <section class="border-b border-slate-200 bg-slate-50">
        <div class="mx-auto grid max-w-6xl gap-6 px-6 py-16 lg:grid-cols-3 lg:px-8">
            <div class="rounded-2xl border border-slate-200 bg-white p-8">
                <h2 class="text-xl font-semibold text-slate-900">TrustSignal <span class="ml-2 rounded-full bg-orange-50 px-2.5 py-0.5 text-xs font-semibold text-orange-700">{{ __('link quality') }}</span></h2>
                <p class="mt-3 leading-7 text-slate-600">{{ __('Measures how trustworthy the sites linking to a domain are. A profile earns a high TrustSignal when its links come from reputable, editorially controlled sources — and a low one when they come from link farms, spam networks, or low-quality directories.') }}</p>
                <p class="mt-4 text-sm font-semibold text-slate-700">{{ __('Signals that feed it:') }}</p>
                <ul class="mt-2 space-y-1.5 text-sm leading-6 text-slate-600">
                    <li>• {{ __('Spam indicators across the backlink profile') }}</li>
                    <li>• {{ __('Graph distance from the well-connected core of the web (harmonic centrality — very hard to fake)') }}</li>
                    <li>• {{ __('How strong the referring domains themselves are') }}</li>
                    <li>• {{ __('Balance of dofollow links') }}</li>
                    <li>• {{ __('Network diversity — many links from few servers is a link-farm fingerprint') }}</li>
                    <li>• {{ __('Links from institutional domains (.gov / .edu) and a curated list of highly trusted sites') }}</li>
                </ul>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-8">
                <h2 class="text-xl font-semibold text-slate-900">CiteSignal <span class="ml-2 rounded-full bg-orange-50 px-2.5 py-0.5 text-xs font-semibold text-orange-700">{{ __('link popularity') }}</span></h2>
                <p class="mt-3 leading-7 text-slate-600">{{ __('Measures how widely the web references a domain — the volume and reach of its links, independent of their quality. A site can be popular without being trusted; comparing the two scores is where the insight lives.') }}</p>
                <p class="mt-4 text-sm font-semibold text-slate-700">{{ __('Signals that feed it:') }}</p>
                <ul class="mt-2 space-y-1.5 text-sm leading-6 text-slate-600">
                    <li>• {{ __('PageRank-family popularity computed over a web graph of 120M+ domains') }}</li>
                    <li>• {{ __('Independent link-index authority ranks') }}</li>
                    <li>• {{ __('Breadth of unique referring domains (log-scaled — the jump from 10 to 100 matters more than 10,000 to 10,090)') }}</li>
                </ul>
                <p class="mt-4 text-sm leading-6 text-slate-500">{{ __('A TrustSignal far below the CiteSignal is the classic footprint of bought or automated links: popularity without trust.') }}</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-8">
                <h2 class="text-xl font-semibold text-slate-900">TopicSignal <span class="ml-2 rounded-full bg-orange-50 px-2.5 py-0.5 text-xs font-semibold text-orange-700">{{ __('topical relevance') }}</span></h2>
                <p class="mt-3 leading-7 text-slate-600">{{ __('Trust earned only from links that are relevant to your topic. We classify every referring domain into a topic and check whether it plausibly overlaps yours — TopicSignal is your TrustSignal weighted by that relevant share.') }}</p>
                <p class="mt-4 text-sm font-semibold text-slate-700">{{ __('Signals that feed it:') }}</p>
                <ul class="mt-2 space-y-1.5 text-sm leading-6 text-slate-600">
                    <li>• {{ __('Your TrustSignal (the quality baseline)') }}</li>
                    <li>• {{ __('Topic classification of every referring domain (classified once, cached)') }}</li>
                    <li>• {{ __('The share of your links that come from topically-relevant sites') }}</li>
                </ul>
                <p class="mt-4 text-sm leading-6 text-slate-500">{{ __('A high TrustSignal with a low TopicSignal means strong but off-topic links — search engines value relevance, so that gap is your link-building roadmap.') }}</p>
            </div>
        </div>
    </section>

    {{-- ── Bands ─────────────────────────────────────────────── --}}
    <section class="border-b border-slate-200 bg-white">
        <div class="mx-auto max-w-6xl px-6 py-16 lg:px-8">
            <h2 class="text-center text-2xl font-semibold text-slate-900">{{ __('Reading the bands') }}</h2>
            <div class="mx-auto mt-8 grid max-w-3xl gap-4 sm:grid-cols-3">
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-5 text-center">
                    <p class="text-2xl font-bold tabular-nums text-rose-700">0–29</p>
                    <p class="mt-1 text-sm font-semibold text-rose-700">{{ __('Weak') }}</p>
                    <p class="mt-2 text-xs leading-5 text-rose-700/80">{{ __('Little authority yet, or quality problems worth investigating.') }}</p>
                </div>
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-center">
                    <p class="text-2xl font-bold tabular-nums text-amber-700">30–59</p>
                    <p class="mt-1 text-sm font-semibold text-amber-700">{{ __('Moderate') }}</p>
                    <p class="mt-2 text-xs leading-5 text-amber-700/80">{{ __('A developing profile — typical for growing sites doing honest link building.') }}</p>
                </div>
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-5 text-center">
                    <p class="text-2xl font-bold tabular-nums text-emerald-700">60–100</p>
                    <p class="mt-1 text-sm font-semibold text-emerald-700">{{ __('Strong') }}</p>
                    <p class="mt-2 text-xs leading-5 text-emerald-700/80">{{ __('Established authority — the range where major publications and institutions live.') }}</p>
                </div>
            </div>
            <p class="mx-auto mt-8 max-w-2xl text-center text-sm leading-6 text-slate-500">
                {{ __('Scores are on our own scale. They are not interchangeable with numbers from other tools — every provider computes authority differently. Compare Serfix scores with Serfix scores.') }}
            </p>
        </div>
    </section>

    {{-- ── Determinism & versioning ──────────────────────────── --}}
    <section class="border-b border-slate-200 bg-slate-50">
        <div class="mx-auto max-w-3xl px-6 py-16 lg:px-8">
            <h2 class="text-center text-2xl font-semibold text-slate-900">{{ __('Deterministic by design') }}</h2>
            <p class="mt-4 text-center leading-7 text-slate-600">
                {{ __('The same report data always produces the same score — no black box, no random drift. A moving score means the data behind it changed, so you can trust movements as signals, not noise.') }}
            </p>
        </div>
    </section>

    {{-- ── CTA ───────────────────────────────────────────────── --}}
    <section class="bg-white">
        <div class="mx-auto max-w-6xl px-6 py-16 text-center lg:px-8">
            <h2 class="text-2xl font-semibold text-slate-900">{{ __('See your own scores') }}</h2>
            <p class="mx-auto mt-3 max-w-xl text-slate-600">{{ __('Run any domain through Site Explorer — Trust Score, Citation Score, and the full backlink profile are part of every report.') }}</p>
            <a href="{{ route('landing') }}#analyze" class="mt-6 inline-flex items-center justify-center rounded-lg bg-orange-600 px-6 py-3 text-sm font-semibold text-white transition hover:bg-orange-700">{{ __('Analyze a website') }}</a>
        </div>
    </section>
</x-marketing.page>
