<x-layouts.app :title="__('Explorer')">
    {{-- No tab bar here on purpose — this is a generic "analyze ANY domain"
         tool, not scoped to one of the user's own websites. It would be
         misleading to show nav tied to whatever website happens to be
         session-pinned before the user has even typed a URL. The nav
         appears on the RESULT page (reports/view.blade.php) once the
         analyzed domain is confirmed to be one of the user's own sites. --}}
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="text-center">
            <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">{{ __('Explorer') }}</h1>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ __('Analyze any website’s backlink profile, authority & competitors.') }}</p>
        </div>

        <div class="mt-6">
            @include('partials.site-explorer-form')
        </div>

        @auth
            <livewire:site-explorer-history />
        @endauth
    </div>

</x-layouts.app>
