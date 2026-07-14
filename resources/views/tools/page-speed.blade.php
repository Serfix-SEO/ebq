<x-marketing.page
    title="Free PageSpeed Test — Core Web Vitals & Lighthouse Audit"
    description="Test any page's speed for free. Get mobile & desktop Lighthouse scores, Core Web Vitals, and the exact resources slowing you down — no signup for your first test."
>
    <x-slot:schema>
        @php
            $toolSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'WebApplication',
                'name' => 'Serfix PageSpeed Test',
                'applicationCategory' => 'DeveloperApplication',
                'operatingSystem' => 'Web',
                'url' => route('tools.pagespeed'),
                'description' => 'Free PageSpeed and Core Web Vitals test: mobile + desktop Lighthouse scores, performance opportunities and the offending resources.',
                'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'],
            ];
        @endphp
        <script type="application/ld+json">{!! json_encode($toolSchema, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    </x-slot:schema>

    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section class="relative">
        <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 top-0 -z-10 h-[26rem] bg-[radial-gradient(ellipse_at_top,rgba(242,100,25,0.08),transparent_60%)]"></div>
        <div class="mx-auto max-w-3xl px-6 pb-16 pt-16 text-center lg:px-8 lg:pb-24 lg:pt-24">
            <a href="{{ route('tools.audit') }}" class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-600 transition hover:border-slate-300 hover:text-slate-900">
                <span class="h-1.5 w-1.5 rounded-full bg-orange-500"></span>
                {{ __('Also free: full SEO audit') }}
                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
            </a>

            <h1 class="mx-auto mt-6 max-w-2xl text-balance text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl">
                {{ __('Free PageSpeed & Core Web Vitals test') }}
            </h1>
            <p class="mx-auto mt-5 max-w-xl text-balance text-[17px] leading-8 text-slate-600">
                {{ __('Get mobile & desktop Lighthouse scores, Core Web Vitals, and the exact resources slowing your page down — in about a minute.') }}
            </p>

            {{-- Search bar --}}
            <div class="relative mx-auto mt-10 max-w-2xl">
                <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 -inset-y-10 sm:-inset-x-8 -z-10 bg-[radial-gradient(55%_60%_at_50%_0%,rgba(242,100,25,0.20),transparent_70%)] blur-2xl"></div>

                <form id="ps-form" data-tool-gate-form class="text-start" data-action="{{ route('guest-pagespeed.store') }}" novalidate>
                    <div class="flex flex-col rounded-[20px] bg-white p-2 shadow-[0_30px_70px_-28px_rgba(15,23,42,0.30)] ring-1 ring-slate-200/80 transition focus-within:ring-2 focus-within:ring-orange-500/70 sm:flex-row sm:items-center">
                        <div class="flex min-w-0 flex-1 items-center gap-3 px-3 py-2.5">
                            <span class="flex h-10 w-10 flex-none items-center justify-center rounded-xl bg-orange-50 text-orange-600 ring-1 ring-inset ring-orange-100">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>
                            </span>
                            <div class="min-w-0 flex-1">
                                <label for="ps-url" class="block text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">{{ __('Page URL') }}</label>
                                <input id="ps-url" name="url" type="text" inputmode="url" autocomplete="url" autofocus required
                                    placeholder="{{ __('yourwebsite.com/page') }}"
                                    class="w-full border-0 bg-transparent p-0 text-[15px] font-medium text-slate-900 placeholder:font-normal placeholder:text-slate-400 focus:outline-none focus:ring-0">
                            </div>
                        </div>
                        <div class="pt-2 sm:ps-2 sm:pt-0">
                            <button type="submit" id="ps-submit"
                                class="group inline-flex h-12 w-full items-center justify-center gap-2 rounded-2xl bg-orange-600 px-6 text-sm font-semibold text-white shadow-lg shadow-orange-600/25 transition hover:bg-orange-700 disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto">
                                <svg id="ps-spinner" class="hidden h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                <span id="ps-label">{{ __('Test speed') }}</span>
                                <svg id="ps-arrow" class="h-4 w-4 transition-transform group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5l7.5 7.5-7.5 7.5M21 12H3" /></svg>
                            </button>
                        </div>
                    </div>

                    @if (\App\Support\Recaptcha::isEnabled())
                        <div id="ps-captcha-hero-slot" class="mt-4 flex justify-center">
                            <div class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.site_key') }}"></div>
                        </div>
                    @endif

                    <p id="ps-error" role="alert" class="mx-auto mt-4 hidden max-w-md rounded-lg bg-rose-50 px-3 py-2 text-center text-[13px] font-medium text-rose-700 ring-1 ring-rose-100"></p>

                    <p class="mt-5 flex flex-wrap items-center justify-center gap-x-2 gap-y-1 text-xs text-slate-500">
                        <span class="inline-flex items-center gap-1.5"><svg class="h-3.5 w-3.5 text-emerald-500" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" /></svg>{{ __('Free') }}</span>
                        <span class="text-slate-300">·</span><span>{{ __('No signup for your first test') }}</span>
                        <span class="text-slate-300">—</span>
                        <a href="{{ route('register') }}" class="font-medium text-orange-600 underline-offset-2 transition hover:text-orange-700 hover:underline">{{ __('or start free →') }}</a>
                    </p>
                </form>

                
            </div>
        </div>
    </section>
    

    {{-- ── What you get ── --}}
    <section class="border-t border-slate-200 bg-slate-50">
        <div class="mx-auto max-w-5xl px-6 py-16 lg:px-8">
            <h2 class="text-center text-2xl font-semibold tracking-tight text-slate-900">{{ __('What’s in your free report') }}</h2>
            <div class="mt-10 grid gap-6 sm:grid-cols-3">
                @foreach ([
                    [__('Lighthouse scores'), __('Performance, Accessibility, Best Practices and SEO — scored 0–100 for both mobile and desktop.')],
                    [__('Core Web Vitals'), __('LCP, CLS, TBT, FCP, Speed Index and TTI with pass / needs-work / poor ratings.')],
                    [__('The exact fixes'), __('Prioritized opportunities with estimated savings and the specific scripts, images and styles to fix.')],
                ] as $f)
                    <div class="rounded-2xl border border-slate-200 bg-white p-6">
                        <h3 class="text-sm font-bold text-slate-900">{{ $f[0] }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $f[1] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    @if (\App\Support\Recaptcha::isEnabled())
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    @endif
    <script>
        (function () {
            var form = document.getElementById('ps-form');
            if (!form) return;
            var btn = document.getElementById('ps-submit'), label = document.getElementById('ps-label'),
                spinner = document.getElementById('ps-spinner'), arrow = document.getElementById('ps-arrow'),
                errorBox = document.getElementById('ps-error'), csrf = document.querySelector('meta[name="csrf-token"]');
            var emailModal = document.getElementById('ps-email-modal'), emailForm = document.getElementById('ps-email-form'),
                nameInput = document.getElementById('ps-name'), emailInput = document.getElementById('ps-email'),
                emailModalMsg = document.getElementById('ps-email-modal-msg'), emailError = document.getElementById('ps-email-error'),
                emailSubmit = document.getElementById('ps-email-submit'), emailLabel = document.getElementById('ps-email-label'),
                emailSpinner = document.getElementById('ps-email-spinner'),
                successCard = document.getElementById('ps-success'), successMsg = document.getElementById('ps-success-msg');
            var capturedName = '', capturedEmail = '';
            var captchaWidget = document.querySelector('.g-recaptcha');
            var heroSlot = document.getElementById('ps-captcha-hero-slot'), modalSlot = document.getElementById('ps-captcha-modal-slot');

            function showError(m) { errorBox.textContent = m; errorBox.classList.remove('hidden'); }
            function clearError() { errorBox.textContent = ''; errorBox.classList.add('hidden'); }
            function setLoading(on) { btn.disabled = on; form.setAttribute('aria-busy', on ? 'true' : 'false'); label.textContent = on ? @json(__('Testing…')) : @json(__('Test speed')); spinner.classList.toggle('hidden', !on); arrow.classList.toggle('hidden', on); }
            function setEmailLoading(on) { if (emailSubmit) emailSubmit.disabled = on; if (emailSpinner) emailSpinner.classList.toggle('hidden', !on); if (emailLabel) emailLabel.textContent = on ? @json(__('Sending…')) : @json(__('Email me my report')); }
            function resetCaptcha() { if (window.grecaptcha && window.grecaptcha.reset) { try { window.grecaptcha.reset(); } catch (e) {} } }
            function captchaToken() { var c = document.querySelector('textarea[name="g-recaptcha-response"]'); return c ? c.value : null; }
            function moveCaptchaTo(slot) { if (captchaWidget && slot && captchaWidget.parentNode !== slot) slot.appendChild(captchaWidget); }
            function toggleModal(el, on) { if (!el) return; el.classList.toggle('hidden', !on); el.classList.toggle('flex', on); }
            function openEmailModal(msg) { if (!emailModal) return; if (emailModalMsg && msg) emailModalMsg.textContent = msg; if (emailError) emailError.classList.add('hidden'); moveCaptchaTo(modalSlot); toggleModal(emailModal, true); if (nameInput) nameInput.focus(); }
            function showSuccess(msg) { if (successMsg && msg) successMsg.textContent = msg; form.classList.add('hidden'); if (successCard) successCard.classList.remove('hidden'); }

            if (emailModal) {
                var eCancel = document.getElementById('ps-email-cancel'), eBack = document.getElementById('ps-email-backdrop');
                var dismiss = function () { toggleModal(emailModal, false); moveCaptchaTo(heroSlot); setLoading(false); setEmailLoading(false); };
                if (eCancel) eCancel.addEventListener('click', dismiss);
                if (eBack) eBack.addEventListener('click', dismiss);
            }

            function run() {
                clearError();
                var url = (document.getElementById('ps-url').value || '').trim();
                if (!url) { showError(@json(__('Please enter your page URL.'))); return; }
                var payload = { url: url };
                if (capturedEmail) { payload.email = capturedEmail; payload.name = capturedName; }
                var token = captchaToken();
                if (token) payload['g-recaptcha-response'] = token;
                setLoading(true);
                if (emailModal && emailModal.classList.contains('flex')) setEmailLoading(true);

                fetch(form.getAttribute('data-action'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf ? csrf.getAttribute('content') : '' },
                    body: JSON.stringify(payload)
                }).then(function (res) {
                    return res.json().catch(function () { return {}; }).then(function (data) { return { status: res.status, data: data }; });
                }).then(function (r) {
                    if (r.status === 202 && r.data.emailed) { toggleModal(emailModal, false); showSuccess(r.data.message); return; }
                    if (r.status === 202 && r.data.results_url) { window.location.href = r.data.results_url; return; }
                    if (r.data && r.data.require === 'email') { setLoading(false); setEmailLoading(false); openEmailModal(r.data.message); return; }
                    if (r.data && r.data.require === 'signup') { window.showToolGate(form); return; }
                    var msg = r.data.message; var errs = r.data.errors || {};
                    if (!msg) { var f = Object.keys(errs)[0]; if (f && errs[f] && errs[f][0]) msg = errs[f][0]; }
                    msg = msg || @json(__('Something went wrong. Please try again.'));
                    if (errs['g-recaptcha-response']) resetCaptcha();
                    if (emailModal && emailModal.classList.contains('flex')) { if (emailError) { emailError.textContent = msg; emailError.classList.remove('hidden'); } }
                    else { showError(msg); }
                    setLoading(false); setEmailLoading(false);
                }).catch(function () { showError(@json(__('Network error. Please check your connection and try again.'))); setLoading(false); setEmailLoading(false); });
            }

            form.addEventListener('submit', function (e) { e.preventDefault(); run(); });
            if (emailForm) {
                emailForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    if (emailError) emailError.classList.add('hidden');
                    var nm = (nameInput.value || '').trim(), em = (emailInput.value || '').trim();
                    if (!nm) { emailError.textContent = @json(__('Please enter your name.')); emailError.classList.remove('hidden'); nameInput.focus(); return; }
                    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(em)) { emailError.textContent = @json(__('Please enter a valid email address.')); emailError.classList.remove('hidden'); emailInput.focus(); return; }
                    capturedName = nm; capturedEmail = em; run();
                });
            }
        })();
    </script>
    @include('partials.tool-gate')
</x-marketing.page>
