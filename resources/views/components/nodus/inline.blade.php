{{--
    <x-nodus.inline> — compact one-line Nodus status chip (mascot + text),
    for inline "working…" / hint rows that sit within a form or list rather
    than a full panel. Mirrors the content-guard chip (competitor-guard.blade).

    Props
      - state: idle | searching | analyzing | success | confused (default searching)
      - size:  mascot px (default 40)
      - tone:  'neutral' (slate, default) | 'brand' (orange chip)

    Text goes in the default slot. Extra attributes pass through
    (add `wire:poll.4s`, `wire:loading`, `wire:target`, etc.):
        <x-nodus.inline wire:loading wire:target="save">{{ __('Saving…') }}</x-nodus.inline>
--}}
@props([
    'state' => 'searching',
    'size' => 40,
    'tone' => 'neutral',
])
@php
    $chip = $tone === 'brand'
        ? 'bg-orange-50 text-orange-800 dark:bg-orange-950 dark:text-orange-200'
        : 'bg-slate-50 text-slate-500 dark:bg-slate-800/60 dark:text-slate-400';
    $mascotTone = $tone === 'brand'
        ? 'text-orange-300 dark:text-orange-800'
        : 'text-slate-400 dark:text-slate-500';
@endphp
<div {{ $attributes->class(['flex items-center gap-2.5 rounded-xl px-4 py-2.5 text-xs font-medium', $chip]) }}>
    <x-nodus :state="$state" :size="$size" :class="'shrink-0 '.$mascotTone"/>
    <span>{{ $slot }}</span>
</div>
