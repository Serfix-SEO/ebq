<x-layouts.app :title="__('Competitor Gap')">
    <div class="space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-2xl font-bold tracking-tight">{{ __('Competitor Gap') }}</h1>
                    <x-guide-link anchor="keywords" />
                </div>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Keywords your competitors rank for that you don’t — sourced from your Site Explorer competitors.') }}</p>
            </div>
            <a href="{{ route('competitive.competitors') }}"
                class="inline-flex items-center gap-1.5 self-start rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 sm:self-auto">
                {{ __('Find competitors') }} →
            </a>
        </div>
        <livewire:competitive.keyword-gap-analysis />
    </div>
</x-layouts.app>
