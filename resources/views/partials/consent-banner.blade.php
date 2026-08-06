{{-- Our own consent banner, driving Google Consent Mode v2.

     Rendered hidden and revealed by script only when no choice is stored, so a
     returning visitor never sees it and a visitor with JS disabled never gets a
     dismissable overlay they cannot dismiss.

     Deliberately vanilla JS, not Alpine: this partial is included by layouts
     that do not all load Alpine, and a consent banner must not depend on a
     bundle finishing before it can be answered.

     The choice is written to localStorage under `serfix_consent` — the same key
     partials/google-analytics.blade.php reads on the next page load to re-apply
     it before the first hit. --}}
<div id="consent-banner" hidden
     role="dialog" aria-modal="false" aria-labelledby="consent-banner-title"
     class="fixed inset-x-0 bottom-0 z-50 px-4 pb-4">
    <div class="mx-auto flex max-w-3xl flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_24px_60px_-20px_rgba(15,23,42,0.35)] sm:flex-row sm:items-center">
        <div class="min-w-0 flex-1">
            <p id="consent-banner-title" class="text-sm font-bold text-slate-900">{{ __('Cookies') }}</p>
            <p class="mt-1 text-sm leading-6 text-slate-600">
                {{ __('We use cookies to measure how the site is used and how well our ads work. You can say no — the site works exactly the same either way.') }}
                <a href="{{ route('privacy-policy') }}" class="font-semibold text-orange-600 underline-offset-2 hover:underline">{{ __('Privacy policy') }}</a>
            </p>
        </div>
        <div class="flex flex-none flex-col gap-2 sm:flex-row sm:items-center">
            <button type="button" data-consent="denied"
                class="order-2 rounded-xl border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 sm:order-1">
                {{ __('Reject') }}
            </button>
            <button type="button" data-consent="granted"
                class="order-1 rounded-xl bg-orange-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-orange-600/25 transition hover:bg-orange-700 sm:order-2">
                {{ __('Accept') }}
            </button>
        </div>
    </div>
</div>
<script>
    (function () {
        var banner = document.getElementById('consent-banner');
        if (! banner) { return; }

        var stored = null;
        try { stored = window.localStorage.getItem('serfix_consent'); } catch (e) { /* storage blocked */ }
        if (stored === 'granted' || stored === 'denied') { return; }   // already answered

        banner.hidden = false;

        banner.addEventListener('click', function (event) {
            var button = event.target.closest('[data-consent]');
            if (! button) { return; }
            var choice = button.getAttribute('data-consent');

            try { window.localStorage.setItem('serfix_consent', choice); } catch (e) { /* storage blocked */ }

            // Update in place so the CURRENT page is measured under the choice
            // just made, rather than waiting for the next navigation.
            if (typeof gtag === 'function') {
                gtag('consent', 'update', {
                    'ad_storage': choice,
                    'ad_user_data': choice,
                    'ad_personalization': choice,
                    'analytics_storage': choice
                });
            }

            banner.hidden = true;
        });
    })();
</script>
