{{--
    Shared "working on it" panel. The spinner is now the Nodus mascot in its
    'analyzing' state (fast orbit, quick pulse) so every async surface that
    reused this component gets the mascot for free. Prop contract UNCHANGED
    (title, description); `state` defaults to 'analyzing' but a caller can pass
    'searching' for slower/idle-ish waits. Extra attributes still merge onto the
    wrapper (callers pass wire:poll / ids / classes as before).
--}}
@props(['title', 'description', 'state' => 'analyzing', 'size' => 72])
<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center rounded-lg border border-dashed border-amber-200 bg-amber-50/40 px-4 py-10 text-center dark:border-amber-500/30 dark:bg-amber-500/5']) }}>
    <x-nodus :state="$state" :size="$size" class="text-orange-400 dark:text-orange-500"/>
    <p class="mt-3 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ $title }}</p>
    <p class="mt-1 max-w-md text-xs text-slate-500 dark:text-slate-400">{{ $description }}</p>
</div>
