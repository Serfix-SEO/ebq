{{-- Strict Product Mode: full-screen live catalog-build progress.
     Shown by ContentCalendar::render() while the product scan is in flight
     (or failed with an empty catalog). Polls the run row for live counters
     and streams product thumbnails in as they are extracted. --}}
@php
    $run = $catalogProgress['run'] ?? null;
    $count = (int) ($catalogProgress['count'] ?? 0);
    $products = $catalogProgress['products'] ?? collect();
    $status = $run?->status;
    $failed = $status === \App\Models\ContentProductRun::STATUS_FAILED || $run === null;
    // Stage index: 0 reading, 1 collecting, 2 organizing, 3 planning (never
    // reached here — finalize flips ready and the calendar takes over).
    $stageIdx = match ($status) {
        \App\Models\ContentProductRun::STATUS_PENDING, \App\Models\ContentProductRun::STATUS_DISCOVERING => 0,
        \App\Models\ContentProductRun::STATUS_EXTRACTING => 1,
        \App\Models\ContentProductRun::STATUS_FINALIZING => 2,
        default => 0,
    };
    $stages = [
        __('Reading your store'),
        __('Collecting your products'),
        __('Organizing your catalog'),
        __('Planning your articles'),
    ];
@endphp
<div @unless($failed) wire:poll.4s @endunless class="mx-auto max-w-3xl">
    <div class="rounded-3xl border border-slate-200 bg-white p-8 shadow-xl sm:p-10 dark:border-slate-800 dark:bg-slate-900">
        @if ($failed)
            <div class="text-center">
                <x-nodus state="confused" :size="96" class="mx-auto mb-3 text-slate-400 dark:text-slate-500"/>
                <h2 class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ __('We couldn\'t collect your products') }}</h2>
                <p class="mx-auto mt-2 max-w-md text-sm text-slate-500 dark:text-slate-400">
                    {{ __('We tried to read your store but couldn\'t build a product list. This can happen when product pages block automated visits or use an unusual layout.') }}
                </p>
                <div class="mt-6 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <button wire:click="retryCatalogScan" wire:loading.attr="disabled" wire:target="retryCatalogScan"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110 disabled:opacity-70">
                        <svg wire:loading wire:target="retryCatalogScan" class="-ms-1 me-1 h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                        {{ __('Try again') }}
                    </button>
                    <button wire:click="useNormalInstead" wire:confirm="{{ __('Switch to broader topics? Articles will cover your niche without being limited to your product list. You can switch back anytime in Settings.') }}"
                        wire:loading.attr="disabled" wire:target="useNormalInstead"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-6 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                        {{ __('Continue with broader topics') }}
                    </button>
                </div>
            </div>
        @else
            <div class="text-center">
                <x-nodus state="searching" :size="96" class="mx-auto mb-3 text-slate-400 dark:text-slate-500"/>
                <p class="text-xs font-bold uppercase tracking-wide text-orange-600 dark:text-orange-400">{{ __('Setting up your product-grounded articles') }}</p>
                <h2 class="mt-1 text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ __('We\'re reading your store') }}</h2>
                <p class="mx-auto mt-2 max-w-md text-sm text-slate-500 dark:text-slate-400">
                    {{ __('Every product we find here becomes material for your articles — comparisons, buying guides and roundups built around what you actually sell.') }}
                </p>
            </div>

            {{-- Staged checklist --}}
            <div class="mx-auto mt-8 max-w-sm space-y-3">
                @foreach ($stages as $i => $label)
                    <div class="flex items-center gap-3">
                        @if ($i < $stageIdx)
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-success/10 text-success">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                            </span>
                            <span class="text-sm font-semibold text-slate-700 dark:text-slate-300">{{ $label }}</span>
                        @elseif ($i === $stageIdx)
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-orange-100 dark:bg-orange-950">
                                <svg class="h-4 w-4 animate-spin text-orange-600 dark:text-orange-400" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                            </span>
                            <span class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ $label }}…</span>
                            @if ($i === 0 && ($run?->pages_found ?? 0) > 0)
                                <span class="ms-auto text-xs font-semibold text-slate-500 dark:text-slate-400">{{ trans_choice(':n page found|:n pages found', $run->pages_found, ['n' => number_format($run->pages_found)]) }}</span>
                            @elseif ($i === 1)
                                <span class="ms-auto text-xs font-semibold text-orange-600 dark:text-orange-400">{{ trans_choice(':n product|:n products', $run?->products_extracted ?? 0, ['n' => number_format($run?->products_extracted ?? 0)]) }}</span>
                            @endif
                        @else
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border-2 border-slate-200 dark:border-slate-700"></span>
                            <span class="text-sm text-slate-400 dark:text-slate-500">{{ $label }}</span>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Product thumbnails streaming in --}}
            @if ($products->isNotEmpty())
                <div class="mt-8">
                    <p class="text-center text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ __('Found so far') }}</p>
                    <div class="mt-3 grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-6">
                        @foreach ($products as $p)
                            <div wire:key="cp-{{ $p->id }}" class="overflow-hidden rounded-xl border border-slate-200 bg-slate-50 dark:border-slate-800 dark:bg-slate-800/40">
                                <img src="{{ $p->image_url }}" alt="" loading="lazy" class="aspect-square w-full object-cover"/>
                                <p dir="auto" class="truncate px-2 py-1.5 text-center text-xs font-medium text-slate-600 dark:text-slate-300">{{ $p->name }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <p class="mt-8 text-center text-xs text-slate-400 dark:text-slate-500">
                {{ __('This usually takes a few minutes for most stores — large catalogs can take longer. You can leave this page; we\'ll keep working in the background.') }}
            </p>
        @endif
    </div>
</div>
