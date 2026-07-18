<x-layouts.app>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ __('Content Settings') }}</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Your business profile, offerings, and how the content calendar works.') }}</p>
        </div>
        <livewire:content.content-calendar mode="settings" />
    </div>
</x-layouts.app>
