<x-layouts.app>
    {{-- The post-onboarding welcome banner now lives on the overview hub
         (website-overview.blade.php) — ConnectGoogle::finishOnboarding()
         redirects there, not here, so the `just_onboarded` flash never
         reaches this page. --}}

    @php $currentWebsite = current_website(); @endphp
    @if ($currentWebsite)
        <x-website-tabs :website="$currentWebsite" active="health" class="mb-6" />
    @endif

    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-2xl font-bold tracking-tight">{{ __('Dashboard') }}</h1>
                </div>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Your highest-impact SEO actions, ranked') }}</p>
            </div>
            <livewire:dashboard.country-filter />
        </div>

        {{-- Prominent banner while a crawl is in progress for the current site.
             Polls and self-dismisses once the CrawlRun finishes; also drives the
             hide/reappear of the crawl-derived widgets below. --}}
        <livewire:crawl-banner />

        {{-- Prominent nudge for sourceless sites (no GSC + no sitemap): add a
             sitemap inline so the dashboard has something to analyse. --}}
        <livewire:dashboard.sitemap-prompt />

        {{-- Crawl-derived Site Health summary (full per-page detail lives on the
             Link Structure page; detailed issues drill down in the queue below). --}}
        <livewire:dashboard.site-health-stats />

        {{-- Actionable widgets stack here. Priority Action Queue is the first;
             more widgets will be added below it over time. --}}
        <livewire:dashboard.priority-action-queue />
    </div>
</x-layouts.app>
