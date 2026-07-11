{{-- Shared async-generation panel: progress while the queued writer job
     runs (summary + review surfaces), and the failure card with retry.
     Progress % / stages are elapsed-time estimates — the LLM call is one
     opaque request, there is no true per-section signal. --}}

{{-- In-flight --}}
<template x-if="gen.active">
    <div class="rounded-xl border border-orange-200 bg-gradient-to-b from-orange-50/80 to-white p-5 dark:border-orange-500/25 dark:from-orange-500/10 dark:to-slate-900">
        <div class="flex items-start justify-between gap-3">
            <div class="flex items-center gap-3">
                <span class="relative flex h-9 w-9 items-center justify-center">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-orange-400 opacity-20"></span>
                    <span class="relative inline-flex h-9 w-9 items-center justify-center rounded-full bg-orange-600 text-white">
                        <svg class="h-4.5 w-4.5 animate-pulse" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19l7-7 3 3-7 7-3-3z"/><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/><path d="M2 2l7.586 7.586"/><circle cx="11" cy="11" r="2"/></svg>
                    </span>
                </span>
                <div>
                    <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ __('Writing your article…') }}</h4>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Usually 1–3 minutes for a full-length post.') }}</p>
                </div>
            </div>
            <span class="rounded-full bg-white/80 px-2.5 py-1 font-mono text-xs font-semibold text-orange-700 shadow-sm ring-1 ring-orange-200 dark:bg-slate-900/80 dark:text-orange-300 dark:ring-orange-500/30" x-text="genElapsedLabel()"></span>
        </div>

        <div class="mt-4 h-2 overflow-hidden rounded-full bg-orange-100 dark:bg-orange-500/15">
            <div class="h-full rounded-full bg-gradient-to-r from-orange-500 to-orange-600 transition-all duration-1000 ease-out" :style="'width:' + genProgressPct() + '%'"></div>
        </div>

        <ul class="mt-4 space-y-1.5">
            <template x-for="(stage, i) in genStages()" :key="i">
                <li class="flex items-center gap-2 text-sm" :class="i <= genStageIndex() ? 'text-slate-800 dark:text-slate-100' : 'text-slate-400 dark:text-slate-500'">
                    <template x-if="i < genStageIndex()">
                        <svg class="h-4 w-4 shrink-0 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
                    </template>
                    <template x-if="i === genStageIndex()">
                        <svg class="h-4 w-4 shrink-0 animate-spin text-orange-600 dark:text-orange-400" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/><path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8V1.5A10.5 10.5 0 001.5 12H4z"/></svg>
                    </template>
                    <template x-if="i > genStageIndex()">
                        <span class="inline-flex h-4 w-4 shrink-0 items-center justify-center"><span class="h-1.5 w-1.5 rounded-full bg-slate-300 dark:bg-slate-600"></span></span>
                    </template>
                    <span x-text="stage"></span>
                </li>
            </template>
        </ul>

        <p class="mt-4 flex items-center gap-1.5 rounded-lg bg-white/70 px-3 py-2 text-xs text-slate-500 ring-1 ring-slate-200/70 dark:bg-slate-900/60 dark:text-slate-400 dark:ring-slate-700/60">
            <svg class="h-3.5 w-3.5 shrink-0 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            {{ __('You can leave this page — writing continues in the background and the finished article is saved to this project.') }}
        </p>
    </div>
</template>

{{-- Failure --}}
<template x-if="!gen.active && gen.error">
    <div class="flex flex-col gap-3 rounded-xl border border-red-200 bg-red-50 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-red-500/30 dark:bg-red-500/10">
        <div class="flex items-start gap-2.5">
            <svg class="mt-0.5 h-4 w-4 shrink-0 text-red-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
            <p class="text-sm text-red-700 dark:text-red-300" x-text="gen.error"></p>
        </div>
        <button type="button" @click="generateArticle()" class="shrink-0 self-start rounded-lg bg-red-600 px-3.5 py-1.5 text-xs font-semibold text-white transition hover:bg-red-700 sm:self-auto">{{ __('Try again') }}</button>
    </div>
</template>
