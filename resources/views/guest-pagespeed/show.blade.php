@php
    $isCompleted = $report->status === \App\Models\GuestPageSpeed::STATUS_COMPLETED;
    $isFailed = $report->status === \App\Models\GuestPageSpeed::STATUS_FAILED;
    $isPending = ! $isCompleted && ! $isFailed;
    $host = \Illuminate\Support\Str::after($report->url, '://');
@endphp
<x-marketing.page
    title="PageSpeed report — {{ \Illuminate\Support\Str::limit($host, 60) }}"
    description="Free PageSpeed & Core Web Vitals report by Serfix."
    robots="noindex, follow"
>
    <section class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:py-14">
        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-orange-600">{{ __('Free PageSpeed report') }}</p>
                <h1 class="mt-1 truncate text-2xl font-bold tracking-tight text-slate-900">{{ $host }}</h1>
            </div>
            <a href="{{ route('tools.pagespeed') }}" class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992V4.356M2.985 19.644v-4.992h4.992m9.348-4.5a8.25 8.25 0 00-14.348-3.348L2.985 9.644m0 0H7.5m9.348 4.5a8.25 8.25 0 01-14.348 3.348L18.015 14.652m0 0H13.5" /></svg>
                {{ __('Test another URL') }}
            </a>
        </div>

        @if ($isPending)
            <div class="flex flex-col items-center justify-center rounded-2xl border border-slate-200 bg-white px-6 py-16 text-center shadow-sm"
                 id="ps-status" data-status-url="{{ route('guest-pagespeed.status', $report) }}">
                <svg class="h-8 w-8 animate-spin text-orange-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                <h2 class="mt-4 text-lg font-semibold text-slate-900">{{ __('Running your PageSpeed test…') }}</h2>
                <p class="mt-2 max-w-sm text-sm text-slate-600">{{ __('We’re auditing the page on mobile and desktop — performance, accessibility, best practices and SEO. This usually takes up to a minute.') }}</p>
            </div>
            <script>
                (function () {
                    var el = document.getElementById('ps-status');
                    if (!el) return;
                    var url = el.getAttribute('data-status-url');
                    var t = setInterval(function () {
                        fetch(url, { headers: { 'Accept': 'application/json' } })
                            .then(function (r) { return r.json(); })
                            .then(function (d) {
                                if (d.status === 'completed' || d.status === 'failed') {
                                    clearInterval(t);
                                    window.location.reload();
                                }
                            })
                            .catch(function () {});
                    }, 3000);
                })();
            </script>
        @elseif ($isFailed)
            <div class="flex flex-col items-center justify-center rounded-2xl border border-rose-200 bg-rose-50 px-6 py-16 text-center">
                <svg class="h-8 w-8 text-rose-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
                <h2 class="mt-4 text-lg font-semibold text-slate-900">{{ __('We couldn’t measure that page') }}</h2>
                <p class="mt-2 max-w-sm text-sm text-slate-600">{{ $report->error_message ?: __('The site may have been slow or unreachable. Please try again.') }}</p>
                <a href="{{ route('tools.pagespeed') }}" class="mt-6 inline-flex items-center gap-2 rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">{{ __('Try another URL →') }}</a>
            </div>
        @else
            @include('partials.page-speed-report', ['result' => $report->result ?? [], 'testedUrl' => $report->url])

            {{-- Signup CTA --}}
            <div class="mt-8 overflow-hidden rounded-2xl border border-orange-200 bg-orange-50 p-6 sm:p-8">
                <div class="flex flex-col items-start gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-lg font-bold tracking-tight text-slate-900">{{ __('Track this — and fix it — continuously') }}</h2>
                        <p class="mt-1.5 max-w-xl text-sm leading-6 text-slate-600">{{ __('Create a free account to monitor Core Web Vitals over time, run full SEO audits, and connect Search Console & Analytics for live keyword and traffic data.') }}</p>
                    </div>
                    <a href="{{ route('register') }}" class="inline-flex shrink-0 items-center gap-2 rounded-xl bg-orange-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-orange-600/25 transition hover:bg-orange-700">
                        {{ __('Start free →') }}
                    </a>
                </div>
            </div>
        @endif
    </section>
    @if ($teaser ?? false)
        @include('partials.report-teaser-modal', ['redirect' => $teaserRedirect ?? ''])
    @endif
</x-marketing.page>
