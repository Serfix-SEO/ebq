<x-layouts.app>
    <div class="space-y-6">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight">{{ __('Content Calendar') }}</h1>
            </div>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Your automatic content calendar: articles planned, written, and checked for you') }}</p>
        </div>
        <livewire:content.content-calendar mode="calendar" />
    </div>
</x-layouts.app>
