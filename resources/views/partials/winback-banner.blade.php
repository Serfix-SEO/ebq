{{-- App-wide trial-discount strip: every trial-tier user (active or expired,
     TrialStatus::isWinbackEligible) sees the straight discount on every
     dashboard page. Hidden on /billing (it carries the big gradient banner
     already). Dismiss is per-tab (sessionStorage) — it comes back next visit
     until they subscribe. --}}
@php
    $wbBannerCode = (string) config('services.stripe.winback_promo_code');
    $wbBannerShow = $wbBannerCode !== ''
        && auth()->check()
        && ! request()->routeIs('billing.*')
        && \App\Support\TrialStatus::isWinbackEligible(auth()->user());
@endphp
@if ($wbBannerShow)
    <div x-data="{ hide: sessionStorage.getItem('wb-banner-dismissed') === '1' }" x-show="! hide" x-cloak
        class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg bg-gradient-to-r from-orange-600 to-orange-500 px-4 py-2.5 text-sm text-white shadow-sm">
        <span class="font-bold">{{ config('services.stripe.winback_promo_percent') }}{{ __('% OFF any plan') }}</span>
        <span class="hidden text-orange-50 sm:inline">{{ __('— applied automatically at checkout, no code needed.') }}</span>
        <a href="{{ route('billing.show') }}" wire:navigate
            class="ms-auto inline-flex items-center gap-1 whitespace-nowrap rounded-md bg-white/15 px-2.5 py-1 text-xs font-semibold transition hover:bg-white/25">
            {{ __('Claim :percent% off', ['percent' => config('services.stripe.winback_promo_percent')]) }}
            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
        </a>
        <button type="button" @click="hide = true; sessionStorage.setItem('wb-banner-dismissed', '1')"
            class="rounded p-0.5 text-orange-100 transition hover:bg-white/15 hover:text-white" aria-label="{{ __('Dismiss') }}">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
@endif
