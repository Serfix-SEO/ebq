<x-layouts.app>
    <div class="space-y-6">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight">{{ __('Keyword Research') }}</h1>
                <x-guide-link anchor="keywords" />
            </div>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Discover keyword ideas, check search volume, and find competitor gaps — one surface, with keywords flowing between tabs.') }}</p>
        </div>
        <livewire:crawl-banner />
        <livewire:keywords.keyword-research />
    </div>
</x-layouts.app>
