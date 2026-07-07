<x-layouts.app>
    <div class="space-y-6">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight">{{ __('Keywords') }}</h1>
                <x-guide-link anchor="keywords" />
            </div>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Search queries driving traffic to your site') }}</p>
        </div>
        <livewire:crawl-banner />
        <livewire:keywords.keywords-table />
    </div>
</x-layouts.app>
