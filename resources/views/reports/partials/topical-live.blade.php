{{-- Live "Topical relevance" card — renders whatever exists NOW (pending
     spinner or partial/complete results) and then updates ITSELF in place by
     polling the tiny report.status JSON endpoint: chips, relevance bar, the
     "N of M analyzed" counter and the TopicSignal gauge (if present on the
     page) all refresh as classification batches land. NO page reloads.

     Params: $domain (string), $section (payload topical_trust|null),
             $dark (bool — emit dark-mode classes; report web body is light-only). --}}
@php
    $tt = $section ?? null;
    $has = ! empty($tt['topics']);
    $done = (int) ($tt['sample'] ?? 0);
    $total = max($done, (int) ($tt['total'] ?? $done));
    $stubFresh = ! empty($tt['pending']) && ! $has
        && now()->diffInMinutes(\Illuminate\Support\Carbon::parse($tt['queued_at'] ?? now()), true) < 30;
    $running = $stubFresh || ($has && $done < $total);
    $d = $dark ?? false;

    $cardCls = $d
        ? 'rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900'
        : 'rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200';
    $titleCls = $d
        ? 'text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400'
        : 'text-sm font-semibold text-slate-900';
    $chipCls = 'inline-flex items-center gap-1.5 rounded-full bg-orange-50 px-2.5 py-1 text-xs font-medium text-orange-700'.($d ? ' dark:bg-orange-500/15 dark:text-orange-300' : '');
    $mutedCls = 'text-xs text-slate-400'.($d ? ' dark:text-slate-500' : '');
@endphp
@if ($has || $stubFresh)
    <div id="tt-live" class="{{ $cardCls }}">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <p class="{{ $titleCls }}">{{ __('Topical relevance') }}</p>
            <p class="inline-flex items-center gap-1.5 {{ $mutedCls }}">
                <span id="tt-spin" @class(['h-3 w-3 flex-none animate-spin rounded-full border-2 border-slate-200 border-t-orange-500', 'dark:border-slate-700' => $d, 'hidden' => ! ($running && $has)])></span>
                <span id="tt-count">
                    @if ($running && $has)
                        {{ __(':done of :total referring domains analyzed', ['done' => $done, 'total' => $total]) }}
                    @elseif ($has)
                        {{ __('Based on all :n referring domains', ['n' => $done]) }}
                    @endif
                </span>
            </p>
        </div>

        {{-- Loud first-run placeholder — removed by the poller the moment the
             first classification batch lands. --}}
        @if ($running && ! $has)
            <div id="tt-wait" class="mt-4 flex flex-col items-center justify-center gap-3 rounded-xl bg-slate-50 px-6 py-8 text-center{{ $d ? ' dark:bg-slate-800/60' : '' }}">
                <span class="h-7 w-7 flex-none animate-spin rounded-full border-[3px] border-slate-200 border-t-orange-500{{ $d ? ' dark:border-slate-700' : '' }}"></span>
                <p class="text-sm font-medium text-slate-700{{ $d ? ' dark:text-slate-200' : '' }}">{{ __('Analyzing the topics of your referring domains') }}</p>
                <p class="{{ $mutedCls }}">{{ __('First results appear here in about half a minute — no need to refresh, this section updates itself.') }}</p>
            </div>
        @endif

        @php $targetChipCls = 'inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-700'.($d ? ' dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200' : ''); @endphp
        <div id="tt-target" class="mt-3 {{ ! empty($tt['target_topic']) ? '' : 'hidden' }}">
            <span class="{{ $targetChipCls }}">{{ __('Your site’s topic:') }} <span id="tt-target-topic" class="text-orange-600{{ $d ? ' dark:text-orange-400' : '' }}">{{ $tt['target_topic'] ?? '' }}</span></span>
        </div>

        <div id="tt-chips" class="mt-3 flex flex-wrap gap-2 {{ $has ? '' : 'hidden' }}">
            @foreach (array_slice($tt['topics'] ?? [], 0, 8) as $topic)
                <span class="{{ $chipCls }}">{{ $topic['topic'] }} <span class="font-bold">×{{ (int) $topic['count'] }}</span></span>
            @endforeach
        </div>

        <div id="tt-relwrap" class="mt-4 {{ $has && isset($tt['relevant_pct']) ? '' : 'hidden' }}">
            <div class="flex items-baseline justify-between text-xs">
                <span class="font-medium text-slate-600{{ $d ? ' dark:text-slate-300' : '' }}">{{ __('Links relevant to your site’s topic') }}</span>
                <span id="tt-pct" class="font-bold tabular-nums text-slate-900{{ $d ? ' dark:text-slate-100' : '' }}">{{ (int) ($tt['relevant_pct'] ?? 0) }}%</span>
            </div>
            <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-100{{ $d ? ' dark:bg-slate-800' : '' }}">
                <div id="tt-bar" class="h-full rounded-full bg-orange-500 transition-all duration-700" style="width: {{ max(2, min(100, (int) ($tt['relevant_pct'] ?? 0))) }}%"></div>
            </div>
        </div>

        @if ($running)
            @auth
                <script>
                (function () {
                    var chipCls = @json($chipCls);
                    var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
                    (function poll(t) {
                        if (t > 240) return; // ~40 min hard stop
                        fetch('{{ route('report.status', ['url' => $domain]) }}', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                            .then(function (r) { return r.ok ? r.json() : null; })
                            .then(function (d) {
                                if (!d) { setTimeout(function () { poll(t + 1); }, 15000); return; }
                                if (d.topical_target) {
                                    document.getElementById('tt-target').classList.remove('hidden');
                                    document.getElementById('tt-target-topic').textContent = d.topical_target;
                                }
                                if (d.topical_topics && d.topical_topics.length) {
                                    var wait = document.getElementById('tt-wait');
                                    if (wait) wait.remove();
                                    document.getElementById('tt-spin').classList.remove('hidden');
                                    document.getElementById('tt-chips').classList.remove('hidden');
                                    document.getElementById('tt-chips').innerHTML = d.topical_topics.map(function (x) {
                                        return '<span class="' + chipCls + '">' + esc(x.topic) + ' <span class="font-bold">×' + (x.count | 0) + '</span></span>';
                                    }).join('');
                                }
                                if (d.topical_relevant_pct !== null && d.topical_relevant_pct !== undefined) {
                                    document.getElementById('tt-relwrap').classList.remove('hidden');
                                    document.getElementById('tt-pct').textContent = d.topical_relevant_pct + '%';
                                    document.getElementById('tt-bar').style.width = Math.max(2, Math.min(100, d.topical_relevant_pct)) + '%';
                                }
                                // Live TopicSignal gauge (present on /backlinks).
                                var gv = document.getElementById('ts-topical-value'), gr = document.getElementById('ts-topical-ring'), gn = document.getElementById('ts-topical-num');
                                if (d.topical_score !== null && d.topical_score !== undefined) {
                                    if (gv) gv.textContent = d.topical_score + '/100';
                                    if (gn) gn.textContent = d.topical_score;
                                    if (gr) {
                                        gr.setAttribute('stroke-dasharray', (d.topical_score / 100 * 163.4).toFixed(1) + ' 163.4');
                                        gr.setAttribute('stroke', d.topical_score >= 60 ? '#10b981' : (d.topical_score >= 30 ? '#f59e0b' : '#f43f5e'));
                                    }
                                    // Gauge "Analyzing topics…" note: keep the spinner while
                                    // batches still run, drop it entirely when complete.
                                    var note = document.getElementById('ts-topical-note');
                                    if (note && d.topical === 'ready' && (d.topical_total || 0) > 0 && (d.topical_done || 0) >= d.topical_total) {
                                        note.remove();
                                    }
                                }
                                var done = d.topical_done || 0, total = d.topical_total || 0;
                                if (total > 0 && done >= total && d.topical === 'ready') {
                                    document.getElementById('tt-spin').classList.add('hidden');
                                    document.getElementById('tt-count').textContent = @json(__('Based on all :n referring domains', ['n' => ':n'])).replace(':n', done);
                                    return; // complete — stop polling
                                }
                                if (done > 0) {
                                    document.getElementById('tt-count').textContent =
                                        @json(__(':done of :total referring domains analyzed', ['done' => ':d', 'total' => ':t'])).replace(':d', done).replace(':t', total);
                                }
                                if (d.topical === 'none') { document.getElementById('tt-live').remove(); return; } // stub cleared → job bailed
                                setTimeout(function () { poll(t + 1); }, 6000);
                            })
                            .catch(function () { setTimeout(function () { poll(t + 1); }, 12000); });
                    })(0);
                })();
                </script>
            @endauth
        @endif
    </div>
@endif
