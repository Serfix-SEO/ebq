{{-- Microsoft Clarity — session recording + heatmaps, project xgs6o38iho --}}
<script type="text/javascript">
    (function(c,l,a,r,i,t,y){
        c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
        t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
        y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
    })(window, document, "clarity", "script", "xgs6o38iho");
</script>
@php
    $clarityTags = \App\Support\ClarityContext::tags();
    $clarityIdentity = \App\Support\ClarityContext::identity();
@endphp
{{-- Custom tags → Clarity's "Filters → Custom tags", saveable as Segments that
     apply to Recordings, Heatmaps and the Dashboard.

     `staff_session = no` is the one to filter on for real customer behaviour:
     admin sessions — and admins IMPERSONATING a client, which otherwise look
     exactly like that client — have been mixed into the data since the tag was
     installed.

     Queued on clarity()'s own command buffer, so ordering against the async
     script load does not matter. Values are non-PII by contract; see
     App\Support\ClarityContext. --}}
<script type="text/javascript">
    window.clarityTag = function (key, value) {
        try { window.clarity("set", key, String(value)); } catch (e) { /* analytics must never break a page */ }
    };
    window.clarityEvent = function (name) {
        try { window.clarity("event", name); } catch (e) { /* ditto */ }
    };
@if ($clarityIdentity !== null)
    // identify() links this person's SESSIONS together. Clarity ends a session
    // after ~30 min idle while ours lasts 24h, so one visit to a product full
    // of multi-minute waits lands as several sessions — this makes them
    // findable as one journey. Pseudonymous id only (Clarity hashes it again);
    // no friendlyName, which Clarity does NOT hash.
    try { window.clarity("identify", {{ Illuminate\Support\Js::from($clarityIdentity) }}); } catch (e) { /* never break a page */ }
@endif
    @foreach ($clarityTags as $key => $value)
        window.clarityTag({{ Illuminate\Support\Js::from($key) }}, {{ Illuminate\Support\Js::from($value) }});
    @endforeach

    // The onboarding wizard runs 8 steps behind ONE url, so page-level data
    // cannot show where people drop. ContentWizard::goToStep dispatches this.
    window.addEventListener('clarity-step', function (e) {
        var step = e.detail?.step ?? (Array.isArray(e.detail) ? e.detail[0]?.step : null);
        if (step == null) { return; }
        window.clarityTag('onboarding_step', step);
        window.clarityTag('onboarding_reached', step);   // multi-value: the furthest funnel view
        window.clarityEvent('onboarding_step_' + step);
    });
</script>
