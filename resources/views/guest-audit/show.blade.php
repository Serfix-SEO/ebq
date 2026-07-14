@php
    $isCompleted = $audit->isCompleted();
    $isFailed = $audit->isFailed();
    $isPending = $audit->isPending();
    $displayUrl = \Illuminate\Support\Str::limit($audit->url, 80);

    // Prefer the audited page's <title> for the heading; fall back to the URL
    // (e.g. while pending, on failure, or for pages with no title tag).
    $pageTitle = trim((string) data_get($audit->result, 'metadata.title', ''));
    $headingText = $pageTitle !== '' ? \Illuminate\Support\Str::limit($pageTitle, 120) : $displayUrl;
@endphp

<x-marketing.page
    title="Your free SEO audit"
    description="Instant on-page + keyword SEO audit — no signup required."
    robots="noindex, nofollow"
>
    <section class="bg-slate-50/60">
        <div class="mx-auto max-w-5xl px-6 py-12 lg:px-8 lg:py-16">

            {{-- ── Header ─────────────────────────────────────────── --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-600">{{ __('Free SEO audit') }}</p>
                    <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl" title="{{ $pageTitle !== '' ? $pageTitle : $audit->url }}">{{ $headingText }}</h1>
                    <p class="mt-1 truncate text-sm text-slate-500" title="{{ $audit->url }}">{{ $displayUrl }}</p>
                    <p class="mt-1 text-sm text-slate-600">
                        {{ __('Target keyword:') }} <span class="font-medium text-slate-900">“{{ $audit->keyword }}”</span>
                    </p>
                </div>
                <a href="{{ route('landing') }}#main" class="inline-flex shrink-0 items-center gap-1.5 self-start rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-900 sm:self-auto">
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    {{ __('Audit another page') }}
                </a>
            </div>

            {{-- ── Upsell banner (top) ────────────────────────────── --}}
            @if ($isCompleted)
                <div class="mt-8 overflow-hidden rounded-2xl border border-orange-200 bg-gradient-to-br from-orange-50 to-white p-6 shadow-sm sm:p-7">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="max-w-2xl">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-700">{{ __('This is just the surface') }}</p>
                            <h2 class="mt-2 text-balance text-xl font-semibold tracking-tight text-slate-900 sm:text-2xl">
                                {{ __('See what Google actually knows about your site.') }}
                            </h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                {{ __('This free audit reads your page and benchmarks it against today’s top-ranking competitors. The') }}
                                <span class="font-semibold text-slate-900">{{ __('full audit') }}</span> {{ __('connects your live') }}
                                <span class="font-semibold">{{ __('Search Console') }}</span> + <span class="font-semibold">{{ __('Analytics') }}</span> — {{ __('your real keyword positions, click & impression data, Core Web Vitals, and continuous tracking across every page you own.') }}
                            </p>
                        </div>
                        <div class="flex shrink-0 flex-col gap-2">
                            <a href="{{ route('register') }}" class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">
                                {{ __('Get the full audit — free') }}
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5l7.5 7.5-7.5 7.5M21 12H3" /></svg>
                            </a>
                            <a href="{{ route('pricing') }}" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-900">
                                {{ __('See pricing') }}
                            </a>
                        </div>
                    </div>
                </div>
            @endif

            {{-- ── Body states ────────────────────────────────────── --}}
            <div class="mt-8">
                @if ($isPending)
                    {{-- Working — poll the status endpoint and reload when done. --}}
                    <div id="ga-working" class="flex flex-col items-center justify-center rounded-2xl border border-slate-200 bg-white px-6 py-20 text-center shadow-sm"
                         data-status-url="{{ route('guest-audit.status', $audit) }}">
                        <svg class="h-10 w-10 animate-spin text-orange-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        <h2 class="mt-5 text-lg font-semibold text-slate-900">{{ __('Auditing your page…') }}</h2>
                        <p class="mt-2 max-w-sm text-sm text-slate-600">{{ __('We’re fetching your page, analyzing on-page SEO, checking your keyword, and comparing you against the top-ranking competitors. This usually takes up to a minute.') }}</p>
                    </div>
                    <script>
                        (function () {
                            var el = document.getElementById('ga-working');
                            if (!el) return;
                            var statusUrl = el.getAttribute('data-status-url');
                            var tries = 0;
                            var timer = setInterval(function () {
                                tries++;
                                if (tries > 80) { clearInterval(timer); return; } // ~3.5 min safety stop
                                fetch(statusUrl, { headers: { 'Accept': 'application/json' } })
                                    .then(function (r) { return r.json(); })
                                    .then(function (d) {
                                        if (d.status === 'completed' || d.status === 'failed') {
                                            clearInterval(timer);
                                            window.location.reload();
                                        }
                                    })
                                    .catch(function () {});
                            }, 2500);
                        })();
                    </script>

                @elseif ($isFailed)
                    <div class="flex flex-col items-center justify-center rounded-2xl border border-rose-200 bg-rose-50/60 px-6 py-16 text-center">
                        <span class="flex h-12 w-12 items-center justify-center rounded-full bg-rose-100 text-rose-600">
                            <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
                        </span>
                        <h2 class="mt-5 text-lg font-semibold text-slate-900">{{ __('We couldn’t complete this audit') }}</h2>
                        <p class="mt-2 max-w-md text-sm text-slate-600">{{ $audit->error_message ?? __('Something went wrong. Please check the URL and try again.') }}</p>
                        <a href="{{ route('landing') }}#main" class="mt-6 inline-flex items-center gap-2 rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">
                            {{ __('Try another page') }}
                        </a>
                    </div>

                @else
                    {{-- Completed: reuse the shared audit-report partial. Guest audits have no
                         GSC country data and no download/email toolbar, so both are hidden. --}}
                    @include('livewire.pages.partials.audit-report', [
                        'auditReport' => $audit,
                        'openAuditSummary' => true,
                        'showCountrySection' => false,
                        'showAuditToolbar' => false,
                    ])
                @endif
            </div>

            {{-- ── Upsell (bottom) ────────────────────────────────── --}}
            @if ($isCompleted)
                <div class="mt-10 rounded-2xl border border-slate-200 bg-white px-6 py-10 text-center shadow-sm sm:px-12">
                    <h2 class="text-balance text-2xl font-semibold tracking-tight text-slate-900">{{ __('Ready for the full picture?') }}</h2>
                    <p class="mx-auto mt-3 max-w-xl text-sm leading-6 text-slate-600">
                        {{ __('Connect Search Console + Analytics and Serfix turns this one-page snapshot into a continuous, site-wide growth engine — ranked action lists, anomaly alerts, rank tracking, and reports that prove what shipped.') }}
                    </p>
                    <div class="mt-7 flex flex-col items-center justify-center gap-3 sm:flex-row">
                        <a href="{{ route('register') }}" class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">
                            {{ __('Start free — connect your data') }}
                        </a>
                        <a href="{{ route('features') }}" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-900">
                            {{ __('Explore the product') }}
                        </a>
                    </div>
                </div>
            @endif

        </div>
    </section>
    @if ($teaser ?? false)
        @include('partials.report-teaser-modal', ['redirect' => $teaserRedirect ?? ''])
    @endif
</x-marketing.page>
