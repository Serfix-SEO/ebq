<x-layouts.app :title="__('Explorer')">
    {{-- No tab bar here on purpose — this is a generic "analyze ANY domain"
         tool, not scoped to one of the user's own websites. It would be
         misleading to show nav tied to whatever website happens to be
         session-pinned before the user has even typed a URL. The nav
         appears on the RESULT page (reports/view.blade.php) once the
         analyzed domain is confirmed to be one of the user's own sites. --}}
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="text-center">
            <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">{{ __('Explorer') }}</h1>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ __('Analyze any website’s backlink profile, authority & competitors.') }}</p>
        </div>

        <form id="se-form" class="mt-6" data-action="{{ route('analyze.store') }}" novalidate>
            @csrf
            <div class="flex flex-col gap-2 rounded-2xl bg-white p-2 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800 sm:flex-row sm:items-center">
                <div class="flex min-w-0 flex-1 items-center gap-3 px-3 py-2">
                    <span class="flex h-9 w-9 flex-none items-center justify-center rounded-lg bg-orange-50 text-orange-600 dark:bg-orange-500/10 dark:text-orange-400">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582" /></svg>
                    </span>
                    <input id="se-url" name="url" type="text" inputmode="url" autocomplete="url" required autofocus
                        placeholder="yourwebsite.com"
                        class="w-full border-0 bg-transparent p-0 text-[15px] font-medium text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-0 dark:text-slate-100 dark:placeholder:text-slate-500">
                </div>
                <button type="submit" id="se-submit"
                    class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-orange-600 px-6 text-sm font-semibold text-white transition hover:bg-orange-700 disabled:opacity-60">
                    <svg id="se-spinner" class="hidden h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    {{ __('Analyze') }}
                </button>
            </div>
            <p id="se-error" role="alert" class="mx-auto mt-4 hidden max-w-md rounded-lg bg-rose-50 px-3 py-2 text-center text-[13px] font-medium text-rose-700 ring-1 ring-rose-100 dark:bg-rose-500/10 dark:text-rose-400 dark:ring-rose-500/20"></p>
        </form>

        @auth
            <livewire:site-explorer-history />
        @endauth
    </div>

    <script>
    (function () {
        var form = document.getElementById('se-form');
        if (!form) return;
        var err = document.getElementById('se-error'), btn = document.getElementById('se-submit'), sp = document.getElementById('se-spinner');
        function busy(on) { btn.disabled = on; sp.classList.toggle('hidden', !on); }
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            err.classList.add('hidden');
            busy(true);
            fetch(form.dataset.action, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, body: new FormData(form) })
                .then(function (r) { return r.json().then(function (d) { return { s: r.status, d: d }; }); })
                .then(function (r) {
                    if (r.d && r.d.results_url) { window.location = r.d.results_url; return; }
                    busy(false);
                    err.textContent = (r.d && r.d.message) || 'Something went wrong. Please try again.';
                    err.classList.remove('hidden');
                })
                .catch(function () { busy(false); err.textContent = 'Something went wrong. Please try again.'; err.classList.remove('hidden'); });
        });
    })();
    </script>
</x-layouts.app>
