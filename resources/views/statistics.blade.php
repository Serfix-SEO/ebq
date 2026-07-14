<x-layouts.app>
    @php $currentWebsite = current_website(); @endphp
    @if ($currentWebsite)
        <x-website-tabs :website="$currentWebsite" active="statistics" class="mb-6" />
    @endif

    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-2xl font-bold tracking-tight">{{ __('Statistics') }}</h1>
                    <x-guide-link anchor="dashboard" />
                </div>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Overview of your website performance') }}</p>
            </div>
            <livewire:dashboard.sync-and-report-panel />
        </div>
        <livewire:dashboard.kpi-cards />
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ __('Action insights') }}</h2>
                <x-guide-link anchor="insight-cards" label="How these work" />
            </div>
            <livewire:dashboard.country-filter />
        </div>
        <livewire:dashboard.insight-cards />
        <div class="grid gap-5 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <livewire:dashboard.traffic-chart />
            </div>
            <div class="lg:col-span-1 space-y-5">
                <livewire:dashboard.top-countries-card />
                <livewire:dashboard.seasonality-card />
                <livewire:dashboard.quick-wins-card />
            </div>
        </div>
    </div>
</x-layouts.app>
