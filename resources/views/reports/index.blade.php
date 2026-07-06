<x-layouts.app>
    <div class="space-y-6"
        x-data="{
            tab: new URL(location.href).searchParams.get('view') || 'insights',
            set(t) {
                this.tab = t;
                const url = new URL(location.href);
                url.searchParams.set('view', t);
                history.replaceState({}, '', url);
            }
        }">
        {{-- Page header --}}
        <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <h1 class="text-2xl font-bold tracking-tight">Reports</h1>
                    <x-guide-link x-show="tab === 'insights'" anchor="insights-panel" />
                    <x-guide-link x-show="tab === 'email'" x-cloak anchor="growth-reports" />
                </div>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Action-list insights and on-demand email reports for the selected website.</p>
            </div>

            {{-- Tab switcher --}}
            <div role="tablist" aria-label="Reports view" class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5 shadow-sm dark:border-slate-700 dark:bg-slate-800">
                <button type="button" role="tab"
                    :aria-selected="tab === 'insights' ? 'true' : 'false'"
                    :class="tab === 'insights' ? 'bg-orange-600 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-slate-100'"
                    @click="set('insights')"
                    class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition">
                    <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg>
                    Insights
                </button>
                <button type="button" role="tab"
                    :aria-selected="tab === 'email' ? 'true' : 'false'"
                    :class="tab === 'email' ? 'bg-orange-600 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-slate-100'"
                    @click="set('email')"
                    class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition">
                    <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                    Custom report
                </button>
            </div>
        </header>

        {{-- Tab panels --}}
        <div role="tabpanel" x-show="tab === 'insights'" x-transition.opacity.duration.150ms>
            <livewire:reports.insights-panel />
        </div>

        <div role="tabpanel" x-show="tab === 'email'" x-transition.opacity.duration.150ms x-cloak>
            <livewire:reports.report-generator />
        </div>
    </div>
</x-layouts.app>
