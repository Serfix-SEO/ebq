<x-layouts.app :title="$website->domain.' · '.__('Overview')">
    <div class="space-y-5">
        @if ($justOnboarded)
            <div class="flex items-start gap-3 rounded-xl border border-orange-200 bg-orange-50 p-4 dark:border-orange-500/30 dark:bg-orange-500/10">
                <svg class="mt-0.5 h-5 w-5 flex-none text-orange-600 dark:text-orange-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z" /></svg>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ __('Welcome — here’s :domain', ['domain' => $website->domain]) }}</p>
                    <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-300">{{ __('Your backlink report is below. Meanwhile we’re crawling your site and pulling Search Console / Analytics history in the background — the tabs above fill in as each one finishes, usually within a few minutes.') }}</p>
                </div>
            </div>
        @endif

        <div>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ $website->domain }}</h1>
            <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ __('Everything Serfix found for your site so far.') }}</p>
        </div>

        {{-- Same tab bar embedded on dashboard/statistics/site-explorer — see
             <x-website-tabs>. Plain links (full navigation), so each tab's
             embedded Livewire components mount fresh with their own
             polling/lazy-load behavior rather than living inside one giant
             always-mounted page. --}}
        <x-website-tabs :website="$website" :active="$tab" />

        <div role="tabpanel" class="pt-1">
            @if ($tab === 'explorer')
                <div class="space-y-4">
                    @if ($reportData !== null)
                        @include('reports._status', array_merge($reportData, [
                            'reloadUrl' => route('website-overview', ['tab' => 'explorer']),
                        ]))
                    @else
                        <div class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                            <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Add a domain to this website to see its backlink report.') }}</p>
                        </div>
                    @endif
                </div>

            @elseif ($tab === 'health')
                <div class="space-y-5">
                    <livewire:crawl-banner />
                    <livewire:dashboard.site-health-stats />
                    <livewire:dashboard.priority-action-queue />
                </div>

            @elseif ($tab === 'statistics')
                {{-- Faithful replica of /statistics (same components, same
                     layout/order) — not a re-invented subset. kpi-cards /
                     traffic-chart intentionally mix GA+GSC (dual-source by
                     design) and already degrade gracefully when only one
                     side is connected, so they're never gated here. Only the
                     confirmed single-source blocks (insight-cards +
                     seasonality-card = pure GSC) get a connect-prompt /
                     processing-panel swapped in when that source isn't ready
                     — same signals as the tab pill above, so they can't disagree. --}}
                <div class="space-y-6">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ __('Statistics') }}</h2>
                            <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ __('Overview of your website performance') }}</p>
                        </div>
                        <livewire:dashboard.sync-and-report-panel />
                    </div>

                    <livewire:dashboard.kpi-cards />

                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ __('Action insights') }}</h2>
                        <livewire:dashboard.country-filter />
                    </div>

                    @if ($status['gsc']['state'] === 'needs_action')
                        <x-connect-source-prompt source="gsc" />
                    @elseif ($status['gsc']['state'] === 'processing')
                        <x-overview.processing-panel
                            :title="__('Importing your Search Console data')"
                            :description="__('We’re pulling the last 12 months from Search Console. This usually takes 5–15 minutes — this section fills in automatically.')" />
                    @else
                        <livewire:dashboard.insight-cards />
                    @endif

                    <div class="grid gap-5 lg:grid-cols-3">
                        <div class="lg:col-span-2">
                            @if ($status['traffic']['state'] === 'needs_action')
                                <x-connect-source-prompt source="ga" />
                            @elseif ($status['traffic']['state'] === 'processing')
                                <x-overview.processing-panel
                                    :title="__('Importing your traffic data')"
                                    :description="__('We’re pulling the last 12 months from Google Analytics. This usually takes 5–15 minutes — this section fills in automatically.')" />
                            @else
                                <livewire:dashboard.traffic-chart />
                            @endif
                        </div>
                        <div class="lg:col-span-1 space-y-5">
                            <livewire:dashboard.top-countries-card />
                            @if ($status['gsc']['state'] === 'ready')
                                <livewire:dashboard.seasonality-card />
                            @endif
                            <livewire:dashboard.quick-wins-card />
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>
