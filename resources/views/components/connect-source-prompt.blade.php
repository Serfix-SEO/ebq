@props([
    'source' => 'gsc', // 'ga' | 'gsc'
    'compact' => false,
])
@php
    $isGa = $source === 'ga';
    $label = $isGa ? __('Google Analytics') : __('Search Console');
    $blurb = $isGa
        ? __('Connect Google Analytics to unlock traffic, sessions and source insights here.')
        : __('Connect Search Console to unlock clicks, impressions, rankings and keyword insights here.');
@endphp
<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center rounded-lg border border-dashed border-orange-200 bg-orange-50/40 text-center dark:border-orange-500/30 dark:bg-orange-500/5 '.($compact ? 'px-4 py-5' : 'px-4 py-10')]) }}>
    <svg class="{{ $compact ? 'h-6 w-6' : 'h-8 w-8' }} text-orange-400 dark:text-orange-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m9.86-3.06a4.5 4.5 0 00-1.242-7.244l-4.5-4.5a4.5 4.5 0 00-6.364 6.364L4.34 8.374" />
    </svg>
    <p class="mt-2 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ $label }} {{ __('not connected') }}</p>
    @unless ($compact)
        <p class="mt-1 max-w-md text-xs text-slate-500 dark:text-slate-400">{{ $blurb }}</p>
    @endunless
    <button type="button"
        x-on:click="window.dispatchEvent(new CustomEvent('open-connect-sources'))"
        class="mt-3 inline-flex h-8 items-center gap-1.5 rounded-md bg-orange-600 px-3 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-700">
        {{ __('Connect') }} {{ $label }}
    </button>
</div>
