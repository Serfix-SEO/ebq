@php
    $p = $payload ?? [];
    $accent = $branding->accent_color ?? '#F26419';
    $company = $branding->company_name ?? 'Serfix';
    $logo = ($branding && method_exists($branding, 'logoUrl')) ? $branding->logoUrl() : null;
    $g = $p['gauges'] ?? [];
    $t = $p['totals'] ?? [];
    $r = $p['ratios'] ?? [];
    $at = $p['anchor_types'] ?? [];
    $fmt = fn ($v) => is_numeric($v) ? number_format((int) $v) : '—';
    $dash = fn ($v) => ($v === null || $v === '') ? '—' : $v;
@endphp

<div style="border-bottom: 2px solid {{ $accent }}; padding-bottom: 10px; margin-bottom: 16px;">
    @if ($logo)
        <img src="{{ $logo }}" alt="{{ $company }}" style="max-height: 40px; max-width: 200px;">
    @else
        <span style="font-size: 16px; font-weight: 500; color: {{ $accent }};">{{ $company }}</span>
    @endif
    <div style="font-size: 16px; font-weight: 500; color: #1e293b; margin-top: 6px;">{{ $p['domain'] ?? '' }}</div>
    <div style="font-size: 12px; color: #94a3b8;">Backlink &amp; authority report @isset($generatedAt)· {{ $generatedAt }}@endisset</div>
</div>

<table style="width: 100%; border-collapse: separate; border-spacing: 8px; margin: 0 0 8px;">
    <tr>
        @foreach ([
            ['Domain Authority', $g['domain_authority'] ?? null, $accent],
            ['Page Authority', $g['page_authority'] ?? null, $accent],
            ['Authority score', $g['authority_score'] ?? null, '#1baf7a'],
            ['Spam score', $g['spam_score'] ?? null, '#1baf7a'],
        ] as [$lbl, $val, $col])
            <td style="width: 25%; background: #f8fafc; border-radius: 12px; padding: 10px; text-align: center;">
                @include('reports.charts.ring', ['value' => $val ?? 0, 'display' => $val === null ? '—' : $val, 'label' => $lbl, 'color' => $col])
                <div style="font-size: 13px; font-weight: 500; color: #1e293b;">{{ $lbl }}</div>
            </td>
        @endforeach
    </tr>
</table>

@php $pop = $p['popularity'] ?? null; @endphp
@if (! empty($pop) && (($pop['rank'] ?? null) !== null || ($pop['score'] ?? null) !== null))
    <table style="width: 100%; border-collapse: separate; border-spacing: 8px; margin: 0 0 8px;">
        <tr>
            <td style="width: 50%; background: #fff7ed; border-radius: 8px; padding: 10px;">
                <div style="font-size: 20px; font-weight: 500; color: #C44E0E;">{{ ($pop['rank'] ?? null) !== null ? '#'.number_format((int) $pop['rank']) : '—' }}</div>
                <div style="font-size: 12px; color: #64748b;">Popularity rank</div>
            </td>
            <td style="width: 50%; background: #fff7ed; border-radius: 8px; padding: 10px;">
                <div style="font-size: 20px; font-weight: 500; color: #C44E0E;">{{ ($pop['score'] ?? null) !== null ? $pop['score'].' / 10' : '—' }}</div>
                <div style="font-size: 12px; color: #64748b;">Popularity score</div>
            </td>
        </tr>
    </table>
@endif

<table style="width: 100%; border-collapse: separate; border-spacing: 8px; margin: 0 0 12px;">
    <tr>
        @foreach ([
            ['Total backlinks', $t['backlinks'] ?? null],
            ['Referring domains', $t['referring_domains'] ?? null],
            ['Referring IPs', $t['referring_ips'] ?? null],
            ['Referring subnets', $t['referring_subnets'] ?? null],
        ] as [$lbl, $val])
            <td style="width: 25%; background: #f8fafc; border-radius: 8px; padding: 10px;">
                <div style="font-size: 20px; font-weight: 500; color: #1e293b;">{{ $fmt($val) }}</div>
                <div style="font-size: 12px; color: #64748b;">{{ $lbl }}</div>
            </td>
        @endforeach
    </tr>
</table>

@if (! empty($p['history']))
    <div style="border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 14px; margin: 0 0 12px;">
        <div style="font-size: 14px; font-weight: 500; color: #1e293b; margin-bottom: 6px;">Backlink profile — active vs lost</div>
        @include('reports.charts.profile', ['history' => $p['history']])
        <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
            <span style="color: {{ $accent }};">&#9632;</span> Active &nbsp;
            <span style="color: #e34948;">&#9632;</span> Lost
        </div>
    </div>
@endif

<table style="width: 100%; border-collapse: separate; border-spacing: 8px; margin: 0 0 12px;">
    <tr>
        <td style="width: 25%; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px; text-align: center;">
            @include('reports.charts.ring', ['value' => $r['dofollow_pct'] ?? 0, 'display' => ($r['dofollow_pct'] ?? null) === null ? '—' : $r['dofollow_pct'] . '%', 'label' => 'Dofollow ratio', 'color' => $accent])
            <div style="font-size: 13px; font-weight: 500; color: #1e293b;">Dofollow ratio</div>
        </td>
        <td style="width: 25%; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px; text-align: center;">
            @include('reports.charts.ring', ['value' => $r['active_pct'] ?? 0, 'display' => ($r['active_pct'] ?? null) === null ? '—' : $r['active_pct'] . '%', 'label' => 'Active links', 'color' => '#1baf7a'])
            <div style="font-size: 13px; font-weight: 500; color: #1e293b;">Active links ratio</div>
        </td>
        <td style="width: 50%; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px;">
            <div style="font-size: 13px; font-weight: 500; color: #1e293b; margin-bottom: 8px;">Anchor types</div>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 6px;"><tr>
                @foreach ([['branded', $accent], ['naked', '#eda100'], ['generic', '#888780'], ['exact', '#2a78d6']] as [$k, $c])
                    @php $pct = (int) ($at[$k] ?? 0); @endphp
                    @if ($pct > 0)
                        <td style="width: {{ $pct }}%; background: {{ $c }}; height: 14px;"></td>
                    @endif
                @endforeach
            </tr></table>
            <div style="font-size: 11px; color: #64748b;">
                <span style="color: {{ $accent }};">&#9632;</span> Branded {{ (int) ($at['branded'] ?? 0) }}%
                <span style="color: #c98500;">&#9632;</span> Naked {{ (int) ($at['naked'] ?? 0) }}%
                <span style="color: #5F5E5A;">&#9632;</span> Generic {{ (int) ($at['generic'] ?? 0) }}%
                <span style="color: #185FA5;">&#9632;</span> Exact {{ (int) ($at['exact'] ?? 0) }}%
            </div>
        </td>
    </tr>
</table>

@if (! empty($p['top_referring_domains']))
    <div style="border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 14px; margin: 0 0 12px;">
        <div style="font-size: 14px; font-weight: 500; color: #1e293b; margin-bottom: 8px;">Top referring domains</div>
        <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
            <thead><tr style="color: #94a3b8; text-align: left;">
                <th style="padding: 4px 0;">Domain</th><th style="text-align: right;">Authority</th><th style="text-align: right;">Backlinks</th><th style="text-align: right;">First seen</th>
            </tr></thead>
            <tbody>
            @foreach (array_slice($p['top_referring_domains'], 0, 10) as $row)
                <tr style="border-top: 1px solid #e2e8f0; color: #64748b;">
                    <td style="padding: 6px 0; color: #185FA5;">{{ $row['domain'] ?? '' }}</td>
                    <td style="text-align: right;">{{ ($row['opr_score'] ?? null) !== null ? $row['opr_score'].'/10' : '—' }}</td>
                    <td style="text-align: right;">{{ $fmt($row['backlinks'] ?? null) }}</td>
                    <td style="text-align: right;">{{ $dash($row['first_seen'] ?? null) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

@if (! empty($p['anchors']))
    <div style="border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 14px; margin: 0 0 12px;">
        <div style="font-size: 14px; font-weight: 500; color: #1e293b; margin-bottom: 8px;">Anchor texts</div>
        <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
            <thead><tr style="color: #94a3b8; text-align: left;">
                <th style="padding: 4px 0;">Anchor</th><th style="text-align: right;">Backlinks</th><th style="text-align: right;">Ref domains</th><th style="text-align: right;">Dofollow</th>
            </tr></thead>
            <tbody>
            @foreach (array_slice($p['anchors'], 0, 12) as $row)
                <tr style="border-top: 1px solid #e2e8f0; color: #64748b;">
                    <td style="padding: 6px 0; color: #1e293b;">{{ ($row['anchor'] ?? '') !== '' ? $row['anchor'] : '(empty)' }}</td>
                    <td style="text-align: right;">{{ $fmt($row['backlinks'] ?? null) }}</td>
                    <td style="text-align: right;">{{ $fmt($row['referring_domains'] ?? null) }}</td>
                    <td style="text-align: right;">{{ $fmt($row['dofollow'] ?? null) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

@if (! empty($p['backlinks']))
    <div style="border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 14px; margin: 0 0 12px;">
        <div style="font-size: 14px; font-weight: 500; color: #1e293b; margin-bottom: 8px;">Backlinks</div>
        <table style="width: 100%; border-collapse: collapse; font-size: 12px; table-layout: fixed;">
            <thead><tr style="color: #94a3b8; text-align: left;">
                <th style="padding: 4px 0; width: 46%;">Source page</th><th style="width: 30%;">Anchor</th><th style="text-align: right; width: 12%;">Type</th><th style="text-align: right; width: 12%;">Authority</th>
            </tr></thead>
            <tbody>
            @foreach (array_slice($p['backlinks'], 0, 15) as $row)
                <tr style="border-top: 1px solid #e2e8f0; color: #64748b; vertical-align: top;">
                    <td style="padding: 6px 6px 6px 0; word-break: break-all;">
                        <a href="{{ $row['url_from'] ?? '#' }}" target="_blank" rel="nofollow noopener" style="color: #185FA5; text-decoration: none;">{{ \Illuminate\Support\Str::limit($row['url_from'] ?? '', 60) }}</a>
                        @if (! empty($row['url_to']))<div style="color: #94a3b8; font-size: 11px;">→ {{ \Illuminate\Support\Str::limit($row['url_to'], 50) }}</div>@endif
                    </td>
                    <td style="padding: 6px; word-break: break-word;">{{ ($row['anchor'] ?? '') !== '' ? \Illuminate\Support\Str::limit($row['anchor'], 40) : '(empty)' }}</td>
                    <td style="text-align: right; padding: 6px 0;">{{ ! empty($row['dofollow']) ? 'dofollow' : 'nofollow' }}</td>
                    <td style="text-align: right; padding: 6px 0;">{{ ($row['opr_score'] ?? null) !== null ? $row['opr_score'].'/10' : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

@if (! empty($p['competitors']))
    <div style="border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 14px; margin: 0 0 12px;">
        <div style="font-size: 14px; font-weight: 500; color: #1e293b; margin-bottom: 8px;">Competitors</div>
        <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
            <thead><tr style="color: #94a3b8; text-align: left;">
                <th style="padding: 4px 0;">Domain</th><th style="text-align: right;">Shared keywords</th><th style="text-align: right;">Avg position</th><th style="text-align: right;">Popularity</th>
            </tr></thead>
            <tbody>
            @foreach ($p['competitors'] as $row)
                <tr style="border-top: 1px solid #e2e8f0; color: #64748b;">
                    <td style="padding: 6px 0; color: #185FA5;">{{ $row['domain'] ?? '' }}</td>
                    <td style="text-align: right;">{{ $fmt($row['shared_keywords'] ?? null) }}</td>
                    <td style="text-align: right;">{{ $dash($row['avg_position'] ?? null) }}</td>
                    <td style="text-align: right;">{{ ($row['opr_score'] ?? null) !== null ? $row['opr_score'].'/10' : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

@if (! empty($p['traffic']))
    <div style="background: #f8fafc; border-radius: 12px; padding: 10px 14px; margin: 0 0 12px;">
        <div style="font-size: 12px; color: #94a3b8; margin-bottom: 6px;">Traffic &amp; keywords</div>
        <table style="width: 100%; border-collapse: separate; border-spacing: 8px 0;"><tr>
            @foreach ([
                ['Clicks (30d)', $fmt($p['traffic']['clicks'] ?? null)],
                ['Impressions', $fmt($p['traffic']['impressions'] ?? null)],
                ['Avg position', $dash($p['traffic']['avg_position'] ?? null)],
                ['Ranking keywords', $fmt($p['traffic']['keywords'] ?? null)],
            ] as [$lbl, $val])
                <td style="width: 25%;">
                    <div style="font-size: 18px; font-weight: 500; color: #1e293b;">{{ $val }}</div>
                    <div style="font-size: 11px; color: #64748b;">{{ $lbl }}</div>
                </td>
            @endforeach
        </tr></table>
    </div>
@endif

<div style="border-top: 1px solid #e2e8f0; padding-top: 10px; font-size: 11px; color: #94a3b8; text-align: center;">
    Prepared by {{ $company }}
</div>
