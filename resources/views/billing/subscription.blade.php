<x-layouts.app>
    @php
        $user = auth()->user();
        $ent = app(\App\Services\Content\ContentEntitlements::class);
        // Does this account have anything to do with Content Autopilot? Used
        // only to pick the default tab — the plans themselves are always
        // browsable, and the summary panels decide their own visibility.
        $hasContent = $user !== null && (
            $ent->hasContentAccess($user)
            || $user->subscription(\App\Services\Content\ContentEntitlements::SUBSCRIPTION) !== null
        );
        $contentUi = \Illuminate\Support\Facades\Route::has('content.get-started');
        // SEO UI kill-switch: the whole SEO half of this page (summary panel,
        // plans tab) disappears and the page becomes single-product.
        $seoUi = (bool) config('features.seo_platform_ui');
        $contentFirst = (bool) $user?->isContentOnly();
    @endphp

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-slate-900 dark:text-white">{{ __('Billing') }}</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            @if ($seoUi)
                {{ __('Your subscriptions, usage and invoices. The SEO platform and Content AI are billed separately.') }}
            @else
                {{ __('Your subscription, invoices and plan.') }}
            @endif
        </p>
    </div>

    {{-- WHAT YOU ARE PAYING FOR comes first, for both products. The pricing
         tables used to sit between the two summaries, so a subscriber had to
         scroll past a wall of plan cards to find their own subscription — and
         a content customer found theirs dead last (reported 2026-08-08). --}}
    @if (! $seoUi)
        {{-- Single product: content summary only. --}}
        <livewire:billing.content-subscription-panel section="summary" :first="true" />
    @elseif ($contentFirst && $contentUi)
        <livewire:billing.content-subscription-panel section="summary" :first="true" />
        <livewire:billing.subscription-panel section="summary" />
    @else
        <livewire:billing.subscription-panel section="summary" />
        @if ($contentUi)
            <livewire:billing.content-subscription-panel section="summary" />
        @endif
    @endif

    {{-- WHAT YOU COULD BUY, one product at a time. Two full pricing tables
         stacked on one page is the mess; a tab shows one and keeps the other
         a click away. Plain Alpine, no server round-trip to switch. --}}
    @if (! $seoUi && $contentUi)
        {{-- Single product: content plans, no tabs. --}}
        <div class="mt-10">
            <h2 class="mb-4 text-lg font-bold text-slate-900 dark:text-white">{{ __('Plans') }}</h2>
            <livewire:billing.content-subscription-panel section="plans" />
        </div>
    @elseif ($contentUi)
        @php $defaultTab = ($hasContent && ! $user?->subscribed('default')) ? 'content' : 'seo'; @endphp
        <div class="mt-10" x-data="{ tab: @js($defaultTab) }">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">{{ __('Plans') }}</h2>
                <div class="flex items-center gap-1 rounded-lg border border-slate-200 bg-slate-50 p-0.5 text-xs font-semibold dark:border-slate-700 dark:bg-slate-800">
                    <button type="button" @click="tab = 'seo'"
                            :class="tab === 'seo' ? 'bg-white shadow text-slate-900 dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200'"
                            class="rounded-md px-3 py-1.5 transition-all">
                        {{ __('SEO platform') }}
                    </button>
                    <button type="button" @click="tab = 'content'"
                            :class="tab === 'content' ? 'bg-white shadow text-slate-900 dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200'"
                            class="rounded-md px-3 py-1.5 transition-all">
                        {{ __('Content AI') }}
                    </button>
                </div>
            </div>

            <div x-show="tab === 'seo'" @if ($defaultTab !== 'seo') style="display:none" @endif>
                <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
                    {{ __('Crawling, audits, rank tracking and reports. Billed separately from Content AI.') }}
                </p>
                <livewire:billing.subscription-panel section="plans" />
            </div>

            <div x-show="tab === 'content'" @if ($defaultTab !== 'content') style="display:none" @endif>
                <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
                    {{ __('A written, optimised and published article calendar. Billed separately from the SEO platform.') }}
                </p>
                <livewire:billing.content-subscription-panel section="plans" />
            </div>
        </div>
    @else
        <div class="mt-10">
            <livewire:billing.subscription-panel section="plans" />
        </div>
    @endif
</x-layouts.app>
