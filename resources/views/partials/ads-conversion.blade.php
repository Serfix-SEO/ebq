{{--
    Google Ads conversions for Content Autopilot: a confirmed subscription
    (ContentBillingController::success) or a started trial
    (ContentEntitlements::startTrial). Both arrive the same way — see
    App\Support\AdsConversion — so this partial only renders what it is handed
    and never decides which conversion happened.

    Renders NOTHING unless `ads_conversion` was flashed. Flash data survives
    exactly one request, so the tag fires once, on the page the customer lands
    on afterwards, and never again on a refresh or a later visit. Both events
    are confirmed server-side, so neither is wired to
    gtag_report_conversion()'s click handler.

    Included at the END OF THE BODY, not in the head: Livewire's wire:navigate
    swaps the body and re-runs its scripts but merges the head without
    re-executing inline scripts there, so a head-only tag would silently miss
    any conversion that lands via a navigate redirect.

    The app layout does not load gtag.js (we don't run analytics across the
    signed-in app), so the tag is loaded here just-in-time for the event. The
    `typeof gtag` guard keeps it safe if this partial is ever included on a page
    that already has the site tag.

    transaction_id carries the Stripe subscription id: Google Ads de-duplicates
    on it, so a customer who somehow reaches this twice is still one conversion.
--}}
@if (session()->has('ads_conversion'))
    @php $conv = (array) session('ads_conversion'); @endphp
    <script async src="https://www.googletagmanager.com/gtag/js?id=AW-18374890122"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        if (typeof gtag !== 'function') { function gtag(){dataLayer.push(arguments);} }
        gtag('js', new Date());
        gtag('config', 'AW-18374890122');
        gtag('event', 'conversion', {
            'send_to': @json($conv['send_to'] ?? 'AW-18374890122/U8gBCNCfkt0cEIql6rlE', JSON_UNESCAPED_SLASHES),
            'value': {{ (float) ($conv['value'] ?? 1.0) }},
            'currency': @json($conv['currency'] ?? 'AED', JSON_UNESCAPED_SLASHES),
            'transaction_id': @json((string) ($conv['transaction_id'] ?? ''), JSON_UNESCAPED_SLASHES)
        });
    </script>
@endif
