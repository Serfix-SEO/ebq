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

    {{-- Authority gauges --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        @foreach ([
            ['Domain Authority', $g['domain_authority'] ?? null, $accent],
            ['Page Authority', $g['page_authority'] ?? null, $accent],
            ['Authority score', $g['authority_score'] ?? null, '#1baf7a'],
            ['Spam score', $g['spam_score'] ?? null, '#1baf7a'],
        ] as [$lbl, $val, $col])
            <div class="rounded-2xl bg-white p-5 text-center shadow-sm ring-1 ring-slate-200">
                <div class="flex justify-center">@include('reports.charts.ring', ['value' => $val ?? 0, 'display' => $val === null ? '—' : $val, 'label' => $lbl, 'color' => $col, 'size' => 76])</div>
                <div class="mt-2 text-sm font-medium text-slate-700">{{ $lbl }}</div>
            </div>
        @endforeach
    </div>

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

    {{-- Backlink profile --}}
    @if (! empty($p['history']))
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="text-base font-semibold text-slate-900">Backlink profile — active vs lost</h2>
            </div>
            @include('reports.charts.profile', ['history' => $p['history']])
            <div class="mt-2 flex gap-4 text-xs text-slate-500">
                <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm" style="background: {{ $accent }}"></span>Active</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-red-500"></span>Lost</span>
            </div>
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
        'head' => ['Domain', 'Authority', 'Backlinks', 'First seen'],
        'scroll' => true,
        'rows' => collect($p['top_referring_domains'] ?? [])->map(fn ($row) => [
            ['link' => $row['domain'] ?? ''],
            ['text' => $score($row['opr_score'] ?? null), 'right' => true],
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
                ['text' => ($row['anchor'] ?? '') !== '' ? $row['anchor'] : '(empty)'],
                ['text' => $fmt($row['backlinks'] ?? null), 'right' => true],
                ['text' => $fmt($row['referring_domains'] ?? null), 'right' => true],
                ['text' => $fmt($row['dofollow'] ?? null), 'right' => true],
            ])->all(),
        ])
    @endif

    @if (! empty($p['backlinks']))
        <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                <span class="text-base font-semibold text-slate-900">Backlinks</span>
                @if (count($p['backlinks']) > 8)
                    <span class="inline-flex items-center gap-1 rounded-full bg-orange-50 px-2.5 py-1 text-xs font-semibold text-orange-700">
                        <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 13.5L12 21m0 0l-7.5-7.5M12 21V3" /></svg>
                        {{ number_format(count($p['backlinks'])) }} {{ \Illuminate\Support\Str::plural('row', count($p['backlinks'])) }} — scroll for more
                    </span>
                @else
                    <span class="text-xs text-slate-400">{{ number_format(count($p['backlinks'])) }} {{ \Illuminate\Support\Str::plural('row', count($p['backlinks'])) }}</span>
                @endif
            </div>
            <div class="max-h-[420px] overflow-y-auto overflow-x-auto [&::-webkit-scrollbar]:w-2.5 [&::-webkit-scrollbar-track]:bg-slate-50 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-slate-300">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 z-10 bg-white"><tr class="text-left text-xs uppercase tracking-wide text-slate-400">
                        <th class="border-b border-slate-100 bg-white px-5 py-2 font-medium">Source page</th><th class="border-b border-slate-100 bg-white px-3 py-2 font-medium">Anchor</th><th class="border-b border-slate-100 bg-white px-3 py-2 text-right font-medium">Type</th><th class="border-b border-slate-100 bg-white px-5 py-2 text-right font-medium">Authority</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($p['backlinks'] as $row)
                        <tr class="border-t border-slate-100 align-top">
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
                            </td>
                            <td class="px-5 py-2.5 text-right text-slate-600">{{ $score($row['opr_score'] ?? null) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if (! empty($p['competitors']))
        @include('reports.partials.web-table', [
            'title' => 'Competitors',
            'head' => ['Domain', 'Shared keywords', 'Avg position', 'Popularity'],
            'scroll' => true,
            'rows' => collect($p['competitors'])->map(fn ($row) => [
                ['link' => $row['domain'] ?? ''],
                ['text' => $fmt($row['shared_keywords'] ?? null), 'right' => true],
                ['text' => $dash($row['avg_position'] ?? null), 'right' => true],
                ['text' => $score($row['opr_score'] ?? null), 'right' => true],
            ])->all(),
        ])
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
</div>
