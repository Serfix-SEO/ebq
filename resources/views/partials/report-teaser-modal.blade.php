{{-- Full-viewport blurred overlay + signup/login modal, laid over a real tool
     report rendered with sample data (the preview page). `backdrop-blur` blurs
     the report behind it — no need to touch the report markup. Params:
     $redirect (tool page URL with ?autorun=1&inputs to re-run after auth). --}}
<div class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-white/30 p-4 backdrop-blur-lg">
    <div class="my-auto">
        @include('partials.auth-modal', [
            'redirect' => $redirect ?? '',
            'title' => 'Your result is ready',
            'subtitle' => 'Create your free account to view your full result.',
        ])
    </div>
</div>
@if (\App\Support\Recaptcha::isEnabled())
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
@endif
