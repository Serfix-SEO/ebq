{{--
    Shared empty-state card. Now fronted by the Nodus mascot (was a warning
    triangle) so empty states across the app read as "nothing here yet" rather
    than "error". Prop contract UNCHANGED (title, body) — every existing caller
    inherits the mascot with no edit. `state` lets a caller pick the mood:
    'confused' (default, no data) / 'searching' (looking) / 'success' (all clear).
--}}
@props(['title', 'body' => null, 'state' => 'confused', 'size' => 72])
<div class="flex flex-col items-center justify-center rounded-lg border border-dashed border-slate-200 bg-slate-50/60 px-4 py-10 text-center dark:border-slate-800 dark:bg-slate-900/40">
    <x-nodus :state="$state" :size="$size" class="text-slate-400 dark:text-slate-500"/>
    <p class="mt-2 text-sm font-medium text-slate-600 dark:text-slate-300">{{ $title }}</p>
    @if ($body)
        <p class="mt-1 max-w-md text-xs text-slate-500 dark:text-slate-400">{{ $body }}</p>
    @endif
</div>
