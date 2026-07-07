<x-layouts.app>
    {{-- One-shot welcome modal shown after onboarding completes. The
         flash key is set by Livewire\Onboarding\ConnectGoogle::saveWebsite,
         so it appears exactly once after the connect-and-save step and
         never again. Pure Alpine, no Livewire — the modal is static info.
         User can dismiss with X, "Got it", Escape, or backdrop click. --}}
    @if (session('just_onboarded'))
        <div
            x-data="{ open: true }"
            x-show="open"
            x-cloak
            x-on:keydown.escape.window="open = false"
            class="fixed inset-0 z-50 flex items-center justify-center px-4"
        >
            {{-- Backdrop --}}
            <div
                x-show="open"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm"
                x-on:click="open = false"
            ></div>

            {{-- Modal --}}
            <div
                x-show="open"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 translate-y-4 scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                class="relative w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700"
                role="dialog"
                aria-modal="true"
                aria-labelledby="onboarded-title"
            >
                <button
                    type="button"
                    x-on:click="open = false"
                    class="absolute right-4 top-4 rounded-md p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 focus:outline-none focus:ring-2 focus:ring-orange-500 dark:hover:bg-slate-700 dark:hover:text-slate-200"
                    aria-label="{{ __('Close') }}"
                >
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                    </svg>
                </button>

                <div class="p-7">
                    <div class="flex items-start gap-4">
                        <div class="flex h-11 w-11 flex-none items-center justify-center rounded-full bg-orange-100 text-orange-600 dark:bg-orange-500/15 dark:text-orange-300">
                            <svg class="h-5 w-5 animate-pulse" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M21 12a9 9 0 11-6.219-8.56"/>
                            </svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 id="onboarded-title" class="text-lg font-semibold text-slate-900 dark:text-white">
                                {{ __('Your dashboard will fill in shortly') }}
                            </h2>
                            <p class="mt-1.5 text-sm leading-6 text-slate-600 dark:text-slate-300">
                                {{ __("We're pulling the last 12 months of data from Google Search Console and Google Analytics. Most accounts finish in") }} <strong>{{ __('5 to 15 minutes') }}</strong>; {{ __('larger sites with extensive history can take up to an hour.') }}
                            </p>
                        </div>
                    </div>

                    <ul class="mt-5 space-y-2.5 text-sm text-slate-600 dark:text-slate-300">
                        <li class="flex gap-2.5">
                            <svg class="mt-0.5 h-4 w-4 flex-none text-emerald-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                            </svg>
                            <span><strong class="font-medium text-slate-900 dark:text-white">{{ __('Search Console:') }}</strong> {{ __('queries, clicks, impressions, average position, and indexing status.') }}</span>
                        </li>
                        <li class="flex gap-2.5">
                            <svg class="mt-0.5 h-4 w-4 flex-none text-emerald-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                            </svg>
                            <span><strong class="font-medium text-slate-900 dark:text-white">{{ __('Google Analytics 4:') }}</strong> {{ __('sessions, users, conversions, and traffic sources for the same window.') }}</span>
                        </li>
                        <li class="flex gap-2.5">
                            <svg class="mt-0.5 h-4 w-4 flex-none text-emerald-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                            </svg>
                            <span>{{ __('You can leave this page and come back. Data fills in automatically as it lands.') }}</span>
                        </li>
                        <li class="flex gap-2.5">
                            <svg class="mt-0.5 h-4 w-4 flex-none text-emerald-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                            </svg>
                            <span>{{ __('Live numbers appear on the') }} <strong class="font-medium text-slate-900 dark:text-white">{{ __('Statistics') }}</strong> {{ __('page once the initial pull completes.') }}</span>
                        </li>
                    </ul>

                    <div class="mt-6 rounded-lg bg-slate-50 p-3.5 text-xs leading-5 text-slate-500 dark:bg-slate-700/40 dark:text-slate-400">
                        {{ __('Empty cards or zeroes you see right now are normal during the first sync. Refresh in a few minutes to see live numbers.') }}
                    </div>

                    <div class="mt-6 flex items-center justify-end gap-2">
                        <button
                            type="button"
                            x-on:click="open = false"
                            class="inline-flex items-center rounded-md bg-orange-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2"
                        >
                            {{ __('Got it') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-2xl font-bold tracking-tight">{{ __('Dashboard') }}</h1>
                </div>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Your highest-impact SEO actions, ranked') }}</p>
            </div>
            <livewire:dashboard.country-filter />
        </div>

        {{-- Prominent banner while a crawl is in progress for the current site.
             Polls and self-dismisses once the CrawlRun finishes; also drives the
             hide/reappear of the crawl-derived widgets below. --}}
        <livewire:crawl-banner />

        {{-- Prominent nudge for sourceless sites (no GSC + no sitemap): add a
             sitemap inline so the dashboard has something to analyse. --}}
        <livewire:dashboard.sitemap-prompt />

        {{-- Crawl-derived Site Health summary (full per-page detail lives on the
             Link Structure page; detailed issues drill down in the queue below). --}}
        <livewire:dashboard.site-health-stats />

        {{-- Actionable widgets stack here. Priority Action Queue is the first;
             more widgets will be added below it over time. --}}
        <livewire:dashboard.priority-action-queue />
    </div>
</x-layouts.app>
