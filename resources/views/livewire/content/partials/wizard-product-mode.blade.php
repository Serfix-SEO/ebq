{{-- Strict Product Mode choice card (step 7, e-commerce plans only).
     Shared by BOTH wizard hosts (ContentCalendar + PublicOnboarding) — the
     dual-component rule applies: chooseProductMode exists in both.
     Skins: 'wizard' (default, selectable cards feeding chooseProductMode) and
     'banner' (existing active client — one-click activate on the calendar). --}}
@php $skin = $skin ?? 'wizard'; @endphp
@if ($skin === 'banner')
    <div class="flex flex-col gap-3 rounded-2xl border border-orange-200 bg-gradient-to-r from-orange-50 to-white p-4 sm:flex-row sm:items-center dark:border-orange-900 dark:from-orange-950 dark:to-slate-900">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-lg shadow-orange-600/25">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007z"/></svg>
        </span>
        <div class="min-w-0 flex-1">
            <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('New: articles built around your own products') }}</p>
            <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-300">{{ __('We can read your store and write only about products you actually sell — comparisons, buying guides and roundups featuring your catalog.') }}</p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <button type="button" wire:click="activateStrictMode"
                wire:confirm="{{ __('Ground your articles in your products? We\'ll scan your store first, and your unwritten upcoming topics will be rebuilt around your catalog. Published and in-progress articles are not touched.') }}"
                wire:loading.attr="disabled" wire:target="activateStrictMode"
                class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110 disabled:opacity-70">
                <svg wire:loading wire:target="activateStrictMode" class="-ms-1 me-1 h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                {{ __('Ground my articles in my products') }}
            </button>
            <button type="button" wire:click="keepNormalMode" wire:loading.attr="disabled" wire:target="keepNormalMode"
                class="inline-flex items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                {{ __('Keep as is') }}
            </button>
        </div>
    </div>
@else
<div class="mt-6 rounded-2xl border border-orange-200 bg-gradient-to-r from-orange-50 to-white p-5 dark:border-orange-900 dark:from-orange-950 dark:to-slate-900">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('How should we write about products?') }}</p>
            <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-400">{{ __('You run a store — pick how your articles should treat products. You can change this later in Settings.') }}</p>
        </div>
        @if ($productModeChoice === '')
            <span class="shrink-0 rounded-full bg-orange-100 px-2.5 py-1 text-xs font-bold text-orange-700 dark:bg-orange-900 dark:text-orange-300">{{ __('Choose one') }}</span>
        @endif
    </div>
    <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <button type="button" wire:click="chooseProductMode('strict')"
            class="rounded-2xl border-2 p-4 text-start transition {{ $productModeChoice === 'strict' ? 'border-orange-500 bg-white shadow-lg shadow-orange-600/10 dark:bg-slate-900' : 'border-slate-200 bg-white hover:border-orange-300 dark:border-slate-700 dark:bg-slate-800' }}">
            <div class="flex items-center gap-2">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl {{ $productModeChoice === 'strict' ? 'bg-gradient-to-br from-orange-500 to-orange-600 text-white' : 'bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-300' }}">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007z"/></svg>
                </span>
                <span class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Only my products') }}</span>
                <span class="rounded-full bg-orange-100 px-2 py-0.5 text-xs font-bold text-orange-700 dark:bg-orange-900 dark:text-orange-300">{{ __('Recommended') }}</span>
                @if ($productModeChoice === 'strict')
                    <svg class="ms-auto h-5 w-5 shrink-0 text-orange-600 dark:text-orange-400" viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zm4.28 7.28a.75.75 0 00-1.06-1.06l-4.72 4.72-1.72-1.72a.75.75 0 10-1.06 1.06l2.25 2.25a.75.75 0 001.06 0l5.25-5.25z" clip-rule="evenodd"/></svg>
                @endif
            </div>
            <p class="mt-2 text-xs leading-relaxed text-slate-600 dark:text-slate-400">{{ __('We first read your store and collect every product you sell, then build each article around them — buying guides, comparisons between your own products, roundups. Your first topics will be rebuilt around your actual products.') }}</p>
        </button>
        <button type="button" wire:click="chooseProductMode('normal')"
            class="rounded-2xl border-2 p-4 text-start transition {{ $productModeChoice === 'normal' ? 'border-orange-500 bg-white shadow-lg shadow-orange-600/10 dark:bg-slate-900' : 'border-slate-200 bg-white hover:border-orange-300 dark:border-slate-700 dark:bg-slate-800' }}">
            <div class="flex items-center gap-2">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl {{ $productModeChoice === 'normal' ? 'bg-gradient-to-br from-orange-500 to-orange-600 text-white' : 'bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-300' }}">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m-18.432 0A8.959 8.959 0 013 12c0-.778.099-1.533.284-2.253"/></svg>
                </span>
                <span class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Broader topics') }}</span>
                @if ($productModeChoice === 'normal')
                    <svg class="ms-auto h-5 w-5 shrink-0 text-orange-600 dark:text-orange-400" viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zm4.28 7.28a.75.75 0 00-1.06-1.06l-4.72 4.72-1.72-1.72a.75.75 0 10-1.06 1.06l2.25 2.25a.75.75 0 001.06 0l5.25-5.25z" clip-rule="evenodd"/></svg>
                @endif
            </div>
            <p class="mt-2 text-xs leading-relaxed text-slate-600 dark:text-slate-400">{{ __('Articles mix your products with the wider topics your audience searches for — how-tos, trends and common questions in your niche.') }}</p>
        </button>
    </div>
</div>
@endif
