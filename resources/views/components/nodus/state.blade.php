{{--
    <x-nodus.state> — the standard Nodus "panel": a centered card for
    loading / empty / success / error states, site-wide. Distilled from the
    content-onboarding wizard patterns (the quality bar) so every surface uses
    ONE consistent block instead of hand-rolled markup.

    Props
      - state:   idle | searching | analyzing | success | confused
                 (passed straight to <x-nodus>; picks the mascot's motion).
      - title:   bold headline (optional).
      - message: muted sub-line, max-w-md centered (optional).
      - size:    mascot px (default 80).
      - bordered:solid border + card bg (default true). false → bare, for
                 places that already sit inside a bordered container.
      - tone:    'neutral' (slate mascot, default) | 'brand' (orange mascot,
                 for success/celebratory moments).

    Extra attributes pass through to the wrapper, so callers add
    `wire:poll.4s`, `wire:loading`, `wire:target=…`, ids, etc. directly:
        <x-nodus.state state="analyzing" wire:poll.5s="refresh"
            :title="__('Analyzing…')" />

    Default slot renders below the message (action buttons / links).

    Self-contained: relies only on <x-nodus> (inline SVG) + utility classes
    already compiled in the app bundle (border/bg/text/flex/rounded — all in
    heavy use across the dashboard, so guaranteed present).
--}}
@props([
    'state' => 'searching',
    'title' => null,
    'message' => null,
    'size' => 80,
    'bordered' => true,
    'tone' => 'neutral',
])
@php
    $mascotTone = $tone === 'brand'
        ? 'text-orange-400 dark:text-orange-500'
        : 'text-slate-400 dark:text-slate-500';
    $shell = $bordered
        ? 'border border-slate-200 bg-slate-50 dark:border-slate-800 dark:bg-slate-800/40'
        : '';
@endphp
<div {{ $attributes->class(['flex flex-col items-center gap-2 rounded-2xl p-8 text-center', $shell]) }}>
    <x-nodus :state="$state" :size="$size" :class="$mascotTone"/>

    @if ($title)
        <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ $title }}</p>
    @endif

    @if ($message)
        <p class="mx-auto max-w-md text-sm text-slate-500 dark:text-slate-400">{{ $message }}</p>
    @endif

    @if (trim($slot) !== '')
        <div class="mt-3">{{ $slot }}</div>
    @endif
</div>
