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
    <x-nodus state="searching" :size="$compact ? 46 : 72" class="text-slate-400 dark:text-slate-500" />
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
