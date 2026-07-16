<x-layouts.app :title="__('Explorer').' · '.$domain">
    <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
        {{-- Explore-the-next-domain: the big analyze input sits on top of
             every report so lookups chain naturally (competitor → competitor).
             Submits through /analyze, so per-plan lookup limits apply. --}}
        <div class="mb-6">
            <div class="mb-2 flex items-center justify-between">
                <a href="{{ route('site-explorer') }}" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">← {{ __('Explorer') }}</a>
            </div>
            @include('partials.site-explorer-form', ['placeholder' => __('Analyze another website — e.g. a competitor…')])
        </div>

        {{-- Only when the analyzed domain is one of the user's OWN websites
             (not an arbitrary/competitor lookup) — same nav as
             dashboard/statistics/the /overview hub. --}}
        @if (! empty($website))
            <div class="mb-5">
                <x-website-tabs :website="$website" active="explorer" />
            </div>
        @endif

        @include('reports._status')
    </div>
</x-layouts.app>
