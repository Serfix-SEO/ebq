@props(['website' => null])
@php
    // Falls back to the session's current website (same convention as
    // x-content.connect-integration) when no explicit site is passed.
    $site = $website
        ?: (session('current_website_id') ? \App\Models\Website::find(session('current_website_id')) : null);
    $hasGsc = $site?->hasGsc() ?? false;
@endphp

@if ($site && ! $hasGsc && \Illuminate\Support\Facades\Route::has('google.redirect'))
    <div class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:flex-row sm:items-center dark:border-slate-800 dark:bg-slate-900">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-900 text-white dark:bg-slate-800">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
        </span>
        <div class="min-w-0 flex-1">
            <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Connect Google Search Console') }}</p>
            <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-400">{{ __('Connect Search Console so every new article is automatically submitted to Google for faster indexing. Until then, we can\'t submit your articles for you.') }}</p>
        </div>
        <a href="{{ route('google.redirect') }}"
           class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
            {{ __('Connect Search Console') }}
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
        </a>
    </div>
@endif
