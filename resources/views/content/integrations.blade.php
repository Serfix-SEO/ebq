<x-layouts.app>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ __('Integrations') }}</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Connect the platforms your articles publish to.') }}</p>
        </div>

        {{-- WordPress plugin download --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-slate-900 text-white dark:bg-slate-800">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zM3.01 12c0-1.3.28-2.53.78-3.65l4.29 11.75A8.98 8.98 0 013.01 12zM12 20.99c-.88 0-1.73-.13-2.54-.37l2.7-7.84 2.76 7.57c.02.04.04.09.06.12-.93.33-1.94.52-2.98.52zm1.24-13.2c.54-.03 1.03-.09 1.03-.09.48-.06.43-.77-.06-.74 0 0-1.46.11-2.4.11-.88 0-2.37-.11-2.37-.11-.48-.03-.54.71-.05.74 0 0 .46.06.95.09l1.41 3.87-1.98 5.95-3.3-9.82c.54-.03 1.03-.09 1.03-.09.48-.06.43-.77-.06-.74 0 0-1.46.11-2.4.11-.17 0-.37 0-.58-.01A8.99 8.99 0 0112 3.01c2.35 0 4.49.9 6.09 2.37-.04 0-.08-.01-.12-.01-.88 0-1.51.77-1.51 1.6 0 .74.43 1.37.88 2.11.34.6.74 1.37.74 2.48 0 .77-.29 1.66-.69 2.9l-.9 3.01-3.25-9.68zm5.65 8.51c1.11-1.65 1.61-3.02 1.61-4.31 0-.46-.03-.9-.09-1.32.4.73.62 1.56.62 2.44 0 1.87-.86 3.54-2.14 4.51z"/></svg>
                </span>
                <div class="min-w-0 flex-1">
                    <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Get more from your WordPress connection') }}</h2>
                    <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ __('Auto-publishing already works via WordPress Application Passwords below — no plugin required. Install our free plugin too for extra SEO benefits: automatic on-page meta sync (title, description, focus keyword) for every article.') }}</p>
                </div>
                <a href="{{ route('wordpress.plugin.download') }}" download
                   class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14"/></svg>
                    {{ __('Download plugin') }}
                </a>
            </div>
        </div>

        <livewire:content.publishing-settings />
    </div>
</x-layouts.app>
