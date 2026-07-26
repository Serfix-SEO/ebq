@props(['website' => null])
@php
    // Falls back to the session's current website (same convention as
    // x-content.connect-gsc) when no explicit site is passed.
    $site = $website
        ?: (session('current_website_id') ? \App\Models\Website::find(session('current_website_id')) : null);
    $hasGa = $site?->hasGa() ?? false;
@endphp

@if ($site && ! $hasGa && \Illuminate\Support\Facades\Route::has('google.redirect'))
    <div class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:flex-row sm:items-center dark:border-slate-800 dark:bg-slate-900">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-900 text-white dark:bg-slate-800">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/></svg>
        </span>
        <div class="min-w-0 flex-1">
            <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Connect Google Analytics') }}</p>
            <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-400">{{ __('Connect Analytics to see how many visitors each published article brings in, day by day.') }}</p>
        </div>
        <a href="{{ route('google.redirect') }}"
           class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
            {{ __('Connect Analytics') }}
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
        </a>
    </div>
@endif
