@php
    $p = $payload ?? [];
    $accent = $branding->accent_color ?? '#F26419';
    $company = $branding->company_name ?? 'Serfix';
    $g = $p['gauges'] ?? [];
    $t = $p['totals'] ?? [];
    $r = $p['ratios'] ?? [];
    $at = $p['anchor_types'] ?? [];
    $pop = $p['popularity'] ?? null;
    $fmt = fn ($v) => is_numeric($v) ? number_format((int) $v) : '—';
    $dash = fn ($v) => ($v === null || $v === '') ? '—' : $v;
    $score = fn ($v) => ($v ?? null) !== null ? $v.'/10' : '—';
    $isPartial = ! empty($p['meta']['partial']);
    $sources = $p['meta']['sources'] ?? [];
    $opportunitySource = $p['meta']['opportunity_source'] ?? null;
    $gapUrl = $gapUrl ?? null;
    // Per-plan render cap + drill-down entitlement (public shares: full view,
    // no drill-down — the button is auth-gated anyway).
    $viewerPlan = auth()->user()?->effectivePlan();
    $canDrilldown = auth()->check() && $viewerPlan?->apiLimit('report.allow_link_drilldown') !== 0;
    // Monthly row quota outcome (BacklinkRowQuota via resolve()); public
    // shares have no key → full view.
    $bv = $p['_backlink_view'] ?? null;
    $shownRows = $bv['shown'] ?? (auth()->check() ? ($viewerPlan?->apiLimit('report.max_backlink_rows') ?? 1000) : 1000);
    $backlinkRows = array_slice($p['backlinks'] ?? [], 0, $shownRows);
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 pb-5">
        <div class="flex items-center gap-3">
            <img src="https://www.google.com/s2/favicons?domain={{ urlencode($p['domain'] ?? '') }}&sz=64" alt="" width="40" height="40" class="h-10 w-10 flex-none rounded-lg bg-slate-100 ring-1 ring-slate-200" loading="lazy" onerror="this.style.visibility='hidden'">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-900">{{ $p['domain'] ?? '' }}</h1>
                <p class="mt-0.5 text-sm text-slate-500">Backlink &amp; authority report{{ !empty($generatedAt) ? ' · '.$generatedAt : '' }}</p>
            </div>
        </div>
        @if (! empty($downloadUrl))
            <a href="{{ $downloadUrl }}" class="inline-flex items-center gap-2 rounded-lg bg-orange-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-orange-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-6L12 15m0 0 4.5-4.5M12 15V3" /></svg>
                Download PDF
            </a>
        @endif
    </div>

    {{-- Young-site notice: no direct index data yet, so this report is built
         from the closest available signals. Sections carrying indirect data
         are tagged with a source badge. --}}
    @if ($isPartial)
        <div class="rounded-2xl border-l-4 border-sky-500 bg-sky-50 p-6 ring-1 ring-sky-200 sm:p-7">
            <p class="text-xl font-bold text-sky-900 sm:text-2xl">{{ $p['domain'] ?? '' }} looks like a new website</p>
            <p class="mt-2 text-base leading-relaxed text-sky-800">
                Search and link databases don't have direct data on it yet, so we went the extra mile and
                gathered the closest available data below. Anything not measured directly from the site
                carries a source tag. Backlink data fills in automatically as the web discovers this site.
            </p>
        </div>
    @endif

    {{-- Authority gauges --}}
    @php
        $scores = $p['scores'] ?? [];
        // TopicSignal computes from the topical classification, which runs in
        // the background after the report lands — show a processing state
        // (live-updated by the topical-live poller) instead of a bare "—".
        $ttSection = $p['topical_trust'] ?? null;
        $topicalRunning = (! empty($ttSection['pending'])
                || ((int) ($ttSection['sample'] ?? 0) < (int) ($ttSection['total'] ?? 0)))
            && now()->diffInMinutes(\Illuminate\Support\Carbon::parse($ttSection['queued_at'] ?? now()), true) < 45;
    @endphp
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
        @foreach ([
            ['TrustSignal', $scores['trust'] ?? null, $accent],
            ['CiteSignal', $scores['citation'] ?? null, $accent],
            ['TopicSignal', $scores['topical'] ?? null, $accent],
            ['Domain Authority', $g['domain_authority'] ?? null, $accent],
            ['Page Authority', $g['page_authority'] ?? null, $accent],
            ['Authority score', $g['authority_score'] ?? null, '#1baf7a'],
            ['Spam score', $g['spam_score'] ?? null, '#1baf7a'],
        ] as [$lbl, $val, $col])
            <div class="rounded-2xl bg-white p-5 text-center shadow-sm ring-1 ring-slate-200">
                @if ($lbl === 'TopicSignal' && $topicalRunning)
                    {{-- Processing state: dasharray ring + center text the
                         topical-live poller fills in as batches land. --}}
                    <div class="flex justify-center">
                        <svg width="76" height="76" viewBox="0 0 72 72" aria-hidden="true">
                            <circle cx="36" cy="36" r="26" fill="none" stroke="#e2e8f0" stroke-width="7" />
                            <circle id="ts-topical-ring" cx="36" cy="36" r="26" fill="none" stroke="{{ $accent }}" stroke-width="7" stroke-linecap="round" stroke-dasharray="{{ $val !== null ? round($val / 100 * 163.4, 1) : 0 }} 163.4" transform="rotate(-90 36 36)" />
                            <text id="ts-topical-num" x="36" y="42" text-anchor="middle" fill="#0f172a" font-size="18" font-weight="700">{{ $val ?? '…' }}</text>
                        </svg>
                    </div>
                    <div class="mt-2 text-sm font-medium text-slate-700">{{ $lbl }}</div>
                    <div id="ts-topical-note" class="mt-1 inline-flex items-center justify-center gap-1.5 text-[11px] text-slate-400">
                        <span id="ts-topical-spin" class="h-2.5 w-2.5 flex-none animate-spin rounded-full border-2 border-slate-200 border-t-orange-500"></span>
                        Analyzing topics…
                    </div>
                @else
                    <div class="flex justify-center">@include('reports.charts.ring', ['value' => $val ?? 0, 'display' => $val === null ? '—' : $val, 'label' => $lbl, 'color' => $col, 'size' => 76])</div>
                    <div class="mt-2 text-sm font-medium text-slate-700">{{ $lbl }}</div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Organic traffic trend — Semrush-style: y-axis value ticks, dated x-axis,
         1Y/2Y/All period selector, area+line, crosshair tooltip. Rendered in JS
         (in-SVG so it stays crisp + responsive). Independent of GSC; visits only,
         never a $ figure. Hidden when the domain has no organic traffic. --}}
    @if (! empty($p['organic_traffic']) && count($p['organic_traffic']) > 1 && collect($p['organic_traffic'])->max('visits') > 0)
        @php
            $ot = collect($p['organic_traffic'])->filter(fn ($r) => isset($r['month']))->sortBy('month')->values();
            $otSeries = $ot->map(fn ($r) => ['m' => $r['month'], 'v' => (int) $r['visits']])->all();
            $otCur = (int) ($ot->last()['visits'] ?? 0);
        @endphp
        <div class="ot-card rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 sm:p-6" data-ot-series='@json($otSeries)'>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Organic traffic') }}</div>
                    <div class="mt-1 flex items-center gap-2">
                        <span class="ot-cur text-2xl font-bold leading-none text-slate-900">{{ number_format($otCur) }}</span>
                        <span class="ot-delta"></span>
                    </div>
                    <div class="mt-0.5 text-xs text-slate-400">{{ __('Estimated monthly visits') }}</div>
                </div>
                <div class="ot-periods inline-flex rounded-lg border border-slate-200 bg-slate-50 p-1 text-xs font-semibold">
                    <button type="button" data-m="12" class="rounded-md px-3 py-1 text-slate-500">1Y</button>
                    <button type="button" data-m="24" class="rounded-md px-3 py-1 text-slate-500">2Y</button>
                    <button type="button" data-m="0" class="rounded-md px-3 py-1 text-slate-500">{{ __('All') }}</button>
                </div>
            </div>
            <div class="ot-canvas relative mt-4 select-none">
                <svg class="ot-svg block w-full" style="height:230px" role="img" aria-label="{{ __('Organic traffic chart') }}"></svg>
                <div class="ot-tip pointer-events-none absolute z-10 hidden whitespace-nowrap rounded-lg px-2.5 py-1.5 text-xs leading-tight text-white shadow-lg" style="background:#0f172a"></div>
            </div>
        </div>
        <script>
        (function () {
            var MO = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            document.querySelectorAll('.ot-card[data-ot-series]').forEach(function (card) {
                if (card._ot) return; card._ot = 1;
                var all; try { all = JSON.parse(card.getAttribute('data-ot-series') || '[]'); } catch (e) { return; }
                if (!all || all.length < 2) return;
                var svg = card.querySelector('.ot-svg'), tip = card.querySelector('.ot-tip'),
                    curEl = card.querySelector('.ot-cur'), deltaEl = card.querySelector('.ot-delta'),
                    canvas = card.querySelector('.ot-canvas'), btns = card.querySelectorAll('.ot-periods button');
                var period = 0, pts = [], vbw = 680, vbh = 230, hover, hx, hd;
                function fmt(n) { n = +n; if (n >= 1e6) return (n / 1e6).toFixed(n >= 1e7 ? 0 : 1).replace(/\.0$/, '') + 'M'; if (n >= 1e3) return Math.round(n / 1e3) + 'K'; return '' + Math.round(n); }
                function mlabel(m) { var a = (m || '').split('-'); return (MO[(+a[1]) - 1] || '') + " '" + (a[0] || '').slice(2); }
                function niceMax(m) { var p = Math.pow(10, Math.floor(Math.log(m) / Math.LN10)); var f = m / p; var nf = f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10; return nf * p; }
                function data() { return period > 0 ? all.slice(Math.max(0, all.length - period)) : all; }
                function render() {
                    var d = data(), w = Math.max(320, canvas.clientWidth || 640), H = 230;
                    var padL = 44, padR = 12, padT = 12, padB = 24, plotW = w - padL - padR, plotH = H - padT - padB;
                    var max = Math.max.apply(null, d.map(function (o) { return o.v; })) || 1, ymax = niceMax(max), N = Math.max(1, d.length - 1);
                    pts = d.map(function (o, i) { return { x: padL + i / N * plotW, y: padT + plotH * (1 - o.v / ymax), m: o.m, v: o.v }; });
                    var line = pts.map(function (p) { return p.x.toFixed(1) + ',' + p.y.toFixed(1); }).join(' ');
                    var area = 'M ' + pts[0].x.toFixed(1) + ',' + (padT + plotH) + ' L ' + line + ' L ' + pts[pts.length - 1].x.toFixed(1) + ',' + (padT + plotH) + ' Z';
                    var s = '<defs><linearGradient id="otg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ea580c" stop-opacity=".18"/><stop offset="1" stop-color="#ea580c" stop-opacity="0"/></linearGradient></defs>';
                    for (var t = 0; t <= 3; t++) { var ry = padT + plotH * t / 3, val = ymax * (1 - t / 3);
                        s += '<line x1="' + padL + '" y1="' + ry.toFixed(1) + '" x2="' + (w - padR) + '" y2="' + ry.toFixed(1) + '" stroke="#f1f5f9"/>';
                        s += '<text x="' + (padL - 8) + '" y="' + (ry + 3.5).toFixed(1) + '" text-anchor="end" font-size="10" fill="#94a3b8">' + (val > 0 ? fmt(val) : '0') + '</text>'; }
                    s += '<path d="' + area + '" fill="url(#otg)"/>';
                    s += '<polyline fill="none" stroke="#ea580c" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" points="' + line + '"/>';
                    s += '<circle cx="' + pts[pts.length - 1].x.toFixed(1) + '" cy="' + pts[pts.length - 1].y.toFixed(1) + '" r="3" fill="#ea580c"/>';
                    var ticks = Math.min(6, d.length);
                    for (var k = 0; k < ticks; k++) { var idx = Math.round(k / Math.max(1, ticks - 1) * (d.length - 1)), px = pts[idx].x;
                        var anch = k === 0 ? 'start' : (k === ticks - 1 ? 'end' : 'middle');
                        s += '<text x="' + px.toFixed(1) + '" y="' + (H - 8) + '" text-anchor="' + anch + '" font-size="10" fill="#94a3b8">' + mlabel(d[idx].m) + '</text>'; }
                    s += '<g class="ot-hover" style="display:none"><line class="ot-hx" y1="' + padT + '" y2="' + (padT + plotH) + '" stroke="#fdba74"/><circle class="ot-hd" r="4" fill="#ea580c" stroke="#fff" stroke-width="2"/></g>';
                    svg.setAttribute('viewBox', '0 0 ' + w + ' ' + H); svg.innerHTML = s; vbw = w; vbh = H;
                    hover = svg.querySelector('.ot-hover'); hx = svg.querySelector('.ot-hx'); hd = svg.querySelector('.ot-hd');
                    curEl.textContent = (all[all.length - 1].v).toLocaleString();
                    var first = null; for (var i = 0; i < d.length; i++) { if (d[i].v > 0) { first = d[i]; break; } }
                    if (first && first.v > 0) { var dl = Math.round((d[d.length - 1].v - first.v) / first.v * 100);
                        deltaEl.innerHTML = '<span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ' + (dl >= 0 ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-500') + '">' + (dl >= 0 ? '▲' : '▼') + ' ' + Math.abs(dl) + '%</span>'; }
                    else deltaEl.innerHTML = '';
                }
                function moveHover(e) {
                    if (!pts.length) return;
                    var r = svg.getBoundingClientRect(), cx = (e.touches && e.touches[0] ? e.touches[0].clientX : e.clientX) - r.left, vx = cx / r.width * vbw;
                    var best = 0, bd = Infinity; for (var i = 0; i < pts.length; i++) { var dd = Math.abs(pts[i].x - vx); if (dd < bd) { bd = dd; best = i; } }
                    var p = pts[best]; if (hover) { hover.style.display = ''; hx.setAttribute('x1', p.x); hx.setAttribute('x2', p.x); hd.setAttribute('cx', p.x); hd.setAttribute('cy', p.y); }
                    var px = p.x / vbw * r.width, py = p.y / vbh * r.height;
                    tip.innerHTML = '<span style="font-weight:600">' + p.v.toLocaleString() + '</span> <span style="opacity:.6">visits</span><br><span style="opacity:.6">' + mlabel(p.m) + '</span>';
                    tip.classList.remove('hidden');
                    tip.style.left = Math.max(0, Math.min(r.width - tip.offsetWidth, px - tip.offsetWidth / 2)) + 'px';
                    tip.style.top = Math.max(0, py - tip.offsetHeight - 10) + 'px';
                }
                function leave() { if (hover) hover.style.display = 'none'; tip.classList.add('hidden'); }
                svg.addEventListener('mousemove', moveHover); svg.addEventListener('mouseleave', leave);
                svg.addEventListener('touchstart', moveHover, { passive: true }); svg.addEventListener('touchmove', moveHover, { passive: true }); svg.addEventListener('touchend', leave);
                function setActive(b) { btns.forEach(function (x) { x.classList.remove('bg-white', 'shadow-sm', 'text-slate-900'); x.classList.add('text-slate-500'); }); b.classList.add('bg-white', 'shadow-sm', 'text-slate-900'); b.classList.remove('text-slate-500'); }
                btns.forEach(function (b) { b.addEventListener('click', function () { period = +b.dataset.m || 0; setActive(b); render(); }); });
                setActive(btns[btns.length - 1]); render();
                var rt; window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(render, 150); });
            });
        })();
        </script>
    @endif

    {{-- Link risk — toxicity interpretation over the backlink profile --}}
    @include('reports.partials.link-risk', [
        'risk' => $p['link_risk'] ?? null,
        'dark' => false,
        'disavowUrl' => auth()->check() ? route('report.disavow', ['url' => $p['domain'] ?? '']) : null,
    ])

    {{-- Topical relevance — live card (self-updating, no reloads) --}}
    @include('reports.partials.topical-live', ['domain' => $p['domain'] ?? '', 'section' => $p['topical_trust'] ?? null, 'dark' => false])

    {{-- Popularity --}}
    @if (! empty($pop) && (($pop['rank'] ?? null) !== null || ($pop['score'] ?? null) !== null))
        <div class="grid grid-cols-2 gap-4">
            <div class="rounded-2xl bg-orange-50 p-5 ring-1 ring-orange-100">
                <div class="text-2xl font-bold text-orange-700">{{ ($pop['rank'] ?? null) !== null ? '#'.number_format((int) $pop['rank']) : '—' }}</div>
                <div class="text-sm text-slate-600">Popularity rank</div>
            </div>
            <div class="rounded-2xl bg-orange-50 p-5 ring-1 ring-orange-100">
                <div class="text-2xl font-bold text-orange-700">{{ $score($pop['score'] ?? null) }}</div>
                <div class="text-sm text-slate-600">Popularity score</div>
            </div>
        </div>
    @endif

    {{-- Totals --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        @foreach ([
            ['Total backlinks', $t['backlinks'] ?? null],
            ['Referring domains', $t['referring_domains'] ?? null],
            ['Referring IPs', $t['referring_ips'] ?? null],
            ['Referring subnets', $t['referring_subnets'] ?? null],
        ] as [$lbl, $val])
            <div class="rounded-xl bg-slate-50 p-4">
                <div class="text-xl font-semibold text-slate-900">{{ $fmt($val) }}</div>
                <div class="text-sm text-slate-500">{{ $lbl }}</div>
            </div>
        @endforeach
    </div>

    {{-- Keywords: the headline value for a NEW site → shown up here, above
         competitors. Established sites get them after the competitors table. --}}
    @if ($isPartial)
        @include('reports.partials.report-keywords')
    @endif

    {{-- Backlink profile: referring-domain growth + active vs lost --}}
    @if (! empty($p['history']))
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="text-base font-semibold text-slate-900">Referring domains over time</h2>
                </div>
                @include('reports.charts.trend', ['history' => $p['history'], 'accent' => $accent])
            </div>
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="text-base font-semibold text-slate-900">Links gained vs lost</h2>
                </div>
                @include('reports.charts.profile', ['history' => $p['history']])
                <div class="mt-2 flex gap-4 text-xs text-slate-500">
                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm" style="background: {{ $accent }}"></span>Active</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-red-500"></span>Lost</span>
                </div>
            </div>
        </div>
    @elseif ($isPartial)
        <div class="rounded-2xl bg-white p-8 text-center shadow-sm ring-1 ring-slate-200">
            <h2 class="text-base font-semibold text-slate-900">Backlink profile</h2>
            <p class="mt-1 text-sm text-slate-500">Not enough backlink data yet — this section fills in automatically as links to this site are discovered.</p>
        </div>
    @endif

    {{-- Ratios + anchor types --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-2xl bg-white p-5 text-center shadow-sm ring-1 ring-slate-200">
            <div class="flex justify-center">@include('reports.charts.ring', ['value' => $r['dofollow_pct'] ?? 0, 'display' => ($r['dofollow_pct'] ?? null) === null ? '—' : $r['dofollow_pct'].'%', 'label' => 'Dofollow', 'color' => $accent, 'size' => 88])</div>
            <div class="mt-2 text-sm font-medium text-slate-700">Dofollow ratio</div>
        </div>
        <div class="rounded-2xl bg-white p-5 text-center shadow-sm ring-1 ring-slate-200">
            <div class="flex justify-center">@include('reports.charts.ring', ['value' => $r['active_pct'] ?? 0, 'display' => ($r['active_pct'] ?? null) === null ? '—' : $r['active_pct'].'%', 'label' => 'Active', 'color' => '#1baf7a', 'size' => 88])</div>
            <div class="mt-2 text-sm font-medium text-slate-700">Active links ratio</div>
        </div>
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <div class="mb-3 text-sm font-medium text-slate-700">Anchor types</div>
            <div class="mb-3 flex h-3.5 overflow-hidden rounded-full">
                @foreach ([['branded', $accent], ['naked', '#eab308'], ['generic', '#94a3b8'], ['exact', '#3b82f6']] as [$k, $c])
                    @php $pct = (int) ($at[$k] ?? 0); @endphp
                    @if ($pct > 0)<div style="width: {{ $pct }}%; background: {{ $c }}"></div>@endif
                @endforeach
            </div>
            <div class="space-y-1 text-xs text-slate-500">
                <div><span class="mr-1 inline-block h-2 w-2 rounded-sm" style="background: {{ $accent }}"></span>Branded {{ (int) ($at['branded'] ?? 0) }}%</div>
                <div><span class="mr-1 inline-block h-2 w-2 rounded-sm bg-yellow-500"></span>Naked {{ (int) ($at['naked'] ?? 0) }}% ·
                    <span class="mx-1 inline-block h-2 w-2 rounded-sm bg-slate-400"></span>Generic {{ (int) ($at['generic'] ?? 0) }}% ·
                    <span class="mx-1 inline-block h-2 w-2 rounded-sm bg-blue-500"></span>Exact {{ (int) ($at['exact'] ?? 0) }}%</div>
            </div>
        </div>
    </div>

    @include('reports.partials.web-table', [
        'title' => 'Top referring domains',
        'head' => ['Domain', 'Trust', 'Citation', 'Backlinks', 'First seen'],
        'scroll' => true,
        'rows' => collect($p['top_referring_domains'] ?? [])->map(fn ($row) => [
            ['link' => $row['domain'] ?? '', 'risk' => $row['tox'] ?? null, 'riskWhy' => $row['tox_why'] ?? null],
            ['pill' => $row['ts'] ?? null, 'right' => true],
            ['pill' => $row['cs'] ?? null, 'right' => true],
            ['text' => $fmt($row['backlinks'] ?? null), 'right' => true],
            ['text' => $dash($row['first_seen'] ?? null), 'right' => true],
        ])->all(),
    ])

    @if (! empty($p['anchors']))
        @include('reports.partials.web-table', [
            'title' => 'Anchor texts',
            'head' => ['Anchor', 'Backlinks', 'Ref domains', 'Dofollow'],
            'scroll' => true,
            'rows' => collect($p['anchors'])->map(fn ($row) => [
                ($row['anchor'] ?? '') !== ''
                    ? ['anchorSearch' => $row['anchor'], 'target' => 'rpt-backlinks', 'text' => $row['anchor'], 'risk' => $row['tox'] ?? null, 'riskWhy' => $row['tox_why'] ?? null]
                    : ['text' => '(empty)'],
                ['text' => $fmt($row['backlinks'] ?? null), 'right' => true],
                ['text' => $fmt($row['referring_domains'] ?? null), 'right' => true],
                ['text' => $fmt($row['dofollow'] ?? null), 'right' => true],
            ])->all(),
        ])
    @endif

    @if (! empty($p['backlinks']))
        <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200" id="rpt-backlinks">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-3">
                <span class="text-base font-semibold text-slate-900">Backlinks</span>
                @if (count($p['backlinks']) > 8)
                    <span class="flex items-center gap-2.5">
                        <span class="inline-flex rounded-lg bg-slate-100 p-0.5 text-xs font-medium"><button type="button" data-rpt-group="rpt-backlinks" data-mode="all" class="rounded-md bg-white px-2.5 py-1 text-slate-900 shadow-sm">All links</button><button type="button" data-rpt-group="rpt-backlinks" data-mode="one" class="rounded-md px-3 py-1 text-slate-500">One per domain</button></span>
                        <input type="text" data-rpt-filter="rpt-backlinks" placeholder="Filter…"
                               class="w-36 rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-700 placeholder:text-slate-400 focus:border-orange-400 focus:outline-none focus:ring-1 focus:ring-orange-400">
                        <span class="inline-flex items-center gap-1 rounded-full bg-orange-50 px-2.5 py-1 text-xs font-semibold text-orange-700">
                            <span data-rpt-count="rpt-backlinks" data-total="{{ number_format(count($backlinkRows)) }}">{{ number_format(count($backlinkRows)) }}</span>
                            {{ \Illuminate\Support\Str::plural('row', count($p['backlinks'])) }}
                        </span>
                    </span>
                @else
                    <span class="text-xs text-slate-400">{{ number_format(count($p['backlinks'])) }} {{ \Illuminate\Support\Str::plural('row', count($p['backlinks'])) }}</span>
                @endif
            </div>
            <div class="max-h-[420px] overflow-y-auto overflow-x-auto [&::-webkit-scrollbar]:w-2.5 [&::-webkit-scrollbar-track]:bg-slate-50 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-slate-300">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 z-10 bg-white"><tr class="text-left text-xs uppercase tracking-wide text-slate-400">
                        <th data-sort class="cursor-pointer select-none border-b border-slate-100 bg-white px-5 py-2 font-medium transition hover:text-slate-600">Source page <span class="sort-ico text-[10px] text-slate-300">↕</span></th><th data-sort class="cursor-pointer select-none border-b border-slate-100 bg-white px-3 py-2 font-medium transition hover:text-slate-600">Anchor <span class="sort-ico text-[10px] text-slate-300">↕</span></th><th data-sort class="cursor-pointer select-none border-b border-slate-100 bg-white px-3 py-2 text-right font-medium transition hover:text-slate-600">Type <span class="sort-ico text-[10px] text-slate-300">↕</span></th><th data-sort class="cursor-pointer select-none border-b border-slate-100 bg-white px-3 py-2 text-right font-medium transition hover:text-slate-600">Trust <span class="sort-ico text-[10px] text-slate-300">↕</span></th><th data-sort class="cursor-pointer select-none border-b border-slate-100 bg-white px-5 py-2 text-right font-medium transition hover:text-slate-600">Citation <span class="sort-ico text-[10px] text-slate-300">↕</span></th>
                    </tr></thead>
                    <tbody>
                    @foreach ($backlinkRows as $row)
                        <tr @class(['border-t border-slate-100 align-top', 'bg-rose-50/60' => ($row['tox'] ?? null) === 'high', 'bg-amber-50/60' => ($row['tox'] ?? null) === 'medium']) data-domain="{{ strtolower((string) parse_url($row['url_from'] ?? '', PHP_URL_HOST)) }}" data-search="{{ ($row['url_from'] ?? '').' '.($row['url_to'] ?? '').' '.($row['anchor'] ?? '') }}">
                            <td class="max-w-[300px] px-5 py-2.5">
                                <div class="flex items-start gap-2">
                                    <img src="https://www.google.com/s2/favicons?domain={{ urlencode(parse_url($row['url_from'] ?? '', PHP_URL_HOST) ?: '') }}&sz=32" alt="" width="16" height="16" class="mt-0.5 h-4 w-4 flex-none rounded-sm bg-slate-100" loading="lazy" onerror="this.style.visibility='hidden'">
                                    <div class="min-w-0">
                                        <a href="{{ $row['url_from'] ?? '#' }}" target="_blank" rel="nofollow noopener" class="break-all text-orange-600 hover:underline">{{ \Illuminate\Support\Str::limit($row['url_from'] ?? '', 60) }}</a>
                                        @if (! empty($row['url_to']))<div class="truncate text-xs text-slate-400">→ {{ \Illuminate\Support\Str::limit($row['url_to'], 50) }}</div>@endif
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-2.5 text-slate-600">{{ ($row['anchor'] ?? '') !== '' ? \Illuminate\Support\Str::limit($row['anchor'], 36) : '(empty)' }}</td>
                            <td class="px-3 py-2.5 text-right">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ ! empty($row['dofollow']) ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500' }}">{{ ! empty($row['dofollow']) ? 'dofollow' : 'nofollow' }}</span>
                                @if (($row['tox'] ?? null) === 'high')
                                    <span title="{{ $row['tox_why'] ?? '' }}" class="ml-1 rounded-full bg-rose-100 px-1.5 py-0.5 text-[10px] font-bold text-rose-700">⚠ Toxic</span>
                                @elseif (($row['tox'] ?? null) === 'medium')
                                    <span title="{{ $row['tox_why'] ?? '' }}" class="ml-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-700">Risky</span>
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                @if (is_numeric($row['ts'] ?? null))
                                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums {{ (int) $row['ts'] >= 60 ? 'bg-emerald-50 text-emerald-700' : ((int) $row['ts'] >= 30 ? 'bg-amber-50 text-amber-700' : 'bg-rose-50 text-rose-700') }}">{{ (int) $row['ts'] }}</span>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="px-5 py-2.5 text-right">
                                @if (is_numeric($row['cs'] ?? null))
                                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums {{ (int) $row['cs'] >= 60 ? 'bg-emerald-50 text-emerald-700' : ((int) $row['cs'] >= 30 ? 'bg-amber-50 text-amber-700' : 'bg-rose-50 text-rose-700') }}">{{ (int) $row['cs'] }}</span>
                                @else
                                    <span class="text-slate-600">{{ $score($row['opr_score'] ?? null) }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @if (auth()->check() && count($p['backlinks'] ?? []) > count($backlinkRows))
                    <div class="border-t border-slate-100 bg-orange-50 px-5 py-3 text-center text-sm text-orange-800">
                        @if (! empty($bv['exhausted']))
                            Monthly backlink row limit reached — {{ number_format($bv['monthly_used']) }} of {{ number_format($bv['monthly_limit']) }} rows used this month.
                        @else
                            Showing {{ number_format(count($backlinkRows)) }} of {{ number_format(count($p['backlinks'])) }} backlinks on your current plan.
                            @if (($bv['monthly_limit'] ?? null) !== null)
                                <span class="opacity-80">({{ number_format($bv['monthly_used']) }} of {{ number_format($bv['monthly_limit']) }} monthly rows used)</span>
                            @endif
                        @endif
                        <a href="{{ route('billing.show') }}" class="font-semibold underline underline-offset-2 hover:text-orange-600">Upgrade to see them all</a>
                    </div>
                @endif
                <div data-rpt-empty="rpt-backlinks" data-none-text="The index has no live links for this exact anchor anymore." class="hidden border-t border-slate-100 px-5 py-6 text-center text-sm text-slate-500">
                    No backlinks match your search. This list holds the top {{ number_format(count($p['backlinks'])) }} links by strength{{ count($p['backlinks']) >= 1000 ? ' — very large profiles are capped at 1,000' : '' }}.
                    @if ($canDrilldown)
                        <div>
                            <button type="button" data-anchor-fetch="rpt-backlinks" data-endpoint="{{ route('report.anchor-links', ['url' => $p['domain'] ?? '']) }}"
                                    data-loading="Fetching from the live index…" data-failed="Nothing found in the index either" data-retry="Try again"
                                    class="mt-3 inline-flex items-center gap-2 rounded-lg bg-orange-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-orange-700 disabled:opacity-60">
                                Fetch this anchor's links from the live index
                            </button>
                        </div>
                        <div data-anchor-results></div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Top pages by backlinks (paid domain_pages data — surfaced 2026-07-15,
         was assembled into every payload but never rendered) --}}
    @if (! empty($p['top_pages']))
        @include('reports.partials.web-table', [
            'title' => 'Top pages by backlinks',
            'head' => ['Page', 'Ref domains', 'Backlinks'],
            'scroll' => true,
            'rows' => collect($p['top_pages'])->map(fn ($row) => [
                ['text' => \Illuminate\Support\Str::limit($row['url'] ?? '', 70)],
                ['text' => $fmt($row['referring_domains'] ?? null), 'right' => true],
                ['text' => $fmt($row['backlinks'] ?? null), 'right' => true],
            ])->all(),
        ])
    @endif

    {{-- Link profile details (summary-call fields — first seen, broken links,
         TLD / country / platform / link-type distributions) --}}
    @if (! empty($p['profile_details']))
        @php $pd = $p['profile_details']; @endphp
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <h2 class="mb-4 text-base font-semibold text-slate-900">Link profile details</h2>
            <div class="mb-5 grid grid-cols-2 gap-4 sm:grid-cols-4">
                @foreach ([
                    ['Domain first seen', $dash($pd['first_seen'] ?? null)],
                    ['Pages crawled', $fmt($pd['crawled_pages'] ?? null)],
                    ['Broken backlinks', $fmt($pd['broken_backlinks'] ?? null)],
                    ['Broken pages', $fmt($pd['broken_pages'] ?? null)],
                ] as [$lbl, $val])
                    <div class="rounded-xl bg-slate-50 p-4">
                        <div class="text-lg font-semibold text-slate-900">{{ $val }}</div>
                        <div class="text-sm text-slate-500">{{ $lbl }}</div>
                    </div>
                @endforeach
            </div>
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                @foreach ([
                    ['Referring TLDs', $pd['tlds'] ?? []],
                    ['Referring countries', $pd['countries'] ?? []],
                    ['Platform types', $pd['platform_types'] ?? []],
                    ['Link types', $pd['link_types'] ?? []],
                ] as [$lbl, $items])
                    @if (! empty($items))
                        @php $max = max(array_map(fn ($i) => (int) $i['count'], $items)); @endphp
                        <div>
                            <div class="mb-2 text-sm font-medium text-slate-700">{{ $lbl }}</div>
                            <div class="space-y-1.5">
                                @foreach (array_slice($items, 0, 6) as $item)
                                    <div class="flex items-center gap-2 text-xs">
                                        <span class="w-24 flex-none truncate text-slate-600">{{ $item['label'] === '' ? '(unknown)' : $item['label'] }}</span>
                                        <div class="h-2.5 flex-1 overflow-hidden rounded-full bg-slate-100">
                                            <div class="h-full rounded-full" style="width: {{ max(2, (int) round(100 * $item['count'] / max(1, $max))) }}%; background: {{ $accent }}"></div>
                                        </div>
                                        <span class="w-16 flex-none text-right tabular-nums text-slate-500">{{ number_format($item['count']) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    @endif

    @if (! empty($p['competitors']))
        @include('reports.partials.web-table', [
            'title' => 'Competitors',
            'badge' => ($sources['competitors'] ?? null) === 'search_results' ? 'Found via related search results' : null,
            'head' => ['Domain', 'Shared keywords', 'Avg position', 'Organic keywords', 'Popularity'],
            'scroll' => true,
            'rows' => collect($p['competitors'])->map(fn ($row) => [
                ['link' => $row['domain'] ?? ''],
                ['text' => $fmt($row['shared_keywords'] ?? null), 'right' => true],
                ['text' => $dash($row['avg_position'] ?? null), 'right' => true],
                ['text' => $fmt($row['organic_keywords'] ?? null), 'right' => true],
                ['text' => $score($row['opr_score'] ?? null), 'right' => true],
            ])->all(),
        ])
        @include('reports.partials.gap-cta', ['gapUrl' => $gapUrl, 'gapText' => 'See exactly which keywords these competitors win on — run a Keyword Gap analysis'])
    @endif

    {{-- Established sites: keywords after the competitive picture. --}}
    @if (! $isPartial)
        @include('reports.partials.report-keywords')
    @endif

    @if (! empty($p['traffic']))
        <div class="rounded-2xl bg-slate-50 p-5">
            <div class="mb-3 text-xs uppercase tracking-wide text-slate-400">Traffic &amp; keywords</div>
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                @foreach ([
                    ['Clicks (30d)', $fmt($p['traffic']['clicks'] ?? null)],
                    ['Impressions', $fmt($p['traffic']['impressions'] ?? null)],
                    ['Avg position', $dash($p['traffic']['avg_position'] ?? null)],
                    ['Ranking keywords', $fmt($p['traffic']['keywords'] ?? null)],
                ] as [$lbl, $val])
                    <div><div class="text-lg font-semibold text-slate-900">{{ $val }}</div><div class="text-xs text-slate-500">{{ $lbl }}</div></div>
                @endforeach
            </div>
        </div>
    @endif

    <p class="pt-2 text-center text-xs text-slate-400">Prepared by {{ $company }}</p>

    {{-- Table filter/sort JS (window-guarded — safe if web-table already included it). --}}
    @include('reports.partials.table-tools')
</div>
