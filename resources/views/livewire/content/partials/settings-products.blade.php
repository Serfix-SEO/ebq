{{-- Settings → Products: catalog review + product-mode switch.
     $productsTab comes from ContentCalendar::productsTabData() (null hides
     the tab entirely — this partial is only included when it is set). --}}
@php
    $pt = $productsTab;
    $mode = $pt['mode'];
    $isStrict = $mode === \App\Models\ContentPlan::PRODUCT_MODE_STRICT;
@endphp
<div class="space-y-5">
    {{-- Mode switch --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('How articles treat products') }}</h2>
        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('Strict grounds every article in your own catalog. Broader mixes your products with wider topics your audience searches for.') }}</p>
        <div class="mt-3 flex flex-wrap items-center gap-2">
            @if ($isStrict)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-orange-100 px-3 py-1.5 text-xs font-bold text-orange-700 dark:bg-orange-950 dark:text-orange-300">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zm4.28 7.28a.75.75 0 00-1.06-1.06l-4.72 4.72-1.72-1.72a.75.75 0 10-1.06 1.06l2.25 2.25a.75.75 0 001.06 0l5.25-5.25z" clip-rule="evenodd"/></svg>
                    {{ __('Only my products') }}
                </span>
                <button type="button" wire:click="setProductMode('normal')"
                    wire:confirm="{{ __('Switch to broader topics? Articles will cover your niche without being limited to your product list. You can switch back anytime.') }}"
                    wire:loading.attr="disabled" wire:target="setProductMode"
                    class="rounded-xl border border-slate-300 px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                    {{ __('Switch to broader topics') }}
                </button>
            @elseif ($mode === \App\Models\ContentPlan::PRODUCT_MODE_NORMAL)
                <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ __('Broader topics') }}</span>
                <button type="button" wire:click="setProductMode('strict')"
                    wire:confirm="{{ __('Ground your articles in your products? We\'ll scan your store first, and your unwritten upcoming topics will be rebuilt around your catalog. Published and in-progress articles are not touched.') }}"
                    wire:loading.attr="disabled" wire:target="setProductMode"
                    class="rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-4 py-2 text-xs font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                    {{ __('Write only about my products') }}
                </button>
            @else
                <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ __('Not chosen yet') }}</span>
                <button type="button" wire:click="setProductMode('strict')"
                    wire:confirm="{{ __('Ground your articles in your products? We\'ll scan your store first, and your unwritten upcoming topics will be rebuilt around your catalog. Published and in-progress articles are not touched.') }}"
                    wire:loading.attr="disabled" wire:target="setProductMode"
                    class="rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-4 py-2 text-xs font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                    {{ __('Write only about my products') }}
                </button>
                <button type="button" wire:click="setProductMode('normal')" wire:loading.attr="disabled" wire:target="setProductMode"
                    class="rounded-xl border border-slate-300 px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                    {{ __('Keep broader topics') }}
                </button>
            @endif
        </div>
    </div>

    {{-- Catalog --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900" @if($pt['inFlight']) wire:poll.6s @endif>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">
                    {{ __('Your product catalog') }}
                    @if ($pt['total'] > 0)
                        <span class="ms-1 rounded-full bg-orange-100 px-2 py-0.5 text-xs font-bold text-orange-700 dark:bg-orange-950 dark:text-orange-300">{{ number_format($pt['total']) }}</span>
                    @endif
                </h2>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                    {{ __('Products we found on your store. Excluded products are never featured in articles.') }}
                    @if ($pt['excluded'] > 0)
                        <span class="font-semibold">{{ trans_choice(':n excluded|:n excluded', $pt['excluded'], ['n' => $pt['excluded']]) }}</span>
                    @endif
                </p>
            </div>
            <div class="flex items-center gap-2">
                @if ($pt['inFlight'])
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-orange-100 px-3 py-1.5 text-xs font-bold text-orange-700 dark:bg-orange-950 dark:text-orange-300">
                        <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                        {{ __('Scanning your store…') }}
                    </span>
                @else
                    <button type="button" wire:click="scanProductsAgain" @disabled(! $pt['canScan']) wire:loading.attr="disabled" wire:target="scanProductsAgain"
                        @unless($pt['canScan']) title="{{ __('You can scan once per day — try again tomorrow.') }}" @endunless
                        class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
                        {{ __('Scan again') }}
                    </button>
                @endif
            </div>
        </div>

        @if ($pt['run']?->finished_at !== null && ! $pt['inFlight'])
            <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ __('Last scanned :time', ['time' => $pt['run']->finished_at->diffForHumans()]) }}</p>
        @endif

        @if ($pt['total'] === 0 && ! $pt['inFlight'])
            <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-8 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-800/40 dark:text-slate-400">
                {{ __('No products collected yet. Scan your store to build your catalog.') }}
            </div>
        @else
            <div class="mt-4">
                <input wire:model.live.debounce.400ms="productSearch" type="text" placeholder="{{ __('Search products…') }}"
                    class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 sm:max-w-xs dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"/>
            </div>
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($pt['products'] as $p)
                    <div wire:key="sp-{{ $p->id }}" class="flex items-center gap-3 rounded-xl border p-3 {{ $p->is_excluded ? 'border-slate-200 bg-slate-50 opacity-60 dark:border-slate-800 dark:bg-slate-800/40' : 'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800' }}">
                        @if ($p->image_url)
                            <img src="{{ $p->image_url }}" alt="" loading="lazy" class="h-12 w-12 shrink-0 rounded-lg object-cover"/>
                        @else
                            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-400 dark:bg-slate-700">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007z"/></svg>
                            </span>
                        @endif
                        <div class="min-w-0 flex-1">
                            <a href="{{ $p->url }}" target="_blank" rel="noopener" dir="auto" class="block truncate text-sm font-semibold text-slate-800 hover:text-orange-600 dark:text-slate-100">{{ $p->name }}</a>
                            <p class="truncate text-xs text-slate-400 dark:text-slate-500">
                                @if ($p->category)<span dir="auto">{{ $p->category }}</span>@endif
                                @if ($p->availability === 'out_of_stock') · {{ __('Out of stock') }}@endif
                            </p>
                        </div>
                        <button type="button" wire:click="toggleProductExclusion('{{ $p->id }}')"
                            class="shrink-0 rounded-lg px-2.5 py-1.5 text-xs font-semibold {{ $p->is_excluded ? 'bg-orange-100 text-orange-700 hover:brightness-105 dark:bg-orange-950 dark:text-orange-300' : 'text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-700' }}">
                            {{ $p->is_excluded ? __('Include') : __('Exclude') }}
                        </button>
                    </div>
                @endforeach
            </div>
            @if ($pt['products']->count() >= 60)
                <p class="mt-3 text-center text-xs text-slate-400 dark:text-slate-500">{{ __('Showing the first 60 — use search to find a specific product.') }}</p>
            @endif
        @endif
    </div>
</div>
