{{-- Shared "domain report" status block — used by the standalone report page
     (reports/view.blade.php) AND the post-signup website overview hub's Site
     Explorer tab. Expects the same variables ReportViewController::resolve()
     returns: $unavailable, $noData, $pending, $payload, $branding,
     $generatedAt, $website (optional), $domain. --}}
@if (! empty($unavailable))
    <div class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
        <p class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ $domain }}</p>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Backlink reports are temporarily unavailable. Please try again later.</p>
    </div>
@elseif (! empty($noData))
    <div class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
        <p class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ $domain }}</p>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">No backlink data is available for this domain yet.</p>
        <a href="{{ route('site-explorer') }}" class="mt-4 inline-block rounded-lg bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-700">Try another site</a>
    </div>
@elseif (! empty($pending))
    @php
        // Steps mirror what the generation job actually does, in order —
        // purely informational, no fake completion (bar caps at 90%). The
        // stepper animates CLIENT-SIDE while a tiny JSON endpoint
        // (report.status) is polled; the page reloads exactly once, when the
        // snapshot is actually ready — no more full-page refresh loop.
        $steps = ! empty($enriching)
            ? [
                'Checking the backlink index',
                'Measuring domain popularity',
                'Reading the site\'s pages',
                'Finding similar sites in search results',
                'Gathering keyword signals',
                'Assembling the closest available data',
            ]
            : [
                'Fetching the backlink profile',
                'Analyzing 12 months of link history',
                'Ranking the referring domains',
                'Classifying anchor texts',
                'Finding organic competitors',
                'Scoring trust & citation',
            ];
    @endphp
    <div class="mx-auto max-w-lg rounded-2xl bg-white p-10 text-center shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800"
         id="rpt-pending"
         data-status-url="{{ route('report.status', ['url' => $domain]) }}"
         data-reload-url="{{ $reloadUrl ?? route('report.view', ['url' => $domain]) }}">
        <div class="mx-auto mb-4 h-9 w-9 animate-spin rounded-full border-[3px] border-slate-200 border-t-orange-500 dark:border-slate-700"></div>
        <p class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ $domain }}</p>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {{ ! empty($enriching) ? 'This looks like a new site — gathering the closest available data…' : 'Building your backlink report…' }}
        </p>

        <div class="mt-5 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
            <div id="rpt-bar" class="h-full rounded-full bg-gradient-to-r from-orange-500 to-orange-600 transition-all duration-1000" style="width: 6%"></div>
        </div>

        <ul class="mx-auto mt-5 max-w-xs space-y-2 text-left">
            @foreach ($steps as $i => $step)
                <li class="rpt-step flex items-center gap-2.5 text-sm text-slate-300 dark:text-slate-600" data-i="{{ $i }}">
                    <svg class="ico-done hidden h-4 w-4 flex-none text-emerald-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                    <span class="ico-active relative hidden h-4 w-4 flex-none items-center justify-center">
                        <span class="absolute h-3 w-3 animate-ping rounded-full bg-orange-400 opacity-60"></span>
                        <span class="relative h-2 w-2 rounded-full bg-orange-500"></span>
                    </span>
                    <span class="ico-idle flex h-4 w-4 flex-none items-center justify-center"><span class="h-2 w-2 rounded-full bg-slate-200 dark:bg-slate-700"></span></span>
                    <span>{{ $step }}</span>
                </li>
            @endforeach
        </ul>

        <p id="rpt-slow" class="mt-4 hidden text-xs text-slate-400 dark:text-slate-500">Still working — bigger sites take a few minutes. This page updates automatically.</p>

        <script>
        (function () {
            var box = document.getElementById('rpt-pending');
            var bar = document.getElementById('rpt-bar');
            var steps = box.querySelectorAll('.rpt-step');
            // Persist the start time across reloads so a refresh RESUMES the
            // progress display instead of restarting it from zero.
            var key = 'rpt-start-' + @json($domain);
            var started = parseInt(sessionStorage.getItem(key) || '0', 10);
            if (!started || Date.now() - started > 30 * 60000) {
                started = Date.now();
                try { sessionStorage.setItem(key, String(started)); } catch (e) {}
            }
            var current = -1, pct = Math.min(90, 6 + (Date.now() - started) / 1000 * 1.4);

            function setStep(n) {
                if (n === current) return;
                current = n;
                steps.forEach(function (li, i) {
                    li.querySelector('.ico-done').classList.toggle('hidden', i >= n);
                    li.querySelector('.ico-active').classList.toggle('hidden', i !== n);
                    li.querySelector('.ico-active').classList.toggle('flex', i === n);
                    li.querySelector('.ico-idle').classList.toggle('hidden', i <= n);
                    li.className = li.className.replace(/ (font-medium text-slate-800 dark:text-slate-200|text-slate-400 dark:text-slate-500|text-slate-300 dark:text-slate-600)/g, '');
                    li.className += i < n ? ' text-slate-400 dark:text-slate-500'
                        : (i === n ? ' font-medium text-slate-800 dark:text-slate-200' : ' text-slate-300 dark:text-slate-600');
                });
            }
            bar.style.width = pct + '%';
            setStep(Math.min(steps.length - 1, Math.floor((Date.now() - started) / 12000)));

            // Local animation: bar creeps to 90%, next step every ~12s —
            // resumes from the persisted elapsed time after a reload.
            setInterval(function () {
                pct = Math.min(90, pct + 1.4);
                bar.style.width = pct + '%';
                setStep(Math.min(steps.length - 1, Math.floor((Date.now() - started) / 12000)));
                if (Date.now() - started > 240000) document.getElementById('rpt-slow').classList.remove('hidden');
            }, 1000);

            // Poll a few bytes of JSON; reload the page exactly once, when ready.
            (function poll() {
                fetch(box.dataset.statusUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (d) {
                        if (d && d.status && d.status !== 'pending' && d.status !== 'missing' && d.status !== 'unknown') {
                            try { sessionStorage.removeItem(key); } catch (e) {}
                            bar.style.width = '100%';
                            setTimeout(function () { location.replace(box.dataset.reloadUrl); }, 400);
                            return;
                        }
                        setTimeout(poll, 5000);
                    })
                    .catch(function () { setTimeout(poll, 8000); });
            })();
        })();
        </script>
    </div>
@else
    @include('reports.web-body', [
        'payload' => $payload,
        'branding' => $branding,
        'generatedAt' => $generatedAt ?? null,
        'downloadUrl' => ! empty($website ?? null) ? route('report.download') : null,
        // Keyword Gap deep-research link. The Gap tool runs on the user's OWN
        // current website, so gate it on that (like the sidebar nav does) —
        // NOT on the report's domain, which is null for a competitor/lookup
        // report (that was why the link vanished on lookups like daomarketing).
        // Shows on any authed report when the user has a keywords-enabled site;
        // never on public shares (auth()->check() is false there).
        'gapUrl' => (function () {
            $wid = (string) session('current_website_id');
            return (auth()->check() && $wid !== '' && $wid !== '0'
                && auth()->user()->hasFeatureAccess('keywords', $wid))
                ? route('keyword-gap.index')
                : null;
        })(),
    ])
@endif
