<x-layouts.app>
    <div class="space-y-6">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight">{{ __('Competitor Discovery') }}</h1>
                <x-guide-link anchor="keywords" />
            </div>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ __('Find who competes with any website in search — even brand-new sites with no backlink data. Powered by the site’s own keywords and live search results.') }}
            </p>
        </div>
        <livewire:competitive.competitor-finder />
    </div>
</x-layouts.app>
