{{-- Refer-&-earn promo: small dismissible banner pinned to the bottom corner.

     Consent-banner pattern (vanilla JS, no Alpine dependency): rendered hidden,
     revealed by script only when the visitor has never dismissed it — the
     choice persists in localStorage under `serfix_ref_promo`, so one close
     hides it forever on that browser. Defers to the cookie-consent banner:
     if that banner is currently visible, the promo waits for a later page
     load rather than stacking two bottom overlays. --}}
<div id="referral-promo" hidden
     class="fixed inset-x-0 bottom-4 z-40 mx-auto w-[calc(100%-2rem)] max-w-xl">
    <div class="relative rounded-2xl border border-slate-200 bg-white p-4 shadow-[0_24px_60px_-20px_rgba(15,23,42,0.35)] dark:border-slate-700 dark:bg-slate-900">
        <button type="button" id="referral-promo-close" aria-label="{{ __('Dismiss') }}"
            class="absolute end-2 top-2 flex h-6 w-6 items-center justify-center rounded-full text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
        <div class="flex items-start gap-3 pe-5">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-lg shadow-orange-600/25">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 11.25v8.25a1.5 1.5 0 01-1.5 1.5H5.25a1.5 1.5 0 01-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 109.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1114.625 7.5H12m0 0V21m-8.625-9.75h18c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125h-18c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>
            </span>
            <div class="min-w-0">
                <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Refer a friend, get 50% off') }}</p>
                <p class="mt-0.5 text-xs leading-5 text-slate-500 dark:text-slate-400">
                    {{ __('Share your link — when they subscribe, 50% of your next bill is on us. Every referral counts.') }}
                </p>
                <div class="mt-2 flex items-center gap-3">
                    <a href="{{ route('referrals.index') }}"
                       class="rounded-lg bg-orange-600 px-3 py-1.5 text-xs font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                        {{ __('Get my link') }}
                    </a>
                    <a href="{{ route('referral-policy') }}" class="text-xs font-medium text-slate-400 underline-offset-2 hover:text-slate-600 hover:underline dark:hover:text-slate-300">
                        {{ __('Program rules') }}
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
    (function () {
        var KEY = 'serfix_ref_promo';
        var promo = document.getElementById('referral-promo');
        if (! promo) { return; }
        try {
            if (window.localStorage.getItem(KEY)) { return; }
        } catch (e) { return; }
        // Never stack on top of the cookie-consent banner.
        var consent = document.getElementById('consent-banner');
        if (consent && ! consent.hidden) { return; }
        setTimeout(function () {
            if (consent && ! consent.hidden) { return; }
            promo.hidden = false;
        }, 1500);
        document.getElementById('referral-promo-close').addEventListener('click', function () {
            promo.hidden = true;
            try { window.localStorage.setItem(KEY, 'dismissed'); } catch (e) {}
        });
    })();
</script>
