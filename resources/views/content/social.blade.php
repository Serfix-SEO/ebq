{{--
    Auto-share — its OWN page under Content (moved off Integrations 2026-07-31).
    Integrations is about where articles PUBLISH to; this is what happens after
    they are live, and burying it under the WordPress/webhook setup meant nobody
    found it.

    The nav item and this page share one gate — SocialPoster::anyProviderConfigured()
    — so the link can never lead to an empty screen. Reached directly with no
    provider configured (or the kill switch off), it explains itself instead of
    rendering nothing.
--}}
<x-layouts.app>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ __('Auto-share') }}</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ __('Connect your accounts once. When an article goes live, we post its link for you.') }}
            </p>
        </div>

        @if (\App\Services\Content\Social\SocialPoster::anyProviderConfigured())
            <livewire:content.social-share-settings />
        @else
            <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center dark:border-slate-800 dark:bg-slate-900">
                <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 100 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186l9.566-5.314m-9.566 7.5l9.566 5.314m0 0a2.25 2.25 0 103.935 2.186 2.25 2.25 0 00-3.935-2.186zm0-12.814a2.25 2.25 0 103.933-2.185 2.25 2.25 0 00-3.933 2.185z"/></svg>
                </span>
                <h2 class="mt-4 text-base font-bold text-slate-900 dark:text-slate-100">{{ __('Coming soon') }}</h2>
                <p class="mx-auto mt-1.5 max-w-md text-sm text-slate-500 dark:text-slate-400">
                    {{ __('Automatic sharing of your published articles isn\'t available on your account yet. Your articles keep publishing as normal.') }}
                </p>
            </div>
        @endif
    </div>
</x-layouts.app>
