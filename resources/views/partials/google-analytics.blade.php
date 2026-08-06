{{-- Google tag (gtag.js) — GA4 G-PS1SPVQXZR + Google Ads AW-18374890122,
     with Consent Mode v2 driven by our own banner (partials/consent-banner).

     ORDER IS LOAD-BEARING. The consent default has to be queued on dataLayer
     BEFORE gtag.js is fetched, or the tag fires once with storage allowed and
     the default never applies to that first hit. That is why this inline block
     comes first and the async loader second.

     Defaults are region-scoped: denied across the EEA, the UK and Switzerland
     until the visitor chooses; granted elsewhere. Consent Mode still sends
     cookieless pings while denied, which is what feeds Google's conversion
     modelling — so denying by default costs far less measurement than it looks.

     One gtag.js load serves both properties. The AW config is what makes
     `send_to: 'AW-18374890122/…'` resolve; without it a conversion event is
     accepted by the page and silently dropped by Google. --}}
<script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}

    // EEA + UK + CH: nothing until the visitor says so.
    gtag('consent', 'default', {
        'ad_storage': 'denied',
        'ad_user_data': 'denied',
        'ad_personalization': 'denied',
        'analytics_storage': 'denied',
        'wait_for_update': 500,
        'region': ['AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IS','IE','IT','LV','LI','LT','LU','MT','NL','NO','PL','PT','RO','SK','SI','ES','SE','GB','CH']
    });
    // Everywhere else.
    gtag('consent', 'default', {
        'ad_storage': 'granted',
        'ad_user_data': 'granted',
        'ad_personalization': 'granted',
        'analytics_storage': 'granted'
    });

    // Redact ad identifiers while consent is denied, and keep click ids in the
    // URL so conversions still attribute without cookies.
    gtag('set', 'ads_data_redaction', true);
    gtag('set', 'url_passthrough', true);

    // A returning visitor's choice is re-applied before the first hit, so the
    // banner never has to appear twice.
    (function () {
        try {
            var choice = window.localStorage.getItem('serfix_consent');
            if (choice === 'granted' || choice === 'denied') {
                gtag('consent', 'update', {
                    'ad_storage': choice,
                    'ad_user_data': choice,
                    'ad_personalization': choice,
                    'analytics_storage': choice
                });
            }
        } catch (e) { /* storage blocked — defaults stand */ }
    })();
</script>
<script async src="https://www.googletagmanager.com/gtag/js?id=G-PS1SPVQXZR"></script>
<script>
    gtag('js', new Date());
    gtag('config', 'G-PS1SPVQXZR');
    gtag('config', 'AW-18374890122');

    {{-- Google's own snippet for the Sign-up conversion, kept verbatim so it
         can be pasted onto a link or button as
         `onclick="return gtag_report_conversion(url)"`. Currently unused: the
         subscription conversion fires from partials/ads-conversion.blade.php on
         the post-payment page, because payment is confirmed server-side and a
         click happens BEFORE it — click-firing would count every abandoned
         checkout as a sale. --}}
    function gtag_report_conversion(url) {
        var callback = function () {
            if (typeof(url) != 'undefined') {
                window.location = url;
            }
        };
        gtag('event', 'conversion', {
            'send_to': 'AW-18374890122/U8gBCNCfkt0cEIql6rlE',
            'value': 1.0,
            'currency': 'AED',
            'event_callback': callback
        });
        return false;
    }
</script>
