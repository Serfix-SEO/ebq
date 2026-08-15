{{-- One topic card. Shared by the desktop month grid and the mobile agenda
     (below `sm` a 7-column grid squeezes each cell to ~120px, which is what
     made the phone view unreadable), so the two can never drift.

     Expects: $topic, $chipClasses, $publishConnected, $overCapIds, $publishWindowNote?
     Optional: $draggable (bool, default true) — the agenda has no drop targets,
     so dragging there would be a dead gesture. --}}
@php $cardDraggable = $draggable ?? true; @endphp
@php
    $p = \App\Livewire\Content\ContentCalendar::statusPresentation($topic->status);
    $imgPending = \App\Livewire\Content\ContentCalendar::imagesPending($topic);
    $cellInFlight = in_array($topic->status, \App\Models\ContentTopic::IN_FLIGHT, true) || $imgPending;
    $canWrite = ! $cellInFlight && in_array($topic->status, ['suggested', 'approved', 'failed'], true);
    $canDrag = in_array($topic->status, ['suggested', 'approved', 'ready', 'scheduled'], true);
    $overCap = in_array($topic->id, $overCapIds ?? [], true);
    $hero = \App\Livewire\Content\ContentCalendar::heroImage($topic);
    $cellScore = $topic->currentArticle?->seo_score;
    $cellScoreColor = $cellScore >= 80 ? 'emerald' : ($cellScore >= 60 ? 'amber' : 'rose');
    $cellImages = $topic->currentArticle?->images?->count() ?? 0;
    $cellWords = $topic->currentArticle?->word_count ?? 0;
@endphp
{{-- The WHOLE card opens the topic page — people click the
     image and the card body, not just the 2-line title (rage
     clicks, 2026-08-08). The guard skips the card's own
     controls and text selection; drag is untouched because
     the handler fires on plain clicks only. --}}
<div wire:key="cell-{{ $topic->id }}" x-data="{ pick: false, opening: false, newDate: '{{ $topic->scheduled_for?->toDateString() }}' }"
     @if($canDrag && $cardDraggable) draggable="true"
        x-on:dragstart="drag.id = '{{ $topic->id }}'; $event.dataTransfer.setData('text/plain', '{{ $topic->id }}'); $event.dataTransfer.effectAllowed = 'move'"
        x-on:dragend="drag.id = null" @endif
     x-on:click="if (! $event.target.closest('button, a, input, [role=switch]') && ! window.getSelection()?.toString()) { opening = true; window.location = '{{ route('content.review', $topic->id) }}' }"
     x-on:pageshow.window="opening = false"
     role="link" tabindex="0" aria-label="{{ $topic->title }}"
     x-on:keydown.enter="opening = true; window.location = '{{ route('content.review', $topic->id) }}'"
     class="relative mb-1.5 cursor-pointer overflow-hidden rounded-lg border shadow-sm transition-shadow hover:shadow-md hover:border-orange-300 dark:hover:border-orange-500/50 {{ $overCap ? 'border-error/60 bg-error/5 dark:border-error/50' : ($cellInFlight ? 'border-orange-200 bg-orange-50 dark:border-orange-900 dark:bg-orange-950' : 'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800') }} {{ $canDrag ? 'active:cursor-grabbing' : '' }}">
    {{-- Opening feedback: navigation to the article page is not
         instant, and a silent click reads as broken (the rage-
         click report). pageshow resets it when the page is
         restored from the back/forward cache. --}}
    <div x-show="opening" x-cloak class="absolute inset-0 z-10 flex items-center justify-center bg-white/70 dark:bg-slate-900/70">
        <svg class="h-5 w-5 animate-spin text-orange-600" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
    </div>
    {{-- Hero image strip (featured first, else newest) — gradient placeholder when none. --}}
    <div class="relative h-24 w-full bg-gradient-to-br sm:h-14 from-orange-50 to-slate-100 dark:from-orange-950/40 dark:to-slate-800">
        @if ($hero?->url())
            <img src="{{ $hero->url() }}" alt="{{ $hero->alt_text ?? $topic->title }}"
                 loading="lazy" decoding="async" draggable="false"
                 class="h-24 w-full object-cover sm:h-14" onerror="this.remove()">
        @else
            <span class="absolute inset-0 flex items-center justify-center text-slate-400 opacity-40 dark:text-slate-500">
                <svg class="h-7 w-7 sm:h-5 sm:w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.375 19.5h17.25c.621 0 1.125-.504 1.125-1.125V5.625c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v12.75c0 .621.504 1.125 1.125 1.125z"/></svg>
            </span>
        @endif
    </div>
    <div class="p-3 sm:p-1.5">
    @if ($topic->currentArticle || $cellInFlight)
        <a href="{{ route('content.review', $topic->id) }}" wire:navigate draggable="false" class="block hover:opacity-80" x-on:click="opening = true">
            <span class="break-words text-sm font-semibold text-slate-800 line-clamp-2 sm:text-xs dark:text-slate-100">{{ $topic->title }}</span>
        </a>
    @else
        <a href="{{ route('content.review', $topic->id) }}" wire:navigate draggable="false" class="block hover:opacity-80" x-on:click="opening = true">
            <span class="break-words text-sm font-semibold text-slate-800 line-clamp-2 sm:text-xs dark:text-slate-100">{{ $topic->title }}</span>
        </a>
    @endif
    @if ($cellScore || $cellImages > 0 || $cellWords > 0)
        <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[10px] text-slate-400 dark:text-slate-500">
            @if ($cellScore)
                <span class="inline-flex items-center rounded-full px-1.5 py-px font-bold {{ $chipClasses[$cellScoreColor] }}" title="{{ __('SEO score') }}">{{ __('SEO') }} {{ $cellScore }}</span>
            @endif
            @if ($cellWords > 0)
                <span>{{ number_format($cellWords) }} {{ __('words') }}</span>
            @endif
            @if ($cellImages > 0)
                <span class="inline-flex items-center gap-0.5" title="{{ __('Images') }}">
                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.375 19.5h17.25c.621 0 1.125-.504 1.125-1.125V5.625c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v12.75c0 .621.504 1.125 1.125 1.125z"/></svg>
                    {{ $cellImages }}
                </span>
            @endif
        </div>
    @endif
    {{-- Wraps: in a narrow desktop grid cell the status chip + date + Write
     button don't fit on one line and the button used to be cut off. --}}
<div class="mt-1 flex flex-wrap items-center justify-between gap-1">
        @php $chipColor = $imgPending ? 'amber' : $p['color']; @endphp
        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold sm:px-1.5 sm:py-px sm:text-[10px] {{ $chipClasses[$chipColor] ?? $chipClasses['slate'] }}">
            @if ($cellInFlight)
                <svg class="h-2.5 w-2.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
            @endif
            {{ $imgPending ? __('Finalizing images…') : $p['label'] }}
        </span>
        <span class="flex items-center gap-1">
            @if ($canDrag)
                {{-- Calendar icon → inline date picker (move to any day, incl. a 2nd on one day). --}}
                <button type="button" draggable="false" x-on:click="pick = !pick" title="{{ __('Change date') }}"
                        class="inline-flex shrink-0 items-center justify-center rounded-md p-1.5 text-slate-400 hover:text-orange-600 sm:p-0.5">
                    <svg class="h-4 w-4 sm:h-3.5 sm:w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4.5" width="18" height="16.5" rx="2"/><path stroke-linecap="round" d="M3 9h18M8 2.5v4M16 2.5v4"/></svg>
                </button>
            @endif
            @if ($canWrite)
                <button wire:click="writeNow('{{ $topic->id }}')" wire:loading.attr="disabled" wire:target="writeNow('{{ $topic->id }}')"
                        class="inline-flex shrink-0 items-center gap-1 rounded-md bg-orange-600 px-2.5 py-1.5 text-[11px] font-bold text-white hover:bg-orange-700 sm:gap-0.5 sm:px-1.5 sm:py-0.5 sm:text-[10px]" title="{{ __('Write now') }}">
                    <svg class="h-2.5 w-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                    {{ __('Write') }}
                </button>
            @endif
        </span>
    </div>
    @if ($canDrag)
        <div x-show="pick" x-cloak class="mt-1 flex items-center gap-1">
            <input type="date" draggable="false" x-model="newDate" min="{{ now()->toDateString() }}"
                   class="w-full rounded-md border border-slate-200 bg-white px-2 py-1.5 text-xs text-slate-600 sm:px-1.5 sm:py-0.5 sm:text-[10px] dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300" />
            <button type="button" draggable="false" wire:key="gsave-{{ $topic->id }}"
                    x-on:click="$wire.reschedule('{{ $topic->id }}', newDate); pick = false"
                    class="inline-flex shrink-0 items-center justify-center rounded-md bg-orange-600 px-2.5 py-1.5 text-[11px] font-bold text-white hover:bg-orange-700 sm:px-1.5 sm:py-0.5 sm:text-[10px]" title="{{ __('Save date') }}">
                {{ __('Save') }}
            </button>
        </div>
    @endif
    @if ($overCap)
        <p class="mt-1 text-[10px] font-semibold text-error">{{ __('Over monthly limit') }}</p>
    @endif
    @if ($publishConnected && ! $imgPending && \App\Livewire\Content\ContentCalendar::publishableNow($topic))
        <button wire:click="publishNow('{{ $topic->id }}')" wire:confirm="{{ __('Publish this article to your site now?') }}" draggable="false"
                class="mt-2 inline-flex w-full items-center justify-center gap-1 rounded-md bg-success px-1.5 py-1.5 text-[11px] font-bold text-white hover:brightness-110 sm:mt-1 sm:py-0.5 sm:text-[10px]">
            <svg class="h-2.5 w-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
            {{ __('Publish now') }}
        </button>
    @endif
    @if ($publishConnected && ! $imgPending && $topic->status === \App\Models\ContentTopic::STATUS_PUBLISHED && $topic->currentArticle)
        <button wire:click="republish('{{ $topic->id }}')" wire:confirm="{{ __('Send this article to your site again? Destinations that already have it get the latest version.') }}" draggable="false"
                title="{{ __('Republish to your site') }}"
                class="mt-2 inline-flex items-center gap-1 rounded-md px-1 py-1 text-[11px] font-semibold text-slate-400 hover:text-orange-600 sm:mt-1 sm:py-0.5 sm:text-[10px] dark:text-slate-500 dark:hover:text-orange-400">
            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992V4.356m-4.992 4.992l3.181-3.183a8.25 8.25 0 00-13.803 3.7M4.031 9.865v4.99m0 0h4.99m-4.99 0l3.181 3.183a8.25 8.25 0 0013.803-3.7"/></svg>
            {{ __('Republish') }}
        </button>
    @endif
    </div>
</div>
