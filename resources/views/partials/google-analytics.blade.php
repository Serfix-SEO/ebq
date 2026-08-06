{{-- Google tag (gtag.js) — GA4 property G-FPEHXNCFT5 + Google Ads AW-18374890122.

     One gtag.js load serves both: the GA4 config reports pageviews, the Ads
     config is what makes `send_to: 'AW-18374890122/…'` conversions resolve.
     Without that second config line a conversion event is accepted by the
     page and silently dropped by Google.

     gtag_report_conversion() is Google's own snippet, kept verbatim so it can
     be pasted onto a link/button as `onclick="return gtag_report_conversion(url)"`.
     The Content Autopilot subscription conversion does NOT go through it — a
     purchase is confirmed server-side, not by a click, so it fires from
     partials/ads-conversion.blade.php instead. --}}
<script async src="https://www.googletagmanager.com/gtag/js?id=G-FPEHXNCFT5"></script>
<script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', 'G-FPEHXNCFT5');
    gtag('config', 'AW-18374890122');

    function gtag_report_conversion(url) {
        var callback = function () {
            if (typeof(url) != 'undefined') {
                window.location = url;
            }
        };
        gtag('event', 'conversion', {
            'send_to': 'AW-18374890122/YhmCCK3Vm90cEIql6rlE',
            'value': 1.0,
            'currency': 'AED',
            'transaction_id': '',
            'event_callback': callback
        });
        return false;
    }
</script>
