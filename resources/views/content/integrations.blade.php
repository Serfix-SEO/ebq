<x-layouts.app>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ __('Integrations') }}</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Connect the platforms your articles publish to.') }}</p>
        </div>
        <livewire:content.publishing-settings />
    </div>
</x-layouts.app>
