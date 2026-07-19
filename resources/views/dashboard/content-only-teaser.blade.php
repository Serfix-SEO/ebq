<x-layouts.app>
    {{-- Content-only users: the dashboard reports/crawl surfaces are shown as a
         blurred teaser (their content pipeline still runs a small crawl behind
         the scenes). Upgrade CTA leads to the dashboard plans. --}}
    <div class="relative overflow-hidden rounded-2xl border border-slate-200 dark:border-slate-800">
        {{-- Faint skeleton of a dashboard behind the overlay --}}
        <div class="pointer-events-none select-none p-6 blur sm:p-10" aria-hidden="true" style="max-height: calc(100vh - 11rem)">
            <div class="mx-auto max-w-5xl space-y-6 opacity-60">
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    @foreach ([1,2,3,4] as $i)
                        <div class="h-24 rounded-xl bg-slate-200 dark:bg-slate-800"></div>
                    @endforeach
                </div>
                <div class="h-64 w-full rounded-xl bg-slate-200 dark:bg-slate-800"></div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="h-40 rounded-xl bg-slate-200 dark:bg-slate-800"></div>
                    <div class="h-40 rounded-xl bg-slate-200 dark:bg-slate-800"></div>
                </div>
            </div>
        </div>

        {{-- Overlay upsell card --}}
        <div class="absolute inset-0 flex items-center justify-center overflow-y-auto bg-white/60 p-4 backdrop-blur-sm dark:bg-slate-900/60">
            <div class="my-auto w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-2xl dark:border-slate-800 dark:bg-slate-900">
                <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-orange-100 text-orange-600 dark:bg-orange-500/15 dark:text-orange-300">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.306a11.95 11.95 0 015.814-5.518l2.256-1.011M21.75 6.75v5.25M21.75 6.75h-5.25"/></svg>
                </span>
                <h2 class="mt-4 text-lg font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ __('Full SEO dashboard is a separate plan') }}</h2>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    {{ __('Your Content Autopilot subscription keeps writing and publishing articles. Site audits, backlinks, rank tracking and the rest of the SEO dashboard need a dashboard plan.') }}
                </p>
                <a href="{{ route('billing.show') }}" wire:navigate
                   class="mt-5 inline-flex w-full items-center justify-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                    {{ __('See dashboard plans') }}
                </a>
                <a href="{{ route('content.index') }}" wire:navigate class="mt-3 inline-block text-xs font-medium text-slate-500 hover:text-orange-600 dark:text-slate-400">
                    {{ __('Back to Content Autopilot') }}
                </a>
            </div>
        </div>
    </div>
</x-layouts.app>
