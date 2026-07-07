<x-layouts.app>
    <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-2xl font-bold tracking-tight">{{ __('Rank Tracking') }}</h1>
                    <x-guide-link anchor="rank-tracking" />
                </div>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Track keyword positions on search engines. Auto-refreshes every 12 hours.') }}</p>
            </div>
        </div>
        <livewire:crawl-banner />
        <livewire:rank-tracking.rank-tracking-manager />
    </div>
</x-layouts.app>
