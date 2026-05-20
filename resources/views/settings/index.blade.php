<x-layouts.app>
    <div class="space-y-6" x-data="{ tab: 'account' }">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Settings</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Manage your account, integrations, and report preferences</p>
        </div>

        <div class="mx-auto max-w-2xl space-y-6">
            {{-- Segmented pill tabs --}}
            <nav class="flex gap-1 rounded-2xl border border-slate-200/80 bg-slate-100/90 p-1 shadow-inner dark:border-slate-700/80 dark:bg-slate-800/60" role="tablist" aria-label="Settings tabs">
                <button type="button" role="tab" @click="tab = 'account'" :aria-selected="tab === 'account'"
                    :class="tab === 'account'
                        ? 'bg-white text-indigo-700 shadow-sm ring-1 ring-slate-200/80 dark:bg-slate-900 dark:text-indigo-300 dark:ring-slate-600/60'
                        : 'text-slate-600 hover:bg-white/60 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-700/50 dark:hover:text-slate-200'"
                    class="flex min-w-0 flex-1 items-center justify-center gap-2 rounded-xl px-3 py-2.5 text-sm font-semibold transition duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-100 dark:focus-visible:ring-offset-slate-800">
                    <svg class="h-4 w-4 shrink-0 opacity-90" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                    <span class="truncate">Account</span>
                </button>
                <button type="button" role="tab" @click="tab = 'integrations'" :aria-selected="tab === 'integrations'"
                    :class="tab === 'integrations'
                        ? 'bg-white text-indigo-700 shadow-sm ring-1 ring-slate-200/80 dark:bg-slate-900 dark:text-indigo-300 dark:ring-slate-600/60'
                        : 'text-slate-600 hover:bg-white/60 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-700/50 dark:hover:text-slate-200'"
                    class="flex min-w-0 flex-1 items-center justify-center gap-2 rounded-xl px-3 py-2.5 text-sm font-semibold transition duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-100 dark:focus-visible:ring-offset-slate-800">
                    <svg class="h-4 w-4 shrink-0 opacity-90" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" /></svg>
                    <span class="truncate">Integrations</span>
                </button>
                <button type="button" role="tab" @click="tab = 'reports'" :aria-selected="tab === 'reports'"
                    :class="tab === 'reports'
                        ? 'bg-white text-indigo-700 shadow-sm ring-1 ring-slate-200/80 dark:bg-slate-900 dark:text-indigo-300 dark:ring-slate-600/60'
                        : 'text-slate-600 hover:bg-white/60 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-700/50 dark:hover:text-slate-200'"
                    class="flex min-w-0 flex-1 items-center justify-center gap-2 rounded-xl px-3 py-2.5 text-sm font-semibold transition duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-100 dark:focus-visible:ring-offset-slate-800">
                    <svg class="h-4 w-4 shrink-0 opacity-90" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3v1.5M3 21v-6m0 0 2.77-.693a9 9 0 0 1 6.208.682l.108.054a9 9 0 0 0 6.086.71l3.114-.732a48.524 48.524 0 0 1-.005-10.499l-3.11.732a9 9 0 0 1-6.085-.711l-.108-.054a9 9 0 0 0-6.208-.682L3 4.5M3 15V4.5" /></svg>
                    <span class="truncate">Reports</span>
                </button>
            </nav>

            {{-- Tab panels --}}
            <div x-show="tab === 'account'" x-cloak>
                <livewire:settings.profile-settings />
            </div>

            <div x-show="tab === 'integrations'" x-cloak class="space-y-6">
                <livewire:settings.integrations-panel />
                <livewire:settings.word-press-plugin />
            </div>

            <div x-show="tab === 'reports'" x-cloak class="space-y-6">
                <livewire:settings.report-recipients />
                <livewire:settings.report-branding />
                <livewire:settings.mail-transport />
                <livewire:settings.gsc-keyword-window />
            </div>
        </div>
    </div>
</x-layouts.app>
