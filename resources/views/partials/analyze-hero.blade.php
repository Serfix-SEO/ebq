{{-- Primary homepage tool: "Analyze website" backlink report. Submitting
     navigates to /report/view — for a signed-OUT visitor that page renders a
     blurred MOCK report + signup modal (no backlink API is called until after
     signup). See WebsiteAnalyzeController + ReportViewController. --}}
<div class="relative mx-auto max-w-3xl">
    <div class="mb-4 flex items-center justify-center gap-2">
        <span class="flex h-2 w-2 relative">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-orange-400 opacity-75"></span>
            <span class="relative inline-flex rounded-full h-2 w-2 bg-orange-500"></span>
        </span>
        <span class="text-xs font-semibold text-slate-600 tracking-wide">{{ __('Analyze any website’s backlink profile & authority') }}</span>
    </div>

    <form id="az-form" class="text-left" data-action="{{ route('analyze.store') }}" novalidate>
        @csrf
        <div class="flex flex-col rounded-[20px] bg-white p-2 shadow-[0_30px_70px_-28px_rgba(15,23,42,0.30)] ring-2 ring-orange-200/60 transition focus-within:ring-2 focus-within:ring-orange-500/70 sm:flex-row sm:items-center">
            <div class="flex min-w-0 flex-1 items-center gap-3 rounded-xl px-3 py-2.5">
                <span class="flex h-10 w-10 flex-none items-center justify-center rounded-xl bg-orange-50 text-orange-600 ring-1 ring-inset ring-orange-100">
                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" /></svg>
                </span>
                <div class="min-w-0 flex-1">
                    <label for="az-url" class="block text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Website URL') }}</label>
                    <input id="az-url" name="url" type="text" inputmode="url" autocomplete="url" required
                        placeholder="yourwebsite.com"
                        class="w-full border-0 bg-transparent p-0 text-[15px] font-medium text-slate-900 placeholder:font-normal placeholder:text-slate-400 focus:outline-none focus:ring-0">
                </div>
            </div>
            <div class="pt-2 sm:pl-2 sm:pt-0">
                <button type="submit" id="az-submit"
                    class="group inline-flex h-12 w-full items-center justify-center gap-2 rounded-2xl bg-orange-600 px-6 text-sm font-semibold text-white shadow-lg shadow-orange-600/25 transition hover:bg-orange-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto">
                    <svg id="az-spinner" class="hidden h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    <span id="az-label">{{ __('Analyze') }}</span>
                </button>
            </div>
        </div>

        @if (\App\Support\Recaptcha::isEnabled())
            <div class="mt-4 flex justify-center">
                <div class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.site_key') }}"></div>
            </div>
        @endif

        <p id="az-error" role="alert" class="mx-auto mt-4 hidden max-w-md rounded-lg bg-rose-50 px-3 py-2 text-center text-[13px] font-medium text-rose-700 ring-1 ring-rose-100"></p>

        <p class="mt-5 text-center text-xs text-slate-500">{{ __('Domain Authority · referring domains · anchors · competitors — free report') }}</p>
    </form>
</div>

<script>
(function () {
    var form = document.getElementById('az-form');
    if (!form) return;
    var errEl = document.getElementById('az-error');
    var btn = document.getElementById('az-submit');
    var spinner = document.getElementById('az-spinner');

    function showError(msg) {
        errEl.textContent = msg || 'Something went wrong. Please try again.';
        errEl.classList.remove('hidden');
    }
    function busy(on) {
        btn.disabled = on;
        spinner.classList.toggle('hidden', !on);
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        errEl.classList.add('hidden');
        busy(true);
        fetch(form.dataset.action, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: new FormData(form),
        }).then(function (res) {
            return res.json().then(function (data) { return { status: res.status, data: data }; });
        }).then(function (r) {
            if (r.data && r.data.results_url) { window.location = r.data.results_url; return; }
            busy(false);
            showError(r.data && r.data.message);
        }).catch(function () { busy(false); showError(); });
    });
})();
</script>
