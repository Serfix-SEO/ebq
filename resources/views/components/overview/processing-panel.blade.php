@props(['title', 'description'])
<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center rounded-lg border border-dashed border-amber-200 bg-amber-50/40 px-4 py-10 text-center dark:border-amber-500/30 dark:bg-amber-500/5']) }}>
    <div class="h-7 w-7 animate-spin rounded-full border-[3px] border-amber-200 border-t-amber-500 dark:border-amber-500/30 dark:border-t-amber-400" aria-hidden="true"></div>
    <p class="mt-3 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ $title }}</p>
    <p class="mt-1 max-w-md text-xs text-slate-500 dark:text-slate-400">{{ $description }}</p>
</div>
