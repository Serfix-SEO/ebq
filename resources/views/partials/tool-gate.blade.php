{{-- Public-tool auth gate: a blurred skeleton + the shared signup/login modal.
     Shown (via window.showToolGate) when an anonymous visitor runs a tool.
     After auth the user returns to the tool page with ?autorun=1 and the tool
     re-runs automatically. Include once per tool page; give the tool's form a
     `data-tool-gate-form` attribute. --}}
<div id="tool-gate" class="pointer-events-none fixed inset-0 z-50 overflow-y-auto opacity-0 transition-opacity duration-150">
    <div class="absolute inset-0 bg-white/50 px-4 py-8" style="filter: blur(7px);" aria-hidden="true">
        <div class="mx-auto max-w-3xl space-y-4">
            <div class="grid grid-cols-4 gap-3">
                @for ($i = 0; $i < 4; $i++)<div class="h-24 rounded-xl bg-slate-200"></div>@endfor
            </div>
            <div class="grid grid-cols-4 gap-3">
                @for ($i = 0; $i < 4; $i++)<div class="h-16 rounded-lg bg-slate-100"></div>@endfor
            </div>
            <div class="h-40 rounded-xl bg-slate-100"></div>
            <div class="space-y-2">
                @for ($i = 0; $i < 6; $i++)<div class="h-6 rounded bg-slate-100"></div>@endfor
            </div>
        </div>
    </div>
    <div class="relative flex min-h-full items-center justify-center p-4">
        @include('partials.auth-modal', ['redirect' => '', 'title' => 'Your result is ready', 'subtitle' => 'Create your free account to view your result.'])
    </div>
</div>

<script>
(function () {
    window.showToolGate = function (form) {
        var params = new URLSearchParams();
        if (form) {
            Array.prototype.forEach.call(form.elements, function (el) {
                if (el.name && el.name !== '_token' && el.name !== 'g-recaptcha-response' && el.name !== '_form' && el.name !== 'redirect' && el.value) {
                    params.append(el.name, el.value);
                }
            });
        }
        var redirect = window.location.pathname + '?autorun=1&' + params.toString();
        document.querySelectorAll('#tool-gate input[name="redirect"]').forEach(function (i) { i.value = redirect; });
        var g = document.getElementById('tool-gate');
        g.classList.remove('opacity-0', 'pointer-events-none');
        document.body.style.overflow = 'hidden';
    };

    // After auth the user lands back here with ?autorun=1 + the inputs — refill
    // the form and re-submit (now authenticated → the tool runs for real).
    var q = new URLSearchParams(window.location.search);
    if (q.get('autorun') === '1') {
        var form = document.querySelector('[data-tool-gate-form]');
        if (form) {
            q.forEach(function (v, k) {
                if (k === 'autorun') return;
                var el = form.querySelector('[name="' + k + '"]');
                if (el) el.value = v;
            });
            setTimeout(function () { if (form.requestSubmit) form.requestSubmit(); else form.submit(); }, 350);
        }
    }
})();
</script>
